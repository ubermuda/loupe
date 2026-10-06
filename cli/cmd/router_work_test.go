package cmd

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/outbound"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// workRules claims two kinds of work: implement runs a worker, and check runs
// a command.
const workRules = `
projects:
  loupe:
    dir: {dir}
work:
  implement:
    prompt: Implement card {cardNumber} for {workRequestId}.
  check:
    action: command
    run: [check, "{cardNumber}"]
`

func workID(n int) string {
	return fmt.Sprintf("0199b000-0000-7000-8000-%012d", n)
}

// workRequest is work request n of the kind on the card, in the state.
func workRequest(n, card int, kind, state string) api.WorkRequest {
	return api.WorkRequest{
		Type: event.WorkRequestType, ProjectID: testProject, Subject: api.WorkRequestSubject{Type: "work-request", ID: workID(n)},
		WorkRequestID: workID(n), Kind: kind, State: state, CardID: cardUUID(card), CardNumber: card, RuleID: "implement-on-next",
		CreatedAt: time.Date(2026, 10, 2, 8, 0, 0, 0, time.UTC),
	}
}

func workPayload(w api.WorkRequest) string {
	b, err := json.Marshal(w)
	if err != nil {
		panic(err)
	}

	return string(b)
}

// tokenOf is the claim token the fake server gives work request n.
func tokenOf(n int) string {
	return "token-" + workID(n)
}

// fakeWork is a server with the work request endpoints. A claim answers the
// request the test offered, or the error errs names for its id.
type fakeWork struct {
	mu       sync.Mutex
	requests map[string]api.WorkRequest
	errs     map[string]error
	claims   []string
	settles  []string
	// settleErr answers each result, and gate holds each claim until the test
	// closes it.
	settleErr error
	gate      chan struct{}
	// mangle makes each claim answer with another kind than the offer.
	mangle bool
}

func (f *fakeWork) ClaimWorkRequest(_ context.Context, _, id string) (api.Claim, error) {
	f.mu.Lock()
	f.claims = append(f.claims, id)
	err, w, gate := f.errs[id], f.requests[id], f.gate
	if _, known := f.requests[id]; !known {
		w, _ = offeredRequest(id)
	}
	if f.mangle {
		w.Kind = "other"
	}
	f.mu.Unlock()
	if gate != nil {
		<-gate
	}
	if err != nil {
		return api.Claim{}, err
	}
	w.State = api.WorkRequestClaimed

	return api.Claim{WorkRequestID: id, ClaimToken: "token-" + id, LeaseUntil: time.Now().Add(2 * time.Minute), WorkRequest: w}, nil
}

func (f *fakeWork) SettleWorkRequest(_ context.Context, _, id, token, state, reason string) (string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.settles = append(f.settles, strings.TrimSpace(fmt.Sprintf("%s %s %s %s", id, token, state, reason)))

	return state, f.settleErr
}

func (f *fakeWork) claimed() []string {
	f.mu.Lock()
	defer f.mu.Unlock()

	return slices.Clone(f.claims)
}

func (f *fakeWork) settled() []string {
	f.mu.Lock()
	defer f.mu.Unlock()

	return slices.Clone(f.settles)
}

// withWork gives the router a queue that sends at once, and returns its fake
// work server.
func (h *harness) withWork() *fakeWork {
	f := h.work
	if h.router.reports == nil {
		h.router.reports = syncQueue{}
	}

	return f
}

// offer sends work request w through the stream, and lets the server know it.
func (h *harness) offer(f *fakeWork, w api.WorkRequest) {
	f.mu.Lock()
	f.requests[w.WorkRequestID] = w
	f.mu.Unlock()
	h.send(workPayload(w))
}

// heldClaims is what the next heartbeat renews.
func (h *harness) heldClaims() []api.WorkClaim {
	h.router.mu.Lock()
	defer h.router.mu.Unlock()

	return h.router.workClaimsLocked()
}

