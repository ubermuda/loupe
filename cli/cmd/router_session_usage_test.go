package cmd

import (
	"context"
	"reflect"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

const usageRunID = "0199a0e2-4444-7c5e-9f2a-3b1c6d7e8f90"

// runUsageCall is one usage report of a run.
type runUsageCall struct {
	handle, sessionID, runID string
	usage                    api.Usage
}

// runUsageRecorder stands in for the usage endpoint. err answers every call.
type runUsageRecorder struct {
	mu    sync.Mutex
	calls []runUsageCall
	err   error
}

func (u *runUsageRecorder) report(_ context.Context, handle, sessionID, runID string, usage api.Usage) error {
	u.mu.Lock()
	defer u.mu.Unlock()
	u.calls = append(u.calls, runUsageCall{handle, sessionID, runID, usage})

	return u.err
}

func (u *runUsageRecorder) recorded() []runUsageCall {
	u.mu.Lock()
	defer u.mu.Unlock()

	return append([]runUsageCall(nil), u.calls...)
}

// sessionUsageCommand asks for the usage of the run from 00:00:01 to 00:00:02
// on 2099-01-01. Both are whole seconds, so the bridge reads 00:00:02 to
// 00:00:03, which holds the last line of message m1 alone.
func sessionUsageCommand() api.Command {
	c := testCommand(testCommandID, api.CommandCollectSessionUsage, testBridgeID, soon())
	start := time.Date(2099, 1, 1, 0, 0, 1, 0, time.UTC)
	end := start.Add(time.Second)
	c.SessionID, c.RunID, c.StartedAt, c.EndedAt = testSession, usageRunID, &start, &end

	return c
}

func withRunUsage(h *harness) *runUsageRecorder {
	rec := &runUsageRecorder{}
	h.router.reportRunUsage = rec.report

	return rec
}

// The usage holds the messages of the window. Each edge on a whole second moves
// to the end of that second, so the message at the end counts, and the next
// second does not.
func TestASessionUsageRequestSendsTheUsageOfTheRun(t *testing.T) {
	claudeHome(t, testSession, early, streamed1, streamed2, second)
	h := newHarness(t)
	acks := h.withAcks()
	usage := withRunUsage(h)

	h.reply(api.HeartbeatReply{Commands: []api.Command{sessionUsageCommand()}})

	want := runUsageCall{testProject, testSession, usageRunID, api.Usage{Source: api.UsageEstimated, Models: map[string]api.ModelUsage{
		"claude-opus-5-5": {InputTokens: 10, OutputTokens: 100, CostUSD: ptr((10*4.0 + 100*20.0) / 1e6)},
	}}}
	if calls := usage.recorded(); len(calls) != 1 || !reflect.DeepEqual(calls[0], want) {
		t.Fatalf("usage calls = %+v, want %+v", calls, want)
	}
	if got := acks.recorded(); len(got) != 1 || got[0] != testBridgeID+" "+testCommandID+" done " {
		t.Fatalf("acks = %q", got)
	}
	if h.runs() != 0 {
		t.Fatalf("workers = %d, want none", h.runs())
	}
}

// The transcript of a run on an account with its own config folder is in that
// folder, not in the default one.
func TestASessionUsageRequestReadsTheConfigFolderOfTheAccount(t *testing.T) {
	claudeHome(t, testSession)
	h, _, f := withAccounts(t, nil)
	writeTranscript(t, f.configB, testSession, early, streamed1, streamed2, second)
	usage := withRunUsage(h)
	c := sessionUsageCommand()
	c.Account = "b"

	if state, reason := h.router.handleCommand(c); state != api.CommandDone {
		t.Fatalf("answer = %s %q", state, reason)
	}
	if calls := usage.recorded(); len(calls) != 1 || calls[0].usage.Models["claude-opus-5-5"].OutputTokens != 100 {
		t.Fatalf("usage calls = %+v", calls)
	}
}

func TestASessionUsageRequestIsRefusedWithNoTranscript(t *testing.T) {
	claudeHome(t, testSession)
	h := newHarness(t)
	usage := withRunUsage(h)

	state, reason := h.router.handleCommand(sessionUsageCommand())

	if state != api.CommandRefused || reason != noSessionTranscript {
		t.Fatalf("answer = %s %q, want refused %q", state, reason, noSessionTranscript)
	}
	if len(usage.recorded()) != 0 {
		t.Fatalf("usage calls = %+v, want none", usage.recorded())
	}
}

// A report the server does not take refuses the command with its reason.
func TestASessionUsageRequestIsRefusedWhenTheReportFails(t *testing.T) {
	claudeHome(t, testSession, streamed1)
	h := newHarness(t)
	usage := withRunUsage(h)
	usage.err = &api.UsageRefused{Status: 404, Code: "run_not_found"}

	state, reason := h.router.handleCommand(sessionUsageCommand())

	if state != api.CommandRefused || !strings.HasPrefix(reason, usageNotSent) || !strings.HasSuffix(reason, "run_not_found") {
		t.Fatalf("answer = %s %q", state, reason)
	}
	if len(usage.recorded()) != 1 {
		t.Fatalf("usage calls = %d, want 1", len(usage.recorded()))
	}
}

// A window with no message sends an empty usage, which says the run spent
// nothing.
func TestASessionUsageRequestSendsAnEmptyWindow(t *testing.T) {
	claudeHome(t, testSession, early)
	h := newHarness(t)
	usage := withRunUsage(h)

	state, reason := h.router.handleCommand(sessionUsageCommand())

	if state != api.CommandDone || reason != "" {
		t.Fatalf("answer = %s %q", state, reason)
	}
	calls := usage.recorded()
	if len(calls) != 1 || calls[0].usage.Source != api.UsageEstimated || len(calls[0].usage.Models) != 0 {
		t.Fatalf("usage calls = %+v", calls)
	}
}

// A kind this bridge does not know is refused, and resumes nothing.
func TestAnUnknownCommandKindIsRefused(t *testing.T) {
	claudeHome(t, testSession, streamed1)
	h := newHarnessWith(t, planWorkRules, rules.Defaults{})
	c := testCommand(testCommandID, "pause-run", testBridgeID, soon())
	c.SessionID, c.RunKey = testSession, failedCommandKey

	state, reason := h.router.handleCommand(c)
	h.router.wg.Wait()

	if state != api.CommandRefused || reason != unknownCommandKind {
		t.Fatalf("answer = %s %q, want refused %q", state, reason, unknownCommandKind)
	}
	h.router.mu.Lock()
	queued := len(h.router.queue)
	h.router.mu.Unlock()
	if queued != 0 || h.runs() != 0 {
		t.Fatalf("queued = %d, workers = %d, want none", queued, h.runs())
	}
}

// An exact end stays as it is. A whole-second end, from an older server, moves
// to the end of that second.
func TestAnExactEndStaysAndAWholeSecondEndMovesOn(t *testing.T) {
	cut := time.Date(2099, 1, 1, 0, 0, 2, 0, time.UTC)
	if !windowEnd(cut).Equal(cut.Add(time.Second)) {
		t.Fatalf("end of a whole second = %s", windowEnd(cut))
	}
	exact := cut.Add(250 * time.Millisecond)
	if !windowEnd(exact).Equal(exact) {
		t.Fatalf("end of an exact time = %s", windowEnd(exact))
	}
}
