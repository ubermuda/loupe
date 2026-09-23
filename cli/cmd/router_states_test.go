package cmd

import (
	"context"
	"errors"
	"slices"
	"strings"
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
	mu    sync.Mutex
	sent  []any
	posts int
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

func (s *stateRecorder) ReportWorkerRun(context.Context, string, api.WorkerRun) (bool, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.posts++

	return true, nil
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

// names is the state of each report sent so far, and "inventory" for a run
// inventory.
func (s *stateRecorder) names() []string {
	s.mu.Lock()
	defer s.mu.Unlock()

	out := make([]string, len(s.sent))
	for i, v := range s.sent {
		if st, ok := v.(stateSent); ok {
			out[i] = st.report.State
		} else {
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
		if r.BridgeID != testBridgeID || r.CardID != cardUUID(87) || r.CardNumber != 87 || r.RuleName != "plan" || r.At.IsZero() {
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
	if rec.posts != 0 {
		t.Fatalf("posted %d old reports to a server with run states", rec.posts)
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
			h.worker.result = tc.result

			h.send(cardMoved(87))

			sent := rec.states()
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

// An event that replaces a waiting one closes the waiting run as replaced, and
// names the run that takes its place.
func TestACoalescedEventReplacesTheWaitingRun(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(87)))
	h.router.onData([]byte(cardMoved(87)))

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunQueued, api.RunReplaced, api.RunQueued)
	waiting, replaced, next := sent[2], sent[3], sent[4]
	if replaced.runID != waiting.runID || replaced.report.ReplacedBy != next.runID {
		t.Fatalf("replaced = %+v, want run %q replaced by %q", replaced, waiting.runID, next.runID)
	}
	if next.runID == waiting.runID || next.runID == sent[0].runID {
		t.Fatalf("the new run reuses id %q", next.runID)
	}

	close(h.worker.block)
	h.router.wg.Wait()
	wantStates(t, ofRun(rec.states(), next.runID), api.RunQueued, api.RunRunning, api.RunSucceeded)
}

// A capped event waits for a person as a run of its own. It never queued.
func TestACappedEventWaitsForAPerson(t *testing.T) {
	h := newHarnessWith(t, chainRules, rules.Defaults{})
	rec := h.states()

	h.send(movedPayload(87, "backlog", "next", "agent"))
	h.send(movedPayload(87, "backlog", "next", "agent"))
	before := len(rec.states())
	h.send(movedPayload(87, "backlog", "next", "agent"))

	sent := rec.states()[before:]
	wantStates(t, sent, api.RunWaitingForPerson)
	if sent[0].report.MaxChain != 2 || sent[0].runID == "" {
		t.Fatalf("waiting-for-person = %+v", sent[0])
	}
	for _, s := range rec.states()[:before] {
		if s.runID == sent[0].runID {
			t.Fatalf("the capped run reuses id %q", s.runID)
		}
	}
}

// A queued resume that meets the cap when the queue releases it closes the run
// it queued as.
func TestAQueuedResumeThatMeetsTheCapWaitsForAPerson(t *testing.T) {
	h := newHarnessWith(t, defaultRules+`
  - name: resume
    on: inbox.ask_closed
    project: loupe
    resume: true
    maxChain: 1
    prompt: Ask {askId} closed.
`, rules.Defaults{})
	rec := h.states()
	h.worker.started = make(chan workerSpec, 3)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(h.mine(ask{card: 87, actor: "agent"})))
	h.router.onData([]byte(h.mine(ask{id: otherAsk, card: 87, actor: "agent"})))
	capped := rec.states()[3]
	close(h.worker.block)
	h.router.wg.Wait()

	wantStates(t, ofRun(rec.states(), capped.runID), api.RunQueued, api.RunWaitingForPerson)
	last := ofRun(rec.states(), capped.runID)[1].report
	if last.MaxChain != 1 {
		t.Fatalf("waiting-for-person = %+v", last)
	}
}

// A resume the ask check lets through reports resumed with its ask, then runs.
// A failed check resumes too.
func TestACheckedResumeReportsResumed(t *testing.T) {
	for name, c := range map[string]*checks{
		"not all read": {state: api.AskState{AskID: testAsk, Closed: true, AllRead: false}},
		"check failed": {err: errors.New("ask check failed (HTTP 500)")},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarnessWith(t, resumeRules, rules.Defaults{})
			rec := h.states()
			h.router.checkAsk = c.check

			h.send(h.mine(ask{card: 87}))

			sent := rec.states()
			wantStates(t, sent, api.RunQueued, api.RunResumed, api.RunRunning, api.RunSucceeded)
			if sent[1].report.AskID != testAsk || sent[1].runID != sent[0].runID {
				t.Fatalf("resumed = %+v", sent[1])
			}
			if sent[2].report.SessionID != askSession {
				t.Fatalf("running = %+v, want the session of the ask", sent[2].report)
			}
		})
	}
}

// A run that replaces a checked resume inherits its check, so it reports
// resumed as it queues.
func TestARunThatReplacesACheckedResumeReportsResumed(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	rec := h.states()
	h.router.maxWorkers = 1
	h.router.checkAsk = (&checks{state: api.AskState{AskID: testAsk, Closed: true, AllRead: false}}).check
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(88)))
	<-h.worker.started
	h.router.onData([]byte(h.mine(ask{card: 87})))
	eventually(t, "the checked resume in the queue", func() bool {
		h.router.mu.Lock()
		defer h.router.mu.Unlock()

		return len(h.router.queue) == 1 && h.router.queue[0].checked
	})
	h.router.onData([]byte(h.mine(ask{card: 87})))
	close(h.worker.block)
	h.router.wg.Wait()

	next := rec.states()[5]
	wantStates(t, rec.states()[2:6], api.RunQueued, api.RunResumed, api.RunReplaced, api.RunQueued)
	wantStates(t, ofRun(rec.states(), next.runID), api.RunQueued, api.RunResumed, api.RunRunning, api.RunSucceeded)
}

