package cmd

import (
	"context"
	"errors"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/outbound"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

const testBridge = "0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90"

// reported is one run the router handed to the queue, with the project it named.
type reported struct {
	handle string
	run    api.WorkerRun
}

// reports wires the real queue to the router, and collects the old reports it
// sends.
func (h *harness) reports(t *testing.T) chan reported {
	t.Helper()

	sent := make(chan reported, 16)
	q := outbound.New(context.Background(), h.router.log)
	t.Cleanup(q.Close)
	h.router.bridgeID = testBridge
	h.router.reports = q
	h.router.runs = newRunReports(&postRecorder{
		fakeRunClient: fakeRunClient{
			state:     func(string) (bool, error) { return false, api.ErrRunStatesUnsupported },
			inventory: func() error { return api.ErrRunStatesUnsupported },
		},
		sent: sent,
	}, h.router.log)

	return sent
}

// postRecorder is a server older than the run state endpoints. It takes every
// old report, and hands each one to sent with the project it named.
type postRecorder struct {
	fakeRunClient
	sent chan reported
}

func (p *postRecorder) ReportWorkerRun(_ context.Context, handle string, run api.WorkerRun) (bool, error) {
	p.sent <- reported{handle: handle, run: run}

	return true, nil
}

// A worker reports its own success by writing to the card. The bridge reports
// every run, because the run that writes nothing is the one worth reading.
func TestEachFinishedRunReachesLoupe(t *testing.T) {
	h := newHarness(t)
	sent := h.reports(t)
	h.worker.result = workerResult{exitCode: 0, output: "wrote a plan"}

	h.send(movedPayload(87, "backlog", "next", "human"))

	got := <-sent
	if got.handle != testProject {
		t.Fatalf("handle = %q, want the project the event named", got.handle)
	}
	if got.run.BridgeID != testBridge || got.run.CardID != cardUUID(87) || got.run.CardNumber != 87 {
		t.Fatalf("run = %+v", got.run)
	}
	if got.run.RuleName != "plan" || got.run.Output != "wrote a plan" {
		t.Fatalf("run = %+v", got.run)
	}
	if got.run.ExitCode == nil || *got.run.ExitCode != 0 || got.run.FailureReason != nil {
		t.Fatalf("exit code = %v, failure reason = %v", got.run.ExitCode, got.run.FailureReason)
	}
	if got.run.EndedAt.Before(got.run.StartedAt) {
		t.Fatalf("ended %v before it started %v, and the server refuses that", got.run.EndedAt, got.run.StartedAt)
	}
}

// The report names the session the worker ran as, so Loupe can find the card of
// a session later.
func TestTheReportCarriesTheWorkersSessionID(t *testing.T) {
	h := newHarness(t)
	sent := h.reports(t)

	h.send(movedPayload(87, "backlog", "next", "human"))

	got := <-sent
	calls := h.worker.recorded()
	if len(calls) != 1 || calls[0].sessionID == "" {
		t.Fatalf("workers = %+v", calls)
	}
	if got.run.SessionID != calls[0].sessionID {
		t.Fatalf("session id = %q, want %q", got.run.SessionID, calls[0].sessionID)
	}
}

// A worker that never started is the run the bridge alone can report. It sends
// no exit code, because none exists, and says why instead.
func TestAWorkerThatNeverRanIsStillReported(t *testing.T) {
	h := newHarness(t)
	sent := h.reports(t)
	h.worker.result = workerResult{err: errors.New("fork/exec claude: permission denied")}

	h.send(movedPayload(87, "backlog", "next", "human"))

	got := <-sent
	if got.run.ExitCode != nil {
		t.Fatalf("exit code = %v, want none for a process that never ran", *got.run.ExitCode)
	}
	if got.run.FailureReason == nil || *got.run.FailureReason != "fork/exec claude: permission denied" {
		t.Fatalf("failure reason = %v", got.run.FailureReason)
	}
}

// A non-zero exit is a run like any other, and the record says so.
func TestAFailedRunCarriesItsExitCode(t *testing.T) {
	h := newHarness(t)
	sent := h.reports(t)
	h.worker.result = workerResult{exitCode: 2, output: "claude: no such option"}

	h.send(movedPayload(87, "backlog", "next", "human"))

	got := <-sent
	if got.run.ExitCode == nil || *got.run.ExitCode != 2 {
		t.Fatalf("exit code = %v, want 2", got.run.ExitCode)
	}
}

// The log is the operator's view, and `jq` filters read its keys. Reporting
// adds a line and changes none.
func TestReportingLeavesTheWorkerLogAlone(t *testing.T) {
	h := newHarness(t)
	sent := h.reports(t)
	h.worker.result = workerResult{exitCode: 0, output: "wrote a plan"}

	h.send(movedPayload(87, "backlog", "next", "human"))
	<-sent

	finished := h.only(t, "worker_finished")
	if num(t, finished, "card") != 87 || num(t, finished, "exit") != 0 || str(t, finished, "rule") != "plan" {
		t.Fatalf("worker_finished = %v", finished)
	}
	if str(t, finished, "output") != "wrote a plan" || finished["duration_ms"] == nil {
		t.Fatalf("worker_finished = %v", finished)
	}
}

// Loupe records a run against a card, so an event that names no card number has
// nothing to report. The skip is logged, because a missing record must not read
// as a run that did not happen.
func TestAnEventWithNoCardNumberIsNotReported(t *testing.T) {
	h := newHarnessWith(t, defaultRules+`
  - name: created
    on: board.card_created
    project: loupe
    prompt: Created in {project}.
`, rules.Defaults{})
	sent := h.reports(t)

	h.send(`{"type":"board.card_created","subject":{"type":"card","id":"` + testCard + `"},"projectId":"` + testProject + `","actor":"human"}`)

	select {
	case got := <-sent:
		t.Fatalf("reported a run with no card number: %+v", got)
	default:
	}
	skipped := h.only(t, "report_skipped")
	if str(t, skipped, "subject") != testCard || str(t, skipped, "rule") != "created" {
		t.Fatalf("report_skipped = %v", skipped)
	}
}
