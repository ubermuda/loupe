package cmd

import (
	"slices"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
)

// failedCommandKey is the key of a command run of card 87 that failed.
const failedCommandKey = "0199a0e2-0000-7c5e-9f2a-00000000fa11"

// rerunOf is a person's rerun of the failed run of the teardown rule.
func rerunOf(runKey string) api.Command {
	c := testCommand(testCommandID, api.CommandRerunCommand, testBridgeID, soon())
	c.RunKey, c.RuleName, c.CardColumn = runKey, "teardown", "done"

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
		"no rule":       {func(_ *testing.T, _ *harness, _ *fakeCommand, c *api.Command) { c.RuleName = "gone" }, noCommandRule},
		"a worker rule": {func(_ *testing.T, _ *harness, _ *fakeCommand, c *api.Command) { c.RuleName = "plan" }, noCommandRule},
		"a handover":    {func(_ *testing.T, h *harness, _ *fakeCommand, _ *api.Command) { h.router.freeze() }, handingOver},
		"a shut bridge": {func(_ *testing.T, h *harness, _ *fakeCommand, _ *api.Command) { h.router.shutdown() }, bridgeShutting},
		"a live run of the card": {func(_ *testing.T, h *harness, _ *fakeCommand, _ *api.Command) {
			h.router.mu.Lock()
			h.router.hold(cardUUID(87))
			h.router.mu.Unlock()
		}, cardBusy},
		"a queued run of the card": {func(t *testing.T, h *harness, _ *fakeCommand, _ *api.Command) {
			h.reply(pausedReply(true))
			h.router.onData([]byte(cardMoved(87)))
		}, cardBusy},
		"a queued rerun": {func(t *testing.T, h *harness, _ *fakeCommand, c *api.Command) {
			h.reply(pausedReply(true))
			if state, reason := h.rerun(*c); state != api.CommandDone {
				t.Fatalf("first rerun = %s %q", state, reason)
			}
		}, cardBusy},
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

// A rerun runs the command of the rule again as a new run that continues the
// failed one. The command takes the card, and its queued report names the
// command as its trigger.
func TestARerunRunsTheCommandAgain(t *testing.T) {
	h, f := withCommand(t, "1m")
	rec := h.states()
	f.result = procResult{output: "removed"}

	if state, reason := h.rerun(rerunOf(failedCommandKey)); state != api.CommandDone {
		t.Fatalf("rerun = %s %q", state, reason)
	}

	specs := f.recorded()
	if len(specs) != 1 || !slices.Equal(specs[0].argv, []string{"teardown", "87"}) || specs[0].dir != h.dir {
		t.Fatalf("command = %+v", specs)
	}
	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunSucceeded)
	wantCommandReports(t, sent)
	queued := sent[0].report
	if sent[0].runID == failedCommandKey || queued.Continues != failedCommandKey || queued.ResumeIndex != 1 || queued.ResumeCap != 1 {
		t.Fatalf("queued = %+v", queued)
	}
	if queued.Trigger == nil || *queued.Trigger != (api.RunTrigger{EventType: "bridge.command"}) || queued.CardColumn != "done" || queued.RuleName != "teardown" {
		t.Fatalf("queued = %+v, trigger = %+v", queued, queued.Trigger)
	}
	if h.cardHeld(87) || h.runs() != 0 {
		t.Fatalf("card held = %v, workers = %d", h.cardHeld(87), h.runs())
	}
}

// A person stopped the earlier run, which held the card. The rerun is a
// person acting on the card, so it ends the hold of this bridge.
func TestARerunReleasesTheHoldOfTheCard(t *testing.T) {
	h, f := withCommand(t, "1m")
	h.router.mu.Lock()
	h.router.holdCardLocked(cardUUID(87))
	h.router.mu.Unlock()

	if state, reason := h.rerun(rerunOf(failedCommandKey)); state != api.CommandDone {
		t.Fatalf("rerun = %s %q", state, reason)
	}

	if len(f.recorded()) != 1 {
		t.Fatalf("commands = %d, want the rerun to run", len(f.recorded()))
	}
}

// A rerun reaches its handler from a heartbeat, and its answer goes out.
func TestARerunFromAHeartbeatIsAnswered(t *testing.T) {
	h, f := withCommand(t, "1m")
	acks := h.withAcks()

	h.reply(api.HeartbeatReply{Commands: []api.Command{rerunOf(failedCommandKey)}})
	h.router.wg.Wait()

	if got := acks.recorded(); !slices.Equal(got, []string{testBridgeID + " " + testCommandID + " done "}) || len(f.recorded()) != 1 {
		t.Fatalf("acks = %v, commands = %d", got, len(f.recorded()))
	}
}
