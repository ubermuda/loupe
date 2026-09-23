package cmd

import (
	"cmp"
	"context"
	"errors"
	"fmt"
	"log/slog"
	"slices"
	"strings"
	"sync"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/outbound"
	"github.com/ubermuda/loupe/cli/internal/rules"
	"github.com/ubermuda/loupe/cli/internal/transport"
)

// router turns each event a rule matches into a worker process and reports
// what it did. Workers run in their own goroutines. mu guards the fields below
// it, and slog serialises its own writes.
type router struct {
	ctx        context.Context
	log        *slog.Logger
	rules      *rules.Set
	projects   []string
	topic      string
	maxWorkers int
	worker     workerOps
	// bridgeID names the bridge in every report it sends, of a run and of rule
	// health alike. With none, as in most tests, the bridge sends no report.
	bridgeID string
	// reports carries each finished run to Loupe, and runs builds what it
	// carries. A nil queue reports nothing.
	reports outbound.Queue
	runs    *runReports
	health  *healthReporter
	// heartbeat tells Loupe the bridge runs. A nil one sends nothing.
	heartbeat *heartbeater
	// checkAsk reads an ask before its session resumes, on the worker goroutine
	// and never behind the report queue. A nil one resumes with no check.
	checkAsk     func(ctx context.Context, handle, askID string) (api.AskState, error)
	checkTimeout time.Duration

	mu sync.Mutex
	// queue holds the accepted events in arrival order, at most one for each
	// card and rule, or for each ask of a resume. running holds the key of each
	// card with a worker or an ask check. A card runs once, and waits once per
	// rule or ask.
	queue   []pending
	running map[string]bool
	// chains counts, per card key and rule name, the runs in a row that an
	// agent's event started. An entry must outlive its workers to hold the cap,
	// so it stays until a person acts: one small map per card agents ran on.
	chains map[string]map[string]int
	active int
	closed bool
	seq    uint64
	// inbox is the inbox flag of the last GET /api/events. A worker that starts
	// while it is on reads both ids in its prompt.
	inbox bool
	// sessions maps each session the bridge ran to its key and card. An entry
	// outlives its worker, because an ask can close before the run report lands.
	sessions map[string]sessionCard

	// unmapped remembers the projects already logged as unmapped, and gone the
	// mapped projects already logged as gone. Only the stream goroutine reads
	// events and refreshes the JWT, so neither needs a lock.
	unmapped map[string]bool
	gone     map[string]bool

	// wg counts the workers in flight. Tests wait on it instead of sleeping.
	wg sync.WaitGroup
}

// pending is an accepted event that waits for a free worker slot, and for its
// card's running worker to exit. The prompt is rendered when the event is
// accepted, from the rule that matched it.
type pending struct {
	key      string
	rule     string
	maxChain int
	spec     workerSpec
	event    event.Event
	// checked marks a resume whose ask check let it run. It holds its card key
	// already, and waits for a slot alone.
	checked bool
	// seq is the arrival order. A resume back from its check returns to it.
	seq uint64
}

// sessionCard is the key a session's worker ran under, and its card when the
// event named one.
type sessionCard struct {
	key    string
	id     string
	number int
}

// askCheckTimeout bounds the ask check. A check that runs out resumes anyway.
const askCheckTimeout = 10 * time.Second

// keyFor keys the running worker and the chain counters by the subject id,
// which every event type carries. A card number repeats across projects, and a
// type this build knows no fields of may carry none. The subject of an ask is
// no card, so resolve keys a resume instead.
func keyFor(e event.Event) string {
	return e.Subject.ID
}