// An open offer claims, runs and settles. The run reports name the work
// rule and the work request event, and the worker reads the entry's prompt.
func TestAWorkOfferClaimsRunsAndSettles(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	rec := h.states()
	f := h.withWork()
	h.worker.result = workerResult{hasResult: true, status: "finished"}

	h.offer(f, workRequest(1, 87, "implement", api.WorkRequestOpen))

	if got := f.claimed(); !slices.Equal(got, []string{workID(1)}) {
		t.Fatalf("claims = %v", got)
	}
	calls := h.worker.recorded()
	if len(calls) != 1 || !strings.HasPrefix(calls[0].prompt, "Implement card 87 for "+workID(1)+".") {
		t.Fatalf("workers = %+v", calls)
	}
	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunSucceeded)
	for _, s := range sent {
		if s.report.WorkKind != "implement" || s.report.WorkRequestID != workID(1) || s.report.RuleID == "" || s.report.Rule != "work:implement" || s.report.CardID != cardUUID(87) || s.report.CardNumber != 87 || s.handle != testProject {
			t.Fatalf("report = %+v", s.report)
		}
	}
	if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " done"}) {
		t.Fatalf("results = %v", got)
	}
	if got := h.heldClaims(); len(got) != 0 {
		t.Fatalf("claims after the result = %v", got)
	}
	h.only(t, "work_request_claimed")
	h.only(t, "work_request_settled")
	for _, raw := range strings.Split(h.log.String(), "\n") {
		if n := strings.Count(raw, `"work_request":`); n > 1 {
			t.Fatalf("log line names the work request %d times: %s", n, raw)
		}
	}
}

// The result of a run follows how it ended. A refusal carries the reason of
// the structured result when Loupe takes it, and a fallback word otherwise.
func TestAWorkResultFollowsHowTheRunEnded(t *testing.T) {
	for name, tc := range map[string]struct {
		kind   string
		worker workerResult
		cmd    procResult
		want   string
	}{
		"finished":                  {"implement", workerResult{hasResult: true, status: "finished"}, procResult{}, "done"},
		"waiting":                   {"implement", workerResult{hasResult: true, status: "waiting"}, procResult{}, "done"},
		"blocked with a reason":     {"implement", workerResult{hasResult: true, status: "blocked", reason: "needs-design"}, procResult{}, "refused needs-design"},
		"blocked with a bad reason": {"implement", workerResult{hasResult: true, status: "blocked", reason: "Needs Design"}, procResult{}, "refused blocked"},
		"unfinished":                {"implement", workerResult{hasResult: true, status: "unfinished"}, procResult{}, "refused unfinished"},
		"failed":                    {"implement", workerResult{exitCode: 2}, procResult{}, "refused failed"},
		"no result":                 {"implement", workerResult{}, procResult{}, "refused failed"},
		"not started":               {"implement", workerResult{err: errors.New("fork/exec claude: permission denied")}, procResult{}, "refused failed"},
		"command passed":            {"check", workerResult{}, procResult{}, "done"},
		"command failed":            {"check", workerResult{}, procResult{exitCode: 1}, "refused failed"},
		"command timed out":         {"check", workerResult{}, procResult{exitCode: -1, timedOut: true}, "refused timeout"},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarnessWith(t, workRules, rules.Defaults{})
			rec := h.states()
			f := h.withWork()
			h.worker.result = tc.worker
			h.router.worker.command = func(_ context.Context, _ procSpec, onStart func(workerProc)) procResult {
				onStart(workerProc{})

				return tc.cmd
			}

			h.offer(f, workRequest(1, 87, tc.kind, api.WorkRequestOpen))

			if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " " + tc.want}) {
				t.Fatalf("results = %v, want %s", got, tc.want)
			}
			sent := rec.states()
			if len(sent) < 2 || sent[0].report.State != api.RunQueued || !api.IsOutcome(sent[len(sent)-1].report.State) || len(ofRun(sent, sent[0].runID)) != len(sent) {
				t.Fatalf("states = %v, want one run that ends with no resume", stateNames(sent))
			}
			if len(h.events(t, "worker_resuming")) != 0 {
				t.Fatal("the bridge resumed a work run")
			}
		})
	}
}

