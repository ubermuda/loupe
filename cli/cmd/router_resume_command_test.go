package cmd

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
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
// and starts nothing. The server names no rule for a run, so no rule matches
// the run of a resume, and the bridge refuses each one it can read.
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