// resolve keys an ask on its card, so its resume waits behind any worker of
// that card. The card comes from the event, else from the session the bridge
// ran, and the key falls back to the session id. It fills the event's card
// from the session, so the prompt and the report name it too.
func (r *router) resolve(e event.Event) (event.Event, string) {
	if e.Type != event.AskClosedType {
		return e, keyFor(e)
	}
	if e.CardID != "" {
		return e, e.CardID
	}
	r.mu.Lock()
	s, ok := r.sessions[e.SessionID]
	r.mu.Unlock()
	if !ok {
		return e, e.SessionID
	}
	if s.number > 0 {
		e.CardID, e.CardNumber = s.id, s.number
	}

	return e, s.key
}

// cardOf is the card a run of the event reports against. A number below 1
// means the event names no card.
func cardOf(e event.Event) (string, int) {
	if e.Type == event.AskClosedType {
		return e.CardID, e.CardNumber
	}

	return e.Subject.ID, e.CardNumber
}

// askOf is the ask a resume continues, and "" for any other event. Each ask
// closes once, so a resume never replaces another in the queue.
func askOf(e event.Event) string {
	if e.Type == event.AskClosedType {
		return e.Subject.ID
	}

	return ""
}

// label names an event's aggregate to a reader: its card number when the event
// carries one, and its subject id otherwise.
func label(e event.Event) (string, any) {
	if e.Type == event.CardMovedType || (e.Type == event.AskClosedType && e.CardNumber > 0) {
		return "card", e.CardNumber
	}

	return "subject", e.Subject.ID
}

// about names the event's aggregate in a log line, and a resume's ask and
// session.
func about(e event.Event, rule string) []any {
	k, v := label(e)
	out := []any{k, v, "project", e.ProjectID, "rule", rule}
	if e.Type == event.AskClosedType {
		out = append(out, "ask", e.Subject.ID, "session_id", e.SessionID)
	}

	return out
}

// aggregate names the event's aggregate in a sentence.
func aggregate(e event.Event) string {
	k, v := label(e)

	return fmt.Sprintf("%s %v", k, v)
}

func (r *router) handler() transport.Handler {
	return transport.Handler{
		OnConnect: func() { r.log.Info("connected", "topic", r.topic, "projects", r.projects) },
		OnError:   func(err error) { r.log.Error("stream_error", "error", err.Error()) },
		OnData:    r.onData,
	}
}

// onData routes one Mercure payload.
//
// A type no rule names is dropped in silence: a newer server publishes types
// an older binary never heard of, which is normal.
func (r *router) onData(data []byte) {
	// Every bridge of the account receives an ask event, and only the one that
	// started the session can resume it. Another bridge's event is not ours to
	// validate, so it is dropped before Parse can log it.
	if event.ForAnotherBridge(data, r.bridgeID) {
		return
	}
	e, err := event.Parse(data, r.rules.ExtraTypes())
	if err != nil {
		if errors.Is(err, event.ErrUnknownType) {
			return
		}
		r.log.Error("event_malformed", "error", err.Error())

		return
	}
	e, key := r.resolve(e)

	// A slug change is a person's action, not a directive to an agent, so it
	// kills rules whatever its actor. Matching then goes on as for any event.
	if dead, dropped := r.kill(func() []rules.Dead { return r.rules.Kill(e) }); len(dead) > 0 {
		for _, d := range dead {
			r.log.Error("rule_dead", "rule", d.Rule, "project", e.ProjectID, "project_slug", d.Project, "reason", d.Reason,
				"message", fmt.Sprintf("rule %s matches nothing until the bridge restarts, because %s", d.Rule, slugChange(e, d.Project)))
		}
		r.logDropped(dropped)
		r.reportHealth(dead[0].Project)
	}

	// A person who touches the card has seen it, which is what a capped chain
	// waits for. Any event of theirs that parsed counts, matched or not.
	if e.Actor == event.ActorHuman {
		r.mu.Lock()
		delete(r.chains, key)
		r.mu.Unlock()
	}

	m := r.rules.Match(e)
	switch m.Skip {
	case rules.Run:
		r.enqueue(pending{
			key:      key,
			rule:     m.Rule,
			maxChain: m.MaxChain,
			spec:     workerSpec{dir: m.Dir, permissionMode: m.PermissionMode, model: m.Model, prompt: m.Prompt, resume: m.Resume},
			event:    e,
		})
	case rules.Untrusted:
		r.log.Warn("event_untrusted", about(e, m.Rule)...)
	case rules.Unmapped:
		if !r.unmapped[e.ProjectID] {
			if r.unmapped == nil {
				r.unmapped = map[string]bool{}
			}
			r.unmapped[e.ProjectID] = true
			r.log.Warn("project_unmapped", "project", e.ProjectID)
		}
	}
}