// A claim another bridge won runs nothing and reports no run. It frees the
// slot at once, so the next offer runs.
func TestALostClaimRaceRunsNothingAndFreesTheSlot(t *testing.T) {
	for name, err := range map[string]error{
		"already claimed": api.ErrWorkRequestAlreadyClaimed,
		"not found":       api.ErrWorkRequestNotFound,
		"rate limited":    &api.WorkRequestRefusal{Status: 429},
		"unsupported":     api.ErrWorkRequestsUnsupported,
		"network":         errors.New("dial tcp: connection refused"),
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarnessWith(t, withMaxWorkers(workRules, 1), rules.Defaults{})
			rec := h.states()
			f := h.withWork()
			f.errs[workID(1)] = err
			h.router.personPaused = true
			h.offer(f, workRequest(1, 87, "implement", api.WorkRequestOpen))
			h.offer(f, workRequest(2, 88, "implement", api.WorkRequestOpen))
			if len(rec.states()) != 0 {
				t.Fatalf("a queued offer reported %v", stateNames(rec.states()))
			}

			h.router.setPersonPause(false)
			h.router.wg.Wait()

			if got := f.claimed(); !slices.Equal(got, []string{workID(1), workID(2)}) {
				t.Fatalf("claims = %v", got)
			}
			if h.runs() != 1 || h.used() != 0 || h.cardHeld(87) || h.cardHeld(88) {
				t.Fatalf("runs = %d, used = %d", h.runs(), h.used())
			}
			sent := rec.states()
			wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunSucceeded)
			if sent[0].report.CardNumber != 88 {
				t.Fatalf("the reports name card %d, want 88", sent[0].report.CardNumber)
			}
			if got := h.heldClaims(); len(got) != 0 {
				t.Fatalf("claims = %v", got)
			}
			h.only(t, "work_request_not_claimed")
		})
	}
}

// One work request queues once, whichever channel brings it and however
// often.
func TestADuplicateWorkOfferQueuesOnce(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	f := h.withWork()
	h.router.personPaused = true
	w := workRequest(1, 87, "implement", api.WorkRequestOpen)

	h.offer(f, w)
	h.offer(f, w)
	h.reply(api.HeartbeatReply{WorkRequests: []api.WorkRequest{w}})
	if n := h.queueLen(); n != 1 {
		t.Fatalf("queue = %d, want 1", n)
	}

	block := h.blocked()
	h.router.setPersonPause(false)
	<-h.worker.started
	h.router.onData([]byte(workPayload(w)))
	h.router.onHeartbeatReply(api.HeartbeatReply{WorkRequests: []api.WorkRequest{w}})
	close(block)
	h.router.wg.Wait()

	if got := f.claimed(); len(got) != 1 || h.runs() != 1 {
		t.Fatalf("claims = %v, runs = %d", got, h.runs())
	}
}

// queueLen is the length of the queue.
func (h *harness) queueLen() int {
	h.router.mu.Lock()
	defer h.router.mu.Unlock()

	return len(h.router.queue)
}

// A cancelled request stops its live worker with the stop ladder and posts no
// result. A cancelled or expired offer that waits leaves the queue unclaimed.
func TestACancelledWorkRequestStopsItsWorker(t *testing.T) {
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
	w.State = api.WorkRequestCancelled
	h.router.onData([]byte(workPayload(w)))
	h.router.wg.Wait()

	wantStates(t, rec.states(), api.RunQueued, api.RunRunning, api.RunStopping, api.RunStopped)
	if got := f.settled(); len(got) != 0 {
		t.Fatalf("results = %v, want none", got)
	}
	if got := h.heldClaims(); len(got) != 0 {
		t.Fatalf("claims = %v", got)
	}

	for _, state := range []string{api.WorkRequestCancelled, api.WorkRequestExpired, api.WorkRequestClaimed} {
		h.router.personPaused = true
		queued := workRequest(2, 88, "implement", api.WorkRequestOpen)
		h.offer(f, queued)
		queued.State = state
		h.send(workPayload(queued))
		if n := h.queueLen(); n != 0 {
			t.Fatalf("%s: queue = %d, want 0", state, n)
		}
	}
	h.router.setPersonPause(false)
	h.router.wg.Wait()
	if got := f.claimed(); !slices.Equal(got, []string{workID(1)}) {
		t.Fatalf("claims = %v", got)
	}
}

// A claim the heartbeat reply names lost stops its worker, posts no result,
// and leaves the next heartbeat.
func TestALostClaimStopsItsWorker(t *testing.T) {
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
	if got := h.heldClaims(); !slices.Equal(got, []api.WorkClaim{{ID: workID(1), ClaimToken: tokenOf(1)}}) {
		t.Fatalf("claims = %v", got)
	}
	h.router.loseClaims([]api.WorkClaim{{ID: workID(1), ClaimToken: tokenOf(1)}})
	h.router.wg.Wait()

	wantStates(t, rec.states(), api.RunQueued, api.RunRunning, api.RunStopping, api.RunStopped)
	if got := f.settled(); len(got) != 0 {
		t.Fatalf("results = %v, want none", got)
	}
	if got := h.heldClaims(); len(got) != 0 {
		t.Fatalf("claims = %v", got)
	}
	h.only(t, "work_request_lost")
}

