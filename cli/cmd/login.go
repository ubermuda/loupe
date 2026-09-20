package cmd

import (
	"context"
	"fmt"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/oauth"
)

func newLoginCmd() *cobra.Command {
	var baseURL, token string

	cmd := &cobra.Command{
		Use:   "login",
		Short: "Sign the bridge in to Loupe",
		Long: "Signs the bridge in to Loupe, so it can subscribe to your site's event stream.\n\n" +
			"With no token, login prints a link and a code. Open the link in a browser where you " +
			"are signed in to Loupe, check the code, and choose Allow. The CLI then stores an " +
			"access token and a refresh token, and refreshes the access token by itself.\n\n" +
			"For CI and scripts, pass an API token with the agent scope in --token or the " +
			"LOUPE_TOKEN env var. Mint one from your account settings page. The token is " +
			"validated against the API before it is saved.",
		RunE: func(cmd *cobra.Command, _ []string) error {
			if token == "" {
				token = os.Getenv("LOUPE_TOKEN")
			}
			token = strings.TrimSpace(token)
			base := strings.TrimRight(baseURL, "/")
			if token == "" {
				return deviceLogin(cmd, base)
			}

			cfg := config.Config{BaseURL: base, Token: token}

			ctx, cancel := context.WithTimeout(cmd.Context(), 10*time.Second)
			defer cancel()
			if _, err := api.New(cfg.BaseURL, cfg.Token, nil).Sites(ctx); err != nil {
				return err
			}

			if err := config.Save(cfg); err != nil {
				return err
			}
			fmt.Fprintln(cmd.OutOrStdout(), "Logged in. Token saved.")

			return nil
		},
	}
	cmd.Flags().StringVar(&baseURL, "url", "https://loupe.dev.localhost", "Loupe base URL")
	cmd.Flags().StringVar(&token, "token", "", "API token for CI and scripts (else LOUPE_TOKEN env, else sign in in a browser)")

	return cmd
}

// deviceLogin runs the device flow: it prints the page and the code, waits for
// the person to approve them, and stores the tokens. It prints no token.
func deviceLogin(cmd *cobra.Command, baseURL string) error {
	ctx, stop := signal.NotifyContext(cmd.Context(), os.Interrupt)
	defer stop()

	hc := &http.Client{Timeout: refreshTimeout}
	flow := oauth.NewFlow(baseURL, hc)
	device, err := flow.Start(ctx)
	if err != nil {
		return err
	}

	out := cmd.OutOrStdout()
	code := oauth.DisplayUserCode(device.UserCode)
	if device.VerificationURIComplete != "" {
		fmt.Fprintf(out, "Open this page in a browser where you are signed in to Loupe:\n\n  %s\n\nCheck that the page shows the code %s, then choose Allow.\n", device.VerificationURIComplete, code)
	} else {
		fmt.Fprintf(out, "Open this page in a browser where you are signed in to Loupe:\n\n  %s\n\nType the code %s, then choose Allow.\n", device.VerificationURI, code)
	}
	fmt.Fprintln(out, "Waiting for your answer...")

	tokens, err := flow.Poll(ctx, device)
	if err != nil {
		return err
	}

	checkCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	if _, err := api.New(baseURL, tokens.AccessToken, hc).Sites(checkCtx); err != nil {
		return err
	}
	if err := config.Save(config.Config{BaseURL: baseURL, OAuth: &tokens}); err != nil {
		return err
	}
	fmt.Fprintln(out, "Logged in. Tokens saved.")

	return nil
}
