package api

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"slices"
	"strings"
	"testing"
	"time"
)

const testRunID = "0199a0e2-d3e4-7f66-9b33-405162738495"

// stateReport is a report of state, with the fields every state carries.
func stateReport(state string) RunStateReport {
	return RunStateReport{
		BridgeID:   "0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90",
		State:      state,
		At:         time.Date(2026, 9, 13, 10, 0, 0, 0, time.UTC),
		CardID:     "0199a0e2-b1f3-7a44-9c11-2d3e4f506172",
		CardNumber: 42,
		RuleName:   "plan",
	}
}

// putState sends report to a server that answers status, and returns the path
// and the body the server read.
func putState(t *testing.T, report RunStateReport, status int) (string, map[string]any, bool, error) {
	t.Helper()

	var gotPath, gotMethod string
	var gotBody map[string]any
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath, gotMethod = r.URL.EscapedPath(), r.Method
		_ = json.NewDecoder(r.Body).Decode(&gotBody)
		w.WriteHeader(status)
	}))
	t.Cleanup(server.Close)

	created, err := New(server.URL, "t", server.Client()).
		ReportRunState(context.Background(), "loupe", testRunID, report)
	if gotMethod != "" && gotMethod != http.MethodPut {
		t.Fatalf("method = %s, want PUT", gotMethod)
	}

	return gotPath, gotBody, created, err
}

// keys lists the fields of body, so a test states the whole shape of a report.
func keys(body map[string]any) string {
	var out []string
	for k := range body {
		out = append(out, k)
	}
	slices.Sort(out)

	return strings.Join(out, ",")
}

// withBase lists the fields every report carries, plus extra, in the order
// keys gives them.
func withBase(extra ...string) string {
	all := append([]string{"at", "bridgeId", "cardId", "cardNumber", "ruleName", "state"}, extra...)
	slices.Sort(all)

	return strings.Join(all, ",")
}

