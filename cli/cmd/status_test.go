package cmd

import (
	"bytes"
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
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
