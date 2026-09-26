package cmd

import (
	"bytes"
	"io"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"github.com/zalando/go-keyring"

	"github.com/ubermuda/loupe/cli/internal/config"
)

const (
	backfillProject = "01a007cc-0000-7000-8000-00000000000a"
	backfillSession = "0199a0e2-0000-7000-8000-00000000000"
)

// backfillServer logs in against a fake Loupe. It refuses each session in
// refusals with a 409 and that error, and takes the others.
func backfillServer(t *testing.T, refusals map[string]string) *recordedRequests {
	t.Helper()
	keyring.MockInit()
	shortConfigHome(t)
	t.Setenv("CLAUDE_CONFIG_DIR", filepath.Join("testdata", "backfill", "claude"))
	got := &recordedRequests{bodies: map[string]string{}}
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		got.add(r.Method+" "+r.URL.Path, string(body))
		for session, code := range refusals {
			if strings.Contains(r.URL.Path, session) {
				w.WriteHeader(http.StatusConflict)
				_, _ = io.WriteString(w, `{"error":"`+code+`"}`)

				return
			}
		}
		_, _ = io.WriteString(w, `{"runs":1,"updated":1}`)
	}))
	t.Cleanup(server.Close)
	if err := config.Save(testLogin(server.URL)); err != nil {
		t.Fatal(err)
	}

	return got
}

type recordedRequests struct {
	mu     sync.Mutex
	order  []string
	bodies map[string]string
}

func (r *recordedRequests) add(request, body string) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.order = append(r.order, request)
	r.bodies[request] = body
}

func runBackfill(t *testing.T, args ...string) (string, error) {
	t.Helper()
	cmd := newUsageCmd()
	var out bytes.Buffer
	cmd.SetArgs(append([]string{"backfill", "--log-file", filepath.Join("testdata", "backfill", "bridge.log")}, args...))
	cmd.SetOut(&out)
	cmd.SetErr(&bytes.Buffer{})
	cmd.SilenceUsage, cmd.SilenceErrors = true, true
	err := cmd.Execute()

	return out.String(), err
}

// A dry run reads every session of the log, prints what it would send and
// why it skips the others, and sends nothing.
func TestBackfillDryRunSendsNothing(t *testing.T) {
	got := backfillServer(t, nil)
	out, err := runBackfill(t, "--dry-run")
	if err != nil {
		t.Fatal(err)
	}
	want := strings.Join([]string{
		"would send " + backfillSession + "1 (card 1): reported $0.5000",
		"would send " + backfillSession + "2 (card 2): reported $0.5000, reported $0.7500",
		"would send " + backfillSession + "3 (card 3): estimated $0.0200, reported $0.2500",
		"skipped " + backfillSession + "4 (card 4): no transcript of the session",
		"skipped " + backfillSession + "5 (card 5): the transcript does not fit the worker processes: the session totals go down",
		"would send " + backfillSession + "6 (card 6): reported $0.1000",
		"skipped " + backfillSession + "7 (no card): a run with no card has no record in Loupe",
	}, "\n") + "\n"
	if out != want {
		t.Fatalf("output:\n%s\nwant:\n%s", out, want)
	}
	if len(got.order) != 0 {
		t.Fatalf("a dry run sent %v", got.order)
	}
}

func TestBackfillTakesOneProject(t *testing.T) {
	backfillServer(t, nil)
	out, err := runBackfill(t, "--dry-run", "--project", "01a007cc-0000-7000-8000-00000000000b")
	if err != nil {
		t.Fatal(err)
	}
	if out != "would send "+backfillSession+"6 (card 6): reported $0.1000\n" {
		t.Fatalf("output = %q", out)
	}
}

// A refused session does not stop the others, and the command then fails.
func TestBackfillGoesOnAfterARefusal(t *testing.T) {
	got := backfillServer(t, map[string]string{backfillSession + "2": "process_count_mismatch"})
	out, err := runBackfill(t)
	if err == nil || err.Error() != "1 of the sessions failed" {
		t.Fatalf("err = %v", err)
	}
	for _, line := range []string{
		"sent " + backfillSession + "1 (card 1): runs 1, updated 1\n",
		"refused " + backfillSession + "2 (card 2): process_count_mismatch\n",
		"sent " + backfillSession + "3 (card 3): runs 1, updated 1\n",
		"skipped " + backfillSession + "4 (card 4): no transcript of the session\n",
	} {
		if !strings.Contains(out, line) {
			t.Fatalf("output has no %q:\n%s", line, out)
		}
	}
	if len(got.order) != 4 {
		t.Fatalf("requests = %v", got.order)
	}
	body := got.bodies["PUT /api/projects/"+backfillProject+"/worker-runs/sessions/"+backfillSession+"3/usage"]
	want := `{"processes":[{"source":"estimated","models":{"claude-opus-5-5":{"inputTokens":0,"outputTokens":1000,"cacheReadTokens":0,"cacheWriteTokens":0,"costUsd":0.02}}},{"source":"reported","models":{"claude-opus-5-5":{"inputTokens":1,"outputTokens":5,"cacheReadTokens":0,"cacheWriteTokens":0,"costUsd":0.25}}}]}`
	if body != want {
		t.Fatalf("body = %s\nwant %s", body, want)
	}
}
