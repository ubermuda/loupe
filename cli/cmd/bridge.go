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

	"github.com/spf13/cobra"
	"github.com/ubermuda/betterplans/cli/internal/api"
	"github.com/ubermuda/betterplans/cli/internal/config"
	"github.com/ubermuda/betterplans/cli/internal/inject"
	"github.com/ubermuda/betterplans/cli/internal/tmux"
	"github.com/ubermuda/betterplans/cli/internal/transport"
)

// defaultSession is the tmux session name used in spawn mode.
const defaultSession = "betterplans"

func newBridgeCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "bridge",
		Short: "Bridge Better Plans events into local tools",
	}
	cmd.AddCommand(newBridgeRunCmd())

	return cmd
}

func newBridgeRunCmd() *cobra.Command {
	var dir, session string
	var useMCP, noAttach bool

	cmd := &cobra.Command{
		Use:   "run",
		Short: "Pipe submitted site reviews into a local Claude Code tmux session",
		Long: "Subscribes to your Better Plans site-review stream and injects each new " +
			"review into a Claude Code session running in tmux.\n\n" +
			"Use --dir to spawn `claude` in a new tmux session in that directory, or " +
			"--session to attach to a tmux session you already have running. By default " +
			"the command attaches you to the session; pass --no-attach to run the bridge " +
			"in the foreground instead (e.g. headless).",
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

			target, err := ensureSession(cmd, dir, session)
			if err != nil {
				return err
			}

			mode := inject.SelfContained
			if useMCP {
				mode = inject.IDOnly
			}

			attach := !noAttach
			if attach && !isTerminal(os.Stdin) {
				fmt.Fprintln(cmd.ErrOrStderr(), "stdin is not a terminal; running in the foreground (pass --no-attach to silence this)")
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
				return runAttached(cmd, cfg, target, buildHandler(out, errOut, target, mode), logPath)
			}

			return runForeground(cmd, cfg, target, buildHandler(out, errOut, target, mode), out)
		},
	}
	cmd.Flags().StringVar(&dir, "dir", "", "spawn `claude` in a new tmux session in this directory")
	cmd.Flags().StringVar(&session, "session", "", "attach to an existing tmux session or target")
	cmd.Flags().BoolVar(&useMCP, "mcp", false, "inject only the review id and let Claude load it via the Better Plans MCP")
	cmd.Flags().BoolVar(&noAttach, "no-attach", false, "run the bridge in the foreground instead of attaching to the tmux session")

	return cmd
}

// runForeground subscribes and blocks in the foreground, logging to out. Ctrl-C
// or SIGTERM stops it.
func runForeground(cmd *cobra.Command, cfg config.Config, target string, h transport.Handler, out io.Writer) error {
	ctx, stop := signal.NotifyContext(cmd.Context(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	creds, err := fetchCreds(ctx, cfg)
	if err != nil {
		return err
	}
	fmt.Fprintf(out, "Bridging site reviews into tmux session %q (topic %s)\n", tmux.SessionName(target), creds.Topic)

	if err := transport.Subscribe(ctx, &http.Client{}, creds.HubURL, creds.Topic, creds.JWT, h); err != nil && ctx.Err() == nil {
		return err
	}

	return nil
}

// runAttached starts the subscribe loop in the background and hands the terminal
// to `tmux attach`. When the user detaches (or the session ends), the loop stops.
func runAttached(cmd *cobra.Command, cfg config.Config, target string, h transport.Handler, logPath string) error {
	ctx, cancel := context.WithCancel(cmd.Context())
	defer cancel()

	creds, err := fetchCreds(ctx, cfg)
	if err != nil {
		return err
	}

	go func() {
		_ = transport.Subscribe(ctx, &http.Client{}, creds.HubURL, creds.Topic, creds.JWT, h)
	}()

	// While attached, Ctrl-C belongs to the program inside tmux (Claude), not
	// to the bridge. The bridge stops when the user detaches with Ctrl-b d.
	signal.Ignore(syscall.SIGINT)

	fmt.Fprintf(cmd.OutOrStdout(),
		"Bridging site reviews into %q — attaching now (detach with Ctrl-b d). Bridge log: %s\n",
		tmux.SessionName(target), logPath)

	attachCmd := exec.CommandContext(ctx, "tmux", "attach", "-t", tmux.SessionName(target))
	attachCmd.Stdin, attachCmd.Stdout, attachCmd.Stderr = os.Stdin, os.Stdout, os.Stderr
	err = attachCmd.Run()
	cancel() // detached — stop the subscribe loop
	if err != nil && ctx.Err() == nil {
		return fmt.Errorf("tmux attach: %w", err)
	}

	return nil
}

func buildHandler(out, errOut io.Writer, target string, mode inject.Mode) transport.Handler {
	return transport.Handler{
		OnConnect: func() { fmt.Fprintln(out, "Connected to hub; waiting for site reviews…") },
		OnError:   func(err error) { fmt.Fprintf(errOut, "stream error (will retry): %v\n", err) },
		OnData: func(data []byte) {
			event, err := inject.Parse(data)
			if err != nil {
				fmt.Fprintf(errOut, "skipping malformed event: %v\n", err)

				return
			}
			if !tmux.HasSession(target) {
				fmt.Fprintf(errOut, "tmux session %q is gone; dropping review %s\n", tmux.SessionName(target), event.BatchID)

				return
			}
			if err := tmux.Send(target, inject.Directive(event, mode)); err != nil {
				fmt.Fprintf(errOut, "failed to inject review %s: %v\n", event.BatchID, err)

				return
			}
			fmt.Fprintf(out, "Injected review %s\n", event.BatchID)
		},
	}
}

func fetchCreds(ctx context.Context, cfg config.Config) (api.StreamCredentials, error) {
	return api.New(cfg.BaseURL, cfg.Token, nil).StreamCredentials(ctx)
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
		base = filepath.Join(base, "betterplans")
		_ = os.MkdirAll(base, 0o700)
	}
	path := filepath.Join(base, "bridge.log")

	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return nil, "", fmt.Errorf("open bridge log: %w", err)
	}

	return f, path, nil
}