// kill runs a rule kill and removes the queued events of the rules it killed,
// in one critical section, so a worker that finishes cannot start one of them
// in between. The caller logs what it returns.
func (r *router) kill(do func() []rules.Dead) ([]rules.Dead, []pending) {
	r.mu.Lock()
	defer r.mu.Unlock()

	dead := do()
	if len(dead) == 0 {
		return nil, nil
	}
	names := map[string]bool{}
	for _, d := range dead {
		names[d.Rule] = true
	}
	var dropped []pending
	released := false
	r.queue = slices.DeleteFunc(r.queue, func(p pending) bool {
		if names[p.rule] {
			dropped = append(dropped, p)
			// A checked resume holds its card, so dropping it frees the card.
			if p.checked {
				delete(r.running, p.key)
				released = true
			}

			return true
		}

		return false
	})
	if released {
		dropped = append(dropped, r.dispatchLocked()...)
	}

	return dead, dropped
}

// slugChange says in words what a slug-changing event did.
func slugChange(e event.Event, project string) string {
	switch e.Type {
	case event.ColumnRenamedType:
		return fmt.Sprintf("column %s of project %s is now %s", e.FromSlug, project, e.ToSlug)
	case event.ColumnDeletedType:
		return fmt.Sprintf("column %s of project %s was deleted", e.Slug, project)
	default:
		return fmt.Sprintf("project %s is now %s", e.FromSlug, e.ToSlug)
	}
}

// reportHealth hands the current health of one project's rules to the
// reporter. The slice is built here, so the reporter never reads the set.
func (r *router) reportHealth(project string) {
	if r.health == nil {
		return
	}
	r.health.submit(project, r.rules.ProjectID(project), r.rules.Health(project))
}

// applyFlags keeps the flags of one GET /api/events answer for the workers that
// start after it, and gives its heartbeat interval to the heartbeat.
func (r *router) applyFlags(events api.Events) {
	r.mu.Lock()
	r.inbox = events.Enabled(api.InboxFlag)
	r.mu.Unlock()
	if r.heartbeat != nil {
		r.heartbeat.setInterval(heartbeatInterval(events))
	}
}

// onRefresh applies the flags of a fresh GET /api/events. It logs, once for
// each, a mapped project that the answer no longer lists, with the rules that
// stop working.
func (r *router) onRefresh(events api.Events) {
	r.applyFlags(events)
	for _, slug := range missingProjects(r.rules, events) {
		if r.gone[slug] {
			continue
		}
		if r.gone == nil {
			r.gone = map[string]bool{}
		}
		r.gone[slug] = true

		var names []string
		for _, rule := range r.rules.Rules() {
			if rule.Project == slug {
				names = append(names, rule.Name)
			}
		}
		r.log.Error("project_gone",
			"project", slug,
			"rules", names,
			"message", fmt.Sprintf("project %s is deleted or no longer yours, so rules %s stop working", slug, strings.Join(names, ", ")),
		)

		dead, dropped := r.kill(func() []rules.Dead { return r.rules.KillProject(slug, api.ReasonProjectGone) })
		for _, d := range dead {
			r.log.Error("rule_dead", "rule", d.Rule, "project", r.rules.ProjectID(slug), "project_slug", slug, "reason", d.Reason,
				"message", fmt.Sprintf("rule %s matches nothing until the bridge restarts, because project %s is gone", d.Rule, slug))
		}
		r.logDropped(dropped)
		// The server most likely answers project_not_found, which the reporter
		// logs once and does not retry.
		if len(dead) > 0 {
			r.reportHealth(slug)
		}
	}
}