func TestReportRunStatePutsTheReportOnTheRun(t *testing.T) {
	path, body, created, err := putState(t, stateReport(RunQueued), http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if !created {
		t.Fatal("created = false, want the 201 to read as a new state")
	}
	if path != "/api/projects/loupe/worker-runs/"+testRunID {
		t.Fatalf("path = %s", path)
	}
	if keys(body) != withBase() {
		t.Fatalf("keys = %s, want %s", keys(body), withBase())
	}
	if body["state"] != "queued" || body["at"] != "2026-09-13T10:00:00Z" || body["cardNumber"] != float64(42) {
		t.Fatalf("body = %v", body)
	}
}

// Each state adds its own fields, and no state sends the fields of another.
func TestReportRunStateSendsTheFieldsOfEachState(t *testing.T) {
	started := time.Date(2026, 9, 13, 10, 0, 5, 0, time.UTC)

	running := stateReport(RunRunning)
	running.SessionID = "5f0c2b1e-8d4a-4c3b-9e2f-1a0b3c4d5e6f"
	running.StartedAt = started

	resumed := stateReport(RunResumed)
	resumed.AskID = "0199a0e2-e4f5-7077-8c44-516273849506"

	replaced := stateReport(RunReplaced)
	replaced.ReplacedBy = "0199a0e2-f506-7188-9d55-627384950617"

	capped := stateReport(RunWaitingForPerson)
	capped.MaxChain = 3

	dropped := stateReport(RunDropped)
	dropped.Reason = "shutdown"

	for _, tc := range []struct {
		report RunStateReport
		want   string
	}{
		{running, withBase("sessionId", "startedAt")},
		{resumed, withBase("askId")},
		{replaced, withBase("replacedBy")},
		{capped, withBase("maxChain")},
		{dropped, withBase("reason")},
		{stateReport(RunSkipped), withBase()},
	} {
		_, body, _, err := putState(t, tc.report, http.StatusCreated)
		if err != nil {
			t.Fatal(err)
		}
		if keys(body) != tc.want {
			t.Fatalf("%s: keys = %s, want %s", tc.report.State, keys(body), tc.want)
		}
	}
}

// A closed outcome keeps the pairing of the old report: an exit code, or a
// failure reason, with the other one sent as null.
func TestReportRunStateSendsAnOutcomeWithTheOldPairing(t *testing.T) {
	ended := time.Date(2026, 9, 13, 10, 0, 26, 0, time.UTC)

	succeeded := stateReport(RunSucceeded)
	succeeded.EndedAt = ended
	succeeded.ExitCode = exitCode(0)

	notStarted := stateReport(RunNotStarted)
	notStarted.EndedAt = ended
	notStarted.FailureReason = reason("claude: executable file not found")
	notStarted.Output = "partial"

	_, body, _, err := putState(t, succeeded, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if keys(body) != withBase("endedAt", "exitCode", "failureReason", "output") {
		t.Fatalf("keys = %s", keys(body))
	}
	if body["exitCode"] != float64(0) || body["failureReason"] != nil || body["output"] != "" || body["endedAt"] != "2026-09-13T10:00:26Z" {
		t.Fatalf("body = %v", body)
	}

	_, body, _, err = putState(t, notStarted, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if body["exitCode"] != nil || body["failureReason"] != "claude: executable file not found" || body["output"] != "partial" {
		t.Fatalf("body = %v", body)
	}
}

// A closed report can carry the session and the start, so a report that lands
// with no running report before it still names its session.
func TestReportRunStateSendsTheSessionOfAnOutcome(t *testing.T) {
	failed := stateReport(RunFailed)
	failed.SessionID = "5f0c2b1e-8d4a-4c3b-9e2f-1a0b3c4d5e6f"
	failed.StartedAt = time.Date(2026, 9, 13, 10, 0, 5, 0, time.UTC)
	failed.EndedAt = time.Date(2026, 9, 13, 10, 0, 26, 0, time.UTC)
	failed.ExitCode = exitCode(1)

	_, body, _, err := putState(t, failed, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if body["sessionId"] != failed.SessionID || body["startedAt"] != "2026-09-13T10:00:05Z" || body["exitCode"] != float64(1) {
		t.Fatalf("body = %v", body)
	}
}

func TestReportRunStateCutsEachValueToTheServersCap(t *testing.T) {
	report := stateReport(RunFailed)
	report.RuleName = "  " + strings.Repeat("r", maxRuleName+5)
	report.Output = strings.Repeat("é", maxRunOutput+10)
	report.FailureReason = reason(strings.Repeat("f", maxFailureReason+10))

	_, body, _, err := putState(t, report, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if got := body["ruleName"].(string); got != strings.Repeat("r", maxRuleName) {
		t.Fatalf("ruleName has %d characters", len([]rune(got)))
	}
	if got := body["output"].(string); len([]rune(got)) != maxRunOutput {
		t.Fatalf("output has %d characters", len([]rune(got)))
	}
	if got := body["failureReason"].(string); len([]rune(got)) != maxFailureReason {
		t.Fatalf("failureReason has %d characters", len([]rune(got)))
	}
}

func TestReportRunStateEscapesTheHandleAndTheRunID(t *testing.T) {
	var gotPath string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.EscapedPath()
		w.WriteHeader(http.StatusCreated)
	}))
	t.Cleanup(server.Close)

	_, _ = New(server.URL, "t", server.Client()).
		ReportRunState(context.Background(), "a/../b", "c/d", stateReport(RunQueued))

	if gotPath != "/api/projects/a%2F..%2Fb/worker-runs/c%2Fd" {
		t.Fatalf("path = %s", gotPath)
	}
}

// A 200 is a state the run already held. A 404 with no error code is a server
// older than the endpoint, and the bridge falls back on the old report. A 404
// that names the project is a project the caller lost, which no fallback fixes.
func TestReportRunStateReadsEachAnswer(t *testing.T) {
	for _, tc := range []struct {
		status      int
		body        string
		created     bool
		ok          bool
		refused     bool
		unsupported bool
	}{
		{status: http.StatusCreated, created: true, ok: true},
		{status: http.StatusOK, ok: true},
		{status: http.StatusNotFound, unsupported: true},
		{status: http.StatusNotFound, body: `<html>Not Found</html>`, unsupported: true},
		{status: http.StatusNotFound, body: `{"error":"project_not_found"}`, refused: true},
		{status: http.StatusUnauthorized, refused: true},
		{status: http.StatusForbidden, refused: true},
		{status: http.StatusBadRequest, refused: true},
		{status: http.StatusUnprocessableEntity, refused: true},
		{status: http.StatusTooManyRequests},
		{status: http.StatusRequestTimeout},
		{status: http.StatusInternalServerError},
		{status: http.StatusBadGateway},
	} {
		server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			w.WriteHeader(tc.status)
			fmt.Fprint(w, tc.body)
		}))

		created, err := New(server.URL, "t", server.Client()).
			ReportRunState(context.Background(), "loupe", testRunID, stateReport(RunQueued))
		server.Close()

		if created != tc.created || (err == nil) != tc.ok {
			t.Fatalf("HTTP %d %s: created = %v, err = %v", tc.status, tc.body, created, err)
		}
		if errors.Is(err, ErrReportRefused) != tc.refused || errors.Is(err, ErrRunStatesUnsupported) != tc.unsupported {
			t.Fatalf("HTTP %d %s: err = %v", tc.status, tc.body, err)
		}
	}
}

