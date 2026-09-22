package cmd

import (
	"errors"
	"fmt"
	"net/http"
	"os"
	"os/signal"

	"github.com/modelcontextprotocol/go-sdk/mcp"
	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/mcpproxy"
	"github.com/ubermuda/loupe/cli/internal/oauth"
	"github.com/ubermuda/loupe/cli/internal/projectfile"
)

// tokenSource gives the bearer token for cfg.
func tokenSource(cfg config.Config, hc *http.Client) api.TokenSource {
	return oauth.NewSource(cfg.BaseURL, *cfg.OAuth, hc)
}

func newMcpCmd() *cobra.Command {
	var projectID string

	cmd := &cobra.Command{
		Use:   "mcp",
		Short: "Serve Loupe's MCP tools to a local agent over stdio",
		Long: "Pipes Model Context Protocol messages between an agent on this machine and your " +
			"Loupe instance. The agent speaks stdio, which is how an agent starts a local MCP " +
			"server, and this command forwards every message to " + mcpPath + " over HTTPS.\n\n" +
			"It adds the credentials, so no tool and no configuration file holds a token. It " +
			"defines no tools of its own, so a tool Loupe adds reaches your agent with no new " +
			"release of this CLI.\n\n" +
			"The project comes from " + projectfile.Name + " in the directory the command runs in. " +
			"Write that file with `loupe init`. With no file, the server uses the single project " +
			"your login covers, and refuses when your login covers several.\n\n" +
			"Every message goes to stdout and every diagnostic to stderr, because stdout is the " +
			"protocol. Run `loupe login` first.",
		RunE: func(cmd *cobra.Command, _ []string) error {
			cfg, err := config.Load()
			if err != nil {
				return err
			}

			if projectID == "" {
				if projectID, err = projectOfWorkingDir(); err != nil {
					return err
				}
			}

			// No timeout: an MCP session stays open for as long as the agent
			// runs, and a client timeout would end it mid-conversation.
			hc := &http.Client{Transport: &mcpproxy.Credentials{
				Tokens:    tokenSource(cfg, &http.Client{Timeout: refreshTimeout}),
				ProjectID: projectID,
			}}

			ctx, stop := signal.NotifyContext(cmd.Context(), os.Interrupt)
			defer stop()

			return mcpproxy.Run(ctx, &mcp.StdioTransport{}, dialLoupe(cfg.BaseURL+mcpPath, hc), cmd.ErrOrStderr())
		},
	}
	cmd.Flags().StringVar(&projectID, "project", "", "project id to act on (else "+projectfile.Name+" in this directory)")

	return cmd
}

// mcpPath is the MCP endpoint of every Loupe instance. The project never
// appears in the URL.
const mcpPath = "/mcp"

// projectOfWorkingDir reads the project id out of the project file. A missing
// file names no project, which leaves the choice to the server.
func projectOfWorkingDir() (string, error) {
	dir, err := os.Getwd()
	if err != nil {
		return "", err
	}

	file, err := projectfile.Load(dir)
	if errors.Is(err, projectfile.ErrNoFile) {
		return "", nil
	}
	if err != nil {
		return "", fmt.Errorf("%w\n\nWrite the file with `loupe init`", err)
	}

	return file.Project, nil
}
