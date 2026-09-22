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

// DefaultBaseURL is the instance `loupe login` signs in to when nothing names
// another. The hosted instance, because that is where a reader of a release
// binary has an account. Someone self-hosting, or working on Loupe itself,
// passes --url once or sets LOUPE_URL.
const DefaultBaseURL = "https://loupe.ac"

// resolveBaseURL picks the instance to sign in to: the flag, else LOUPE_URL,
// else the hosted instance. The trailing slash goes, because every caller joins
// a path onto it.
func resolveBaseURL(flag, env string) string {
	for _, candidate := range []string{flag, env, DefaultBaseURL} {
		if trimmed := strings.TrimRight(strings.TrimSpace(candidate), "/"); "" != trimmed {
			return trimmed
		}
	}

	return DefaultBaseURL
}

func newLoginCmd() *cobra.Command {
	var baseURL string

	cmd := &cobra.Command{
		Use:   "login",
		Short: "Sign the bridge in to Loupe",
		Long: "Signs the bridge in to Loupe, so it can subscribe to your site's event stream.\n\n" +
			"login prints a link and a code. Open the link in a browser where you are signed in " +
			"to Loupe, check the code, and choose Allow. The CLI then stores an access token and " +
			"a refresh token, and refreshes the access token by itself.\n\n" +
			"A browser is the only way in. Loupe issues no credential a person cannot see, so a " +
			"machine with no browser anywhere cannot sign in.",
		RunE: func(cmd *cobra.Command, _ []string) error {
			return deviceLogin(cmd, resolveBaseURL(baseURL, os.Getenv("LOUPE_URL")))
		},
	}
	cmd.Flags().StringVar(&baseURL, "url", "", "Loupe base URL (else LOUPE_URL env, else "+DefaultBaseURL+")")

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