// With no ask check, a resume reports resumed as it starts.
func TestAnUncheckedResumeReportsResumedAsItStarts(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	rec := h.states()

	h.send(h.mine(ask{card: 87}))

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunResumed, api.RunRunning, api.RunSucceeded)
	if sent[1].report.AskID != testAsk {
		t.Fatalf("resumed = %+v", sent[1].report)
	}
}

// A resume whose session read every item of its ask closes as skipped.
func TestASkippedResumeReportsSkipped(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	rec := h.states()
	h.router.checkAsk = (&checks{state: api.AskState{AskID: testAsk, Closed: true, AllRead: true}}).check

	h.send(h.mine(ask{card: 87}))

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunSkipped)
	if sent[1].runID != sent[0].runID {
		t.Fatalf("skipped names run %q, want %q", sent[1].runID, sent[0].runID)
	}
}

// Each run a shutdown drops closes as dropped, for the shutdown.
func TestAShutdownDropsEachWaitingRun(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.router.maxWorkers = 1
	h.worker.started = make(chan workerSpec, 1)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(88)))
	h.router.shutdown()
	close(h.worker.block)
	h.router.wg.Wait()

	queued := rec.states()[2]
	wantStates(t, ofRun(rec.states(), queued.runID), api.RunQueued, api.RunDropped)
	if got := ofRun(rec.states(), queued.runID)[1].report; got.Reason != "shutdown" || got.CardNumber != 88 {
		t.Fatalf("dropped = %+v", got)
	}
}

// A run whose rule dies in the queue closes as dropped, for the dead rule.
func TestADeadRuleDropsItsWaitingRun(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.worker.started = make(chan workerSpec, 1)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(87)))
	h.router.onData([]byte(projectRenamed()))
	close(h.worker.block)
	h.router.wg.Wait()

	queued := rec.states()[2]
	wantStates(t, ofRun(rec.states(), queued.runID), api.RunQueued, api.RunDropped)
	if got := ofRun(rec.states(), queued.runID)[1].report; got.Reason != "rule_dead" {
		t.Fatalf("dropped = %+v", got)
	}
}

