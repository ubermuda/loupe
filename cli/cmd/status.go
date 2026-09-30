package cmd

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"strings"
	"time"

	"github.com/modelcontextprotocol/go-sdk/mcp"
	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/claudecode"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/mcpjson"
	"github.com/ubermuda/loupe/cli/internal/mcpproxy"
	"github.com/ubermuda/loupe/cli/internal/projectfile"
)

// The last line of `loupe status` on stdout. The error follows it on stderr.
const (
	statusPass = "loupe status: PASS"
	statusFail = "loupe status: FAIL"
)

// statusTimeout bounds the whole check, from the first request to the close.
const statusTimeout = 30 * time.Second

func newStatusCmd() *cobra.Command {
	var projectID string

	cmd := &cobra.Command{
		Use:   "status",
		Short: "Check the login, the project and the MCP connection to Loupe",
		Long: "Checks the setup that `loupe mcp` depends on, with one real call. It reads your " +
			"login, resolves the project the same way `loupe mcp` does, opens one MCP session " +
			"with your Loupe instance, and calls project_current.\n\n" +
			"The project comes from --project, else from " + projectfile.Name + " in this " +
			"directory, else from the single project your login covers.\n\n" +
			"It prints the instance and the project, then notes how Claude Code starts the `" +
			mcpjson.ServerKey + "` MCP server. That note never fails the check, because an agent " +
			"other than Claude Code keeps its own configuration. A pass proves the login and the " +
			"project that `loupe mcp` uses in this directory. It does not read the command an " +
			"agent is configured to start.\n\n" +
			"The last line on stdout is `" + statusPass + "` or `" + statusFail + "`. A failure " +
			"exits non-zero, and the error on stderr says what to run next. Read the exit " +
			"status, because the error comes after the verdict when the two streams merge.",
		RunE: func(cmd *cobra.Command, _ []string) error {
			out := cmd.OutOrStdout()
			err := checkStatus(cmd.Context(), out, projectID, cmd.ErrOrStderr())
			noteClaudeCode(out)
			if err != nil {
				fmt.Fprintln(out, statusFail)

				return err
			}
			fmt.Fprintln(out, statusPass)

			return nil
		},
	}
	cmd.Flags().StringVar(&projectID, "project", "", "project id to check (else "+projectfile.Name+" in this directory)")

	return cmd
}

// checkStatus runs the checks that decide pass or fail, and prints what each
// one found.
func checkStatus(ctx context.Context, out io.Writer, projectID string, notes io.Writer) error {
	cfg, err := config.Load()
	if err != nil {
		return err
	}
	fmt.Fprintf(out, "Instance:    %s\n", cfg.BaseURL)

	project, err := mcpProject(projectID, notes)
	if err != nil {
		return err
	}

	ctx, cancel := context.WithTimeout(ctx, statusTimeout)
	defer cancel()

	hc := &http.Client{Transport: &mcpproxy.Credentials{
		Tokens:  tokenSource(cfg, &http.Client{Timeout: refreshTimeout}),
		Project: project,
	}}
	client := mcp.NewClient(&mcp.Implementation{Name: "loupe-status", Version: versionString()}, nil)
	session, err := client.Connect(ctx, loupeTransport(cfg.BaseURL+mcpPath, hc), nil)
	if err != nil {
		return fmt.Errorf("could not open an MCP session with %s: %w\n\nCheck that the instance is up, or run `loupe login` again", cfg.BaseURL, err)
	}
	defer func() { _ = session.Close() }()

	res, err := session.CallTool(ctx, &mcp.CallToolParams{Name: "project_current"})
	if err != nil {
		return fmt.Errorf("project_current failed: %w\n\nCheck that the instance is up, or run `loupe login` again", err)
	}
	if res.IsError {
		return fmt.Errorf("project_current refused: %s", toolText(res))
	}

	current, err := readCurrentProject(res)
	if err != nil {
		return err
	}
	fmt.Fprintf(out, "Project:     %s\n", describe(current))

	return nil
}

// readCurrentProject takes the project from the structured content, or from a
// text block that holds the same JSON.
func readCurrentProject(res *mcp.CallToolResult) (api.Site, error) {
	var body struct {
		Project struct {
			ID   string  `json:"id"`
			Slug *string `json:"slug"`
			Name string  `json:"name"`
		} `json:"project"`
	}
	raw, err := json.Marshal(res.StructuredContent)
	if err != nil || res.StructuredContent == nil {
		raw = []byte(toolText(res))
	}
	if err := json.Unmarshal(raw, &body); err != nil || body.Project.ID == "" {
		return api.Site{}, errors.New("project_current answered with no project: update the CLI with `loupe update`, or report it")
	}

	site := api.Site{ID: body.Project.ID, Name: body.Project.Name}
	if body.Project.Slug != nil {
		site.Slug = *body.Project.Slug
	}

	return site, nil
}

// toolText joins the text blocks of a tool result.
func toolText(res *mcp.CallToolResult) string {
	var parts []string
	for _, c := range res.Content {
		if text, ok := c.(*mcp.TextContent); ok {
			parts = append(parts, text.Text)
		}
	}
	if len(parts) == 0 {
		return "the tool sent no reason"
	}

	return strings.Join(parts, "\n")
}

// noteClaudeCode says how Claude Code starts the server. It is a note only, so
// it returns nothing.
func noteClaudeCode(out io.Writer) {
	dir, err := os.Getwd()
	if err != nil {
		fmt.Fprintf(out, "Claude Code: could not check how it starts %q: %v\n", mcpjson.ServerKey, err)

		return
	}
	got, err := claudecode.Effective(dir, mcpjson.ServerKey)
	switch {
	case err != nil:
		fmt.Fprintf(out, "Claude Code: could not check how it starts %q: %v\n", mcpjson.ServerKey, err)
	case got.Correct():
		fmt.Fprintf(out, "Claude Code: starts `loupe mcp` for %s.\n", got.Where())
	case got.Declared():
		fmt.Fprintf(out, "Claude Code: starts %q from %s as %s, which is not `loupe mcp`. Run `loupe init --mcp` to fix it.\n", mcpjson.ServerKey, got.Where(), got.Entry.Summary())
	default:
		fmt.Fprintf(out, "Claude Code: does not declare %q. Run `loupe init --mcp` to declare it. Other agents keep their own configuration.\n", mcpjson.ServerKey)
	}
}
