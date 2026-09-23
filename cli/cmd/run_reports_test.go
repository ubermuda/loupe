package cmd

import (
	"context"
	"errors"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
)

// fakeRunClient records each call, and answers the way its fields say. A nil
// answer function answers a success.
type fakeRunClient struct {
	state     func(state string) (bool, error)
	inventory func() error
	post      func(run api.WorkerRun) (bool, error)

	mu          sync.Mutex
	puts        []string
	posts       []api.WorkerRun
	inventories [][]api.InventoryRun
}

func (f *fakeRunClient) ReportRunState(_ context.Context, _, _ string, report api.RunStateReport) (bool, error) {
	f.mu.Lock()
	f.puts = append(f.puts, report.State)
	f.mu.Unlock()
	if f.state == nil {
		return true, nil
	}

	return f.state(report.State)
}

func (f *fakeRunClient) ReportRunInventory(_ context.Context, _ string, runs []api.InventoryRun) error {
	f.mu.Lock()
	f.inventories = append(f.inventories, runs)
	f.mu.Unlock()
	if f.inventory == nil {
		return nil
	}

	return f.inventory()
}

func (f *fakeRunClient) ReportWorkerRun(_ context.Context, _ string, run api.WorkerRun) (bool, error) {
	f.mu.Lock()
	f.posts = append(f.posts, run)
	f.mu.Unlock()
	if f.post == nil {
		return true, nil
	}

	return f.post(run)
}

// oldServer is a server that predates the run state endpoints.
func oldServer() *fakeRunClient {
	return &fakeRunClient{
		state:     func(string) (bool, error) { return false, api.ErrRunStatesUnsupported },
		inventory: func() error { return api.ErrRunStatesUnsupported },
	}
}

func newTestRunReports(client runReportClient) (*runReports, *syncBuffer) {
	log := &syncBuffer{}

	return newRunReports(client, newBridgeLogger(log)), log
}

// countEvents counts the log lines of event name.
func countEvents(t *testing.T, log *syncBuffer, name string) int {
	t.Helper()

	h := &harness{log: log}

	return len(h.events(t, name))
}

// failedReport is the closed report of a run that started and exited with 1.
func failedReport() api.RunStateReport {
	started := time.Date(2026, 9, 13, 10, 0, 5, 0, time.UTC)

	return api.RunStateReport{
		BridgeID:   testBridge,
		State:      api.RunFailed,
		At:         started.Add(21 * time.Second),
		CardID:     cardUUID(87),
		CardNumber: 87,
		RuleName:   "plan",
		SessionID:  "5f0c2b1e-8d4a-4c3b-9e2f-1a0b3c4d5e6f",
		StartedAt:  started,
		EndedAt:    started.Add(21 * time.Second),
		ExitCode:   exitCodeOf(1),
		HasResult:  new(bool),
		Output:     "claude: no such option",
	}
}

func exitCodeOf(code int) *int {
	return &code
}

func TestAStateReportGoesToTheRunStateEndpoint(t *testing.T) {
	client := &fakeRunClient{}
	reports, _ := newTestRunReports(client)

	report := reports.state(testProject, "run-1", failedReport())
	created, err := report.Send(context.Background())

	if err != nil || !created {
		t.Fatalf("created = %v, err = %v", created, err)
	}
	if report.Card != 87 || report.Rule != "plan" {
		t.Fatalf("report names card %d and rule %q", report.Card, report.Rule)
	}
	if len(client.puts) != 1 || len(client.posts) != 0 {
		t.Fatalf("puts = %v, posts = %d", client.puts, len(client.posts))
	}
}

// A report that waits in the queue when the bridge learns the server is old
// must not be lost, so the same send posts it to the old endpoint.
func TestAnOutcomeFallsBackOnTheOldReport(t *testing.T) {
	client := oldServer()
	reports, log := newTestRunReports(client)

	created, err := reports.state(testProject, "run-1", failedReport()).Send(context.Background())

	if err != nil || !created {
		t.Fatalf("created = %v, err = %v", created, err)
	}
	if len(client.puts) != 1 || len(client.posts) != 1 {
		t.Fatalf("puts = %v, posts = %d", client.puts, len(client.posts))
	}
	want := failedReport()
	got := client.posts[0]
	if got.BridgeID != want.BridgeID || got.SessionID != want.SessionID || got.CardID != want.CardID || got.CardNumber != 87 || got.RuleName != "plan" {
		t.Fatalf("post = %+v", got)
	}
	if !got.StartedAt.Equal(want.StartedAt) || !got.EndedAt.Equal(want.EndedAt) || got.ExitCode == nil || *got.ExitCode != 1 || got.Output != want.Output {
		t.Fatalf("post = %+v", got)
	}
	if got.HasResult == nil || *got.HasResult {
		t.Fatalf("post = %+v, want the result flag of the report", got)
	}
	if n := countEvents(t, log, "run_states_unsupported"); n != 1 {
		t.Fatalf("run_states_unsupported logged %d times", n)
	}
}