// A lost answer for a token the bridge no longer holds leaves the claim, as
// when the bridge took the request again after the heartbeat went out.
func TestAStaleLostClaimLeavesTheClaim(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	f := h.withWork()
	block := h.blocked()

	w := workRequest(1, 87, "implement", api.WorkRequestOpen)
	f.requests[w.WorkRequestID] = w
	h.router.onData([]byte(workPayload(w)))
	<-h.worker.started
	h.router.loseClaims([]api.WorkClaim{{ID: workID(1), ClaimToken: "token-of-an-older-claim"}})
	if got := h.heldClaims(); len(got) != 1 {
		t.Fatalf("claims = %v", got)
	}
	close(block)
	h.router.wg.Wait()

	if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " done"}) {
		t.Fatalf("results = %v", got)
	}
}

// replying answers each heartbeat with reply.
type replying struct {
	mu    sync.Mutex
	reply api.HeartbeatReply
	sent  []api.Heartbeat
}

func (s *replying) Heartbeat(_ context.Context, _ string, hb api.Heartbeat) (api.HeartbeatReply, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.sent = append(s.sent, hb)

	return s.reply, nil
}

// A heartbeat reply drops the lost claim before it takes the offers, so an
// offer of the same request in that reply is claimed again.
func TestAHeartbeatReplyLosesClaimsBeforeItTakesOffers(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
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
	server := &replying{reply: api.HeartbeatReply{LostClaims: []string{workID(1)}, WorkRequests: []api.WorkRequest{w}}}
	hb := newHeartbeater(context.Background(), latestNow{}, server, testBridgeID, heartbeatBody(h.router.rules()), time.Minute, h.router.log)
	hb.onReply, hb.onLost = h.router.onHeartbeatReply, h.router.loseClaims
	h.router.heartbeat = hb

	h.router.onData([]byte(workPayload(w)))
	<-h.worker.started
	hb.send()
	h.router.wg.Wait()

	if got := f.claimed(); !slices.Equal(got, []string{workID(1), workID(1)}) {
		t.Fatalf("claims = %v, want the request claimed again", got)
	}
	if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " done"}) {
		t.Fatalf("results = %v, want the second run alone", got)
	}
}

// latestNow sends each latest-wins item at once, and each report too.
type latestNow struct{ syncQueue }

func (latestNow) SendLatest(_ string, send func(context.Context) error, done func(error)) {
	done(send(context.Background()))
}

var _ outbound.Queue = latestNow{}

// The heartbeat renews each claim the bridge holds, and stops once the
// result lands. It names the work capabilities of the rule file.
func TestTheHeartbeatRenewsTheHeldClaims(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	f := h.withWork()
	client := &fakeHeartbeats{}
	hb := newHeartbeater(context.Background(), latestNow{}, client, testBridgeID, heartbeatBody(h.router.rules()), time.Minute, h.router.log)
	h.router.heartbeat = hb
	block := h.blocked()

	w := workRequest(1, 87, "implement", api.WorkRequestOpen)
	f.requests[w.WorkRequestID] = w
	h.router.onData([]byte(workPayload(w)))
	<-h.worker.started
	hb.send()
	close(block)
	h.router.wg.Wait()
	hb.send()

	client.mu.Lock()
	defer client.mu.Unlock()
	if len(client.sent) != 2 {
		t.Fatalf("heartbeats = %d", len(client.sent))
	}
	if got := client.sent[0].WorkClaims; !slices.Equal(got, []api.WorkClaim{{ID: workID(1), ClaimToken: tokenOf(1)}}) {
		t.Fatalf("claims while running = %v", got)
	}
	if got := client.sent[1].WorkClaims; len(got) != 0 {
		t.Fatalf("claims after the result = %v", got)
	}
	if got := client.sent[0].Capabilities; !slices.Equal(got, []string{"commands", "rerun-command", "session-usage", "work-requests"}) {
		t.Fatalf("capabilities = %v", got)
	}
}