// enqueue puts the event at the back of the queue, or in place of a waiting
// event for the same card and rule. A resume replaces only a waiting resume of
// the same ask, because each ask closes once. It refuses an agent's event once
// its rule has run maxChain times in a row on the card from agents' events. It
// logs under mu, so no line for this event can follow worker_started.
func (r *router) enqueue(p pending) {
	r.mu.Lock()
	if p.event.Actor == event.ActorAgent && r.chains[p.key][p.rule] >= p.maxChain {
		r.log.Warn("chain_capped", append(about(p.event, p.rule),
			"max_chain", p.maxChain,
			"message", fmt.Sprintf("%s hit the chain cap of rule %s, waiting for a person", aggregate(p.event), p.rule),
		)...)
		r.mu.Unlock()

		return
	}
	if i := slices.IndexFunc(r.queue, func(q pending) bool {
		return q.key == p.key && q.rule == p.rule && askOf(q.event) == askOf(p.event)
	}); i >= 0 {
		p.checked, p.seq = r.queue[i].checked, r.queue[i].seq
		r.queue[i] = p
		r.log.Info("worker_coalesced", about(p.event, p.rule)...)
		r.mu.Unlock()

		return
	}
	r.seq++
	p.seq = r.seq
	r.queue = append(r.queue, p)
	r.log.Info("worker_queued", append(about(p.event, p.rule), "queue_depth", len(r.queue))...)
	r.mu.Unlock()

	r.dispatch()
}

// dispatch starts queued workers while a slot is free. It runs on the goroutine
// that accepted an event.
func (r *router) dispatch() {
	r.mu.Lock()
	dropped := r.dispatchLocked()
	r.mu.Unlock()

	r.logDropped(dropped)
}

// dispatchLocked starts the oldest queued events whose card is free, while a
// slot is free, and starts the ask check of a queued resume whether or not a
// slot is. The caller holds mu, and logs the queue a shut router returns. One
// card runs one worker, because two agents in one checkout undo each other.
// Pop and start share the lock, or two finishing workers could reorder starts.
func (r *router) dispatchLocked() []pending {
	if r.shut() {
		dropped := r.queue
		r.queue = nil

		return dropped
	}

	// waiting holds the keys of events this pass leaves queued, so a later
	// event of the same card never goes first.
	waiting := map[string]bool{}
	for i := 0; i < len(r.queue); {
		next := r.queue[i]
		if waiting[next.key] || (r.running[next.key] && !next.checked) {
			waiting[next.key] = true
			i++

			continue
		}
		if next.spec.resume && !next.checked && r.checkAsk != nil {
			r.queue = slices.Delete(r.queue, i, i+1)
			r.hold(next.key)
			r.check(next)

			continue
		}
		if r.active >= r.maxWorkers {
			waiting[next.key] = true
			i++

			continue
		}
		r.queue = slices.Delete(r.queue, i, i+1)
		// Asks never coalesce, so several agent closes of one card can pass the
		// cap at enqueue and wait together. The cap is read again here.
		if next.spec.resume && next.event.Actor == event.ActorAgent && r.chains[next.key][next.rule] >= next.maxChain {
			r.log.Warn("chain_capped", append(about(next.event, next.rule),
				"max_chain", next.maxChain,
				"message", fmt.Sprintf("%s hit the chain cap of rule %s, waiting for a person", aggregate(next.event), next.rule),
			)...)
			delete(r.running, next.key)

			continue
		}
		r.active++
		r.hold(next.key)
		if next.event.Actor == event.ActorAgent {
			r.countChain(next.key, next.rule)
		}
		r.start(next)
	}

	return nil
}

