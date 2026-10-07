package cmd

import (
	"context"
	"errors"
	"slices"
	"sync"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/outbound"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// stateSent is one run state the router sent, with the project and run it named.
type stateSent struct {
	handle string
	runID  string
	report api.RunStateReport
}

// stateRecorder is a server with the run state endpoints. It keeps every state
// and inventory in the order the queue sent them.
type stateRecorder struct {
	mu   sync.Mutex
	sent []any
}

func (s *stateRecorder) ReportRunState(_ context.Context, handle, runID string, report api.RunStateReport) (bool, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.sent = append(s.sent, stateSent{handle: handle, runID: runID, report: report})

	return true, nil
}

func (s *stateRecorder) ReportRunInventory(_ context.Context, _ string, runs []api.InventoryRun) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.sent = append(s.sent, runs)

	return nil
}

func (s *stateRecorder) ReportInteractiveLaunch(_ context.Context, handle, sessionID string, report api.InteractiveLaunchReport) (bool, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.sent = append(s.sent, launchSent{handle: handle, sessionID: sessionID, report: report})

	return true, nil
}

// launches is every launch report sent so far, in order.
func (s *stateRecorder) launches() []launchSent {
	s.mu.Lock()
	defer s.mu.Unlock()

	var out []launchSent
	for _, v := range s.sent {
		if l, ok := v.(launchSent); ok {
			out = append(out, l)
		}
	}

	return out
}

// states is every run state sent so far, in order.
func (s *stateRecorder) states() []stateSent {
	s.mu.Lock()
	defer s.mu.Unlock()

	var out []stateSent
	for _, v := range s.sent {
		if st, ok := v.(stateSent); ok {
			out = append(out, st)
		}
	}

	return out
}

// names is the state of each report sent so far, "inventory" for a run
// inventory and "launch" for a launch report.
func (s *stateRecorder) names() []string {
	s.mu.Lock()
	defer s.mu.Unlock()

	out := make([]string, len(s.sent))
	for i, v := range s.sent {
		switch v := v.(type) {
		case stateSent:
			out[i] = v.report.State
		case launchSent:
			out[i] = "launch"
		default:
			out[i] = "inventory"
		}
	}

	return out
}

// inventories is every run inventory sent so far.
func (s *stateRecorder) inventories() [][]api.InventoryRun {
	s.mu.Lock()
	defer s.mu.Unlock()

	var out [][]api.InventoryRun
	for _, v := range s.sent {
		if runs, ok := v.([]api.InventoryRun); ok {
			out = append(out, runs)
		}
	}

	return out
}

// syncQueue sends each report as it is enqueued, so the test reads the order in
// which the router made them.
type syncQueue struct{}

func (syncQueue) Enqueue(report outbound.Report) {
	_, _ = report.Send(context.Background())
}

func (syncQueue) SendLatest(string, func(context.Context) error, func(error)) {}

func (syncQueue) Pending() int { return 0 }

func (syncQueue) Close() {}

// states wires a recorder with the run state endpoints to the router.
func (h *harness) states() *stateRecorder {
	rec := &stateRecorder{}
	h.router.reports = syncQueue{}
	h.router.runs = newRunReports(rec, h.router.log)

	return rec
}

func stateNames(sent []stateSent) []string {
	out := make([]string, len(sent))
	for i, s := range sent {
		out[i] = s.report.State
	}

	return out
}

func wantStates(t *testing.T, sent []stateSent, want ...string) {
	t.Helper()
	if got := stateNames(sent); !slices.Equal(got, want) {
		t.Fatalf("states = %v, want %v", got, want)
	}
}

// ofRun keeps the states of one run.
func ofRun(sent []stateSent, runID string) []stateSent {
	return slices.DeleteFunc(slices.Clone(sent), func(s stateSent) bool { return s.runID != runID })
}

