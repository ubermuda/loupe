package cmd

import (
	"fmt"
	"net/http"
	"os"
	"os/signal"
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
	var useMCP bool

	cmd := &cobra.Command{
		Use:   "run",
		Short: "Pipe submitted site reviews into a local Claude Code tmux session",
		Long: "Subscribes to your Better Plans site-review stream and injects each new " +
			"review into a Claude Code session running in tmux.\n\n" +
			"Use --dir to spawn `claude` in a new tmux session in that directory, or " +
			"--session to attach to a tmux session you already have running.",
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

			out := cmd.OutOrStdout()
			errOut := cmd.ErrOrStderr()

			target, err := ensureSession(cmd, dir, session)
			if err != nil {
				return err
			}

			mode := inject.SelfContained
			if useMCP {
				mode = inject.IDOnly
			}

			ctx, stop := signal.NotifyContext(cmd.Context(), os.Interrupt, syscall.SIGTERM)
			defer stop()

			creds, err := api.New(cfg.BaseURL, cfg.Token, nil).StreamCredentials(ctx)
			if err != nil {
				return err
			}
			fmt.Fprintf(out, "Bridging site reviews into tmux session %q (topic %s)\n", target, creds.Topic)

			handler := transport.Handler{
				OnConnect: func() { fmt.Fprintln(out, "Connected to hub; waiting for site reviews…") },
				OnError:   func(err error) { fmt.Fprintf(errOut, "stream error (will retry): %v\n", err) },
				OnData: func(data []byte) {
					event, err := inject.Parse(data)
					if err != nil {
						fmt.Fprintf(errOut, "skipping malformed event: %v\n", err)

						return
					}
					if !tmux.HasSession(target) {
						fmt.Fprintf(errOut, "tmux session %q is gone; dropping review %s\n", target, event.BatchID)

						return
					}
					if err := tmux.Send(target, inject.Directive(event, mode)); err != nil {
						fmt.Fprintf(errOut, "failed to inject review %s: %v\n", event.BatchID, err)

						return
					}
					fmt.Fprintf(out, "Injected review %s\n", event.BatchID)
				},
			}

			// SSE is a long-lived stream: no overall client timeout.
			if err := transport.Subscribe(ctx, &http.Client{}, creds.HubURL, creds.Topic, creds.JWT, handler); err != nil && ctx.Err() == nil {
				return err
			}

			return nil
		},
	}
	cmd.Flags().StringVar(&dir, "dir", "", "spawn `claude` in a new tmux session in this directory")
	cmd.Flags().StringVar(&session, "session", "", "attach to an existing tmux session or target")
	cmd.Flags().BoolVar(&useMCP, "mcp", false, "inject only the review id and let Claude load it via the Better Plans MCP")

	return cmd
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
		fmt.Fprintf(cmd.OutOrStdout(), "Started claude in tmux session %q — attach with: tmux attach -t %s\n", target, target)
	}

	return target, nil
}
