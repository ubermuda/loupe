package cmd

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"regexp"
	"strings"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/config"
)

// githubAPIURL is the GitHub REST API. A test points it at a stub.
var githubAPIURL = "https://api.github.com"

// githubTimeout bounds one GET /user.
const githubTimeout = 15 * time.Second

// githubLogin is the alphabet of a GitHub login. The login goes into a shell
// credential helper, so nothing else may reach it.
var githubLogin = regexp.MustCompile(`\A[A-Za-z0-9-]+\z`)

func newAgentAccountCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "agent-account",
		Short: "Set the GitHub user that bridge workers push as",
		Long: "Stores a token of a separate GitHub user for agents. Each claude worker of the bridge " +
			"then pushes, commits and calls gh as that user. The token stays on this machine. The " +
			"bridge reports only the login to Loupe.\n\n" +
			"The bridge reads the account when it starts, so restart it after a change.",
	}
	cmd.AddCommand(newAgentAccountSetCmd(), newAgentAccountShowCmd(), newAgentAccountClearCmd())

	return cmd
}

func newAgentAccountSetCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "set",
		Short: "Read a GitHub token from standard input, check it and store it",
		Long: "Reads one line from standard input, which is the token of the agent's GitHub user. " +
			"Pipe it in, or type it and press Enter. The terminal shows what you type.\n\n" +
			"set asks GitHub which user the token belongs to, and stores the token, the login and " +
			"the id. A token GitHub refuses stores nothing.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			line, err := bufio.NewReader(cmd.InOrStdin()).ReadString('\n')
			if err != nil && !errors.Is(err, io.EOF) {
				return fmt.Errorf("read the token: %w", err)
			}
			token := strings.TrimSpace(line)
			if token == "" {
				return errors.New("no token on standard input")
			}

			login, id, err := githubUser(cmd.Context(), token)
			if err != nil {
				return err
			}
			if err := config.SetAgentAccount(&config.AgentAccount{Token: token, Login: login, ID: id}); err != nil {
				return err
			}
			fmt.Fprintf(cmd.OutOrStdout(), "Workers push as %s. Restart the bridge to apply it.\n", login)

			return nil
		},
	}
}

func newAgentAccountShowCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "show",
		Short: "Print the login and the id of the agent account",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			a, err := config.LoadAgentAccount()
			if err != nil {
				return err
			}
			out := cmd.OutOrStdout()
			if a == nil {
				fmt.Fprintln(out, "No agent account is set.")

				return nil
			}
			fmt.Fprintf(out, "Login: %s\nID:    %d\n", a.Login, a.ID)

			return nil
		},
	}
}

func newAgentAccountClearCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "clear",
		Short: "Remove the agent account",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			if err := config.SetAgentAccount(nil); err != nil {
				return err
			}
			fmt.Fprintln(cmd.OutOrStdout(), "No agent account is set. Restart the bridge to apply it.")

			return nil
		},
	}
}

// githubUser asks GitHub which user token belongs to.
func githubUser(ctx context.Context, token string) (string, int64, error) {
	ctx, cancel := context.WithTimeout(ctx, githubTimeout)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, strings.TrimRight(githubAPIURL, "/")+"/user", nil)
	if err != nil {
		return "", 0, err
	}
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Accept", "application/vnd.github+json")

	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return "", 0, fmt.Errorf("ask GitHub for the token's user: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		detail, _ := io.ReadAll(io.LimitReader(resp.Body, 512))

		return "", 0, fmt.Errorf("GitHub refused the token (HTTP %d): %s", resp.StatusCode, strings.TrimSpace(string(detail)))
	}

	var user struct {
		Login string `json:"login"`
		ID    int64  `json:"id"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(&user); err != nil {
		return "", 0, fmt.Errorf("read GitHub's answer: %w", err)
	}
	if !githubLogin.MatchString(user.Login) || user.ID <= 0 {
		return "", 0, fmt.Errorf("GitHub answered an unusable user %q with id %d", user.Login, user.ID)
	}

	return user.Login, user.ID, nil
}

// checkAgentAccount asks GitHub for the login of the stored token, which the
// heartbeat reports. No account, or a token GitHub refuses, gives "", so a
// revoked token never shows as set up.
func checkAgentAccount(ctx context.Context, a *config.AgentAccount, log *slog.Logger) string {
	if a == nil {
		return ""
	}
	login, _, err := githubUser(ctx, a.Token)
	if err != nil {
		log.Warn("agent_account_check_failed", "login", a.Login, "error", err.Error())

		return ""
	}

	return login
}