// Once the bridge knows the server is old, it sends no more state reports, and
// says so once.
func TestAnOldServerIsNamedOnceAndAskedOnce(t *testing.T) {
	client := oldServer()
	reports, log := newTestRunReports(client)
	ctx := context.Background()

	queued := failedReport()
	queued.State = api.RunQueued
	for _, report := range []api.RunStateReport{queued, failedReport(), queued, failedReport()} {
		if created, err := reports.state(testProject, "run-1", report).Send(ctx); err != nil || !created {
			t.Fatalf("%s: created = %v, err = %v", report.State, created, err)
		}
	}

	if len(client.puts) != 1 {
		t.Fatalf("puts = %v, want the first report only", client.puts)
	}
	if len(client.posts) != 2 {
		t.Fatalf("posts = %d, want one per outcome", len(client.posts))
	}
	if n := countEvents(t, log, "run_states_unsupported"); n != 1 {
		t.Fatalf("run_states_unsupported logged %d times", n)
	}
}

// Each open state has no place in the old report, so the bridge counts it as
// delivered and sends nothing.
func TestAnOldServerSkipsEveryStateButAnOutcome(t *testing.T) {
	client := oldServer()
	reports, _ := newTestRunReports(client)
	ctx := context.Background()

	for _, state := range []string{
		api.RunQueued, api.RunReplaced, api.RunResumed, api.RunSkipped,
		api.RunRunning, api.RunWaitingForPerson, api.RunDropped,
	} {
		report := failedReport()
		report.State = state
		if created, err := reports.state(testProject, "run-1", report).Send(ctx); err != nil || !created {
			t.Fatalf("%s: created = %v, err = %v", state, created, err)
		}
	}

	if len(client.posts) != 0 {
		t.Fatalf("posts = %+v, want none", client.posts)
	}
}

// A retry of an outcome after the fallback goes straight to the old endpoint.
func TestAFallbackRetryPostsAgain(t *testing.T) {
	client := oldServer()
	client.post = func(api.WorkerRun) (bool, error) { return false, errors.New("connection refused") }
	reports, _ := newTestRunReports(client)
	report := reports.state(testProject, "run-1", failedReport())

	for range 2 {
		if _, err := report.Send(context.Background()); err == nil {
			t.Fatal("err = nil, want the post's failure")
		}
	}

	if len(client.puts) != 1 || len(client.posts) != 2 {
		t.Fatalf("puts = %v, posts = %d", client.puts, len(client.posts))
	}
}

// The old report needs a session and a start. A run that never reached running
// has neither, so the bridge logs it rather than send a report that is refused.
func TestAnOutcomeWithNoSessionIsNotPosted(t *testing.T) {
	client := oldServer()
	reports, log := newTestRunReports(client)
	report := failedReport()
	report.State = api.RunNotStarted
	report.SessionID = ""
	report.StartedAt = time.Time{}
	report.ExitCode = nil
	died := "the rule died"
	report.FailureReason = &died

	created, err := reports.state(testProject, "run-1", report).Send(context.Background())

	if err != nil || !created {
		t.Fatalf("created = %v, err = %v", created, err)
	}
	if len(client.posts) != 0 {
		t.Fatalf("posts = %+v, want none", client.posts)
	}
	if n := countEvents(t, log, "report_skipped"); n != 1 {
		t.Fatalf("report_skipped logged %d times", n)
	}
}

func TestTheInventoryGoesToTheBridge(t *testing.T) {
	client := &fakeRunClient{}
	reports, _ := newTestRunReports(client)
	runs := []api.InventoryRun{{RunID: "run-1", ProjectID: testProject, State: api.RunRunning}}

	if created, err := reports.inventory(testBridge, runs).Send(context.Background()); err != nil || !created {
		t.Fatalf("created = %v, err = %v", created, err)
	}
	if len(client.inventories) != 1 || len(client.inventories[0]) != 1 {
		t.Fatalf("inventories = %v", client.inventories)
	}
}

// An old server has no inventory endpoint, so its 404 counts as delivered and
// the next inventory is not sent at all.
func TestAnOldServerGetsNoInventory(t *testing.T) {
	client := oldServer()
	reports, log := newTestRunReports(client)
	ctx := context.Background()

	for range 2 {
		if created, err := reports.inventory(testBridge, nil).Send(ctx); err != nil || !created {
			t.Fatalf("created = %v, err = %v", created, err)
		}
	}
	queued := failedReport()
	queued.State = api.RunQueued
	if _, err := reports.state(testProject, "run-1", queued).Send(ctx); err != nil {
		t.Fatal(err)
	}

	if len(client.inventories) != 1 || len(client.puts) != 0 {
		t.Fatalf("inventories = %d, puts = %v", len(client.inventories), client.puts)
	}
	if n := countEvents(t, log, "run_states_unsupported"); n != 1 {
		t.Fatalf("run_states_unsupported logged %d times", n)
	}
}

// Any other failure is the queue's to retry, and says nothing about the server.
func TestARetryableFailureLeavesTheFlagAlone(t *testing.T) {
	client := &fakeRunClient{state: func(string) (bool, error) { return false, errors.New("HTTP 503") }}
	reports, log := newTestRunReports(client)

	if _, err := reports.state(testProject, "run-1", failedReport()).Send(context.Background()); err == nil {
		t.Fatal("err = nil, want the failure for the queue to retry")
	}

	if len(client.posts) != 0 || countEvents(t, log, "run_states_unsupported") != 0 {
		t.Fatalf("posts = %d, log = %s", len(client.posts), log.String())
	}
}