// Two offers of one card run one after the other, as two events of one card
// do.
func TestTwoWorkOffersOfOneCardRunInTurn(t *testing.T) {
	h := newHarnessWith(t, withMaxWorkers(workRules, 2), rules.Defaults{})
	f := h.withWork()
	block := h.blocked()

	first := workRequest(1, 87, "implement", api.WorkRequestOpen)
	second := workRequest(2, 87, "implement", api.WorkRequestOpen)
	f.requests[first.WorkRequestID], f.requests[second.WorkRequestID] = first, second
	h.router.onData([]byte(workPayload(first)))
	<-h.worker.started
	h.router.onData([]byte(workPayload(second)))
	if got := f.claimed(); !slices.Equal(got, []string{workID(1)}) {
		t.Fatalf("claims while the first runs = %v", got)
	}
	close(block)
	h.router.wg.Wait()

	if got := f.claimed(); !slices.Equal(got, []string{workID(1), workID(2)}) || h.worker.peak() != 1 {
		t.Fatalf("claims = %v, peak = %d", got, h.worker.peak())
	}
}

// An offer of a kind the file does not map, or of a project it does not
// map, is never claimed.
func TestAnUnmappedWorkOfferIsNotClaimed(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	f := h.withWork()

	h.offer(f, workRequest(1, 87, "deploy", api.WorkRequestOpen))
	other := workRequest(2, 87, "implement", api.WorkRequestOpen)
	other.ProjectID = otherProject
	h.offer(f, other)

	if got := f.claimed(); len(got) != 0 || h.runs() != 0 {
		t.Fatalf("claims = %v, runs = %d", got, h.runs())
	}
	h.only(t, "project_unmapped")
	h.only(t, "work_request_skipped")
}

// A bridge with no work client claims nothing.
func TestABridgeWithNoWorkClientClaimsNothing(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})

	h.send(workPayload(workRequest(1, 87, "implement", api.WorkRequestOpen)))

	if h.runs() != 0 || h.queueLen() != 0 {
		t.Fatalf("runs = %d, queue = %d", h.runs(), h.queueLen())
	}
}

// A renamed project drops its queued offers. A running one goes on and posts
// its result.
func TestARenameDropsQueuedWorkOffers(t *testing.T) {
	h := newHarnessWith(t, withMaxWorkers(workRules, 1), rules.Defaults{})
	f := h.withWork()
	block := h.blocked()

	h.router.onData([]byte(func() string {
		w := workRequest(1, 87, "implement", api.WorkRequestOpen)
		f.requests[w.WorkRequestID] = w

		return workPayload(w)
	}()))
	<-h.worker.started
	queued := workRequest(2, 88, "implement", api.WorkRequestOpen)
	f.requests[queued.WorkRequestID] = queued
	h.router.onData([]byte(workPayload(queued)))
	if n := h.queueLen(); n != 1 {
		t.Fatalf("queue = %d", n)
	}
	h.router.onData([]byte(projectRenamed()))
	if n := h.queueLen(); n != 0 {
		t.Fatalf("queue after the rename = %d", n)
	}
	close(block)
	h.router.wg.Wait()

	if got := f.claimed(); !slices.Equal(got, []string{workID(1)}) {
		t.Fatalf("claims = %v", got)
	}
	if got := f.settled(); len(got) != 1 {
		t.Fatalf("results = %v, want the running one", got)
	}
	if got := dropped(t, h.only(t, "queue_dropped")); !slices.Equal(got, []string{"88/work:implement"}) {
		t.Fatalf("dropped = %v", got)
	}
}

// A handover keeps the held claims, the live work run and the queued offer.
// The adopted run posts its result with the token of the old image.
func TestAHandoverKeepsTheWorkClaims(t *testing.T) {
	h := newHarnessWith(t, withMaxWorkers(workRules, 1), rules.Defaults{})
	h.states()
	f := h.withWork()
	h.worker.block = make(chan struct{})
	defer close(h.worker.block)

	running := workRequest(1, 87, "implement", api.WorkRequestOpen)
	queued := workRequest(2, 88, "implement", api.WorkRequestOpen)
	f.requests[running.WorkRequestID], f.requests[queued.WorkRequestID] = running, queued
	h.router.onEvent("id-1", []byte(workPayload(running)))
	h.router.onEvent("id-2", []byte(workPayload(queued)))
	h.router.pause()
	if err := h.router.drain(context.Background(), 5*time.Second); err != nil {
		t.Fatal(err)
	}
	st := roundTrip(t, h.router.freeze())
	if len(st.WorkClaims) != 1 || st.WorkClaims[0].ID != workID(1) || st.WorkClaims[0].Token != tokenOf(1) {
		t.Fatalf("claims = %+v", st.WorkClaims)
	}
	if len(st.Live) != 1 || st.Live[0].Work == nil || st.Live[0].ClaimToken != tokenOf(1) {
		t.Fatalf("live = %+v", st.Live)
	}
	if len(st.Queue) != 1 || st.Queue[0].Work == nil || st.Queue[0].Work.WorkRequestID != workID(2) {
		t.Fatalf("queue = %+v", st.Queue)
	}

	h2 := newHarnessWith(t, withMaxWorkers(workRules, 1), rules.Defaults{})
	h2.states()
	f2 := h2.withWork()
	f2.requests[queued.WorkRequestID] = queued
	h2.router.worker.adopt = func(context.Context, string) workerResult {
		return workerResult{hasResult: true, status: "finished"}
	}
	h2.router.pause()
	h2.router.adopt(st)
	if got := h2.heldClaims(); !slices.Equal(got, []api.WorkClaim{{ID: workID(1), ClaimToken: tokenOf(1)}}) {
		t.Fatalf("adopted claims = %v", got)
	}
	if got, want := stateJSON(t, h2.router.freeze()), stateJSON(t, st); got != want {
		t.Fatalf("adopted state freezes to\n%s\nwant\n%s", got, want)
	}
	h2.router.resume()
	h2.router.wg.Wait()

	want := []string{workID(1) + " " + tokenOf(1) + " done", workID(2) + " " + tokenOf(2) + " done"}
	if got := f2.settled(); !slices.Equal(got, want) {
		t.Fatalf("results = %v, want %v", got, want)
	}
}

