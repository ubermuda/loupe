package api

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

// exitCode returns a pointer to code, which is how a run that started reports.
func exitCode(code int) *int {
	return &code
}

func reason(text string) *string {
	return &text
}

// finishedRun is a run that started and exited cleanly.
func finishedRun() WorkerRun {
	started := time.Date(2026, 9, 13, 10, 0, 0, 0, time.UTC)

	return WorkerRun{
		BridgeID:   "0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90",
		CardID:     "0199a0e2-b1f3-7a44-9c11-2d3e4f506172",
		CardNumber: 42,
		RuleName:   "plan",
		StartedAt:  started,
		EndedAt:    started.Add(21 * time.Second),
		ExitCode:   exitCode(0),
		Output:     "wrote a plan",
	}
}

func TestReportWorkerRunPostsTheRun(t *testing.T) {
	var gotPath, gotAuth, gotType string
	var gotBody map[string]any
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath, gotAuth = r.URL.EscapedPath(), r.Header.Get("Authorization")
		gotType = r.Header.Get("Content-Type")
		_ = json.NewDecoder(r.Body).Decode(&gotBody)
		w.WriteHeader(http.StatusCreated)
		fmt.Fprint(w, `{"id":"0199a0e2-c2d3-7e55-8a22-3f4051627384"}`)
	}))
	t.Cleanup(server.Close)

	created, err := New(server.URL, "secret", server.Client()).
		ReportWorkerRun(context.Background(), "loupe", finishedRun())
	if err != nil {
		t.Fatal(err)
	}
	if !created {
		t.Fatal("created = false, want the 201 to read as a new row")
	}
	if gotPath != "/api/projects/loupe/worker-runs" || gotAuth != "Bearer secret" || gotType != "application/json" {
		t.Fatalf("path = %q, auth = %q, content type = %q", gotPath, gotAuth, gotType)
	}
	for field, want := range map[string]any{
		"bridgeId":      "0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90",
		"cardId":        "0199a0e2-b1f3-7a44-9c11-2d3e4f506172",
		"cardNumber":    float64(42),
		"ruleName":      "plan",
		"startedAt":     "2026-09-13T10:00:00Z",
		"endedAt":       "2026-09-13T10:00:21Z",
		"exitCode":      float64(0),
		"failureReason": nil,
		"output":        "wrote a plan",
	} {
		if gotBody[field] != want {
			t.Fatalf("%s = %#v, want %#v", field, gotBody[field], want)
		}
	}
}

// The server answers 200 for a report it already holds, so a retry of a report
// that landed must read as a success and not as a fault.
func TestReportWorkerRunTakesBothSuccessCodes(t *testing.T) {
	for status, wantCreated := range map[int]bool{http.StatusOK: false, http.StatusCreated: true} {
		server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			w.WriteHeader(status)
			fmt.Fprint(w, `{"id":"0199a0e2-c2d3-7e55-8a22-3f4051627384"}`)
		}))

		created, err := New(server.URL, "t", server.Client()).
			ReportWorkerRun(context.Background(), "loupe", finishedRun())
		server.Close()

		if err != nil {
			t.Fatalf("HTTP %d: err = %v", status, err)
		}
		if created != wantCreated {
			t.Fatalf("HTTP %d: created = %v, want %v", status, created, wantCreated)
		}
	}
}

// A worker that never started sends no exit code, and says why instead.
func TestReportWorkerRunSendsANullExitCode(t *testing.T) {
	var gotBody map[string]any
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewDecoder(r.Body).Decode(&gotBody)
		w.WriteHeader(http.StatusCreated)
	}))
	t.Cleanup(server.Close)

	run := finishedRun()
	run.ExitCode = nil
	run.FailureReason = reason("fork/exec claude: permission denied")

	if _, err := New(server.URL, "t", server.Client()).ReportWorkerRun(context.Background(), "loupe", run); err != nil {
		t.Fatal(err)
	}

	value, present := gotBody["exitCode"]
	if !present || value != nil {
		t.Fatalf("exitCode = %#v, present = %v, want a null the server reads as a run that never started", value, present)
	}
	if gotBody["failureReason"] != "fork/exec claude: permission denied" {
		t.Fatalf("failureReason = %#v", gotBody["failureReason"])
	}
}