// A run that starts and succeeds sends queued, running and succeeded, all under
// one run id. Every report names the bridge, the card and the rule.
func TestARunReportsEachStateUnderOneRunID(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.worker.result = workerResult{exitCode: 0, output: "wrote a plan", hasResult: true}

	h.send(cardMoved(87))

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunSucceeded)
	runID := sent[0].runID
	if runID == "" {
		t.Fatal("the run has no id")
	}
	for _, s := range sent {
		if s.runID != runID || s.handle != testProject {
			t.Fatalf("report %s names run %q in %q, want %q in %q", s.report.State, s.runID, s.handle, runID, testProject)
		}
		r := s.report
		if r.BridgeID != testBridgeID || r.SubjectType != api.SubjectCard || r.SubjectID != cardUUID(87) || r.CardNumber != 87 || r.Rule != "work:plan" || r.WorkKind != "plan" || r.WorkRequestID == "" || r.RuleID != "plan-rule" || r.At.IsZero() {
			t.Fatalf("report = %+v", r)
		}
	}

	running, done := sent[1].report, sent[2].report
	if running.SessionID != testSession || running.StartedAt.IsZero() {
		t.Fatalf("running = %+v", running)
	}
	if done.SessionID != testSession || !done.StartedAt.Equal(running.StartedAt) || done.EndedAt.Before(done.StartedAt) {
		t.Fatalf("succeeded = %+v, want the start of running", done)
	}
	if done.ExitCode == nil || *done.ExitCode != 0 || done.FailureReason != nil || done.Output != "wrote a plan" {
		t.Fatalf("succeeded = %+v", done)
	}
	if done.HasResult == nil || !*done.HasResult {
		t.Fatalf("succeeded = %+v, want a result", done)
	}
}

// A non-zero exit is a failed run, a clean exit with no result line has no
// result, and a worker that never ran did not start.
func TestAFinishedRunReportsHowItEnded(t *testing.T) {
	for name, tc := range map[string]struct {
		result workerResult
		want   string
	}{
		"failed":      {workerResult{exitCode: 2, output: "no such option"}, api.RunFailed},
		"no result":   {workerResult{exitCode: 0, output: "no such option"}, api.RunNoResult},
		"not started": {workerResult{err: errors.New("fork/exec claude: permission denied")}, api.RunNotStarted},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarness(t)
			rec := h.states()
			h.worker.results = []workerResult{tc.result}
			h.worker.result = finishedRun

			h.send(cardMoved(87))

			sent := ofRun(rec.states(), rec.states()[0].runID)
			// A process that never started never reads as running.
			if tc.result.err != nil {
				wantStates(t, sent, api.RunQueued, tc.want)
				done := sent[1].report
				if done.ExitCode != nil || done.HasResult != nil || done.FailureReason == nil || *done.FailureReason != tc.result.err.Error() {
					t.Fatalf("not-started = %+v", done)
				}

				return
			}
			wantStates(t, sent, api.RunQueued, api.RunRunning, tc.want)
			done := sent[2].report
			if done.ExitCode == nil || *done.ExitCode != tc.result.exitCode || done.FailureReason != nil || done.Output != "no such option" {
				t.Fatalf("%s = %+v", tc.want, done)
			}
			if done.HasResult == nil || *done.HasResult {
				t.Fatalf("%s = %+v, want no result", tc.want, done)
			}
		})
	}
}

// A router with no queue sends nothing, and still runs.
func TestARouterWithNoQueueSendsNoState(t *testing.T) {
	h := newHarness(t)

	h.send(cardMoved(87))
	h.router.handler().OnConnect()

	if h.runs() != 1 {
		t.Fatalf("runs = %d", h.runs())
	}
}

// Each connect sends the runs the bridge holds, after every report before it.
// A closed run is no longer held.
func TestEachConnectSendsTheRunsTheBridgeHolds(t *testing.T) {
	h := newHarnessWith(t, withMaxWorkers(defaultRules, 1), rules.Defaults{})
	rec := h.states()
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})

	h.router.handler().OnConnect()
	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(88)))
	h.router.handler().OnConnect()

	// A waiting offer holds no claim, so the server has no run of it yet.
	sent := rec.states()
	want := []api.InventoryRun{{RunID: sent[0].runID, ProjectID: testProject, State: api.RunRunning}}
	inv := rec.inventories()
	if len(inv) != 2 || len(inv[0]) != 0 || !slices.Equal(inv[1], want) {
		t.Fatalf("inventories = %+v, want none held, then %+v", inv, want)
	}
	if got := rec.names(); !slices.Equal(got, []string{"inventory", api.RunQueued, api.RunRunning, "inventory"}) {
		t.Fatalf("sent = %v", got)
	}

	h.worker.block <- struct{}{}
	<-h.worker.started
	close(h.worker.block)
	h.router.wg.Wait()
	h.router.handler().OnConnect()
	if inv := rec.inventories(); len(inv) != 3 || len(inv[2]) != 0 {
		t.Fatalf("inventory after every run ended = %+v", inv[len(inv)-1])
	}
}
