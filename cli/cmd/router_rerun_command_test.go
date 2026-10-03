package cmd

import (
	"slices"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
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
// and runs nothing. The server names no rule for a run, so no rule matches
// the run of a rerun.
func TestARerunIsRefusedWhenACheckFails(t *testing.T) {
	for name, tc := range map[string]struct {
		change func(t *testing.T, h *harness, f *fakeCommand, c *api.Command)
		reason string
	}{
		"a work run":      {func(_ *testing.T, _ *harness, _ *fakeCommand, _ *api.Command) {}, noCommandRule},
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

// A rerun reaches its handler from a heartbeat, and its answer goes out.
func TestARerunFromAHeartbeatIsAnswered(t *testing.T) {
	h, f := withCommand(t, "1m")
	acks := h.withAcks()

	h.reply(api.HeartbeatReply{Commands: []api.Command{rerunOf(failedCommandKey)}})
	h.router.wg.Wait()

	if got := acks.recorded(); !slices.Equal(got, []string{testBridgeID + " " + testCommandID + " refused " + noCommandRule}) || len(f.recorded()) != 0 {
		t.Fatalf("acks = %v, commands = %d", got, len(f.recorded()))
	}
}