// hold reserves a card key. The caller holds mu.
func (r *router) hold(key string) {
	if r.running == nil {
		r.running = map[string]bool{}
	}
	r.running[key] = true
}

// countChain records one more run in a row from an agent's event. The caller
// holds mu. It counts at start rather than on acceptance, so an event that
// replaces a waiting one counts once.
func (r *router) countChain(key, rule string) {
	if r.chains == nil {
		r.chains = map[string]map[string]int{}
	}
	if r.chains[key] == nil {
		r.chains[key] = map[string]int{}
	}
	r.chains[key][rule]++
}

// start runs one worker, as a new claude session or as the resume of the
// session its ask names. The caller holds mu, and start never takes it.
//
// wg counts the worker before the goroutine exists, and the finish call that
// admits the next worker runs before wg.Done, so a waiter never sees the count
// reach zero between two queued workers.
func (r *router) start(p pending) {
	if p.spec.resume {
		p.spec.sessionID = p.event.SessionID
		r.log.Info("worker_started", about(p.event, p.rule)...)
	} else {
		p.spec.sessionID = r.worker.sessionID()
		r.log.Info("worker_started", append(about(p.event, p.rule), "session_id", p.spec.sessionID)...)
	}
	if r.inbox {
		p.spec.prompt += "\n" + directive.InboxLine(p.spec.sessionID, r.bridgeID)
	}
	id, number := cardOf(p.event)
	if r.sessions == nil {
		r.sessions = map[string]sessionCard{}
	}
	r.sessions[p.spec.sessionID] = sessionCard{key: p.key, id: id, number: number}

	r.wg.Add(1)
	go func() {
		defer r.wg.Done()

		began := time.Now()
		res := r.worker.run(r.workerContext(), p.spec)
		r.report(p, res, began, time.Since(began))
		r.finish(p.key)
	}()
}

// check reads the ask of a resume the queue released, on its own goroutine.
// The resume holds its card key and no worker slot meanwhile. A session that
// read every item of its closed ask is skipped. Any failed check resumes, so a
// fault never loses a resume. The caller holds mu, and check never takes it.
func (r *router) check(p pending) {
	r.wg.Add(1)
	go func() {
		defer r.wg.Done()

		timeout := r.checkTimeout
		if timeout <= 0 {
			timeout = askCheckTimeout
		}
		ctx, cancel := context.WithTimeout(r.workerContext(), timeout)
		state, err := r.checkAsk(ctx, p.event.ProjectID, p.event.Subject.ID)
		cancel()

		// A kill drops queued events only, and this resume was out of the queue
		// during the check, so its rule is read again under the same lock.
		r.mu.Lock()
		switch {
		case r.shut() || !r.rules.Live(p.rule):
			delete(r.running, p.key)
			dropped := append([]pending{p}, r.dispatchLocked()...)
			r.mu.Unlock()
			r.logDropped(dropped)

			return
		case err != nil:
			r.log.Warn("resume_check_failed", append(about(p.event, p.rule),
				"error", err.Error(),
				"message", "the bridge could not check the ask, so it resumes the session anyway",
			)...)
			p.checked = true
		case state.Closed && state.AllRead:
			r.log.Info("resume_skipped", append(about(p.event, p.rule),
				"message", "the session already read every item of its ask",
			)...)
			delete(r.running, p.key)
		default:
			p.checked = true
		}
		// A resume the check lets through returns to its place in arrival order.
		if p.checked {
			i, _ := slices.BinarySearchFunc(r.queue, p.seq, func(q pending, seq uint64) int { return cmp.Compare(q.seq, seq) })
			r.queue = slices.Insert(r.queue, i, p)
		}
		dropped := r.dispatchLocked()
		r.mu.Unlock()

		r.logDropped(dropped)
	}()
}

