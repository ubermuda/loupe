package cmd

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/config"
)

// stubGitHub answers GET /user with status and body, and records the headers.
func stubGitHub(t *testing.T, status int, body string) *http.Header {
	t.Helper()
	var seen http.Header
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet || r.URL.Path != "/user" {
			t.Errorf("unexpected %s %s", r.Method, r.URL.Path)
		}
		seen = r.Header.Clone()
		w.WriteHeader(status)
		fmt.Fprint(w, body)
	}))
	t.Cleanup(server.Close)
	old := githubAPIURL
	githubAPIURL = server.URL
	t.Cleanup(func() { githubAPIURL = old })

	return &seen
}

func runAgentAccount(t *testing.T, stdin string, args ...string) (string, error) {
	t.Helper()
	var out bytes.Buffer
	cmd := newAgentAccountCmd()
	cmd.SetOut(&out)
	cmd.SetErr(io.Discard)
	cmd.SetIn(strings.NewReader(stdin))
	cmd.SetArgs(args)
	err := cmd.Execute()

	return out.String(), err
}

func TestAgentAccountSetStoresTheAccount(t *testing.T) {
	useLoginConfigHome(t)
	headers := stubGitHub(t, http.StatusOK, `{"login":"loupe-bot","id":4242}`)

	out, err := runAgentAccount(t, "  ghp_secret \n", "set")
	if err != nil {
		t.Fatalf("set: %v", err)
	}
	if got := headers.Get("Authorization"); got != "Bearer ghp_secret" {
		t.Fatalf("Authorization = %q", got)
	}
	if got := headers.Get("Accept"); got != "application/vnd.github+json" {
		t.Fatalf("Accept = %q", got)
	}
	if !strings.Contains(out, "loupe-bot") || strings.Contains(out, "ghp_secret") {
		t.Fatalf("set printed %q", out)
	}
	stored, err := config.LoadAgentAccount()
	if err != nil || stored == nil || *stored != (config.AgentAccount{Token: "ghp_secret", Login: "loupe-bot", ID: 4242}) {
		t.Fatalf("stored %+v, %v", stored, err)
	}

	out, err = runAgentAccount(t, "", "show")
	if err != nil || !strings.Contains(out, "loupe-bot") || !strings.Contains(out, "4242") || strings.Contains(out, "ghp_secret") {
		t.Fatalf("show printed %q, %v", out, err)
	}

	if _, err := runAgentAccount(t, "", "clear"); err != nil {
		t.Fatalf("clear: %v", err)
	}
	if stored, err := config.LoadAgentAccount(); err != nil || stored != nil {
		t.Fatalf("after clear, stored %+v, %v", stored, err)
	}
	out, err = runAgentAccount(t, "", "show")
	if err != nil || !strings.Contains(out, "No agent account") {
		t.Fatalf("show printed %q, %v", out, err)
	}
}

func TestAgentAccountSetRefusesABadToken(t *testing.T) {
	useLoginConfigHome(t)
	stubGitHub(t, http.StatusUnauthorized, `{"message":"Bad credentials"}`)

	if _, err := runAgentAccount(t, "ghp_bad\n", "set"); err == nil || !strings.Contains(err.Error(), "401") {
		t.Fatalf("set error = %v, want a 401", err)
	}
	if stored, err := config.LoadAgentAccount(); err != nil || stored != nil {
		t.Fatalf("stored %+v, %v", stored, err)
	}
}

func TestAgentAccountSetRefusesAnEmptyToken(t *testing.T) {
	useLoginConfigHome(t)
	stubGitHub(t, http.StatusOK, `{"login":"loupe-bot","id":4242}`)

	if _, err := runAgentAccount(t, "\n", "set"); err == nil {
		t.Fatal("set took an empty token")
	}
}

// The login goes into a shell credential helper, so a login outside GitHub's
// own alphabet is refused.
func TestGitHubUserRefusesAnOddLogin(t *testing.T) {
	stubGitHub(t, http.StatusOK, `{"login":"bot; rm -rf ~","id":1}`)

	if _, _, err := githubUser(context.Background(), "ghp_x"); err == nil {
		t.Fatal("githubUser took the login")
	}
}

func TestCheckAgentAccount(t *testing.T) {
	log := slog.New(slog.NewTextHandler(io.Discard, nil))
	account := &config.AgentAccount{Token: "ghp_x", Login: "old-name", ID: 7}

	if got := checkAgentAccount(context.Background(), nil, log); got != "" {
		t.Fatalf("no account gives %q", got)
	}

	stubGitHub(t, http.StatusOK, `{"login":"loupe-bot","id":7}`)
	if got := checkAgentAccount(context.Background(), account, log); got != "loupe-bot" {
		t.Fatalf("a good token gives %q", got)
	}

	stubGitHub(t, http.StatusUnauthorized, `{}`)
	if got := checkAgentAccount(context.Background(), account, log); got != "" {
		t.Fatalf("a revoked token gives %q", got)
	}
}
