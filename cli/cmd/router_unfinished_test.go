package cmd

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

var (
	finishedRun   = workerResult{hasResult: true, status: "finished", output: "done"}
	unfinishedRun = workerResult{hasResult: true, status: "unfinished", output: "CI still runs"}
)

// cardReads answers the card read with a fixed column or error. entered and
// release, when set, let a test hold a read.
type cardReads struct {
	mu      sync.Mutex
	calls   []string
	column  string
	err     error
	entered chan struct{}
	release chan struct{}
}

func (c *cardReads) read(_ context.Context, handle, cardID string) (string, error) {
	c.mu.Lock()
	c.calls = append(c.calls, handle+" "+cardID)
	c.mu.Unlock()
	if c.entered != nil {
		c.entered <- struct{}{}
	}
	if c.release != nil {
		<-c.release
	}

	return c.column, c.err
}

func (c *cardReads) recorded() []string {
	c.mu.Lock()
	defer c.mu.Unlock()

	return append([]string(nil), c.calls...)
}

// outcomeOf is the last state a run sent.
func outcomeOf(t *testing.T, sent []stateSent, runID string) api.RunStateReport {
	t.Helper()
	states := ofRun(sent, runID)
	if len(states) == 0 {
		t.Fatalf("run %s sent nothing", runID)
	}

	return states[len(states)-1].report
}

// runIDs lists the run ids in the order of their first report.
func runIDs(sent []stateSent) []string {
	var out []string
	seen := map[string]bool{}
	for _, s := range sent {
		if !seen[s.runID] {
			seen[s.runID] = true
			out = append(out, s.runID)
		}
	}

	return out
}

// Each way a run ends maps to one state, and only an unfinished run, a run
// with no result and a failed run are resumed. A non-zero exit wins over the
// status, as on the server.
func TestAnEndedRunIsClassified(t *testing.T) {
	for name, tc := range map[string]struct {
		result workerResult
		state  string
		calls  int
	}{
		"finished":           {finishedRun, api.RunSucceeded, 1},
		"blocked":            {workerResult{hasResult: true, status: "blocked", output: "needs a person"}, api.RunBlocked, 1},
		"killed":             {workerResult{exitCode: -1, killed: true}, api.RunFailed, 1},
		"not started":        {workerResult{err: errors.New("fork/exec claude: permission denied")}, api.RunNotStarted, 1},
		"unfinished":         {unfinishedRun, api.RunUnfinished, 2},
		"no result":          {workerResult{output: "cut off"}, api.RunNoResult, 2},
		"failed":             {workerResult{exitCode: 1, output: "boom"}, api.RunFailed, 2},
		"failed but blocked": {workerResult{exitCode: 1, hasResult: true, status: "blocked"}, api.RunFailed, 2},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarness(t)
			rec := h.states()
			h.worker.results = []workerResult{tc.result}
			h.worker.result = finishedRun

			h.send(cardMoved(87))

			if got := h.runs(); got != tc.calls {
				t.Fatalf("workers = %d, want %d", got, tc.calls)
			}
			sent := rec.states()
			if got := outcomeOf(t, sent, runIDs(sent)[0]); got.State != tc.state {
				t.Fatalf("outcome = %+v, want %s", got, tc.state)
			}
			if h.cardHeld(87) {
				t.Fatal("the card is still held")
			}
		})
	}
}

// A resume runs claude --resume on the session of the run it continues, with
// the fixed resume prompt, and reports its place in the series.
func TestAResumeContinuesTheSessionOfItsRun(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.worker.results = []workerResult{unfinishedRun}
	h.worker.result = finishedRun

	h.send(cardMoved(87))

	calls := h.worker.recorded()
	if len(calls) != 2 {
		t.Fatalf("workers = %+v", calls)
	}
	resume := calls[1]
	if !resume.resume || resume.sessionID != calls[0].sessionID || resume.dir != h.dir || resume.schema != calls[0].schema {
		t.Fatalf("resume = %+v, first = %+v", resume, calls[0])
	}
	if want := directive.RenderResumeUnfinished("status unfinished"); resume.prompt != want {
		t.Fatalf("prompt = %q, want %q", resume.prompt, want)
	}

	sent := rec.states()
	ids := runIDs(sent)
	if len(ids) != 2 {
		t.Fatalf("runs = %v", ids)
	}
	wantStates(t, ofRun(sent, ids[0]), api.RunQueued, api.RunRunning, api.RunUnfinished)
	wantStates(t, ofRun(sent, ids[1]), api.RunQueued, api.RunRunning, api.RunSucceeded)
	first, queued := ofRun(sent, ids[0])[0].report, ofRun(sent, ids[1])[0].report
	if first.CardColumn != "next" || first.Continues != "" || first.ResumeIndex != 0 {
		t.Fatalf("first queued = %+v", first)
	}
	if queued.Continues != ids[0] || queued.ResumeIndex != 1 || queued.ResumeCap != rules.DefaultMaxResumes || queued.CardColumn != "next" {
		t.Fatalf("resume queued = %+v", queued)
	}
	if ended := outcomeOf(t, sent, ids[0]); ended.ResultStatus != "unfinished" || ended.ResumeSkipped != "" {
		t.Fatalf("ended = %+v", ended)
	}

	line := h.only(t, "worker_resuming")
	if line["level"] != "WARN" || num(t, line, "resume") != 1 || num(t, line, "max_resumes") != 2 || str(t, line, "reason") != "status unfinished" {
		t.Fatalf("worker_resuming = %v", line)
	}
}

