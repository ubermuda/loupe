package cmd

import (
	"context"
	"errors"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/rules"
	"github.com/ubermuda/loupe/cli/internal/transcript"
)

// endedRunKey is the key of a run of card 87 that ended before the resume.
const endedRunKey = "0199a0e2-0000-7c5e-9f2a-00000000e0d0"

// resumeOf is a person's resume of the run.
func resumeOf(runKey string) api.Command {
	c := testCommand(testCommandID, api.CommandResumeRun, testBridgeID, soon())
	c.RunKey, c.SessionID = runKey, testSession

	return c
}

// transcripts makes the transcript of every session present, or none.
func (h *harness) transcripts(present bool) {
	h.router.findTranscript = func(string) error {
		if present {
			return nil
		}

		return transcript.ErrNotFound
	}
}

func (h *harness) resume(c api.Command) (string, string) {
	state, reason := h.router.resumeRun(c)
	h.router.wg.Wait()

	return state, reason
}

// Each check that fails refuses the resume with a reason a person can read,
// and starts nothing. The rules of this harness run no work kind, so no entry
// matches the run of a resume.
func TestAPersonsResumeIsRefusedWhenACheckFails(t *testing.T) {
	for name, tc := range map[string]struct {
		change func(t *testing.T, h *harness, c *api.Command)
		reason string
	}{
		"no session": {change: func(_ *testing.T, _ *harness, c *api.Command) { c.SessionID = "" }, reason: noSession},
		"a failed card read": {change: func(_ *testing.T, h *harness, _ *api.Command) {
			h.router.readCard = (&cardReads{err: errors.New("card read failed (HTTP 502)")}).read
		}, reason: "The bridge could not read the card: card read failed (HTTP 502)"},
		"no transcript":   {change: func(_ *testing.T, h *harness, _ *api.Command) { h.transcripts(false) }, reason: noTranscript},
		"a handover":      {change: func(_ *testing.T, h *harness, _ *api.Command) { h.router.freeze() }, reason: handingOver},
		"a shut bridge":   {change: func(_ *testing.T, h *harness, _ *api.Command) { h.router.shutdown() }, reason: bridgeShutting},
		"a work run":      {change: func(_ *testing.T, _ *harness, _ *api.Command) {}, reason: noWorkerRule},
		"a run of a rule": {change: func(_ *testing.T, _ *harness, c *api.Command) { c.WorkKind = "" }, reason: noWorkerRule},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarnessWith(t, defaultRules, rules.Defaults{})
			h.transcripts(true)
			h.router.readCard = (&cardReads{column: "next"}).read
			c := resumeOf(endedRunKey)
			tc.change(t, h, &c)
			before := h.runs()

			state, reason := h.resume(c)

			if state != api.CommandRefused || reason != tc.reason {
				t.Fatalf("resume = %s %q, want refused %q", state, reason, tc.reason)
			}
			if h.runs() != before {
				t.Fatalf("workers = %d after a refused resume", h.runs())
			}
		})
	}
}

// A handover waits for a command handler, because the frozen state could not
// name the run a resume is about to queue.
func TestDrainWaitsForACommandHandler(t *testing.T) {
	h := newHarness(t)
	h.withAcks()
	h.transcripts(true)
	reads := &cardReads{column: "next", entered: make(chan struct{}, 1), release: make(chan struct{})}
	h.router.readCard = reads.read

	h.router.onHeartbeatReply(api.HeartbeatReply{Commands: []api.Command{resumeOf(endedRunKey)}})
	<-reads.entered
	err := h.router.drain(context.Background(), 50*time.Millisecond)
	if err == nil || !strings.Contains(err.Error(), "1 commands") {
		t.Fatalf("drain = %v, want the command named", err)
	}
	close(reads.release)
	h.router.wg.Wait()
	if err := h.router.drain(context.Background(), 50*time.Millisecond); err != nil {
		t.Fatalf("drain after the command = %v", err)
	}
}

// planWorkRules runs the plan kind as a worker and the teardown kind as a
// command.
const planWorkRules = `
projects:
  loupe:
    dir: {dir}
work:
  plan:
    prompt: Plan card {cardNumber}.
  teardown:
    action: command
    run: [teardown, '{cardNumber}', '{workRequestId}']
    timeout: 1m
`

// workResumeOf is a person's resume of a run of the plan work.
func workResumeOf(runKey string) api.Command {
	c := resumeOf(runKey)
	c.WorkRequestID, c.RuleID = workID(1), "plan-on-next"

	return c
}

// newWorkHarness is a harness whose work map runs the kinds of planWorkRules.
func newWorkHarness(t *testing.T) *harness {
	t.Helper()
	h := newHarnessWith(t, planWorkRules, rules.Defaults{})
	h.transcripts(true)

	return h
}