// interactiveWorkRules opens a session for each design request, and runs a
// worker with a variant for each split request.
const interactiveWorkRules = `
projects:
  loupe:
    dir: {dir}
launch:
  command: {launcher}
work:
  design:
    action: interactive
    prompt: Design card {cardNumber}.
  split:
    prompt: Split card {cardNumber}.
    variants:
      - {name: a, weight: 1, model: opus}
      - {name: b, weight: 3, model: sonnet}
`

// An interactive entry launches only once the claim holds. A launch that
// works settles done, a failed one refused, and a lost race launches nothing.
func TestAnInteractiveWorkEntryLaunchesAfterItsClaim(t *testing.T) {
	for name, tc := range map[string]struct {
		launcher string
		claimErr error
		launches int
		want     []string
	}{
		"launched":      {`[sh, -c, 'exit 0', sh, '{script}']`, nil, 1, []string{workID(1) + " " + tokenOf(1) + " done"}},
		"failed":        {`[sh, -c, 'exit 3', sh, '{script}']`, nil, 1, []string{workID(1) + " " + tokenOf(1) + " refused failed"}},
		"lost the race": {`[sh, -c, 'exit 0', sh, '{script}']`, api.ErrWorkRequestAlreadyClaimed, 0, nil},
	} {
		t.Run(name, func(t *testing.T) {
			h, rec := launchHarnessWith(t, interactiveWorkRules, tc.launcher)
			f := h.withWork()
			f.errs[workID(1)] = tc.claimErr

			h.offer(f, workRequest(1, 87, "design", api.WorkRequestOpen))

			if got := rec.launches(); len(got) != tc.launches {
				t.Fatalf("launches = %+v", got)
			}
			if got := f.settled(); !slices.Equal(got, tc.want) {
				t.Fatalf("results = %v, want %v", got, tc.want)
			}
			if tc.launches > 0 && rec.launches()[0].report.WorkKind != "design" {
				t.Fatalf("launch = %+v", rec.launches()[0].report)
			}
			h.assertNoWorkerState(t, rec)
			if got := h.heldClaims(); len(got) != 0 {
				t.Fatalf("claims = %v", got)
			}
		})
	}
}

// An entry with variants pins its variant per card, as an experiment named
// after the kind.
func TestAWorkEntryWithVariantsPinsItsVariant(t *testing.T) {
	h, rec := launchHarnessWith(t, interactiveWorkRules, `[sh, -c, 'exit 0', sh, '{script}']`)
	f := h.withWork()
	pins := h.pins(func(context.Context, string) (string, string, error) { return "b", "", nil })

	h.offer(f, workRequest(1, 87, "split", api.WorkRequestOpen))

	calls := pins.recorded()
	if len(calls) != 1 || calls[0].experiment != "split" || calls[0].cardID != cardUUID(87) || calls[0].handle != testProject {
		t.Fatalf("pin calls = %+v", calls)
	}
	if got := h.worker.recorded(); len(got) != 1 || got[0].model != "sonnet" {
		t.Fatalf("workers = %+v", got)
	}
	wantExperiment(t, rec.states(), runPin{Experiment: "split", Variant: "b", RequestedModel: "sonnet"})
}