// A run that never finishes is resumed maxResumes times, and the last run of
// the series gives up.
func TestAResumeSeriesGivesUpAtItsCap(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.worker.result = unfinishedRun

	h.send(cardMoved(87))

	if got := h.runs(); got != 3 {
		t.Fatalf("workers = %d, want 3", got)
	}
	sent := rec.states()
	ids := runIDs(sent)
	for i, want := range []string{api.RunUnfinished, api.RunUnfinished, api.RunGaveUp} {
		if got := outcomeOf(t, sent, ids[i]); got.State != want {
			t.Fatalf("run %d ended %+v, want %s", i, got, want)
		}
	}
	line := h.only(t, "worker_gave_up")
	if line["level"] != "ERROR" || num(t, line, "resume") != 2 || num(t, line, "max_resumes") != 2 {
		t.Fatalf("worker_gave_up = %v", line)
	}
	if h.cardHeld(87) {
		t.Fatal("the card is still held")
	}
}

func TestMaxResumesZeroGivesUpAtOnce(t *testing.T) {
	h := newHarnessWith(t, noResumeRules, rules.Defaults{})
	rec := h.states()
	h.worker.result = workerResult{output: "cut off"}

	h.send(cardMoved(87))

	if got := h.runs(); got != 1 {
		t.Fatalf("workers = %d, want 1", got)
	}
	sent := rec.states()
	if got := outcomeOf(t, sent, runIDs(sent)[0]); got.State != api.RunGaveUp || got.HasResult == nil || *got.HasResult {
		t.Fatalf("outcome = %+v", got)
	}
	h.only(t, "worker_gave_up")
}

// A failed run waits a minute before its resume, so a fault that repeats at
// once does not spend the series at once.
func TestAFailedRunWaitsBeforeItsResume(t *testing.T) {
	h := newHarness(t)
	waited := make(chan time.Duration, 1)
	fire := make(chan time.Time)
	h.router.after = func(d time.Duration) <-chan time.Time {
		waited <- d

		return fire
	}
	h.worker.results = []workerResult{{exitCode: 1, output: "boom"}}
	h.worker.result = finishedRun

	h.router.onData([]byte(cardMoved(87)))
	if d := <-waited; d != time.Minute {
		t.Fatalf("wait = %s, want 1m", d)
	}
	if got := h.runs(); got != 1 {
		t.Fatalf("workers = %d before the wait ends", got)
	}
	close(fire)
	h.router.wg.Wait()

	calls := h.worker.recorded()
	if len(calls) != 2 || calls[1].prompt != directive.RenderResumeUnfinished("exit code 1") {
		t.Fatalf("workers = %+v", calls)
	}
}

// An unfinished run does not wait.
func TestAnUnfinishedRunResumesWithNoWait(t *testing.T) {
	h := newHarness(t)
	h.router.after = func(time.Duration) <-chan time.Time {
		t.Error("an unfinished run waited")

		return now(0)
	}
	h.worker.results = []workerResult{unfinishedRun}
	h.worker.result = finishedRun

	h.send(cardMoved(87))

	if got := h.runs(); got != 2 {
		t.Fatalf("workers = %d", got)
	}
}

// A card a person moved on is not the stage the worker ran for, so the resume
// is skipped and the ended run says why.
func TestAResumeSkipsACardThatMoved(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	reads := &cardReads{column: "done"}
	h.router.readCard = reads.read
	h.worker.result = unfinishedRun

	h.send(cardMoved(87))

	if got := h.runs(); got != 1 {
		t.Fatalf("workers = %d, want 1", got)
	}
	if got := reads.recorded(); len(got) != 1 || got[0] != testProject+" "+cardUUID(87) {
		t.Fatalf("reads = %v", got)
	}
	sent := rec.states()
	if ids := runIDs(sent); len(ids) != 1 {
		t.Fatalf("runs = %v", ids)
	}
	if got := outcomeOf(t, sent, runIDs(sent)[0]); got.State != api.RunUnfinished || got.ResumeSkipped != "card_moved" {
		t.Fatalf("outcome = %+v", got)
	}
	if line := h.only(t, "resume_skipped"); str(t, line, "reason") != "card_moved" {
		t.Fatalf("resume_skipped = %v", line)
	}
	if h.cardHeld(87) {
		t.Fatal("the card is still held")
	}
}

