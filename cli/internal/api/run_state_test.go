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

// exitCode returns a pointer to code, which is how a run that started reports.
func exitCode(code int) *int {
	return &code
}

func reason(text string) *string {
	return &text
}

func hasResult(v bool) *bool {
	return &v
}

// stateReport is a report of state, with the fields every state carries.
func stateReport(state string) RunStateReport {
	return RunStateReport{
		BridgeID:    "0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90",
		State:       state,
		At:          time.Date(2026, 9, 13, 10, 0, 0, 0, time.UTC),
		SubjectType: SubjectCard,
		SubjectID:   "0199a0e2-b1f3-7a44-9c11-2d3e4f506172",
		CardNumber:  42,
		Rule:        "plan",
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
	all := append([]string{"at", "bridgeId", "cardNumber", "state", "subjectId", "subjectType"}, extra...)
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
	if body["state"] != "queued" || body["at"] != "2026-09-13T10:00:00Z" || body["cardNumber"] != float64(42) ||
		body["subjectType"] != "card" || body["subjectId"] != "0199a0e2-b1f3-7a44-9c11-2d3e4f506172" {
		t.Fatalf("body = %v", body)
	}
}

// A run about a subject that is no card names the subject and sends no card
// number.
func TestReportRunStateSendsASubjectThatIsNoCard(t *testing.T) {
	report := stateReport(RunQueued)
	report.SubjectType, report.SubjectID, report.CardNumber = "analysis", "0199a0e2-0000-7a44-9c11-2d3e4f506172", 0
	_, body, _, err := putState(t, report, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	want := "at,bridgeId,state,subjectId,subjectType"
	if keys(body) != want || body["subjectType"] != "analysis" || body["subjectId"] != "0199a0e2-0000-7a44-9c11-2d3e4f506172" {
		t.Fatalf("keys = %s, body = %v, want %s", keys(body), body, want)
	}
}

// Each state adds its own fields, and no state sends the fields of another.
func TestReportRunStateSendsTheFieldsOfEachState(t *testing.T) {
	started := time.Date(2026, 9, 13, 10, 0, 5, 0, time.UTC)

	running := stateReport(RunRunning)
	running.SessionID = "5f0c2b1e-8d4a-4c3b-9e2f-1a0b3c4d5e6f"
	running.StartedAt = started

	replaced := stateReport(RunReplaced)
	replaced.ReplacedBy = "0199a0e2-f506-7188-9d55-627384950617"

	dropped := stateReport(RunDropped)
	dropped.Reason = "shutdown"

	for _, tc := range []struct {
		report RunStateReport
		want   string
	}{
		{running, withBase("sessionId", "startedAt")},
		{replaced, withBase("replacedBy")},
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

// A worker run names its pool in every state, an outcome included.
func TestReportRunStateSendsTheWorkerPool(t *testing.T) {
	queued := stateReport(RunQueued)
	queued.WorkerPool = "quick"
	succeeded := stateReport(RunSucceeded)
	succeeded.WorkerPool = "quick"
	succeeded.ExitCode = exitCode(0)

	for _, report := range []RunStateReport{queued, succeeded} {
		_, body, _, err := putState(t, report, http.StatusCreated)
		if err != nil {
			t.Fatal(err)
		}
		if body["workerPool"] != "quick" {
			t.Fatalf("%s: body = %v", report.State, body)
		}
	}
}

// A run in an experiment sends its variant on running and on the outcome.
func TestReportRunStateSendsTheExperimentFields(t *testing.T) {
	running := stateReport(RunRunning)
	running.Experiment, running.Variant, running.RequestedModel, running.SwitchedFrom = "impl-model", "sonnet", "claude-sonnet-5-5", "opus"
	failed := stateReport(RunNotStarted)
	failed.Experiment, failed.Variant, failed.RequestedModel = "impl-model", "sonnet", "claude-sonnet-5-5"

	_, body, _, err := putState(t, running, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if body["experiment"] != "impl-model" || body["variant"] != "sonnet" || body["requestedModel"] != "claude-sonnet-5-5" || body["switchedFrom"] != "opus" {
		t.Fatalf("running = %v", body)
	}
	_, body, _, err = putState(t, failed, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if _, ok := body["switchedFrom"]; ok || body["variant"] != "sonnet" {
		t.Fatalf("not started = %v, want a variant and no switchedFrom", body)
	}
}

// A run sends the harness, the account and the model it ran with, and leaves
// out an empty one so the server keeps what it holds.
func TestReportRunStateSendsTheRunSettings(t *testing.T) {
	running := stateReport(RunRunning)
	running.Harness, running.Account, running.Model = "claude-code", "work", "opus"
	_, body, _, err := putState(t, running, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if body["harness"] != "claude-code" || body["account"] != "work" || body["model"] != "opus" {
		t.Fatalf("running = %v", body)
	}

	running.Model = ""
	_, body, _, err = putState(t, running, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if _, ok := body["model"]; ok || body["account"] != "work" {
		t.Fatalf("running with no model = %v", body)
	}
	if _, ok := body["harnessSessionId"]; ok {
		t.Fatalf("running = %v", body)
	}
}

// A model the server would refuse is left out, so the rest of the report lands.
func TestReportRunStateLeavesOutAModelTheServerRefuses(t *testing.T) {
	for _, model := range []string{strings.Repeat("m", maxModel+1), "opus\n", "op\u200bus"} {
		running := stateReport(RunRunning)
		running.Account, running.Model = "work", model
		_, body, _, err := putState(t, running, http.StatusCreated)
		if err != nil {
			t.Fatal(err)
		}
		if _, ok := body["model"]; ok || body["account"] != "work" {
			t.Fatalf("model %q: body = %v", model, body)
		}
	}
	launch := launchReport(RunRunning)
	launch.Model = "opus\n"
	if _, body, _, err := putLaunch(t, launch, http.StatusCreated); err != nil || body["model"] != nil {
		t.Fatalf("launch body = %v, %v", body, err)
	}
}

// A closed outcome sends an exit code, or a failure reason, with the other one
// sent as null.
func TestReportRunStateSendsAnOutcomeWithItsPairing(t *testing.T) {
	ended := time.Date(2026, 9, 13, 10, 0, 26, 0, time.UTC)

	noResult := false
	succeeded := stateReport(RunNoResult)
	succeeded.EndedAt = ended
	succeeded.ExitCode = exitCode(0)
	succeeded.HasResult = &noResult

	notStarted := stateReport(RunNotStarted)
	notStarted.EndedAt = ended
	notStarted.FailureReason = reason("claude: executable file not found")
	notStarted.Output = "partial"

	_, body, _, err := putState(t, succeeded, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if keys(body) != withBase("endedAt", "exitCode", "failureReason", "hasResult", "output") {
		t.Fatalf("keys = %s", keys(body))
	}
	if body["exitCode"] != float64(0) || body["hasResult"] != false || body["failureReason"] != nil || body["output"] != "" || body["endedAt"] != "2026-09-13T10:00:26Z" {
		t.Fatalf("body = %v", body)
	}

	_, body, _, err = putState(t, notStarted, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if body["exitCode"] != nil || body["hasResult"] != nil || body["failureReason"] != "claude: executable file not found" || body["output"] != "partial" {
		t.Fatalf("body = %v", body)
	}
	if _, ok := body["hasResult"]; !ok {
		t.Fatalf("body = %v, want hasResult sent as null", body)
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
	report.Output = strings.Repeat("é", maxRunOutput+10)
	report.FailureReason = reason(strings.Repeat("f", maxFailureReason+10))

	_, body, _, err := putState(t, report, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
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

// A 200 is a state the run already held. Every 404 is refused, because no
// retry of the same body finds the endpoint or the project.
func TestReportRunStateReadsEachAnswer(t *testing.T) {
	for _, tc := range []struct {
		status  int
		body    string
		created bool
		ok      bool
		refused bool
	}{
		{status: http.StatusCreated, created: true, ok: true},
		{status: http.StatusOK, ok: true},
		{status: http.StatusNotFound, refused: true},
		{status: http.StatusNotFound, body: `<html>Not Found</html>`, refused: true},
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
		if errors.Is(err, ErrReportRefused) != tc.refused {
			t.Fatalf("HTTP %d %s: err = %v", tc.status, tc.body, err)
		}
	}
}

// A resume series sends its result and its place in the series. A field
// that is not set is not sent.
func TestReportRunStateSendsTheResultAndTheResumeFields(t *testing.T) {
	code, has := 0, true
	outcome := stateReport(RunUnfinished)
	outcome.ExitCode, outcome.HasResult = &code, &has
	outcome.ResultStatus, outcome.ResultFields = "unfinished", map[string]any{"prUrl": "https://example.test/pr/1"}
	outcome.ResultReason, outcome.ResumeSkipped = "ci-red", "card_moved"
	_, body, _, err := putState(t, outcome, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if body["resultStatus"] != "unfinished" || body["resultReason"] != "ci-red" || body["resumeSkipped"] != "card_moved" || body["resultFields"].(map[string]any)["prUrl"] != "https://example.test/pr/1" {
		t.Fatalf("body = %v", body)
	}

	queued := stateReport(RunQueued)
	queued.Continues = testRunID
	_, body, _, err = putState(t, queued, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if want := withBase("continues"); keys(body) != want {
		t.Fatalf("keys = %s, want %s", keys(body), want)
	}
	if body["continues"] != testRunID {
		t.Fatalf("body = %v", body)
	}

	_, body, _, err = putState(t, stateReport(RunQueued), http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if keys(body) != withBase() {
		t.Fatalf("keys = %s", keys(body))
	}
}

// A run of a work request names the request, its kind and the rule that
// opened it. A run of a rules: entry names none, and the rule name stays in
// the bridge.
func TestReportRunStateSendsTheWorkRequest(t *testing.T) {
	queued := stateReport(RunQueued)
	queued.WorkRequestID, queued.WorkKind, queued.RuleID = testRunID, "implement", "implement-on-entry"
	_, body, _, err := putState(t, queued, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if want := withBase("ruleId", "workKind", "workRequestId"); keys(body) != want {
		t.Fatalf("keys = %s, want %s", keys(body), want)
	}
	if body["workRequestId"] != testRunID || body["workKind"] != "implement" || body["ruleId"] != "implement-on-entry" {
		t.Fatalf("body = %v", body)
	}

	_, body, _, err = putState(t, stateReport(RunQueued), http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if keys(body) != withBase() {
		t.Fatalf("keys = %s, want no work request and no rule name", keys(body))
	}
}

func TestIsOutcomeNamesTheEndsOfARun(t *testing.T) {
	for _, state := range []string{RunSucceeded, RunNoResult, RunFailed, RunNotStarted, RunUnfinished, RunBlocked, RunWaitingOnForge} {
		if !IsOutcome(state) {
			t.Fatalf("IsOutcome(%q) = false", state)
		}
	}
	for _, state := range []string{RunQueued, RunReplaced, RunSkipped, RunPreparing, RunRunning, RunDropped} {
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
		status  int
		ok      bool
		refused bool
	}{
		{status: http.StatusOK, ok: true},
		{status: http.StatusNoContent, ok: true},
		{status: http.StatusNotFound, refused: true},
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
		if errors.Is(err, ErrReportRefused) != tc.refused {
			t.Fatalf("HTTP %d: err = %v", tc.status, err)
		}
	}
}

// A command run names its kind in every state, and its running report carries
// a start and no session. A worker run sends no kind.
func TestReportRunStateSendsTheKindOfACommandRun(t *testing.T) {
	running := stateReport(RunRunning)
	running.Kind = RunKindCommand
	running.StartedAt = time.Date(2026, 9, 13, 10, 0, 5, 0, time.UTC)

	failed := stateReport(RunFailed)
	failed.Kind = RunKindCommand
	failed.StartedAt = running.StartedAt
	failed.EndedAt = time.Date(2026, 9, 13, 10, 0, 26, 0, time.UTC)
	failed.ExitCode = exitCode(-1)

	queued := stateReport(RunQueued)
	queued.Kind = RunKindCommand

	for _, tc := range []struct {
		report RunStateReport
		want   string
	}{
		{queued, withBase("kind")},
		{running, withBase("kind", "startedAt")},
		{failed, withBase("endedAt", "exitCode", "failureReason", "hasResult", "kind", "output", "startedAt")},
	} {
		_, body, _, err := putState(t, tc.report, http.StatusCreated)
		if err != nil {
			t.Fatal(err)
		}
		if keys(body) != tc.want || body["kind"] != "command" {
			t.Fatalf("%s: body = %v, want keys %s", tc.report.State, body, tc.want)
		}
	}
}

// An outcome and a stopped report send the peak context when the bridge read
// one, and leave it out when it read none.
func TestReportRunStateSendsThePeakContext(t *testing.T) {
	peak := int64(31772)
	for _, state := range []string{RunSucceeded, RunStopped} {
		report := stateReport(state)
		report.PeakContextTokens = &peak
		_, body, _, err := putState(t, report, http.StatusCreated)
		if err != nil {
			t.Fatal(err)
		}
		if body["peakContextTokens"] != float64(31772) {
			t.Fatalf("%s: body = %v", state, body)
		}

		report.PeakContextTokens = nil
		_, body, _, err = putState(t, report, http.StatusCreated)
		if err != nil {
			t.Fatal(err)
		}
		if _, ok := body["peakContextTokens"]; ok {
			t.Fatalf("%s: body = %v, want no peakContextTokens", state, body)
		}
	}
}