func TestIsOutcomeNamesTheThreeEndsOfARun(t *testing.T) {
	for _, state := range []string{RunSucceeded, RunFailed, RunNotStarted} {
		if !IsOutcome(state) {
			t.Fatalf("IsOutcome(%q) = false", state)
		}
	}
	for _, state := range []string{RunQueued, RunReplaced, RunResumed, RunSkipped, RunRunning, RunWaitingForPerson, RunDropped} {
		if IsOutcome(state) {
			t.Fatalf("IsOutcome(%q) = true", state)
		}
	}
}

func TestReportRunInventoryPutsTheRunsOfTheBridge(t *testing.T) {
	var gotPath, gotMethod string
	var gotBody map[string]any
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath, gotMethod = r.URL.EscapedPath(), r.Method
		_ = json.NewDecoder(r.Body).Decode(&gotBody)
		w.WriteHeader(http.StatusNoContent)
	}))
	t.Cleanup(server.Close)

	runs := []InventoryRun{{RunID: testRunID, ProjectID: "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7", State: RunRunning}}
	if err := New(server.URL, "t", server.Client()).ReportRunInventory(context.Background(), "bridge/1", runs); err != nil {
		t.Fatal(err)
	}
	if gotMethod != http.MethodPut || gotPath != "/api/bridges/bridge%2F1/runs" {
		t.Fatalf("%s %s", gotMethod, gotPath)
	}
	got, _ := json.Marshal(gotBody)
	want := `{"runs":[{"projectId":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7","runId":"` + testRunID + `","state":"running"}]}`
	if string(got) != want {
		t.Fatalf("body = %s, want %s", got, want)
	}
}

// A bridge that holds no run still sends the list, because the empty list is
// what marks the runs of a previous process Lost.
func TestReportRunInventorySendsAnEmptyListAsAList(t *testing.T) {
	var raw string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		data, _ := io.ReadAll(r.Body)
		raw = string(data)
		w.WriteHeader(http.StatusOK)
	}))
	t.Cleanup(server.Close)

	if err := New(server.URL, "t", server.Client()).ReportRunInventory(context.Background(), "b", nil); err != nil {
		t.Fatal(err)
	}
	if raw != `{"runs":[]}` {
		t.Fatalf("body = %s", raw)
	}
}

func TestReportRunInventoryReadsEachAnswer(t *testing.T) {
	for _, tc := range []struct {
		status      int
		ok          bool
		refused     bool
		unsupported bool
	}{
		{status: http.StatusOK, ok: true},
		{status: http.StatusNoContent, ok: true},
		{status: http.StatusNotFound, unsupported: true},
		{status: http.StatusForbidden, refused: true},
		{status: http.StatusUnprocessableEntity, refused: true},
		{status: http.StatusTooManyRequests},
		{status: http.StatusRequestTimeout},
		{status: http.StatusServiceUnavailable},
	} {
		server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			w.WriteHeader(tc.status)
		}))

		err := New(server.URL, "t", server.Client()).ReportRunInventory(context.Background(), "b", nil)
		server.Close()

		if (err == nil) != tc.ok {
			t.Fatalf("HTTP %d: err = %v", tc.status, err)
		}
		if errors.Is(err, ErrReportRefused) != tc.refused || errors.Is(err, ErrRunStatesUnsupported) != tc.unsupported {
			t.Fatalf("HTTP %d: err = %v", tc.status, err)
		}
	}
}
