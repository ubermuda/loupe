package cmd

import (
	"cmp"
	"context"
	"errors"
	"fmt"
	"log/slog"
	"maps"
	"slices"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/outbound"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// workClient claims and settles work requests. *api.Client is one.
type workClient interface {
	ClaimWorkRequest(ctx context.Context, bridgeID, id string) (api.Claim, error)
	SettleWorkRequest(ctx context.Context, bridgeID, id, token, state, reason string) (string, error)
}

// The channels a work request arrives on, as its log lines name them.
const (
	workFromEvent     = "event"
	workFromHeartbeat = "heartbeat"
)

// The fallback reasons of a refused work request.
const (
	workBlocked    = "blocked"
	workUnfinished = "unfinished"
	workFailed     = "failed"
	workTimeout    = "timeout"
)

// heldClaim is a work request the bridge claims or holds. token is "" while
// the claim call runs. running is on from the claim until the run ends, and
// a held claim with running off waits for its result to land. gone marks a
// request that was cancelled or expired while the claim call ran.
type heldClaim struct {
	token   string
	runID   string
	req     api.WorkRequest
	running bool
	gone    bool
	// abort ends the launch of an interactive claim until its command returns.
	abort context.CancelFunc
}

// abortableLocked is the context of the launch of p, which an end of its claim
// cancels. The caller holds mu.
func (r *router) abortableLocked(p pending) context.Context {
	c := r.claims[p.work.WorkRequestID]
	if c == nil {
		return context.Background()
	}
	ctx, cancel := context.WithCancel(context.Background())
	c.abort = cancel

	return ctx
}

// isWork reports whether p runs a work request.
func (p pending) isWork() bool {
	return p.work.WorkRequestID != ""
}

// followsWork reports whether p continues a run of a work request, as a
// person's resume or rerun asks.
func (p pending) followsWork() bool {
	return p.origin.Kind != ""
}

// workOrOrigin is the work request p runs or continues, and empty for the run
// of an event.
func (p pending) workOrOrigin() api.WorkRequest {
	if p.isWork() {
		return p.work
	}

	return p.origin
}

// workEvent is the event a run of the work request reports and logs with. It
// names the card of a card subject only.
func workEvent(w api.WorkRequest) event.Event {
	e := event.Event{
		Type: event.WorkRequestType, Subject: event.Subject{Type: "work-request", ID: w.WorkRequestID},
		ProjectID: w.ProjectID,
	}
	if w.OnCard() {
		e.CardID, e.CardNumber = w.SubjectID, w.CardNumber
	}

	return e
}

// matchPending matches a queued run again on set: a work request against the
// work map, and the run of a person's command against the entry of its kind.
// The match keeps the action.
func matchPending(set *rules.Set, p pending) (rules.Match, bool) {
	var m rules.Match
	switch {
	case p.isWork():
		m = set.MatchWork(p.work)
	case p.followsWork():
		m = set.MatchKind(p.origin)
	default:
		return rules.Match{Skip: rules.NoRule}, false
	}

	return m, m.Skip == rules.Run && m.Action == p.action
}

// onWorkRequestEvent takes a bridge.work_request event.
func (r *router) onWorkRequestEvent(data []byte) {
	w, err := event.ParseWorkRequest(data)
	if err != nil {
		r.log.Warn("work_request_dropped", "reason", "malformed", "source", workFromEvent, "error", err.Error())

		return
	}
	r.takeWork(w, workFromEvent)
}

// takeWork acts on one checked work request. An open one is an offer, and any
// other state ends what the bridge holds of it.
func (r *router) takeWork(w api.WorkRequest, source string) {
	if r.workAPI == nil || r.bridgeID == "" {
		return
	}
	if w.State == api.WorkRequestOpen {
		r.offerWork(w, source)

		return
	}
	r.quiesce.RLock()
	defer r.quiesce.RUnlock()
	r.mu.Lock()
	id := w.WorkRequestID
	removed := r.dropQueuedWorkLocked(id)
	c := r.claims[id]
	switch w.State {
	case api.WorkRequestCancelled, api.WorkRequestExpired:
		if c != nil {
			r.log.Info("work_request_ended", append(workAttrs(w, source), "state", w.State)...)
			r.endClaimLocked(id, c)
		}
	case api.WorkRequestDone, api.WorkRequestRefused:
		// A result that landed already dropped the claim. One whose post is
		// in flight drops it here, so the next heartbeat renews no settled
		// claim, and a launch that still runs aborts.
		if c != nil && c.token != "" && !c.running {
			r.endClaimLocked(id, c)
		}
	}
	dropped := r.dispatchLocked()
	r.mu.Unlock()
	if removed {
		r.log.Info("work_request_withdrawn", append(workAttrs(w, source), "state", w.State)...)
	}
	r.logDropped(dropped)
}

// offerWork queues an open offer that the work map runs. The queue drops a
// copy of an offer it holds already.
func (r *router) offerWork(w api.WorkRequest, source string) {
	set := r.rules()
	m := set.MatchWork(w)
	switch m.Skip {
	case rules.Run:
	case rules.Unmapped:
		r.mu.Lock()
		first := r.markUnmappedLocked(w.ProjectID)
		r.mu.Unlock()
		if first {
			r.log.Warn("project_unmapped", "project", w.ProjectID)
		}

		return
	default:
		// The heartbeat offers an open request again at each interval.
		level := slog.LevelInfo
		if source == workFromHeartbeat {
			level = slog.LevelDebug
		}
		r.log.Log(context.Background(), level, "work_request_skipped", append(workAttrs(w, source), "reason", "no_entry")...)

		return
	}
	p := pending{key: w.SubjectID, event: workEvent(w), work: w, set: set}
	// A session that is not on this machine cannot resume, so the work starts fresh.
	if w.ResumeSessionID != "" && m.Action == "" && r.hasTranscript(w.ResumeSessionID) {
		p.event.SessionID = w.ResumeSessionID
	}
	p.apply(m)
	r.enqueue(p)
}

// workAttrs names a work request in a log line.
func workAttrs(w api.WorkRequest, source string) []any {
	return []any{"work_request", w.WorkRequestID, "kind", w.Kind, "subject_type", w.SubjectType, "card", w.CardNumber, "project", w.ProjectID, "source", source}
}

// knownWorkLocked reports whether the bridge queues, claims or holds the work
// request. The caller holds mu.
func (r *router) knownWorkLocked(id string) bool {
	return r.claims[id] != nil || slices.ContainsFunc(r.queue, func(p pending) bool { return p.work.WorkRequestID == id })
}

// dropQueuedWorkLocked removes the queued offer of the work request, and
// reports whether there was one. An offer holds nothing, and the server has
// no run of it, so it goes with no report. The caller holds mu.
func (r *router) dropQueuedWorkLocked(id string) bool {
	n := len(r.queue)
	r.queue = slices.DeleteFunc(r.queue, func(p pending) bool { return p.work.WorkRequestID == id })

	return len(r.queue) != n
}

// dropDeadWorkLocked removes the queued offers that the current set no longer
// runs, as a slug change or a gone project leaves them. The caller holds mu.
func (r *router) dropDeadWorkLocked() []pending {
	set := r.rules()
	var dropped []pending
	r.queue = slices.DeleteFunc(r.queue, func(p pending) bool {
		if !p.isWork() || set.MatchWork(p.work).Skip == rules.Run {
			return false
		}
		p.dropReason = api.DropRuleDead
		dropped = append(dropped, p)

		return true
	})
	if len(dropped) > 0 {
		r.noteBusyLocked()
		r.notePoolsLocked()
	}

	return dropped
}

// claimThenLocked claims the work request of p on its own goroutine, and on a
// claim calls run under mu. When the run must not start, release gives back
// what p holds. A run reports nothing before its claim, so a claim another
// bridge won leaves no run on the server. The caller holds mu.
func (r *router) claimThenLocked(p pending, run func(pending), release func()) {
	registered := r.registerClaimLocked(p)
	r.wg.Add(1)
	go func() {
		defer r.wg.Done()

		var claim api.Claim
		err := registered
		if err == nil {
			claim, err = r.requestClaim(p)
		}
		r.mu.Lock()
		p, verdict := r.takeClaimLocked(p, claim, err, registered == nil)
		if verdict == claimRun {
			run(p)
		} else {
			release()
		}
		dropped := r.dispatchLocked()
		r.mu.Unlock()
		r.logDropped(dropped)
		if verdict == claimRefuse {
			r.settleWork(p, api.WorkRequestRefused, workFailed)
		}
	}()
}

// The verdicts on a claim call.
const (
	claimRun = iota
	claimSkip
	claimRefuse
)

// errClaimCap marks a claim the bridge did not ask for, because it holds the
// most claims one heartbeat renews.
var errClaimCap = fmt.Errorf("the bridge holds %d claims, the most one heartbeat renews", api.MaxWorkClaims)

// errClaimHeld marks a claim the bridge did not ask for, because it claims or
// holds the request already.
var errClaimHeld = errors.New("the bridge claims or holds this work request already")

// registerClaimLocked records the claim before its call starts, so no second
// offer of the request runs or takes its place. The caller holds mu.
func (r *router) registerClaimLocked(p pending) error {
	id := p.work.WorkRequestID
	switch {
	case r.claims[id] != nil:
		return errClaimHeld
	case len(r.claims) >= api.MaxWorkClaims:
		return errClaimCap
	}
	if r.claims == nil {
		r.claims = map[string]*heldClaim{}
	}
	r.claims[id] = &heldClaim{runID: p.runID, req: p.work}

	return nil
}

// requestClaim asks the server for the claim, off mu.
func (r *router) requestClaim(p pending) (api.Claim, error) {
	id := p.work.WorkRequestID
	timeout := r.checkTimeout
	if timeout <= 0 {
		timeout = readTimeout
	}
	ctx, cancel := context.WithTimeout(r.workerContext(), timeout)
	defer cancel()

	return r.workAPI.ClaimWorkRequest(ctx, r.bridgeID, id)
}

// takeClaimLocked reads the answer to a claim. On a claim it keeps the token
// and reports the run as queued. registered says the claim of p made the
// entry, which a failure removes. The caller holds mu.
func (r *router) takeClaimLocked(p pending, claim api.Claim, err error, registered bool) (pending, int) {
	id := p.work.WorkRequestID
	attrs := about(p.event, p.rule)
	if err == nil {
		err = checkClaim(claim, p.work)
		if err != nil {
			r.log.Warn("work_request_claim_invalid", append(attrs, "error", err.Error())...)
			p.claimToken = claim.ClaimToken
			if c := r.claims[id]; c != nil {
				c.token = claim.ClaimToken
			}
			r.noteClaimsLocked()

			return p, claimRefuse
		}
	}
	c := r.claims[id]
	if err != nil {
		if registered && c != nil && c.token == "" {
			delete(r.claims, id)
		}
		r.logClaimFailed(attrs, err)

		return p, claimSkip
	}
	if c == nil || c.gone || r.shut() {
		// The request ended while the claim call ran, or the bridge shuts
		// down. The lease runs out on the server.
		delete(r.claims, id)
		r.log.Info("work_request_not_claimed", append(attrs, "reason", "ended")...)

		return p, claimSkip
	}
	c.token, c.running = claim.ClaimToken, p.action != rules.ActionInteractive
	p.claimToken = claim.ClaimToken
	r.noteClaimsLocked()
	r.log.Info("work_request_claimed", attrs...)
	// A launch reports through its own endpoint, and never as a queued run.
	if p.action != rules.ActionInteractive {
		r.emitLocked(p, api.RunStateReport{State: api.RunQueued})
	}

	return p, claimRun
}

// checkClaim checks the request a claim answers with, and that it is the
// request the offer named.
func checkClaim(claim api.Claim, offer api.WorkRequest) error {
	w := claim.WorkRequest
	if err := event.CheckWorkRequest(&w); err != nil {
		return err
	}
	if w.WorkRequestID != offer.WorkRequestID || w.Kind != offer.Kind || w.ProjectID != offer.ProjectID ||
		w.SubjectType != offer.SubjectType || w.SubjectID != offer.SubjectID {
		return errors.New("the claim answers with another request than the offer")
	}

	return nil
}

// logClaimFailed says why a claim gave nothing. The heartbeat offers an open
// request again, so a failure that clears on its own needs no retry here.
func (r *router) logClaimFailed(attrs []any, err error) {
	var refusal *api.WorkRequestRefusal
	switch {
	case errors.Is(err, api.ErrWorkRequestAlreadyClaimed):
		r.log.Info("work_request_not_claimed", append(attrs, "reason", "already_claimed")...)
	case errors.Is(err, api.ErrWorkRequestNotFound):
		r.log.Info("work_request_not_claimed", append(attrs, "reason", "not_found")...)
	case errors.As(err, &refusal) && refusal.RateLimited():
		r.log.Info("work_request_not_claimed", append(attrs, "reason", "rate_limited")...)
	case errors.Is(err, errClaimHeld):
		r.log.Info("work_request_not_claimed", append(attrs, "reason", "duplicate")...)
	case errors.Is(err, api.ErrWorkRequestsUnsupported), errors.As(err, &refusal), errors.Is(err, errClaimCap):
		r.log.Warn("work_request_not_claimed", append(attrs, "reason", "refused", "error", err.Error())...)
	default:
		r.log.Info("work_request_not_claimed", append(attrs, "reason", "error", "error", err.Error())...)
	}
}

// endClaimLocked ends what the bridge holds of a request that is no longer
// its own. It drops the claim and stops a run that has not ended, which then
// reports stopped and posts no result. A claim call in flight learns of it
// when it returns. The caller holds mu.
func (r *router) endClaimLocked(id string, c *heldClaim) {
	if c.token == "" {
		c.gone = true

		return
	}
	delete(r.claims, id)
	r.noteClaimsLocked()
	// A launch command that returned already leaves the session to the person.
	if c.abort != nil {
		c.abort()
	}
	if !c.running {
		return
	}
	if r.stops == nil {
		r.stops = map[string]bool{}
	}
	if r.stops[c.runID] {
		return
	}
	r.stops[c.runID] = true
	if run, ok := r.live[c.runID]; ok {
		r.stopLiveLocked(run)
	}
}

// loseClaims acts on the claims a heartbeat reply names lost, each with the
// token that heartbeat sent. A claim taken again since then holds another
// token, so the stale answer leaves it. A frozen router leaves them all, so
// the next image reads them in its own reply.
func (r *router) loseClaims(lost []api.WorkClaim) {
	r.quiesce.RLock()
	defer r.quiesce.RUnlock()
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.frozen {
		return
	}
	for _, l := range lost {
		c := r.claims[l.ID]
		if c == nil || c.token == "" || c.token != l.ClaimToken {
			continue
		}
		r.log.Warn("work_request_lost", append(workAttrs(c.req, workFromHeartbeat), "message", "the server no longer holds this claim for the bridge, so the bridge stops its run")...)
		r.endClaimLocked(l.ID, c)
	}
}

// lostOf names, with its token, each claim the heartbeat sent that the reply
// names lost.
func lostOf(sent []api.WorkClaim, ids []string) []api.WorkClaim {
	var out []api.WorkClaim
	for _, c := range sent {
		if slices.Contains(ids, c.ID) {
			out = append(out, c)
		}
	}

	return out
}

// noteWorkDeathLocked logs once when a kill ends the work of a project. The
// work map names no project, so nothing else shows that its offers now go
// unclaimed. before is the reason the work was dead before the kill. The
// caller holds mu.
func (r *router) noteWorkDeathLocked(set *rules.Set, id, before string) {
	slug := slugOf(set, id)
	if slug == "" || before != "" || !set.HasWork() {
		return
	}
	if reason := set.WorkDead(slug); reason != "" {
		r.log.Info("work_dead", "project", id, "project_slug", slug, "reason", reason,
			"message", fmt.Sprintf("the bridge claims no work request of project %s until you fix rules.yaml and run loupe bridge reload", slug))
	}
}

// workClaimsLocked lists the claims the next heartbeat renews, in id order.
// The caller holds mu.
func (r *router) workClaimsLocked() []api.WorkClaim {
	var out []api.WorkClaim
	for _, id := range slices.Sorted(maps.Keys(r.claims)) {
		if c := r.claims[id]; c.token != "" {
			out = append(out, api.WorkClaim{ID: id, ClaimToken: c.token})
		}
	}

	return out
}

// noteClaimsLocked hands the held claims to the heartbeat. The caller holds mu.
func (r *router) noteClaimsLocked() {
	if r.heartbeat == nil {
		return
	}
	r.heartbeat.setClaims(r.workClaimsLocked())
}

// dropClaimLocked forgets the claim of p, when p still holds it. The caller
// holds mu.
func (r *router) dropClaimLocked(id, token string) {
	if c := r.claims[id]; c != nil && c.token == token {
		delete(r.claims, id)
		r.noteClaimsLocked()
	}
}

// workResultLocked decides the result of a work run that ended, and marks its
// claim as settling. It answers false when the run posts none: the claim is
// gone, or the bridge stopped the run. The caller holds mu.
func (r *router) workResultLocked(p pending, res workerResult, shut bool) (string, string, bool) {
	if !p.isWork() || p.claimToken == "" {
		return "", "", false
	}
	id := p.work.WorkRequestID
	c := r.claims[id]
	if c == nil || c.token != p.claimToken {
		return "", "", false
	}
	if shut || res.killed {
		r.dropClaimLocked(id, p.claimToken)

		return "", "", false
	}
	c.running = false
	state, reason := workOutcome(res)

	return state, reason, true
}

// workOutcome maps how a run ended to the result of its work request.
func workOutcome(res workerResult) (string, string) {
	refused := func(fallback string) (string, string) {
		if res.hasResult {
			return api.WorkRequestRefused, cmp.Or(resultReason(res.reason), fallback)
		}

		return api.WorkRequestRefused, fallback
	}
	switch {
	case res.timedOut:
		return api.WorkRequestRefused, workTimeout
	case res.command && res.exitCode == 0:
		return api.WorkRequestDone, ""
	case res.command, res.before, res.resumeGone, res.err != nil, res.exitCode != 0, !res.hasResult:
		return refused(workFailed)
	case res.status == "blocked":
		return refused(workBlocked)
	case res.status == "unfinished":
		return refused(workUnfinished)
	}

	return api.WorkRequestDone, ""
}

// settleWork posts the result of a work request through the report queue,
// which retries a network failure. The claim stays renewed until the post
// settles, so a slow post never loses its lease. A post the queue gives up
// or drops ends the claim too.
func (r *router) settleWork(p pending, state, reason string) {
	id, token := p.work.WorkRequestID, p.claimToken
	attrs := append(about(p.event, p.rule), "state", state, "reason", reason)
	if r.reports == nil || r.bridgeID == "" {
		r.mu.Lock()
		r.dropClaimLocked(id, token)
		r.mu.Unlock()

		return
	}
	_, number := cardOf(p.event)
	r.reports.Enqueue(outbound.Report{
		Card: number,
		Rule: p.rule,
		Send: func(ctx context.Context) (bool, error) {
			stored, err := r.workAPI.SettleWorkRequest(ctx, r.bridgeID, id, token, state, reason)
			var refusal *api.WorkRequestRefusal
			switch {
			case err == nil:
				r.log.Info("work_request_settled", append(attrs, "stored", stored)...)
			case errors.Is(err, api.ErrClaimLost):
				r.log.Warn("work_request_result_lost", append(attrs, "message", "the server no longer holds this claim for the bridge, so it dropped the result")...)
			case errors.As(err, &refusal) && refusal.RateLimited():
				return false, err
			case errors.As(err, &refusal), errors.Is(err, api.ErrWorkRequestNotFound), errors.Is(err, api.ErrWorkRequestsUnsupported):
				r.log.Warn("work_request_result_refused", append(attrs, "error", err.Error())...)
			default:
				return false, err
			}
			r.mu.Lock()
			r.dropClaimLocked(id, token)
			r.mu.Unlock()

			return true, nil
		},
		// The lease of a result that never lands runs out, and the server
		// offers the request again. Renewing it would hold the request forever.
		Lost: func() {
			r.log.Warn("work_request_result_dropped", attrs...)
			r.mu.Lock()
			r.dropClaimLocked(id, token)
			r.mu.Unlock()
		},
	})
}