// A resume out of the queue for its check is dropped for whichever stopped it.
func TestAResumeDroppedAfterItsCheckNamesTheCause(t *testing.T) {
	for name, stop := range map[string]func(h *harness){
		"shutdown":  func(h *harness) { h.router.shutdown() },
		"rule_dead": func(h *harness) { h.router.onData([]byte(projectRenamed())) },
		"reload":    func(h *harness) { h.router.reload(context.Background(), h.source(defaultRules)) },
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarnessWith(t, resumeRules, rules.Defaults{})
			rec := h.states()
			g := newGate()
			h.router.checkAsk = g.check

			h.router.onData([]byte(h.mine(ask{card: 87})))
			<-g.entered
			stop(h)
			close(g.release)
			h.router.wg.Wait()

			sent := rec.states()
			wantStates(t, sent, api.RunQueued, api.RunDropped)
			if sent[1].report.Reason != name {
				t.Fatalf("dropped = %+v, want reason %s", sent[1].report, name)
			}
		})
	}
}

// A reload that drops a waiting run closes it as dropped, for the reload, and
// the next inventory no longer lists it. A run the reload keeps keeps its id.
func TestAReloadDropsTheRunsItNoLongerRuns(t *testing.T) {
	h := newHarnessWith(t, twoRuleFile, rules.Defaults{})
	rec := h.states()
	h.router.maxWorkers = 1
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(88)))
	h.router.onData([]byte(movedPayload(89, "backlog", "review", "human")))
	plan, review := rec.states()[2], rec.states()[3]

	// plan takes another name, so its waiting run drops, and review stays.
	res := h.reload(t, strings.Replace(twoRuleFile, "  - name: plan", "  - name: gone", 1))
	if !res.OK {
		t.Fatalf("result = %+v", res)
	}
	wantStates(t, ofRun(rec.states(), plan.runID), api.RunQueued, api.RunDropped)
	if got := ofRun(rec.states(), plan.runID)[1].report; got.Reason != api.DropReload || got.CardNumber != 88 {
		t.Fatalf("dropped = %+v", got)
	}
	h.router.handler().OnConnect()
	inv := rec.inventories()
	if len(inv) != 1 || len(inv[0]) != 2 || slices.ContainsFunc(inv[0], func(r api.InventoryRun) bool { return r.RunID == plan.runID }) {
		t.Fatalf("inventory = %+v, want the running run and the kept run alone", inv)
	}

	close(h.worker.block)
	h.router.wg.Wait()
	wantStates(t, ofRun(rec.states(), review.runID), api.RunQueued, api.RunRunning, api.RunSucceeded)
}

// An event with no card sends no state, and logs the skip once.
func TestARunWithNoCardSendsNoState(t *testing.T) {
	h := newHarnessWith(t, defaultRules+`
  - name: created
    on: board.card_created
    project: loupe
    prompt: Created in {project}.
`, rules.Defaults{})
	rec := h.states()

	h.send(`{"type":"board.card_created","subject":{"type":"card","id":"` + testCard + `"},"projectId":"` + testProject + `","actor":"human"}`)

	if got := rec.names(); len(got) != 0 {
		t.Fatalf("sent %v for a run with no card", got)
	}
	h.only(t, "report_skipped")
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
	h := newHarness(t)
	rec := h.states()
	h.router.maxWorkers = 1
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})

	h.router.handler().OnConnect()
	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(88)))
	h.router.handler().OnConnect()

	sent := rec.states()
	want := []api.InventoryRun{
		{RunID: sent[0].runID, ProjectID: testProject, State: api.RunRunning},
		{RunID: sent[2].runID, ProjectID: testProject, State: api.RunQueued},
	}
	slices.SortFunc(want, func(a, b api.InventoryRun) int { return strings.Compare(a.RunID, b.RunID) })
	inv := rec.inventories()
	if len(inv) != 2 || len(inv[0]) != 0 || !slices.Equal(inv[1], want) {
		t.Fatalf("inventories = %+v, want none held, then %+v", inv, want)
	}
	if got := rec.names(); !slices.Equal(got, []string{"inventory", api.RunQueued, api.RunRunning, api.RunQueued, "inventory"}) {
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
