package cmd

import (
	"slices"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// failedCommandKey is the key of a command run of card 87 that failed.
const failedCommandKey = "0199a0e2-0000-7c5e-9f2a-00000000fa11"

// rerunOf is a person's rerun of the failed run of the teardown work.
func rerunOf(runKey string) api.Command {
	c := testCommand(testCommandID, api.CommandRerunCommand, testBridgeID, soon())
	c.RunKey, c.WorkKind = runKey, "teardown"

	return c
}

func (h *harness) rerun(c api.Command) (string, string) {
	state, reason := h.router.rerunCommand(c)
	h.router.wg.Wait()

	return state, reason
}

// Each check that fails refuses the rerun with a reason a person can read,
// and runs nothing.
func TestARerunIsRefusedWhenACheckFails(t *testing.T) {
	for name, tc := range map[string]struct {
		change func(t *testing.T, h *harness, f *fakeCommand, c *api.Command)
		reason string
	}{
		"an unknown kind": {func(_ *testing.T, _ *harness, _ *fakeCommand, c *api.Command) { c.WorkKind = "unknown" }, noCommandRule},
		"a run of a rule": {func(_ *testing.T, _ *harness, _ *fakeCommand, c *api.Command) { c.WorkKind = "" }, noCommandRule},
		"a handover":      {func(_ *testing.T, h *harness, _ *fakeCommand, _ *api.Command) { h.router.freeze() }, handingOver},
		"a shut bridge":   {func(_ *testing.T, h *harness, _ *fakeCommand, _ *api.Command) { h.router.shutdown() }, bridgeShutting},
	} {
		t.Run(name, func(t *testing.T) {
			h, f := withCommand(t, "1m")
			c := rerunOf(failedCommandKey)
			tc.change(t, h, f, &c)

			state, reason := h.rerun(c)

			if state != api.CommandRefused || reason != tc.reason {
				t.Fatalf("rerun = %s %q, want refused %q", state, reason, tc.reason)
			}
			if len(f.recorded()) != 0 || h.runs() != 0 {
				t.Fatalf("commands = %d, workers = %d after a refused rerun", len(f.recorded()), h.runs())
			}
		})
	}
}

// workRerunOf is a person's rerun of the failed run of the teardown work.
func workRerunOf(runKey string) api.Command {
	c := rerunOf(runKey)
	c.WorkRequestID, c.RuleID = workID(1), "teardown-on-done"

	return c
}

// withWorkCommand is a harness whose work map runs the kinds of
// planWorkRules, with a fake command.
func withWorkCommand(t *testing.T) (*harness, *fakeCommand) {
	t.Helper()
	h := newHarnessWith(t, planWorkRules, rules.Defaults{})
	f := &fakeCommand{}
	h.router.worker.command = f.run

	return h, f
}

// A rerun of a work run is refused when the map runs no command of its kind,
// when the card is busy, and when the command needs a value the run lacks.
func TestARerunOfAWorkRunIsRefusedWhenACheckFails(t *testing.T) {
	for name, tc := range map[string]struct {
		change func(t *testing.T, h *harness, c *api.Command)
		reason string
	}{
		"an unknown kind": {func(_ *testing.T, _ *harness, c *api.Command) { c.WorkKind = "gone" }, noCommandRule},
		"a worker kind":   {func(_ *testing.T, _ *harness, c *api.Command) { c.WorkKind = "plan" }, noCommandRule},
		"a live run of the card": {func(_ *testing.T, h *harness, _ *api.Command) {
			h.router.mu.Lock()
			h.router.hold(cardUUID(87))
			h.router.mu.Unlock()
		}, cardBusy},
		"a queued rerun": {func(t *testing.T, h *harness, c *api.Command) {
			h.reply(pausedReply(true))
			if state, reason := h.rerun(*c); state != api.CommandDone {
				t.Fatalf("first rerun = %s %q", state, reason)
			}
		}, cardBusy},
		"no work request": {func(_ *testing.T, _ *harness, c *api.Command) { c.WorkRequestID = "" }, needsEvent + " {workRequestId}"},
	} {
		t.Run(name, func(t *testing.T) {
			h, f := withWorkCommand(t)
			c := workRerunOf(failedCommandKey)
			tc.change(t, h, &c)

			state, reason := h.rerun(c)

			if state != api.CommandRefused || reason != tc.reason {
				t.Fatalf("rerun = %s %q, want refused %q", state, reason, tc.reason)
			}
			if len(f.recorded()) != 0 || h.runs() != 0 {
				t.Fatalf("commands = %d, workers = %d after a refused rerun", len(f.recorded()), h.runs())
			}
		})
	}
}

// A rerun runs the command of the work entry again as a new run that
// continues the failed one. Its reports name the work of the run, and it
// claims no work request.
func TestARerunRunsTheCommandAgain(t *testing.T) {
	h, f := withWorkCommand(t)
	work := h.withWork()
	rec := h.states()
	f.result = procResult{output: "removed"}

	if state, reason := h.rerun(workRerunOf(failedCommandKey)); state != api.CommandDone {
		t.Fatalf("rerun = %s %q", state, reason)
	}

	specs := f.recorded()
	if len(specs) != 1 || !slices.Equal(specs[0].argv, []string{"teardown", "87", workID(1)}) || specs[0].dir != h.dir {
		t.Fatalf("command = %+v", specs)
	}
	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunSucceeded)
	wantCommandReports(t, sent)
	queued := sent[0].report
	if sent[0].runID == failedCommandKey || queued.Continues != failedCommandKey || queued.WorkKind != "teardown" || queued.WorkRequestID != workID(1) || queued.RuleID != "teardown-on-done" {
		t.Fatalf("queued = %+v", queued)
	}
	if got := work.claimed(); len(got) != 0 {
		t.Fatalf("claims = %v, want none", got)
	}
	if h.cardHeld(87) || h.runs() != 0 {
		t.Fatalf("card held = %v, workers = %d", h.cardHeld(87), h.runs())
	}
}