// The server refuses a value past its cap, and a refused report is lost.
func TestReportWorkerRunCutsEachValueToTheServersCap(t *testing.T) {
	var gotBody map[string]any
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewDecoder(r.Body).Decode(&gotBody)
		w.WriteHeader(http.StatusCreated)
	}))
	t.Cleanup(server.Close)

	run := finishedRun()
	run.Output = strings.Repeat("é", maxRunOutput+50)
	run.RuleName = strings.Repeat("r", maxRuleName+50)
	run.ExitCode = nil
	run.FailureReason = reason(strings.Repeat("f", maxFailureReason+50))

	if _, err := New(server.URL, "t", server.Client()).ReportWorkerRun(context.Background(), "loupe", run); err != nil {
		t.Fatal(err)
	}

	for field, limit := range map[string]int{
		"output":        maxRunOutput,
		"ruleName":      maxRuleName,
		"failureReason": maxFailureReason,
	} {
		text, ok := gotBody[field].(string)
		if !ok {
			t.Fatalf("%s = %#v, want a string", field, gotBody[field])
		}
		if got := len([]rune(text)); got != limit {
			t.Fatalf("%s is %d characters, want %d", field, got, limit)
		}
	}
}

// The server trims a rule name and then measures it. A cut that ran first would
// turn a padded name into spaces alone, which the server refuses for good.
func TestReportWorkerRunTrimsARuleNameBeforeItCutsIt(t *testing.T) {
	var gotBody map[string]any
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewDecoder(r.Body).Decode(&gotBody)
		w.WriteHeader(http.StatusCreated)
	}))
	t.Cleanup(server.Close)

	run := finishedRun()
	run.RuleName = strings.Repeat(" ", maxRuleName) + "plan" + strings.Repeat(" ", 10)

	if _, err := New(server.URL, "t", server.Client()).ReportWorkerRun(context.Background(), "loupe", run); err != nil {
		t.Fatal(err)
	}

	if gotBody["ruleName"] != "plan" {
		t.Fatalf("ruleName = %#v, want the name the padding hid", gotBody["ruleName"])
	}
}

// The handle is a path segment, so a slash in it must not reach another route.
func TestReportWorkerRunEscapesTheHandle(t *testing.T) {
	var gotPath string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.EscapedPath()
		w.WriteHeader(http.StatusCreated)
	}))
	t.Cleanup(server.Close)

	_, _ = New(server.URL, "t", server.Client()).ReportWorkerRun(context.Background(), "a/../b", finishedRun())
	if gotPath != "/api/projects/a%2F..%2Fb/worker-runs" {
		t.Fatalf("path = %q", gotPath)
	}
}

// A refused report stays refused, and the queue reads ErrReportRefused as the
// signal to stop retrying. A rate limit and a server fault both clear on their
// own, so neither carries it.
func TestReportWorkerRunSaysWhichFailuresARetryCannotFix(t *testing.T) {
	for _, tc := range []struct {
		status  int
		refused bool
	}{
		{http.StatusUnauthorized, true},
		{http.StatusForbidden, true},
		{http.StatusNotFound, true},
		{http.StatusBadRequest, true},
		{http.StatusUnprocessableEntity, true},
		{http.StatusTooManyRequests, false},
		{http.StatusRequestTimeout, false},
		{http.StatusInternalServerError, false},
		{http.StatusBadGateway, false},
	} {
		server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			w.WriteHeader(tc.status)
			fmt.Fprint(w, `{"error":"nope"}`)
		}))

		_, err := New(server.URL, "t", server.Client()).
			ReportWorkerRun(context.Background(), "loupe", finishedRun())
		server.Close()

		if err == nil {
			t.Fatalf("HTTP %d: err = nil, want a failure", tc.status)
		}
		if errors.Is(err, ErrReportRefused) != tc.refused {
			t.Fatalf("HTTP %d: err = %v, refused = %v, want %v", tc.status, err, !tc.refused, tc.refused)
		}
	}
}