// A resume of a work run whose kind the map runs with no worker is refused,
// and so is a resume of a run that is still open or that resumes already.
func TestAPersonsResumeOfAWorkRunIsRefusedWhenACheckFails(t *testing.T) {
	for name, tc := range map[string]struct {
		change func(t *testing.T, h *harness, c *api.Command)
		reason string
	}{
		"an unknown kind": {change: func(_ *testing.T, _ *harness, c *api.Command) { c.WorkKind = "gone" }, reason: noWorkerRule},
		"a command kind":  {change: func(_ *testing.T, _ *harness, c *api.Command) { c.WorkKind = "teardown" }, reason: noWorkerRule},
		"another project": {change: func(_ *testing.T, _ *harness, c *api.Command) { c.ProjectID = cardUUID(1) }, reason: noWorkerRule},
		"an open run": {change: func(_ *testing.T, h *harness, _ *api.Command) {
			h.router.held = map[string]api.InventoryRun{endedRunKey: {RunID: endedRunKey}}
		}, reason: runOpen},
		"a queued resume": {change: func(t *testing.T, h *harness, c *api.Command) {
			h.reply(pausedReply(true))
			if state, reason := h.resume(*c); state != api.CommandDone {
				t.Fatalf("first resume = %s %q", state, reason)
			}
		}, reason: resumingAlready},
	} {
		t.Run(name, func(t *testing.T) {
			h := newWorkHarness(t)
			c := workResumeOf(endedRunKey)
			tc.change(t, h, &c)
			before := h.runs()

			state, reason := h.resume(c)

			if state != api.CommandRefused || reason != tc.reason {
				t.Fatalf("resume = %s %q, want refused %q", state, reason, tc.reason)
			}
			if h.runs() != before {
				t.Fatalf("workers = %d after a refused resume", h.runs())
			}
		})
	}
}

// A person's resume continues the session of the run with the fixed prompt,
// in the folder and the settings of the work entry of its kind. Its reports
// continue the run and name its work, and it claims no work request. The card
// may be held, because a person's resume ends the hold on the server.
func TestAPersonsResumeContinuesTheSessionOfTheRun(t *testing.T) {
	h := newWorkHarness(t)
	work := h.withWork()
	rec := h.states()
	reads := &cardReads{column: "next"}
	h.router.readCard = reads.read
	h.worker.result = finishedRun

	if state, reason := h.resume(workResumeOf(endedRunKey)); state != api.CommandDone {
		t.Fatalf("resume = %s %q", state, reason)
	}

	if got := reads.recorded(); !slices.Equal(got, []string{testProject + " " + cardUUID(87)}) {
		t.Fatalf("card reads = %v", got)
	}
	calls := h.worker.recorded()
	if len(calls) != 1 {
		t.Fatalf("workers = %+v", calls)
	}
	spec := calls[0]
	if !spec.resume || spec.sessionID != testSession || spec.prompt != directive.RenderResumeByPerson() || spec.rule != rules.WorkRulePrefix+"plan" || spec.dir != h.dir {
		t.Fatalf("spec = %+v", spec)
	}
	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunSucceeded)
	queued := sent[0].report
	if sent[0].runID == endedRunKey || queued.Continues != endedRunKey {
		t.Fatalf("queued = %+v", queued)
	}
	for _, s := range sent {
		r := s.report
		if r.WorkKind != "plan" || r.WorkRequestID != workID(1) || r.RuleID != "plan-on-next" || r.CardNumber != 87 || r.CardID != cardUUID(87) {
			t.Fatalf("%s report = %+v", r.State, r)
		}
	}
	if queued.WorkerPool != rules.DefaultPool {
		t.Fatalf("queued = %+v", queued)
	}
	if got := work.claimed(); len(got) != 0 {
		t.Fatalf("claims = %v, want none", got)
	}
	h.router.mu.Lock()
	session := h.router.sessions[testSession]
	h.router.mu.Unlock()
	if session.key != cardUUID(87) || session.number != 87 {
		t.Fatalf("session = %+v", session)
	}
}

// The server decides each resume, so a resumed run that ends unfinished ends
// there.
func TestAPersonsResumeNeverResumesOnItsOwn(t *testing.T) {
	h := newWorkHarness(t)
	rec := h.states()
	h.worker.results = []workerResult{unfinishedRun}
	h.worker.result = finishedRun

	if state, _ := h.resume(workResumeOf(endedRunKey)); state != api.CommandDone {
		t.Fatalf("resume = %s", state)
	}
	h.router.wg.Wait()

	if h.runs() != 1 {
		t.Fatalf("workers = %d, want the resume alone", h.runs())
	}
	wantStates(t, rec.states(), api.RunQueued, api.RunRunning, api.RunUnfinished)
}

