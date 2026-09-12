package cmd

import (
	"context"
	"fmt"
	"net/http"
	"os"
	"os/exec"
	"os/signal"
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
	var dir, site, permissionMode string

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
			"tool call that needs approval.",
		RunE: func(cmd *cobra.Command, _ []string) error {
			if dir == "" {
				return fmt.Errorf("--dir is required: it names the directory every worker runs in")
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

			r := &router{
				out:            cmd.OutOrStdout(),
				errOut:         cmd.ErrOrStderr(),
				dir:            dir,
				permissionMode: permissionMode,
				worker:         defaultWorkerOps(),
			}

			return subscribe(cmd, cfg, site, r)
		},
	}
	cmd.Flags().StringVar(&dir, "dir", "", "run every worker in this `directory`")
	cmd.Flags().StringVar(&site, "site", "", "the Loupe site to bridge (name or id); omitted: pick interactively")
	cmd.Flags().StringVar(&permissionMode, "permission-mode", "", "pass this `mode` to every `claude` the bridge starts; empty passes no flag, and a worker cannot answer a prompt")

	return cmd
}

// subscribe blocks in the foreground until Ctrl-C or SIGTERM. Cancelling also
// kills every worker in flight, so the bridge leaves no unattended claude
// behind. It then waits for their reports before it returns.
func subscribe(cmd *cobra.Command, cfg config.Config, site string, r *router) error {
	ctx, stop := signal.NotifyContext(cmd.Context(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	r.ctx = ctx

	creds, err := fetchCreds(ctx, cfg, site)
	if err != nil {
		return err
	}
	r.logf("Bridging Loupe events for site %q into workers in %s (topic %s)\n", creds.Site.Name, r.dir, creds.Topic)

	err = transport.Subscribe(ctx, &http.Client{}, creds.HubURL, creds.Topic, jwtRefresher(cfg, creds.Site.ID), r.handler())
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

func isTerminal(f *os.File) bool {
	fi, err := f.Stat()

	return err == nil && fi.Mode()&os.ModeCharDevice != 0
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