// finish frees the slot and the card, and starts what waits, in one critical
// section. A new event for the card cannot slip between the release and the
// start of the card's waiting event.
func (r *router) finish(key string) {
	r.mu.Lock()
	delete(r.running, key)
	r.active--
	dropped := r.dispatchLocked()
	r.mu.Unlock()

	r.logDropped(dropped)
}

// shut reports whether the queue accepts no more starts. The caller holds mu.
//
// A cancelled context counts. Ctrl-C kills the workers before Subscribe
// unwinds, so a worker that finishes first would otherwise start a queued card
// that cannot run.
func (r *router) shut() bool {
	return r.closed || r.workerContext().Err() != nil
}

// shutdown stops the queue for good and drops what is still in it.
func (r *router) shutdown() {
	r.mu.Lock()
	r.closed = true
	r.mu.Unlock()

	r.dispatch()
}

// logDropped names what a shut queue lost. These workers never started, so a
// silent drop would hide a trigger the operator asked for. One card can wait
// once per rule or per ask, so each entry names the card, the rule and the ask.
func (r *router) logDropped(dropped []pending) {
	if len(dropped) == 0 {
		return
	}

	lost := make([]map[string]any, len(dropped))
	for i, p := range dropped {
		k, v := label(p.event)
		lost[i] = map[string]any{k: v, "rule": p.rule}
		if ask := askOf(p.event); ask != "" {
			lost[i]["ask"] = ask
		}
	}
	r.log.Warn("queue_dropped", "count", len(lost), "dropped", lost)
}

// report says how a worker ended. The log is the operator's view, and the queue
// carries the same run to Loupe.
func (r *router) report(p pending, res workerResult, began time.Time, elapsed time.Duration) {
	r.logResult(p, res, elapsed)
	r.enqueueReport(p, res, began, elapsed)
}

// enqueueReport hands one finished run to Loupe. A worker that never ran sends
// no exit code and says why instead, because the server keeps the two faults
// apart.
//
// endedAt is derived from the start, so the value the server reads can never
// precede startedAt, whatever the wall clock does between the two calls.
func (r *router) enqueueReport(p pending, res workerResult, began time.Time, elapsed time.Duration) {
	if r.reports == nil || r.runs == nil {
		return
	}
	cardID, cardNumber := cardOf(p.event)
	if cardNumber < 1 {
		r.log.Warn("report_skipped", append(about(p.event, p.rule),
			"message", "Loupe records a run against a card, and this event names none",
		)...)

		return
	}

	run := api.WorkerRun{
		BridgeID:   r.bridgeID,
		SessionID:  p.spec.sessionID,
		CardID:     cardID,
		CardNumber: cardNumber,
		RuleName:   p.rule,
		StartedAt:  began,
		EndedAt:    began.Add(elapsed),
		Output:     res.output,
	}
	if res.err == nil {
		run.ExitCode = &res.exitCode
	} else {
		reason := res.err.Error()
		run.FailureReason = &reason
	}

	// The handle is the project id the event carried, which a rename never
	// changes.
	r.reports.Enqueue(r.runs.final(p.event.ProjectID, run))
}

// logResult writes what a worker ended as. The bridge owns the worker's
// streams, so this line is the operator's only view of what claude answered or
// why it failed.
func (r *router) logResult(p pending, res workerResult, elapsed time.Duration) {
	if res.err != nil {
		r.log.Error("worker_failed", append(about(p.event, p.rule), "error", res.err.Error())...)

		return
	}

	args := append(about(p.event, p.rule),
		"exit", res.exitCode,
		"duration_ms", elapsed.Milliseconds(),
		"output", res.output,
	)
	if res.exitCode != 0 {
		r.log.Error("worker_finished", args...)

		return
	}

	r.log.Info("worker_finished", args...)
}

func (r *router) workerContext() context.Context {
	if r.ctx == nil {
		return context.Background()
	}

	return r.ctx
}
