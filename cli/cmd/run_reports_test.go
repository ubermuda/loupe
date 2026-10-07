package cmd

import (
	"context"
	"errors"
	"fmt"
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
	launch    func() (bool, error)

	mu          sync.Mutex
	puts        []string
	inventories [][]api.InventoryRun
	launches    []launchSent
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

func (f *fakeRunClient) ReportInteractiveLaunch(_ context.Context, handle, sessionID string, report api.InteractiveLaunchReport) (bool, error) {
	f.mu.Lock()
	f.launches = append(f.launches, launchSent{handle: handle, sessionID: sessionID, report: report})
	f.mu.Unlock()
	if f.launch == nil {
		return true, nil
	}

	return f.launch()
}

// launchSent is one launch report, with the project and the session it named.
type launchSent struct {
	handle    string
	sessionID string
	report    api.InteractiveLaunchReport
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
		BridgeID:    testBridge,
		State:       api.RunFailed,
		At:          started.Add(21 * time.Second),
		SubjectType: api.SubjectCard,
		SubjectID:   cardUUID(87),
		CardNumber:  87,
		Rule:        "plan",
		SessionID:   "5f0c2b1e-8d4a-4c3b-9e2f-1a0b3c4d5e6f",
		StartedAt:   started,
		EndedAt:     started.Add(21 * time.Second),
		ExitCode:    exitCodeOf(1),
		HasResult:   new(bool),
		Output:      "claude: no such option",
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
	if len(client.puts) != 1 {
		t.Fatalf("puts = %v", client.puts)
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

// A failure goes back to the queue as it is, a 404 included, and the next
// report is still sent.
func TestAFailureIsTheQueuesToJudge(t *testing.T) {
	refused := fmt.Errorf("%w (HTTP 404)", api.ErrReportRefused)
	client := &fakeRunClient{state: func(string) (bool, error) { return false, refused }, inventory: func() error { return refused }}
	reports, _ := newTestRunReports(client)
	ctx := context.Background()

	for range 2 {
		if _, err := reports.state(testProject, "run-1", failedReport()).Send(ctx); !errors.Is(err, api.ErrReportRefused) {
			t.Fatalf("err = %v, want the refusal", err)
		}
		if created, err := reports.inventory(testBridge, nil).Send(ctx); created || !errors.Is(err, api.ErrReportRefused) {
			t.Fatalf("created = %v, err = %v, want the refusal", created, err)
		}
	}

	if len(client.puts) != 2 || len(client.inventories) != 2 {
		t.Fatalf("puts = %v, inventories = %d", client.puts, len(client.inventories))
	}
}
