package cmd

import (
	"context"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/transport"
)

// refreshTimeout bounds a credentials fetch. http.DefaultClient has none at
// all, so a single unanswered request would stall reconnection for good — the
// bridge would sit there looking healthy and never receive anything again.
const refreshTimeout = 15 * time.Second

// defaultMaxWorkers bounds the workers that run at once. One at a time is a
// surprise for a queue a person fills by dragging several cards, and no bound
// is a way to start twenty agents by accident.
const defaultMaxWorkers = 3

// lookPath resolves the worker binary. Tests replace it.
var lookPath = exec.LookPath

// apiClient is for short request/response calls only. The SSE subscription must
// keep its own timeout-free client: a stream is meant to stay open.
func apiClient(cfg config.Config) *api.Client {
	return api.New(cfg.BaseURL, cfg.Token, &http.Client{Timeout: refreshTimeout})
}

func newBridgeCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "bridge",
		Short: "Bridge Loupe events into local workers",
	}
	cmd.AddCommand(newBridgeRunCmd())

	return cmd
}

func newBridgeRunCmd() *cobra.Command {
	var dir, site, permissionMode, logFile string
	var maxWorkers int

	cmd := &cobra.Command{
		Use:   "run",
		Short: "Watch a Loupe board and run a Claude Code worker per card",
		Long: "Subscribes to your Loupe event stream and runs one worker for every board " +
			"card that moves to next. A worker is `claude -p <directive>` started in the " +
			"--dir directory. It prints its answer and exits, and the bridge reports the " +
			"exit code.\n\n" +
			"Use --site to name the site to bridge, by name or id; omit it to pick " +
			"interactively from your list of sites. Use --permission-mode to pass that " +
			"flag to every `claude` the bridge starts. A worker has no terminal, so it " +
			"cannot answer a permission prompt: omit the flag and claude denies every " +
			"tool call that needs approval.\n\n" +
			"Use --max-workers to bound the workers that run at once. Events past the " +
			"bound wait in a queue and start in arrival order. The bridge writes one JSON " +
			"object per line to stdout and to --log-file.",
		RunE: func(cmd *cobra.Command, _ []string) error {
			if dir == "" {
				return fmt.Errorf("--dir is required: it names the directory every worker runs in")
			}
			if err := requireDir(dir); err != nil {
				return err
			}
			if maxWorkers < 1 {
				return fmt.Errorf("--max-workers must be at least 1, got %d", maxWorkers)
			}
			if _, err := lookPath("claude"); err != nil {
				return fmt.Errorf("claude is not installed or not on PATH")
			}

			cfg, err := config.Load()
			if err != nil {
				return err
			}

			client := apiClient(cfg)
			if site == "" {
				if !isTerminal(os.Stdin) {
					return fmt.Errorf("--site is required when not running interactively")
				}
				picked, err := pickSite(cmd, client)
				if err != nil {
					return err
				}
				site = picked
			}

			logPath := logFile
			if logPath == "" {
				logPath = defaultLogPath()
			}
			f, err := openLogFile(logPath)
			if err != nil {
				return err
			}
			defer f.Close()

			r := &router{
				log:            newBridgeLogger(bridgeLogWriter(f, cmd.OutOrStdout())),
				dir:            dir,
				permissionMode: permissionMode,
				maxWorkers:     maxWorkers,
				worker:         defaultWorkerOps(),
			}
			r.log.Info("bridge_started", "dir", dir, "max_workers", maxWorkers, "log_file", logPath)

			return subscribe(cmd, cfg, site, r)
		},
	}
	cmd.Flags().StringVar(&dir, "dir", "", "run every worker in this `directory`")
	cmd.Flags().StringVar(&site, "site", "", "the Loupe site to bridge (name or id); omitted: pick interactively")
	cmd.Flags().StringVar(&permissionMode, "permission-mode", "", "pass this `mode` to every `claude` the bridge starts; empty passes no flag, and a worker cannot answer a prompt")
	cmd.Flags().IntVar(&maxWorkers, "max-workers", defaultMaxWorkers, "run at most this `number` of workers at once; later events queue")
	cmd.Flags().StringVar(&logFile, "log-file", "", "append the JSON log to this `path`; empty uses bridge.log in your config directory")

	return cmd
}

// bridgeLogWriter fans one line out to the log file and to out.
//
// The file comes first. io.MultiWriter stops at the first writer that fails,
// and a reader piped to stdout can leave mid-run, which breaks that pipe. The
// history must survive that.
func bridgeLogWriter(file, out io.Writer) io.Writer {
	return io.MultiWriter(file, out)
}