func TestAResumeRunsWhenTheCardStays(t *testing.T) {
	h := newHarness(t)
	reads := &cardReads{column: "next"}
	h.router.readCard = reads.read
	h.worker.results = []workerResult{unfinishedRun}
	h.worker.result = finishedRun

	h.send(cardMoved(87))

	if got := h.runs(); got != 2 || len(reads.recorded()) != 1 {
		t.Fatalf("workers = %d, reads = %v", got, reads.recorded())
	}
}

// A card read that fails resumes anyway, so a fault never loses a resume.
func TestAFailedCardReadResumesAnyway(t *testing.T) {
	h := newHarness(t)
	h.router.readCard = (&cardReads{err: errors.New("card read failed (HTTP 404)")}).read
	h.worker.results = []workerResult{unfinishedRun}
	h.worker.result = finishedRun

	h.send(cardMoved(87))

	if got := h.runs(); got != 2 {
		t.Fatalf("workers = %d, want 2", got)
	}
	if line := h.only(t, "card_read_failed"); line["level"] != "WARN" {
		t.Fatalf("card_read_failed = %v", line)
	}
}

// An event of the card that arrives while its resume waits runs after the
// resume, and does not replace it.
func TestANewEventWaitsBehindAResume(t *testing.T) {
	h := newHarness(t)
	reads := &cardReads{column: "next", entered: make(chan struct{}), release: make(chan struct{})}
	h.router.readCard = reads.read
	h.worker.results = []workerResult{unfinishedRun}
	h.worker.result = finishedRun

	h.router.onData([]byte(cardMoved(87)))
	<-reads.entered
	h.router.onData([]byte(cardMoved(87)))
	close(reads.release)
	h.router.wg.Wait()

	calls := h.worker.recorded()
	if len(calls) != 3 || calls[0].resume || !calls[1].resume || calls[2].resume {
		t.Fatalf("workers = %+v", calls)
	}
}

// The coalesce test skips a queued resume, so a new event of the same card and
// rule queues behind it.
func TestANewEventDoesNotReplaceAQueuedResume(t *testing.T) {
	h := newHarness(t)
	e := event.Event{Type: event.CardMovedType, Subject: event.Subject{Type: "card", ID: cardUUID(87)}, ProjectID: testProject, CardNumber: 87, FromStatus: "backlog", ToStatus: "next", Actor: event.ActorHuman}
	h.router.mu.Lock()
	h.router.hold(cardUUID(87))
	h.router.active = h.router.maxWorkers
	h.router.seq = 1
	h.router.queue = []pending{{key: cardUUID(87), rule: "plan", event: e, continues: "0199a0e2-0000-4000-8000-000000000099", checked: true, seq: 1, runID: "r1"}}
	h.router.mu.Unlock()

	h.router.onData([]byte(cardMoved(87)))

	if got := h.queued(); len(got) != 2 {
		t.Fatalf("queue = %v", got)
	}
	h.router.mu.Lock()
	defer h.router.mu.Unlock()
	if h.router.queue[0].continues == "" || h.router.queue[1].continues != "" {
		t.Fatalf("queue = %+v", h.router.queue)
	}
}

// A resume matches its rule again first. A reload that removed the rule drops
// it with reason reload, and a kill with reason rule_dead.
func TestAResumeDropsWhenItsRuleEnds(t *testing.T) {
	rename := fmt.Sprintf(`{"type":"board.column_renamed","projectId":%q,"subject":{"type":"board_column","id":"0192f3a1-5555-7d3e-8f10-a2b3c4d5e6f7"},"actor":"human","fromSlug":"next","toSlug":"ready"}`, testProject)
	for name, tc := range map[string]struct {
		end    func(h *harness)
		reason string
	}{
		"reload": {func(h *harness) { h.reload(t, strings.Replace(defaultRules, "name: plan", "name: build", 1)) }, api.DropReload},
		"kill":   {func(h *harness) { h.router.onData([]byte(rename)) }, api.DropRuleDead},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarness(t)
			rec := h.states()
			reads := &cardReads{column: "next", entered: make(chan struct{}), release: make(chan struct{})}
			h.router.readCard = reads.read
			h.worker.result = unfinishedRun

			h.router.onData([]byte(cardMoved(87)))
			<-reads.entered
			tc.end(h)
			close(reads.release)
			h.router.wg.Wait()

			if got := h.runs(); got != 1 {
				t.Fatalf("workers = %d, want 1", got)
			}
			sent := rec.states()
			if got := outcomeOf(t, sent, runIDs(sent)[0]); got.ResumeSkipped != tc.reason {
				t.Fatalf("outcome = %+v, want resumeSkipped %s", got, tc.reason)
			}
			if line := h.only(t, "resume_skipped"); str(t, line, "reason") != tc.reason {
				t.Fatalf("resume_skipped = %v", line)
			}
			if h.cardHeld(87) {
				t.Fatal("the card is still held")
			}
		})
	}
}

