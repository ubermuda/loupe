package cmd

import (
	"bufio"
	"context"
	"fmt"
	"os"
	"strings"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/betterplans/cli/internal/api"
	"github.com/ubermuda/betterplans/cli/internal/config"
)

func newLoginCmd() *cobra.Command {
	var baseURL, token string

	cmd := &cobra.Command{
		Use:   "login",
		Short: "Store a Better Plans API token (site-review scope) for the bridge",
		Long: "Stores a Better Plans API token so the bridge can subscribe to your " +
			"site-review stream. The token is validated against the API before it is saved.\n\n" +
			"Provide the token with --token, the BETTERPLANS_TOKEN env var, or interactively.",
		RunE: func(cmd *cobra.Command, _ []string) error {
			if token == "" {
				token = os.Getenv("BETTERPLANS_TOKEN")
			}
			if token == "" {
				fmt.Fprint(cmd.OutOrStdout(), "API token (site-review scope): ")
				line, _ := bufio.NewReader(cmd.InOrStdin()).ReadString('\n')
				token = line
			}
			token = strings.TrimSpace(token)
			if token == "" {
				return fmt.Errorf("no token provided")
			}

			cfg := config.Config{BaseURL: strings.TrimRight(baseURL, "/"), Token: token}

			ctx, cancel := context.WithTimeout(cmd.Context(), 10*time.Second)
			defer cancel()
			if _, err := api.New(cfg.BaseURL, cfg.Token, nil).StreamCredentials(ctx); err != nil {
				return err
			}

			if err := config.Save(cfg); err != nil {
				return err
			}
			fmt.Fprintln(cmd.OutOrStdout(), "Logged in. Token saved.")

			return nil
		},
	}
	cmd.Flags().StringVar(&baseURL, "url", "https://betterplans.dev.localhost", "Better Plans base URL")
	cmd.Flags().StringVar(&token, "token", "", "API token (else BETTERPLANS_TOKEN env, else prompt)")

	return cmd
}
