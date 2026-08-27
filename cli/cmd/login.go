package cmd

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"strings"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"golang.org/x/term"
)

func newLoginCmd() *cobra.Command {
	var baseURL, token string

	cmd := &cobra.Command{
		Use:   "login",
		Short: "Store a Loupe API token (site-review scope) for the bridge",
		Long: "Stores a Loupe API token so the bridge can subscribe to your " +
			"site-review stream. The token is validated against the API before it is saved.\n\n" +
			"Provide the token with --token, the LOUPE_TOKEN env var, or interactively.",
		RunE: func(cmd *cobra.Command, _ []string) error {
			if token == "" {
				token = os.Getenv("LOUPE_TOKEN")
			}
			if token == "" {
				fmt.Fprint(cmd.OutOrStdout(), "API token (site-review scope): ")
				read, err := promptForToken(cmd)
				if err != nil {
					return err
				}
				token = read
			}
			token = strings.TrimSpace(token)
			if token == "" {
				return fmt.Errorf("no token provided")
			}

			cfg := config.Config{BaseURL: strings.TrimRight(baseURL, "/"), Token: token}

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
	cmd.Flags().StringVar(&token, "token", "", "API token (else LOUPE_TOKEN env, else prompt)")

	return cmd
}

// promptForToken reads the token without echoing it when the input is a real
// terminal, and falls back to a line read for pipes and tests.
func promptForToken(cmd *cobra.Command) (string, error) {
	in := cmd.InOrStdin()

	if f, ok := in.(*os.File); ok && term.IsTerminal(int(f.Fd())) {
		raw, err := term.ReadPassword(int(f.Fd()))
		// Suppressed echo swallows the Enter keypress too, so the next line of
		// output would otherwise run on from the prompt.
		fmt.Fprintln(cmd.OutOrStdout())
		if err != nil {
			return "", fmt.Errorf("read token: %w", err)
		}

		return string(raw), nil
	}

	line, err := bufio.NewReader(in).ReadString('\n')
	// A token piped in without a trailing newline ends in EOF with the line
	// already read, which is success. EOF with nothing read, or any other
	// error, is a failed read.
	if err != nil && !(errors.Is(err, io.EOF) && line != "") {
		return "", fmt.Errorf("read token: %w", err)
	}

	return line, nil
}
