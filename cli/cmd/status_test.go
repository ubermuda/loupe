package cmd

import (
	"bytes"
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"github.com/modelcontextprotocol/go-sdk/mcp"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/mcpproxy"
)

type currentProject struct {
	Project struct {
		ID   string  `json:"id"`
		Slug *string `json:"slug"`
		Name string  `json:"name"`
	} `json:"project"`
}

// statusServer stands in for the MCP endpoint of Loupe. It answers
// project_current with the handler, and records the project header.
func statusServer(t *testing.T, handler func() (currentProject, error)) (*httptest.Server, func() string) {
	t.Helper()
	server := mcp.NewServer(&mcp.Implementation{Name: "fake-loupe", Version: "test"}, nil)
	mcp.AddTool(server, &mcp.Tool{Name: "project_current"}, func(context.Context, *mcp.CallToolRequest, struct{}) (*mcp.CallToolResult, currentProject, error) {
		out, err := handler()

		return nil, out, err
	})
	mcpHandler := mcp.NewStreamableHTTPHandler(func(*http.Request) *mcp.Server { return server }, nil)

	var mu sync.Mutex
	header := ""
	httpServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != mcpPath {
			t.Errorf("unexpected path %s", r.URL.Path)
		}
		mu.Lock()
		if got := r.Header.Get(mcpproxy.ProjectHeader); got != "" {
			header = got
		}
		mu.Unlock()
		mcpHandler.ServeHTTP(w, r)
	}))
	t.Cleanup(httpServer.Close)

	return httpServer, func() string {
		mu.Lock()
		defer mu.Unlock()

		return header
	}
}

// runStatus runs `loupe status` through the root command, which silences the
// usage text on an error the way the binary does.
func runStatus(t *testing.T, args ...string) (string, error) {
	t.Helper()
	var out, errOut bytes.Buffer
	cmd := newRootCmd()
	cmd.SetOut(&out)
	cmd.SetErr(&errOut)
	cmd.SetArgs(append([]string{"status"}, args...))
	err := cmd.Execute()

	return out.String(), err
}

func lastLine(out string) string {
	lines := strings.Split(strings.TrimRight(out, "\n"), "\n")

	return lines[len(lines)-1]
}

func TestStatusPassesWhenLoupeNamesTheProject(t *testing.T) {
	server, sentProject := statusServer(t, func() (currentProject, error) {
		var p currentProject
		p.Project.ID = projectA
		p.Project.Name = "Acme site"

		return p, nil
	})
	inRepo(t, server.URL)

	out, err := runStatus(t, "--project", projectA)
	if err != nil {
		t.Fatalf("status: %v\n%s", err, out)
	}
	for _, want := range []string{server.URL, "Acme site", projectA, "Claude Code"} {
		if !strings.Contains(out, want) {
			t.Errorf("output must contain %q, got:\n%s", want, out)
		}
	}
	if got := lastLine(out); got != statusPass {
		t.Errorf("last line = %q, want %q", got, statusPass)
	}
	if got := sentProject(); got != projectA {
		t.Errorf("project header = %q, want %q", got, projectA)
	}
}

func TestStatusFailsWhenTheToolReturnsAnError(t *testing.T) {
	server, _ := statusServer(t, func() (currentProject, error) {
		return currentProject{}, errors.New("This login covers several projects. Run `loupe init` in the repository")
	})
	inRepo(t, server.URL)

	out, err := runStatus(t)
	if err == nil {
		t.Fatalf("status with a tool error: got no error\n%s", out)
	}
	if !strings.Contains(err.Error(), "loupe init") {
		t.Errorf("error must carry the tool's advice, got %v", err)
	}
	if got := lastLine(out); got != statusFail {
		t.Errorf("last line = %q, want %q", got, statusFail)
	}
}

func TestStatusFailsWhenNotLoggedIn(t *testing.T) {
	inRepo(t, "")

	out, err := runStatus(t)
	if !errors.Is(err, config.ErrNotLoggedIn) {
		t.Fatalf("status with no login: got %v, want ErrNotLoggedIn", err)
	}
	if !strings.Contains(out, "Claude Code") {
		t.Errorf("the Claude Code note must print on a failure too, got:\n%s", out)
	}
	if got := lastLine(out); got != statusFail {
		t.Errorf("last line = %q, want %q", got, statusFail)
	}
}