// A failed rerun ends there, because the server decides what runs next.
func TestAFailedRerunEndsThere(t *testing.T) {
	h, f := withWorkCommand(t)
	rec := h.states()
	f.result = procResult{exitCode: 1, output: "still there"}

	if state, reason := h.rerun(workRerunOf(failedCommandKey)); state != api.CommandDone {
		t.Fatalf("rerun = %s %q", state, reason)
	}
	h.router.wg.Wait()

	if len(f.recorded()) != 1 {
		t.Fatalf("commands = %d, want the rerun alone", len(f.recorded()))
	}
	wantStates(t, rec.states(), api.RunQueued, api.RunRunning, api.RunFailed)
}

// A rerun of a held card passes, and its run waits in the queue until the
// hold ends. The rerun keeps the hold.
func TestARerunOfAHeldCardWaitsForTheHold(t *testing.T) {
	h, f := withWorkCommand(t)
	h.withHoldList()
	h.router.mu.Lock()
	h.router.holdCardLocked(cardUUID(87))
	h.router.mu.Unlock()

	if state, reason := h.rerun(workRerunOf(failedCommandKey)); state != api.CommandDone {
		t.Fatalf("rerun = %s %q", state, reason)
	}

	h.router.mu.Lock()
	held := h.router.cardHolds[cardUUID(87)]
	h.router.mu.Unlock()
	if !held || len(f.recorded()) != 0 {
		t.Fatalf("held = %v, commands = %d", held, len(f.recorded()))
	}

	h.router.dropHold(cardUUID(87))
	h.router.dispatch()
	h.router.wg.Wait()
	if len(f.recorded()) != 1 {
		t.Fatalf("commands = %d after the hold ends", len(f.recorded()))
	}
}

// With no held list read, an older server ends the hold on a rerun and says
// nothing, so the rerun ends the hold of this bridge and runs.
func TestWithNoHeldListARerunEndsTheHold(t *testing.T) {
	h, f := withWorkCommand(t)
	h.holdCard(87)

	if state, reason := h.rerun(workRerunOf(failedCommandKey)); state != api.CommandDone {
		t.Fatalf("rerun = %s %q", state, reason)
	}

	h.router.wg.Wait()
	if h.cardHoldOf(87) || len(f.recorded()) != 1 {
		t.Fatalf("held = %v, commands = %d", h.cardHoldOf(87), len(f.recorded()))
	}
	h.only(t, "card_hold_released")
}

// A rerun reaches its handler from a heartbeat, and its answer goes out.
func TestARerunFromAHeartbeatIsAnswered(t *testing.T) {
	h, f := withWorkCommand(t)
	acks := h.withAcks()

	h.reply(api.HeartbeatReply{Commands: []api.Command{workRerunOf(failedCommandKey)}})
	h.router.wg.Wait()

	if got := acks.recorded(); !slices.Equal(got, []string{testBridgeID + " " + testCommandID + " done "}) || len(f.recorded()) != 1 {
		t.Fatalf("acks = %v, commands = %d", got, len(f.recorded()))
	}
}
