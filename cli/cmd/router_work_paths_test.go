package cmd

import (
	"context"
	"errors"
	"slices"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/outbound"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// giveUpQueue sends each report once, and gives up on one that fails, as the
// report queue does once its attempts run out.
type giveUpQueue struct{ syncQueue }

func (giveUpQueue) Enqueue(report outbound.Report) {
	if _, err := report.Send(context.Background()); err != nil && report.Lost != nil {
		report.Lost()
	}
}

// An offer that first arrives in a heartbeat reply runs to its result.
func TestAHeartbeatOfferRunsToItsResult(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	rec := h.states()
	f := h.withWork()
	w := workRequest(1, 87, "implement", api.WorkRequestOpen)
	f.requests[w.WorkRequestID] = w

	h.reply(api.HeartbeatReply{WorkRequests: []api.WorkRequest{w}})

	wantStates(t, rec.states(), api.RunQueued, api.RunRunning, api.RunSucceeded)
	if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " done"}) {
		t.Fatalf("results = %v", got)
	}
}

// A malformed offer in a heartbeat reply is dropped with a line, and never
// claimed.
func TestAMalformedHeartbeatOfferIsDropped(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	f := h.withWork()
	w := workRequest(1, 87, "Implement It", api.WorkRequestOpen)

	h.reply(api.HeartbeatReply{WorkRequests: []api.WorkRequest{w}})

	if got := f.claimed(); len(got) != 0 {
		t.Fatalf("claims = %v", got)
	}
	if line := h.only(t, "work_request_dropped"); line["source"] != workFromHeartbeat || line["reason"] != "malformed" {
		t.Fatalf("line = %v", line)
	}
}

// startClaim offers w and waits until its claim call holds at the gate.
func (h *harness) startClaim(t *testing.T, f *fakeWork, w api.WorkRequest) {
	t.Helper()
	f.mu.Lock()
	f.requests[w.WorkRequestID] = w
	f.mu.Unlock()
	h.router.onData([]byte(workPayload(w)))
	eventually(t, "the claim call", func() bool { return len(f.claimed()) == 1 })
}

// A request cancelled while its claim call runs starts nothing and reports
// nothing, whatever the claim answers.
func TestACancelWhileTheClaimRunsStartsNothing(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	rec := h.states()
	f := h.withWork()
	f.gate = make(chan struct{})
	w := workRequest(1, 87, "implement", api.WorkRequestOpen)

	h.startClaim(t, f, w)
	w.State = api.WorkRequestCancelled
	h.router.onData([]byte(workPayload(w)))
	close(f.gate)
	h.router.wg.Wait()

	if h.runs() != 0 || len(rec.states()) != 0 || len(f.settled()) != 0 || len(h.heldClaims()) != 0 {
		t.Fatalf("runs = %d, states = %v, results = %v, claims = %v", h.runs(), rec.names(), f.settled(), h.heldClaims())
	}
	if h.used() != 0 || h.cardHeld(87) {
		t.Fatalf("used = %d, card held = %v", h.used(), h.cardHeld(87))
	}
	if line := h.only(t, "work_request_not_claimed"); line["reason"] != "ended" {
		t.Fatalf("line = %v", line)
	}
}

// An offer that arrives again while its claim call runs neither queues nor
// claims a second time.
func TestAnOfferDuringItsClaimCallWaitsForNothing(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	f := h.withWork()
	f.gate = make(chan struct{})
	w := workRequest(1, 87, "implement", api.WorkRequestOpen)

	h.startClaim(t, f, w)
	h.router.onData([]byte(workPayload(w)))
	h.router.onHeartbeatReply(api.HeartbeatReply{WorkRequests: []api.WorkRequest{w}})
	if n := h.queueLen(); n != 0 {
		t.Fatalf("queue = %d", n)
	}
	close(f.gate)
	h.router.wg.Wait()

	if got := f.claimed(); len(got) != 1 || h.runs() != 1 {
		t.Fatalf("claims = %v, runs = %d", got, h.runs())
	}
}

// Each failed result ends the claim, so the heartbeat stops renewing it.
func TestAFailedResultEndsTheClaim(t *testing.T) {
	for name, tc := range map[string]struct {
		err   error
		queue outbound.Queue
		line  string
	}{
		"claim lost": {api.ErrClaimLost, syncQueue{}, "work_request_result_lost"},
		"refused":    {&api.WorkRequestRefusal{Status: 422, Code: "invalid_state"}, syncQueue{}, "work_request_result_refused"},
		"given up":   {errors.New("dial tcp: connection refused"), giveUpQueue{}, "work_request_result_dropped"},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarnessWith(t, workRules, rules.Defaults{})
			h.router.reports = tc.queue
			h.router.runs = newRunReports(&stateRecorder{}, h.router.log)
			f := h.withWork()
			f.settleErr = tc.err

			h.offer(f, workRequest(1, 87, "implement", api.WorkRequestOpen))

			if got := f.settled(); len(got) != 1 {
				t.Fatalf("results = %v", got)
			}
			if got := h.heldClaims(); len(got) != 0 {
				t.Fatalf("claims = %v", got)
			}
			h.only(t, tc.line)
		})
	}
}