// statusRules writes a rule file whose default account is ready and whose
// account out is logged out, and returns its path.
func statusRules(t *testing.T, withOut bool) string {
	t.Helper()
	in := t.TempDir()
	if err := os.WriteFile(filepath.Join(in, "in"), nil, 0o600); err != nil {
		t.Fatal(err)
	}
	body := "accounts:\n  in:\n    harness: claude-code\n    configDir: " + in + "\n  out:\n    harness: claude-code\n    configDir: " + t.TempDir() + "\n" +
		"defaults:\n  account: in\nprojects:\n  loupe:\n    dir: " + readyProject(t) + "\nwork:\n  plan:\n    prompt: go\n"
	if withOut {
		body += "  review:\n    prompt: go\n    account: out\n"
	}
	path := filepath.Join(t.TempDir(), "rules.yaml")
	if err := os.WriteFile(path, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}

	return path
}

func passingStatus(t *testing.T) {
	t.Helper()
	server, _ := statusServer(t, func() (currentProject, error) {
		var p currentProject
		p.Project.ID = projectA
		p.Project.Name = "Acme site"

		return p, nil
	})
	inRepo(t, server.URL)
	loggedInClaude(t)
}

func TestStatusSaysWhenNoRuleFileNamesAnAccount(t *testing.T) {
	passingStatus(t)

	out, err := runStatus(t, "--project", projectA)
	if err != nil || !strings.Contains(out, "Accounts:    no rule file at ") || !strings.Contains(out, "rules.yaml, so no account to check\n") || lastLine(out) != statusPass {
		t.Fatalf("status: %v\n%s", err, out)
	}

	path := filepath.Join(t.TempDir(), "studio.yaml")
	out, err = runStatus(t, "--project", projectA, "--rules", path)
	if err == nil || !strings.Contains(err.Error(), "--rules "+path) || lastLine(out) != statusFail {
		t.Fatalf("a missing --rules file: %v\n%s", err, out)
	}
}

// The line names the rule file that --rules gives, not the default name.
func TestStatusNamesARuleFileThatRunsNoAgent(t *testing.T) {
	passingStatus(t)
	path := filepath.Join(t.TempDir(), "studio.yaml")
	body := "accounts:\n  in:\n    harness: claude-code\ndefaults:\n  account: in\nprojects:\n  loupe:\n    dir: " + t.TempDir() + "\nwork:\n  test:\n    action: command\n    run: [make]\n"
	if err := os.WriteFile(path, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}

	out, err := runStatus(t, "--project", projectA, "--rules", path)
	if err != nil || !strings.Contains(out, "Accounts:    "+path+" runs no agent, so no account is in use\n") {
		t.Fatalf("status: %v\n%s", err, out)
	}
}

func TestStatusPassesWhenEachUsedAccountIsReady(t *testing.T) {
	passingStatus(t)

	out, err := runStatus(t, "--project", projectA, "--rules", statusRules(t, false))
	if err != nil || !strings.Contains(out, "Account:     in (claude-code): ready\n") || lastLine(out) != statusPass {
		t.Fatalf("status: %v\n%s", err, out)
	}
}

// An account no rule runs on prints its line and its detail, and fails nothing.
func TestStatusPrintsAFailingUnusedAccountAndStillPasses(t *testing.T) {
	passingStatus(t)

	out, err := runStatus(t, "--project", projectA, "--rules", statusRules(t, false))
	if err != nil || !strings.Contains(out, "Account:     out (claude-code, unused): failing: not logged in\n             run `CLAUDE_CONFIG_DIR=") || lastLine(out) != statusPass {
		t.Fatalf("status: %v\n%s", err, out)
	}
}

func TestStatusFailsWhenAnAccountFails(t *testing.T) {
	passingStatus(t)

	out, err := runStatus(t, "--project", projectA, "--rules", statusRules(t, true))
	if err == nil || !strings.Contains(err.Error(), "out") || strings.Contains(err.Error(), "in,") {
		t.Fatalf("status: %v\n%s", err, out)
	}
	for _, want := range []string{
		"Account:     in (claude-code): ready\n",
		"Account:     out (claude-code): failing: not logged in\n             run `CLAUDE_CONFIG_DIR=",
	} {
		if !strings.Contains(out, want) {
			t.Fatalf("output must contain %q, got:\n%s", want, out)
		}
	}
	if got := lastLine(out); got != statusFail {
		t.Fatalf("last line = %q, want %q", got, statusFail)
	}
}

func TestStatusFailsOnARuleFileThatDoesNotLoad(t *testing.T) {
	passingStatus(t)
	path := filepath.Join(t.TempDir(), "rules.yaml")
	if err := os.WriteFile(path, []byte("projects: [\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	out, err := runStatus(t, "--project", projectA, "--rules", path)
	if err == nil || !strings.Contains(err.Error(), path) || lastLine(out) != statusFail {
		t.Fatalf("status: %v\n%s", err, out)
	}
}
