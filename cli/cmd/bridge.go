package cmd

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/outbound"
	"github.com/ubermuda/loupe/cli/internal/rules"
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
	var rulesPath, permissionMode, model, logFile string
	var maxWorkers int

	cmd := &cobra.Command{
		Use:   "run",
		Short: "Watch a Loupe board and run a Claude Code worker for each rule an event matches",
		Long: "Reads the rule file, rules.yaml in your config directory, and runs " +
			"`claude -p --session-id <uuid> -- <prompt>` for every event a rule matches. Each rule names an event, " +
			"a project and a column, and the prompt its worker runs. The projects map in " +
			"the file gives each project the directory its workers run in.\n\n" +
			"The bridge refuses to start without the file, and checks every project and " +
			"column slug against the server first. It follows every project you own on " +
			"one connection, and ignores the events of a project the file does not map.\n\n" +
			"Use --permission-mode and --model to set the value of every rule that sets " +
			"none. A worker has no terminal, so it cannot answer a permission prompt: " +
			"with no mode, claude denies every tool call that needs approval.\n\n" +
			"Use --max-workers to bound the workers that run at once. Events past the " +
			"bound wait in a queue and start in arrival order, except that an event waits " +
			"while its card has a worker. The bridge writes one JSON " +
			"object per line to stdout and to --log-file.",
		RunE: func(cmd *cobra.Command, _ []string) error {
			if maxWorkers < 1 {
				return fmt.Errorf("--max-workers must be at least 1, got %d", maxWorkers)
			}

			defaults := rules.Defaults{PermissionMode: permissionMode, Model: model}
			if err := defaults.Check(); err != nil {
				return err
			}

			path := rulesPath
			if path == "" {
				var err error
				if path, err = defaultRulesPath(); err != nil {
					return err
				}
			}
			set, err := rules.Load(path, defaults)
			if err != nil {
				return fmt.Errorf("rule file %s: %w", path, err)
			}
			if _, err := lookPath("claude"); err != nil {
				return fmt.Errorf("claude is not installed or not on PATH")
			}

			cfg, err := config.Load()
			if err != nil {
				return err
			}
			if err := set.Check(cmd.Context(), apiClient(cfg)); err != nil {
				return fmt.Errorf("rule file %s: %w", path, err)
			}
			// A bridge with no stored credentials reaches neither the stream nor
			// the reporting endpoints, so it stops here rather than starting a
			// worker whose run it can report nothing about.
			bridgeID, err := config.EnsureBridgeID()
			if errors.Is(err, config.ErrNotLoggedIn) {
				return config.ErrNotLoggedIn
			}
			if err != nil {
				return fmt.Errorf("bridge id: %w", err)
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
				log:        newBridgeLogger(bridgeLogWriter(f, cmd.OutOrStdout())),
				rules:      set,
				maxWorkers: maxWorkers,
				worker:     defaultWorkerOps(),
				bridgeID:   bridgeID,
			}
			r.log.Info("bridge_started", "rules", path, "projects", set.Projects(), "rule_count", len(set.Rules()), "max_workers", maxWorkers, "log_file", logPath, "bridge_id", bridgeID)
			warnUnknownModes(r.log, set)

			return subscribe(cmd, cfg, r)
		},
	}
	cmd.Flags().StringVar(&rulesPath, "rules", "", "read rules from this `path`; empty uses rules.yaml in your config directory")
	cmd.Flags().StringVar(&permissionMode, "permission-mode", "", "pass this `mode` to every `claude` whose rule sets none; empty passes no flag, and a worker cannot answer a prompt")
	cmd.Flags().StringVar(&model, "model", "", "pass this `model` to every `claude` whose rule sets none; empty passes no flag")
	cmd.Flags().IntVar(&maxWorkers, "max-workers", defaultMaxWorkers, "run at most this `number` of workers at once; later events queue")
	cmd.Flags().StringVar(&logFile, "log-file", "", "append the JSON log to this `path`; empty uses bridge.log in your config directory")

	return cmd
}

// warnUnknownModes names each permission mode this build does not know. A newer
// claude may accept it, so the bridge starts, and a typo shows in the log.
func warnUnknownModes(log *slog.Logger, set *rules.Set) {
	for _, mode := range set.UnknownPermissionModes() {
		log.Warn("permission_mode_unknown", "mode", mode, "known", rules.PermissionModes)
	}
}

