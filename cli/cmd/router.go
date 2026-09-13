package cmd

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"slices"
	"strings"
	"sync"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/event"
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
	// bridgeID names the bridge in its rule health reports. With none, as in
	// most tests, the bridge sends no report.
	bridgeID string
	health   *healthReporter

	mu sync.Mutex
	// queue holds the accepted events in arrival order, at most one for each
	// card and rule. running holds the key of each card with a worker. The two
	// are separate key spaces: a card runs once, and waits once per rule.
	queue   []pending
	running map[string]bool
	// chains counts, per card key and rule name, the runs in a row that an
	// agent's event started. An entry must outlive its workers to hold the cap,
	// so it stays until a person acts: one small map per card agents ran on.
	chains map[string]map[string]int
	active int
	closed bool

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
}

// keyFor keys the running worker and the chain counters by the subject id,
// the one identity every event type carries. A card number repeats across
// projects, and a type this build knows no fields of may carry none.
func keyFor(e event.Event) string {
	return e.Subject.ID
}

// label names an event's aggregate to a reader: its card number when the event
// carries one, and its subject id otherwise.
func label(e event.Event) (string, any) {
	if e.Type == event.CardMovedType {
		return "card", e.CardNumber
	}

	return "subject", e.Subject.ID
}

// about names the event's aggregate in a log line.
func about(e event.Event, rule string) []any {
	k, v := label(e)

	return []any{k, v, "project", e.ProjectID, "rule", rule}
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
	e, err := event.Parse(data, r.rules.ExtraTypes())
	if err != nil {
		if errors.Is(err, event.ErrUnknownType) {
			return
		}
		r.log.Error("event_malformed", "error", err.Error())

		return
	}

	// A slug change is a person's action, not a directive to an agent, so it
	// kills rules whatever its actor. Matching then goes on as for any event.
	if dead := r.rules.Kill(e); len(dead) > 0 {
		for _, d := range dead {
			r.log.Error("rule_dead", "rule", d.Rule, "project", e.ProjectID, "project_slug", d.Project, "reason", d.Reason,
				"message", fmt.Sprintf("rule %s matches nothing until the bridge restarts, because %s", d.Rule, slugChange(e, d.Project)))
		}
		r.reportHealth(dead[0].Project)
	}

	// A person who touches the card has seen it, which is what a capped chain
	// waits for. Any event of theirs that parsed counts, matched or not.
	if e.Actor == event.ActorHuman {
		r.mu.Lock()
		delete(r.chains, keyFor(e))
		r.mu.Unlock()
	}

	m := r.rules.Match(e)
	switch m.Skip {
	case rules.Run:
		r.enqueue(pending{
			key:      keyFor(e),
			rule:     m.Rule,
			maxChain: m.MaxChain,
			spec:     workerSpec{dir: m.Dir, permissionMode: m.PermissionMode, model: m.Model, prompt: m.Prompt},
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

// onRefresh logs, once for each, a mapped project that a fresh GET /api/events
// no longer lists, with the rules that stop working.
func (r *router) onRefresh(events api.Events) {
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

		dead := r.rules.KillProject(slug, api.ReasonProjectGone)
		for _, d := range dead {
			r.log.Error("rule_dead", "rule", d.Rule, "project", r.rules.ProjectID(slug), "project_slug", slug, "reason", d.Reason,
				"message", fmt.Sprintf("rule %s matches nothing until the bridge restarts, because project %s is gone", d.Rule, slug))
		}
		// The server most likely answers project_not_found, which the reporter
		// logs once and does not retry.
		if len(dead) > 0 {
			r.reportHealth(slug)
		}
	}
}

// enqueue puts the event at the back of the queue, or in place of a waiting
// event for the same card and rule. It refuses an agent's event once its rule
// has run maxChain times in a row on the card from agents' events. It logs
// under mu, so no line for this event can follow worker_started.
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
	if i := slices.IndexFunc(r.queue, func(q pending) bool { return q.key == p.key && q.rule == p.rule }); i >= 0 {
		r.queue[i] = p
		r.log.Info("worker_coalesced", about(p.event, p.rule)...)
		r.mu.Unlock()

		return
	}
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

// dispatchLocked starts the oldest queued events whose card has no worker, while
// a slot is free. The caller holds mu, and logs the queue a shut router returns.
// One card runs one worker, because two agents in one checkout undo each other.
// Pop and start share the lock, or two finishing workers could reorder starts.
func (r *router) dispatchLocked() []pending {
	if r.shut() {
		dropped := r.queue
		r.queue = nil

		return dropped
	}

	for r.active < r.maxWorkers {
		i := slices.IndexFunc(r.queue, func(q pending) bool { return !r.running[q.key] })
		if i < 0 {
			return nil
		}
		next := r.queue[i]
		r.queue = slices.Delete(r.queue, i, i+1)
		r.active++
		if r.running == nil {
			r.running = map[string]bool{}
		}
		r.running[next.key] = true
		if next.event.Actor == event.ActorAgent {
			r.countChain(next.key, next.rule)
		}
		r.start(next)
	}

	return nil
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

// start runs one worker. The caller holds mu, and start never takes it.
//
// wg counts the worker before the goroutine exists, and the finish call that
// admits the next worker runs before wg.Done, so a waiter never sees the count
// reach zero between two queued workers.
func (r *router) start(p pending) {
	r.log.Info("worker_started", about(p.event, p.rule)...)

	r.wg.Add(1)
	go func() {
		defer r.wg.Done()

		began := time.Now()
		res := r.worker.run(r.workerContext(), p.spec)
		r.report(p, res, time.Since(began))
		r.finish(p.key)
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
// once per rule, so each entry names the card and the rule.
func (r *router) logDropped(dropped []pending) {
	if len(dropped) == 0 {
		return
	}

	lost := make([]map[string]any, len(dropped))
	for i, p := range dropped {
		k, v := label(p.event)
		lost[i] = map[string]any{k: v, "rule": p.rule}
	}
	r.log.Warn("queue_dropped", "count", len(lost), "dropped", lost)
}

// report says how a worker ended, and carries the output it captured. The
// bridge owns the worker's streams, so this report is the operator's only view
// of what claude answered or why it failed.
func (r *router) report(p pending, res workerResult, elapsed time.Duration) {
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