// newBridgeLogger writes one JSON object per line to w.
//
// slog names the message "msg". The bridge names it "event", because a reader
// selects lines by what happened rather than by a prose message.
func newBridgeLogger(w io.Writer) *slog.Logger {
	return slog.New(slog.NewJSONHandler(w, &slog.HandlerOptions{
		ReplaceAttr: func(_ []string, a slog.Attr) slog.Attr {
			if a.Key == slog.MessageKey {
				a.Key = "event"
			}

			return a
		},
	}))
}

// defaultLogPath names the log the bridge appends to. The temp directory is the
// fallback, because a host with no config directory must still keep a history.
func defaultLogPath() string {
	base, err := os.UserConfigDir()
	if err != nil {
		base = os.TempDir()
	}

	return filepath.Join(base, "loupe", "bridge.log")
}

// openLogFile appends to path and creates its directory. A supervisor's log is
// a history, so the file is never truncated.
func openLogFile(path string) (*os.File, error) {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return nil, fmt.Errorf("create log directory: %w", err)
	}

	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
	if err != nil {
		return nil, fmt.Errorf("open log file: %w", err)
	}

	return f, nil
}

// subscribe blocks in the foreground until Ctrl-C or SIGTERM. Cancelling also
// kills every worker in flight, so the bridge leaves no unattended claude
// behind. It then waits for their reports before it returns.
//
// The shutdown drops the queue before it waits. Subscribe calls the handler on
// this goroutine, so no event can arrive after it returns.
func subscribe(cmd *cobra.Command, cfg config.Config, site string, r *router) error {
	ctx, stop := signal.NotifyContext(cmd.Context(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	r.ctx = ctx

	creds, err := fetchCreds(ctx, cfg, site)
	if err != nil {
		return err
	}
	r.site, r.topic = creds.Site.Name, creds.Topic

	err = transport.Subscribe(ctx, &http.Client{}, creds.HubURL, creds.Topic, jwtRefresher(cfg, creds.Site.ID), r.handler())
	r.shutdown()
	r.wg.Wait()
	if err != nil && ctx.Err() == nil {
		return err
	}

	return nil
}

func fetchCreds(ctx context.Context, cfg config.Config, site string) (api.StreamCredentials, error) {
	return apiClient(cfg).StreamCredentials(ctx, site)
}

// jwtRefresher mints a fresh subscriber JWT per connection attempt. Subscriber
// JWTs are short-lived, so a bridge left running would otherwise reconnect with
// an expired token forever once the first one lapsed.
//
// siteID must be the resolved id, never the handle the user passed: --site also
// accepts a name, and renaming the project would then break every reconnect.
func jwtRefresher(cfg config.Config, siteID string) transport.TokenFunc {
	return func(ctx context.Context) (string, error) {
		creds, err := fetchCreds(ctx, cfg, siteID)
		if err != nil {
			return "", err
		}

		return creds.JWT, nil
	}
}

// requireDir refuses a --dir no worker could run in. The bridge otherwise
// subscribes, looks healthy, and fails only when the first card arrives.
func requireDir(dir string) error {
	info, err := os.Stat(dir)
	if err != nil {
		return fmt.Errorf("--dir %s: %w", dir, err)
	}
	if !info.IsDir() {
		return fmt.Errorf("--dir %s is not a directory", dir)
	}

	return nil
}

func isTerminal(f *os.File) bool {
	fi, err := f.Stat()

	return err == nil && fi.Mode()&os.ModeCharDevice != 0
}

// pickSite lists the user's sites and prompts for a numbered choice.
//
// The prompt goes to stderr. stdout carries the JSON log, so a reader piped to
// jq would otherwise get this prose first.
func pickSite(cmd *cobra.Command, client *api.Client) (string, error) {
	sites, err := client.Sites(cmd.Context())
	if err != nil {
		return "", err
	}
	if len(sites) == 0 {
		return "", fmt.Errorf("no sites found: create one in Loupe first (Site reviews → Add site)")
	}
	prompt := cmd.ErrOrStderr()
	if len(sites) == 1 {
		fmt.Fprintf(prompt, "Using your only site %q\n", sites[0].Name)

		return sites[0].ID, nil
	}

	fmt.Fprintln(prompt, "Which site should this bridge follow?")
	for i, s := range sites {
		fmt.Fprintf(prompt, "  %d) %s\n", i+1, s.Name)
	}
	fmt.Fprint(prompt, "Site number: ")
	var choice int
	if _, err := fmt.Fscanln(cmd.InOrStdin(), &choice); err != nil {
		return "", fmt.Errorf("read choice: %w", err)
	}
	if choice < 1 || choice > len(sites) {
		return "", fmt.Errorf("invalid choice")
	}

	return sites[choice-1].ID, nil
}