// defaultRulesPath puts the rule file beside config.json.
func defaultRulesPath() (string, error) {
	dir, err := config.Dir()
	if err != nil {
		return "", err
	}

	return filepath.Join(dir, rules.FileName), nil
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
//
// The server publishes every event of a project on its owner's topic too, so
// one topic follows every project, including one created after the start. The
// router ignores a project the file does not map.
func subscribe(cmd *cobra.Command, cfg config.Config, r *router) error {
	ctx, stop := signal.NotifyContext(cmd.Context(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	r.ctx = ctx

	// The queue closes after the workers, so it sees every report a dying worker
	// still makes, and its grace window can send them.
	queue := newOutboundQueue(ctx, r.log, cfg)
	r.reports = queue
	defer queue.Close()

	events, err := apiClient(cfg).Events(ctx)
	if err != nil {
		return err
	}
	if missing := missingProjects(r.rules, events); len(missing) > 0 {
		return fmt.Errorf("GET /api/events does not list %s, so no event of theirs can reach the bridge", strings.Join(missing, ", "))
	}
	r.projects, r.topic = r.rules.Projects(), events.Topic
	if r.checkAsk == nil {
		r.checkAsk = apiClient(cfg).CheckAsk
	}
	r.applyFlags(events)
	if r.bridgeID != "" {
		r.health = newHealthReporter(ctx, apiClient(cfg), r.bridgeID, r.log)
		for _, slug := range r.projects {
			r.reportHealth(slug)
		}
		r.heartbeat = newHeartbeater(ctx, queue, apiClient(cfg), r.bridgeID, heartbeatBody(r.rules), heartbeatInterval(events), r.log)
		r.heartbeat.start()
	}

	err = transport.Subscribe(ctx, &http.Client{}, events.HubURL, []string{events.Topic}, jwtRefresher(cfg, events.JWT, r.onRefresh), r.handler())
	failed := err != nil && ctx.Err() == nil
	r.shutdown()
	r.wg.Wait()
	// The reports stay on the server, so a pending one is dropped rather than
	// sent. Its goroutines return once the context ends.
	stop()
	if r.health != nil {
		r.health.wait()
	}
	if r.heartbeat != nil {
		r.heartbeat.wait()
	}
	if failed {
		return err
	}

	return nil
}

// newOutboundQueue builds the queue that sends the bridge's reports and its
// heartbeat to Loupe. One bridge follows several projects, so the handle travels
// with each run report, and it is the project id the event carried rather than
// the slug a rename changes.
func newOutboundQueue(ctx context.Context, log *slog.Logger, cfg config.Config) *outbound.Sender {
	client := apiClient(cfg)

	return outbound.New(ctx, log, func(ctx context.Context, handle string, run api.WorkerRun) (bool, error) {
		return client.ReportWorkerRun(ctx, handle, run)
	})
}

// heartbeatBody names the projects the rule file maps, by id, and the build
// that `loupe version` reports.
func heartbeatBody(set *rules.Set) api.Heartbeat {
	ids := []string{}
	for _, slug := range set.Projects() {
		ids = append(ids, set.ProjectID(slug))
	}

	return api.Heartbeat{Projects: ids, CLIVersion: buildID()}
}

// missingProjects names the mapped projects that GET /api/events does not list:
// deleted, or no longer the user's. Their rules can never fire.
func missingProjects(set *rules.Set, events api.Events) []string {
	listed := map[string]bool{}
	for _, p := range events.Projects {
		listed[strings.ToLower(p.ID)] = true
	}
	var missing []string
	for _, slug := range set.Projects() {
		if !listed[set.ProjectID(slug)] {
			missing = append(missing, slug)
		}
	}

	return missing
}

// jwtRefresher hands out first for the first connection, then mints a fresh
// subscriber JWT per attempt and gives each fresh answer to onRefresh.
// Subscriber JWTs are short-lived, so a bridge left running would otherwise
// reconnect with an expired token forever once the first one lapsed. Subscribe
// calls it from one goroutine.
func jwtRefresher(cfg config.Config, first string, onRefresh func(api.Events)) transport.TokenFunc {
	return func(ctx context.Context) (string, error) {
		if first != "" {
			jwt := first
			first = ""

			return jwt, nil
		}
		events, err := apiClient(cfg).Events(ctx)
		if err != nil {
			return "", err
		}
		onRefresh(events)

		return events.JWT, nil
	}
}