// A paused bridge keeps the resume queued, and the unpause starts it.
func TestAPausedBridgeKeepsAPersonsResumeQueued(t *testing.T) {
	h := newWorkHarness(t)
	rec := h.states()
	h.reply(pausedReply(true))

	if state, _ := h.resume(workResumeOf(endedRunKey)); state != api.CommandDone {
		t.Fatalf("resume = %s", state)
	}
	if h.runs() != 0 {
		t.Fatalf("workers = %d while paused", h.runs())
	}
	wantStates(t, rec.states(), api.RunQueued)

	h.reply(pausedReply(false))
	h.router.wg.Wait()
	if h.runs() != 1 {
		t.Fatalf("workers = %d after the unpause", h.runs())
	}
}

// A reload keeps a queued resume while the map still runs its kind, and a
// handover carries the work it continues.
func TestAQueuedResumeKeepsItsWorkAcrossAReloadAndAHandover(t *testing.T) {
	h := newWorkHarness(t)
	h.reply(pausedReply(true))
	if state, _ := h.resume(workResumeOf(endedRunKey)); state != api.CommandDone {
		t.Fatalf("resume = %s", state)
	}

	h.router.mu.Lock()
	dropped := h.router.rewriteLocked(h.router.rules())
	h.router.mu.Unlock()
	if len(dropped) != 0 {
		t.Fatalf("reload dropped %+v", dropped)
	}
	st := h.router.freeze()
	if len(st.Queue) != 1 || st.Queue[0].Origin == nil || st.Queue[0].Origin.Kind != "plan" || st.Queue[0].Origin.WorkRequestID != workID(1) {
		t.Fatalf("queue = %+v", st.Queue)
	}

	h2 := newWorkHarness(t)
	h2.router.adopt(roundTrip(t, st))
	h2.router.mu.Lock()
	queue := slices.Clone(h2.router.queue)
	h2.router.mu.Unlock()
	if len(queue) != 1 || !queue[0].followsWork() || queue[0].continues != endedRunKey || !queue[0].spec.resume || queue[0].spec.sessionID != testSession {
		t.Fatalf("adopted queue = %+v", queue)
	}
}

// A resume of a held card passes, and its run waits in the queue until the
// hold ends. The resume keeps the hold.
func TestAPersonsResumeOfAHeldCardWaitsForTheHold(t *testing.T) {
	h := newWorkHarness(t)
	h.withHoldList()
	rec := h.states()
	h.router.mu.Lock()
	h.router.holdCardLocked(cardUUID(87))
	h.router.mu.Unlock()

	if state, _ := h.resume(workResumeOf(endedRunKey)); state != api.CommandDone {
		t.Fatalf("resume = %s", state)
	}

	h.router.mu.Lock()
	held := h.router.cardHolds[cardUUID(87)]
	h.router.mu.Unlock()
	if !held || h.runs() != 0 {
		t.Fatalf("held = %v, workers = %d", held, h.runs())
	}
	wantStates(t, rec.states(), api.RunQueued)
	if got := h.events(t, "card_hold_released"); len(got) != 0 {
		t.Fatalf("released = %v", got)
	}

	h.router.dropHold(cardUUID(87))
	h.router.dispatch()
	h.router.wg.Wait()
	if h.runs() != 1 {
		t.Fatalf("workers = %d after the hold ends", h.runs())
	}
}

// With no held list read, an older server ends the hold on a resume and says
// nothing, so the resume ends the hold of this bridge and starts.
func TestWithNoHeldListAResumeEndsTheHold(t *testing.T) {
	h := newWorkHarness(t)
	h.states()
	h.holdCard(87)

	if state, _ := h.resume(workResumeOf(endedRunKey)); state != api.CommandDone {
		t.Fatalf("resume = %s", state)
	}

	h.router.wg.Wait()
	if h.cardHoldOf(87) || h.runs() != 1 {
		t.Fatalf("held = %v, workers = %d", h.cardHoldOf(87), h.runs())
	}
	h.only(t, "card_hold_released")
}

// A resume waits for another worker of the card, so one card runs one worker.
func TestAPersonsResumeWaitsForTheWorkerOfItsCard(t *testing.T) {
	h := newWorkHarness(t)
	work := h.withWork()
	h.states()
	release := h.blocked()
	h.worker.result = finishedRun

	w := workRequest(2, 87, "plan", api.WorkRequestOpen)
	work.requests[w.WorkRequestID] = w
	h.router.onData([]byte(workPayload(w)))
	<-h.worker.started
	if state, _ := h.router.resumeRun(workResumeOf(endedRunKey)); state != api.CommandDone {
		t.Fatalf("resume = %s", state)
	}
	if h.runs() != 1 {
		t.Fatalf("workers = %d while the card runs", h.runs())
	}

	close(release)
	h.router.wg.Wait()
	calls := h.worker.recorded()
	if len(calls) != 2 || calls[1].sessionID != testSession || !calls[1].resume {
		t.Fatalf("workers = %+v", calls)
	}
}
