package cmd

import (
	"context"
	"fmt"
	"io"
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
	"github.com/ubermuda/loupe/cli/internal/inject"
	"github.com/ubermuda/loupe/cli/internal/tmux"
	"github.com/ubermuda/loupe/cli/internal/transport"
)

// defaultSession is the tmux session name used in spawn mode.
const defaultSession = "loupe"

// refreshTimeout bounds a credentials fetch. http.DefaultClient has none at
// all, so a single unanswered request would stall reconnection for good — the
// bridge would sit there looking healthy and never receive anything again.
const refreshTimeout = 15 * time.Second

// apiClient is for short request/response calls only. The SSE subscription must
// keep its own timeout-free client: a stream is meant to stay open.
func apiClient(cfg config.Config) *api.Client {
	return api.New(cfg.BaseURL, cfg.Token, &http.Client{Timeout: refreshTimeout})
}

func newBridgeCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "bridge",
		Short: "Bridge Loupe events into local tools",
	}
	cmd.AddCommand(newBridgeRunCmd())

	return cmd
}

func newBridgeRunCmd() *cobra.Command {
	var dir, session, site string
	var attach bool

	cmd := &cobra.Command{
		Use:   "run",
		Short: "Pipe submitted site reviews into a local Claude Code tmux session",
		Long: "Subscribes to your Loupe site-review stream and injects each new " +
			"review into a Claude Code session running in tmux.\n\n" +
			"Use --site to specify which site to bridge (by name or id); omit it to pick " +
			"interactively from your list of sites. Use --dir to spawn `claude` in a new " +
			"tmux session in that directory, or --session to attach to a tmux session you " +
			"already have running. By default the command attaches you to the session; pass " +
			"--attach=false to run the bridge in the foreground instead (e.g. headless).",
		RunE: func(cmd *cobra.Command, _ []string) error {
			if !tmux.Available() {
				return fmt.Errorf("tmux is not installed or not on PATH")
			}
			if (dir == "") == (session == "") {
				return fmt.Errorf("provide exactly one of --dir (spawn) or --session (attach)")
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

			target, err := ensureSession(cmd, dir, session)
			if err != nil {
				return err
			}

			if attach && !isTerminal(os.Stdin) {
				fmt.Fprintln(cmd.ErrOrStderr(), "stdin is not a terminal; running in the foreground (pass --attach=false to silence this)")
				attach = false
			}

			// When attached, the terminal is owned by the tmux client, so the
			// bridge's own output must go to a file — printing to stdout would
			// corrupt the tmux display.
			out, errOut := cmd.OutOrStdout(), cmd.ErrOrStderr()
			if attach {
				logFile, logPath, err := openBridgeLog()
				if err != nil {
					return err
				}
				defer logFile.Close()
				out, errOut = logFile, logFile
				return runAttached(cmd, cfg, site, target, buildHandler(out, errOut, target), logPath)
			}

			return runForeground(cmd, cfg, site, target, buildHandler(out, errOut, target), out)
		},
	}
	cmd.Flags().StringVar(&dir, "dir", "", "spawn `claude` in a new tmux session in this directory")
	cmd.Flags().StringVar(&session, "session", "", "attach to an existing tmux session or target")
	cmd.Flags().StringVar(&site, "site", "", "the Loupe site to bridge (name or id); omitted: pick interactively")
	cmd.Flags().BoolVar(&attach, "attach", true, "attach to the tmux session and watch Claude; use --attach=false to run headless")

	return cmd
}

// runForeground subscribes and blocks in the foreground, logging to out. Ctrl-C
// or SIGTERM stops it.
func runForeground(cmd *cobra.Command, cfg config.Config, site, target string, h transport.Handler, out io.Writer) error {
	ctx, stop := signal.NotifyContext(cmd.Context(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	creds, err := fetchCreds(ctx, cfg, site)
	if err != nil {
		return err
	}
	fmt.Fprintf(out, "Bridging site reviews for site %q into tmux session %q (topic %s)\n", creds.Site.Name, tmux.SessionName(target), creds.Topic)

	if err := transport.Subscribe(ctx, &http.Client{}, creds.HubURL, creds.Topic, jwtRefresher(cfg, creds.Site.ID), h); err != nil && ctx.Err() == nil {
		return err
	}

	return nil
}

// runAttached starts the subscribe loop in the background and hands the terminal
// to `tmux attach`. When the user detaches (or the session ends), the loop stops.
func runAttached(cmd *cobra.Command, cfg config.Config, site, target string, h transport.Handler, logPath string) error {
	ctx, cancel := context.WithCancel(cmd.Context())
	defer cancel()

	creds, err := fetchCreds(ctx, cfg, site)
	if err != nil {
		return err
	}

	// The handler writes to the bridge log, which the caller closes as soon as
	// this returns — so detaching while a write is in flight would close the
	// file underneath it. Wait for the loop to actually stop.
	done := make(chan struct{})
	go func() {
		defer close(done)
		_ = transport.Subscribe(ctx, &http.Client{}, creds.HubURL, creds.Topic, jwtRefresher(cfg, creds.Site.ID), h)
	}()

	// While attached, Ctrl-C belongs to the program inside tmux (Claude), not
	// to the bridge. The bridge stops when the user detaches with Ctrl-b d.
	signal.Ignore(syscall.SIGINT)

	fmt.Fprintf(cmd.OutOrStdout(),
		"Bridging site reviews for site %q into %q — attaching now (detach with Ctrl-b d). Bridge log: %s\n",
		creds.Site.Name, tmux.SessionName(target), logPath)

	attachCmd := exec.CommandContext(ctx, "tmux", "attach", "-t", tmux.SessionName(target))
	attachCmd.Stdin, attachCmd.Stdout, attachCmd.Stderr = os.Stdin, os.Stdout, os.Stderr
	err = attachCmd.Run()
	cancel() // detached — stop the subscribe loop
	<-done
	if err != nil && ctx.Err() == nil {
		return fmt.Errorf("tmux attach: %w", err)
	}

	return nil
}

func buildHandler(out, errOut io.Writer, target string) transport.Handler {
	return transport.Handler{
		OnConnect: func() { fmt.Fprintln(out, "Connected to hub; waiting for site reviews…") },
		OnError:   func(err error) { fmt.Fprintf(errOut, "stream error (will retry): %v\n", err) },
		OnData: func(data []byte) {
			if _, err := inject.Parse(data); err != nil {
				fmt.Fprintf(errOut, "skipping malformed event: %v\n", err)

				return
			}
			if !tmux.HasSession(target) {
				fmt.Fprintf(errOut, "tmux session %q is gone; dropping notification\n", tmux.SessionName(target))

				return
			}
			if err := tmux.Send(target, inject.Directive()); err != nil {
				fmt.Fprintf(errOut, "failed to inject notification: %v\n", err)

				return
			}
			fmt.Fprintln(out, "Injected site-review notification")
		},
	}
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

// ensureSession spawns or validates the tmux session and returns the target.
func ensureSession(cmd *cobra.Command, dir, session string) (string, error) {
	if session != "" {
		if !tmux.HasSession(session) {
			return "", fmt.Errorf("tmux session %q not found", session)
		}

		return session, nil
	}

	target := defaultSession
	if !tmux.HasSession(target) {
		if err := tmux.Spawn(target, dir); err != nil {
			return "", err
		}
		fmt.Fprintf(cmd.OutOrStdout(), "Started claude in tmux session %q\n", target)
	}

	return target, nil
}

func isTerminal(f *os.File) bool {
	fi, err := f.Stat()

	return err == nil && fi.Mode()&os.ModeCharDevice != 0
}

func openBridgeLog() (*os.File, string, error) {
	base, err := os.UserConfigDir()
	if err != nil {
		base = os.TempDir()
	} else {
		base = filepath.Join(base, "loupe")
		_ = os.MkdirAll(base, 0o700)
	}
	path := filepath.Join(base, "bridge.log")

	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return nil, "", fmt.Errorf("open bridge log: %w", err)
	}

	return f, path, nil
}

// pickSite lists the user's sites and prompts for a numbered choice.
func pickSite(cmd *cobra.Command, client *api.Client) (string, error) {
	sites, err := client.Sites(cmd.Context())
	if err != nil {
		return "", err
	}
	if len(sites) == 0 {
		return "", fmt.Errorf("no sites found: create one in Loupe first (Site reviews → Add site)")
	}
	if len(sites) == 1 {
		fmt.Fprintf(cmd.OutOrStdout(), "Using your only site %q\n", sites[0].Name)
		return sites[0].ID, nil
	}

	fmt.Fprintln(cmd.OutOrStdout(), "Which site should this bridge follow?")
	for i, s := range sites {
		fmt.Fprintf(cmd.OutOrStdout(), "  %d) %s\n", i+1, s.Name)
	}
	fmt.Fprint(cmd.OutOrStdout(), "Site number: ")
	var choice int
	if _, err := fmt.Fscanln(cmd.InOrStdin(), &choice); err != nil {
		return "", fmt.Errorf("read choice: %w", err)
	}
	if choice < 1 || choice > len(sites) {
		return "", fmt.Errorf("invalid choice")
	}

	return sites[choice-1].ID, nil
}
