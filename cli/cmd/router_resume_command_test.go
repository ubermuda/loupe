package cmd

import (
	"cmp"
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

// resumeOf is a person's resume of the run, which was the third of its series.
func resumeOf(runKey string) api.Command {
	c := testCommand(testCommandID, api.CommandResumeRun, testBridgeID, soon())
	c.RunKey, c.SessionID = runKey, testSession
	index := 2
	c.ResumeIndex = &index

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
// and starts nothing.
func TestAPersonsResumeIsRefusedWhenACheckFails(t *testing.T) {
	for name, tc := range map[string]struct {
		body   string
		change func(t *testing.T, h *harness, c *api.Command)
		reason string
	}{
		"no session":          {change: func(_ *testing.T, _ *harness, c *api.Command) { c.SessionID = "" }, reason: noSession},
		"no rule":             {change: func(_ *testing.T, _ *harness, c *api.Command) { c.RuleName = "gone" }, reason: noWorkerRule},
		"an interactive rule": {body: strings.Replace(launchRules, "{launcher}", `["open", "{script}"]`, 1), change: func(_ *testing.T, _ *harness, c *api.Command) { c.RuleName = "design" }, reason: noWorkerRule},
		"a card that moved": {change: func(_ *testing.T, h *harness, _ *api.Command) {
			h.router.readCard = (&cardReads{column: "done"}).read
		}, reason: cardMovedAway},
		"a failed card read": {change: func(_ *testing.T, h *harness, _ *api.Command) {
			h.router.readCard = (&cardReads{err: errors.New("card read failed (HTTP 502)")}).read
		}, reason: "The bridge could not read the card: card read failed (HTTP 502)"},
		"no transcript": {change: func(_ *testing.T, h *harness, _ *api.Command) { h.transcripts(false) }, reason: noTranscript},
		"a handover":    {change: func(_ *testing.T, h *harness, _ *api.Command) { h.router.freeze() }, reason: handingOver},
		"a shut bridge": {change: func(_ *testing.T, h *harness, _ *api.Command) { h.router.shutdown() }, reason: bridgeShutting},
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
			h := newHarnessWith(t, cmp.Or(tc.body, defaultRules), rules.Defaults{})
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

// A person's resume continues the session of the run with the fixed prompt.
// Its queued report names the command as its trigger, continues the run, and
// starts a fresh count of automatic resumes. The card may be held, because a
// person's resume ends the hold on the server.
func TestAPersonsResumeContinuesTheSessionOfTheRun(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.transcripts(true)
	reads := &cardReads{column: "next"}
	h.router.readCard = reads.read
	h.worker.result = finishedRun

	if state, reason := h.resume(resumeOf(endedRunKey)); state != api.CommandDone {
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
	if !spec.resume || spec.sessionID != testSession || spec.prompt != directive.RenderResumeByPerson() || spec.rule != "plan" {
		t.Fatalf("spec = %+v", spec)
	}
	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunSucceeded)
	queued := sent[0].report
	if sent[0].runID == endedRunKey || queued.Continues != endedRunKey || queued.ResumeIndex != 3 || queued.ResumeCap != 3+rules.DefaultMaxResumes {
		t.Fatalf("queued = %+v", queued)
	}
	if queued.Trigger == nil || *queued.Trigger != (api.RunTrigger{EventType: "bridge.command"}) {
		t.Fatalf("trigger = %+v", queued.Trigger)
	}
	if queued.CardColumn != "next" || queued.WorkerPool != rules.DefaultPool || queued.CardNumber != 87 || queued.CardID != cardUUID(87) || queued.RuleName != "plan" {
		t.Fatalf("queued = %+v", queued)
	}
	h.router.mu.Lock()
	session := h.router.sessions[testSession]
	h.router.mu.Unlock()
	if session.key != cardUUID(87) || session.number != 87 || session.column != "next" {
		t.Fatalf("session = %+v", session)
	}
}

// The resume starts a fresh count of automatic resumes, so a run it starts
// that ends unfinished resumes again.
func TestAPersonsResumeStartsAFreshCountOfResumes(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.transcripts(true)
	h.worker.results = []workerResult{unfinishedRun}
	h.worker.result = finishedRun

	if state, _ := h.resume(resumeOf(endedRunKey)); state != api.CommandDone {
		t.Fatalf("resume = %s", state)
	}
	h.router.wg.Wait()

	if h.runs() != 2 {
		t.Fatalf("workers = %d, want the resume and one automatic resume", h.runs())
	}
	sent := rec.states()
	ids := runIDs(sent)
	next := ofRun(sent, ids[1])[0].report
	if next.Continues != ids[0] || next.ResumeIndex != 4 || next.ResumeCap != 3+rules.DefaultMaxResumes {
		t.Fatalf("automatic resume = %+v", next)
	}
}

// A paused bridge keeps the resume queued, and the unpause starts it.
func TestAPausedBridgeKeepsAPersonsResumeQueued(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.transcripts(true)
	h.reply(pausedReply(true))

	if state, _ := h.resume(resumeOf(endedRunKey)); state != api.CommandDone {
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

// A resume of a held card passes, and its run waits in the queue until the
// hold ends. The resume keeps the hold.
func TestAPersonsResumeOfAHeldCardWaitsForTheHold(t *testing.T) {
	h := newHarness(t)
	h.withHoldList()
	rec := h.states()
	h.transcripts(true)
	h.router.readCard = (&cardReads{column: "next"}).read
	h.router.mu.Lock()
	h.router.holdCardLocked(cardUUID(87))
	h.router.mu.Unlock()

	if state, _ := h.resume(resumeOf(endedRunKey)); state != api.CommandDone {
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
	h := newHarness(t)
	h.states()
	h.transcripts(true)
	h.router.readCard = (&cardReads{column: "next"}).read
	h.holdCard(87)

	if state, _ := h.resume(resumeOf(endedRunKey)); state != api.CommandDone {
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
	h := newHarness(t)
	h.states()
	h.transcripts(true)
	release := h.blocked()
	h.worker.result = finishedRun

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	if state, _ := h.router.resumeRun(resumeOf(endedRunKey)); state != api.CommandDone {
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

// A run of a pull request event records no column, so the card may sit in any
// column when a person resumes it.
func TestAPersonsResumeOfARunWithNoColumnReadsNoMove(t *testing.T) {
	h := newHarness(t)
	h.transcripts(true)
	h.router.readCard = (&cardReads{column: "done"}).read
	c := resumeOf(endedRunKey)
	c.CardColumn = ""

	if state, reason := h.resume(c); state != api.CommandDone {
		t.Fatalf("resume = %s %q, want done", state, reason)
	}
}