// A shutdown wakes a resume that waits, and drops it.
func TestShutdownDropsAWaitingResume(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	waiting := make(chan struct{})
	h.router.after = func(time.Duration) <-chan time.Time {
		close(waiting)

		return nil
	}
	h.worker.result = workerResult{exitCode: 1, output: "boom"}

	h.router.onData([]byte(cardMoved(87)))
	<-waiting
	h.router.shutdown()
	h.router.wg.Wait()

	if got := h.runs(); got != 1 {
		t.Fatalf("workers = %d, want 1", got)
	}
	sent := rec.states()
	if got := outcomeOf(t, sent, runIDs(sent)[0]); got.State != api.RunFailed || got.ResumeSkipped != api.DropShutdown {
		t.Fatalf("outcome = %+v", got)
	}
}

// A resume is not an agent's event, so it neither counts toward the chain nor
// stops at its cap.
func TestAResumeSkipsTheChainCap(t *testing.T) {
	h := newHarnessWith(t, strings.Replace(defaultRules, "    to: next\n", "    to: next\n    maxChain: 1\n", 1), rules.Defaults{})
	h.worker.results = []workerResult{unfinishedRun}
	h.worker.result = finishedRun

	h.send(movedPayload(87, "backlog", "next", "agent"))

	if got := h.runs(); got != 2 {
		t.Fatalf("workers = %d, want 2", got)
	}
	h.router.mu.Lock()
	defer h.router.mu.Unlock()
	if got := h.router.chains[cardUUID(87)]["plan"]; got != 1 {
		t.Fatalf("chain = %d, want 1", got)
	}
}

// The bridge wires the card read to the server's route, by project id.
func TestTheBridgeReadsTheCardBeforeItResumes(t *testing.T) {
	fake := &fakeLoupe{cardColumn: "done", sse: "data: " + cardMoved(87) + "\n\n"}
	server := httptest.NewServer(http.HandlerFunc(fake.serve))
	t.Cleanup(server.Close)
	cfg := testLogin(server.URL)

	body := "projects:\n  loupe:\n    dir: " + t.TempDir() + "\nrules:\n  - name: plan\n    on: board.card_moved\n    project: loupe\n    to: next\n    prompt: go\n"
	set, err := rules.Parse([]byte(body), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	if err := set.Check(context.Background(), apiClient(cfg)); err != nil {
		t.Fatal(err)
	}
	log := &syncBuffer{}
	worker := &fakeWorker{result: unfinishedRun}
	r := withRules(&router{log: newBridgeLogger(log), maxWorkers: defaultMaxWorkers, worker: worker.ops(), bridgeID: testBridgeID}, set)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	cmd := &cobra.Command{}
	cmd.SetContext(ctx)
	done := make(chan error, 1)
	go func() { done <- subscribe(cmd, cfg, r) }()

	eventually(t, "the skipped resume", func() bool {
		return strings.Contains(log.String(), `"event":"resume_skipped"`)
	})
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}

	if calls := worker.recorded(); len(calls) != 1 {
		t.Fatalf("workers = %+v", calls)
	}
	fake.mu.Lock()
	defer fake.mu.Unlock()
	if len(fake.cardReads) != 1 || fake.cardReads[0] != "/api/projects/"+testProject+"/board/cards/"+cardUUID(87) {
		t.Fatalf("card reads = %v", fake.cardReads)
	}
}

// The result fields ride the outcome, unless their JSON passes the server's
// limit, when none are sent.
func TestTheOutcomeCarriesTheResultFields(t *testing.T) {
	for name, tc := range map[string]struct {
		fields  map[string]any
		want    bool
		dropped bool
	}{
		"small": {map[string]any{"prUrl": "https://example.test/pr/1"}, true, false},
		"large": {map[string]any{"blob": strings.Repeat("x", 4000)}, false, true},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarness(t)
			rec := h.states()
			h.worker.result = workerResult{hasResult: true, status: "finished", fields: tc.fields}

			h.send(cardMoved(87))

			sent := rec.states()
			got := outcomeOf(t, sent, runIDs(sent)[0])
			if got.ResultStatus != "finished" || (got.ResultFields != nil) != tc.want {
				t.Fatalf("outcome = %+v", got)
			}
			if lines := h.events(t, "result_fields_dropped"); (len(lines) == 1) != tc.dropped {
				t.Fatalf("result_fields_dropped = %v", lines)
			}
		})
	}
}