// A claim whose answer fails the check runs nothing, and settles refused.
func TestABadClaimAnswerSettlesRefused(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	rec := h.states()
	f := h.withWork()
	f.mangle = true

	h.offer(f, workRequest(1, 87, "implement", api.WorkRequestOpen))

	if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " refused failed"}) {
		t.Fatalf("results = %v", got)
	}
	if h.runs() != 0 || len(rec.states()) != 0 || h.used() != 0 || len(h.heldClaims()) != 0 {
		t.Fatalf("runs = %d, states = %v, used = %d, claims = %v", h.runs(), rec.names(), h.used(), h.heldClaims())
	}
	h.only(t, "work_request_claim_invalid")
}

// beforeWorkRules prepares the checkout of each implement request first.
const beforeWorkRules = `
projects:
  loupe:
    dir: {dir}
work:
  implement:
    prompt: Implement card {cardNumber}.
    before:
      run: [prepare, '{cardNumber}']
`

// A before command that fails settles the request refused, with failed.
func TestAFailedBeforeCommandSettlesRefused(t *testing.T) {
	h := newHarnessWith(t, beforeWorkRules, rules.Defaults{})
	h.states()
	f := h.withWork()
	h.router.worker.before = func(context.Context, procSpec, func(workerProc)) procResult {
		return procResult{exitCode: 1, runDir: t.TempDir()}
	}

	h.offer(f, workRequest(1, 87, "implement", api.WorkRequestOpen))

	if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " refused failed"}) {
		t.Fatalf("results = %v", got)
	}
	if h.runs() != 0 {
		t.Fatalf("runs = %d", h.runs())
	}
}

// A person's stop of a work run reports stopped and posts no result.
func TestAPersonsStopOfAWorkRunPostsNoResult(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	rec := h.states()
	f := h.withWork()
	s := h.stopper()
	s.gone = true
	block := h.blocked()
	s.on = func(sig stopSignal) {
		if sig == stopInt {
			close(block)
		}
	}
	w := workRequest(1, 87, "implement", api.WorkRequestOpen)
	f.requests[w.WorkRequestID] = w

	h.router.onData([]byte(workPayload(w)))
	<-h.worker.started
	h.stop(t, firstRun(t, rec))
	h.router.wg.Wait()

	wantStates(t, rec.states(), api.RunQueued, api.RunRunning, api.RunStopping, api.RunStopped)
	if got := f.settled(); len(got) != 0 {
		t.Fatalf("results = %v", got)
	}
	if got := h.heldClaims(); len(got) != 0 {
		t.Fatalf("claims = %v", got)
	}
}

// A reload that maps the kind no more drops its queued offer, and the server
// hears nothing of it.
func TestAReloadDropsAQueuedOfferSilently(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	rec := h.states()
	f := h.withWork()
	h.router.personPaused = true
	h.offer(f, workRequest(1, 87, "implement", api.WorkRequestOpen))

	res := h.reload(t, `
projects:
  loupe:
    dir: {dir}
work:
  check:
    action: command
    run: [check]
`)
	if !res.OK {
		t.Fatalf("reload = %+v", res)
	}

	if n := h.queueLen(); n != 0 {
		t.Fatalf("queue = %d", n)
	}
	if got := rec.states(); len(got) != 0 {
		t.Fatalf("states = %v", stateNames(got))
	}
	h.only(t, "queue_dropped")
}

// A paused bridge claims no interactive offer, so another bridge can take it.
func TestAPausedBridgeLeavesAnInteractiveOffer(t *testing.T) {
	h, rec := launchHarnessWith(t, interactiveWorkRules, `[sh, -c, 'exit 0', sh, '{script}']`)
	f := h.withWork()
	h.router.personPaused = true

	h.offer(f, workRequest(1, 87, "design", api.WorkRequestOpen))

	if got := f.claimed(); len(got) != 0 || len(rec.launches()) != 0 {
		t.Fatalf("claims = %v, launches = %d", got, len(rec.launches()))
	}
}

// A rename of a project kills its work and says so once, even in a file
// with no rules.
func TestARenameLogsTheDeadWorkOnce(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	h.withWork()

	h.send(projectRenamed())
	h.send(projectRenamed())

	line := h.only(t, "work_dead")
	if line["project_slug"] != "loupe" || line["reason"] != api.ReasonProjectRenamed {
		t.Fatalf("line = %v", line)
	}
}
