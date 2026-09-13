package cmd

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"strings"
	"sync"
	"time"

	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/rules"
	"github.com/ubermuda/loupe/cli/internal/transport"
)

// router turns each event a rule matches into a worker process and reports
// what it did.
//
// Workers run in their own goroutines. mu guards the queue, the claimed keys,
// the running count and the closed flag. The logger needs no lock, because slog
// serialises its own writes.
type router struct {
	ctx        context.Context
	log        *slog.Logger
	rules      *rules.Set
	project    string
	topic      string
	maxWorkers int
	worker     workerOps

	mu      sync.Mutex
	claimed map[string]bool
	queue   []pending
	active  int
	closed  bool

	// unmapped remembers the projects already logged as unmapped. Only the
	// stream goroutine reads events, so it needs no lock.
	unmapped map[string]bool

	// wg counts the workers in flight. Tests wait on it instead of sleeping.
	wg sync.WaitGroup
}

// pending is an accepted event that waits for a free worker slot. The prompt
// is rendered when the event is accepted, from the rule that matched it.
type pending struct {
	key   string
	rule  string
	spec  workerSpec
	event event.Event
}

// workerKey identifies the worker for one card.
//
// The card number alone is not enough. It counts from 1 inside a project and
// repeats across them, so two projects would share a key for their card 87.
//
// The project is identified by the last 12 hex digits of its id rather than the
// first. These ids are uuidv7, whose leading bits are a millisecond timestamp,
// so two projects created in the same minute share a leading prefix.
func workerKey(cardNumber int, projectID string) string {
	digits := strings.ReplaceAll(projectID, "-", "")
	if len(digits) > 12 {
		digits = digits[len(digits)-12:]
	}

	return fmt.Sprintf("card-%d-%s", cardNumber, digits)
}

// keyFor is the worker key of the aggregate an event is about. A type this
// build knows no fields of has no card number, so its subject id keys it.
func keyFor(e event.Event) string {
	if e.Type == event.CardMovedType {
		return workerKey(e.CardNumber, e.ProjectID)
	}

	return "subject-" + e.Subject.ID
}

// about names the event's aggregate in a log line.
func about(e event.Event, rule string) []any {
	attrs := []any{"project", e.ProjectID, "rule", rule}
	if e.Type == event.CardMovedType {
		return append([]any{"card", e.CardNumber}, attrs...)
	}

	return append([]any{"subject", e.Subject.ID}, attrs...)
}

func (r *router) handler() transport.Handler {
	return transport.Handler{
		OnConnect: func() { r.log.Info("connected", "topic", r.topic, "project", r.project) },
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

	m := r.rules.Match(e)
	switch m.Skip {
	case rules.Run:
		r.enqueue(pending{
			key:   keyFor(e),
			rule:  m.Rule,
			spec:  workerSpec{dir: m.Dir, permissionMode: m.PermissionMode, model: m.Model, prompt: m.Prompt},
			event: e,
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

// enqueue claims the card's key and puts the event at the back of the queue.
//
// One card gets one worker at a time, on purpose: two agents working one card
// in one checkout undo each other's work. The claim covers queued work as well
// as running work, so a card moved twice while every slot is busy does not run
// twice.
//
// queue_depth counts the accepted events that wait at this moment, this one
// included.
func (r *router) enqueue(p pending) {
	if !r.claim(p.key) {
		r.log.Warn("worker_refused", about(p.event, p.rule)...)

		return
	}

	r.mu.Lock()
	r.queue = append(r.queue, p)
	depth := len(r.queue)
	r.mu.Unlock()

	r.log.Info("worker_queued", append(about(p.event, p.rule), "queue_depth", depth)...)
	r.dispatch()
}

// dispatch starts queued workers while a slot is free. It runs on the goroutine
// that accepted an event, and again on the one that finished a worker.
//
// The pop and the start share one critical section. Two workers that finish at
// the same instant dispatch on their own goroutines, and a start outside the
// lock would let the later card start first.
func (r *router) dispatch() {
	for {
		r.mu.Lock()
		if r.shut() {
			r.mu.Unlock()
			r.dropQueued()

			return
		}
		if r.active >= r.maxWorkers || len(r.queue) == 0 {
			r.mu.Unlock()

			return
		}
		next := r.queue[0]
		r.queue = r.queue[1:]
		r.active++
		r.start(next)
		r.mu.Unlock()
	}
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

// finish frees the slot and the card, then admits whatever waits.
func (r *router) finish(key string) {
	r.mu.Lock()
	r.release(key)
	r.active--
	r.mu.Unlock()

	r.dispatch()
}

// release frees key, so the same card can start another worker later. The
// caller holds mu.
func (r *router) release(key string) {
	delete(r.claimed, key)
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

// dropQueued empties the queue and names what it lost. These workers never
// started, so a silent drop would hide a trigger the operator asked for.
func (r *router) dropQueued() {
	r.mu.Lock()
	dropped := r.queue
	r.queue = nil
	for _, p := range dropped {
		r.release(p.key)
	}
	r.mu.Unlock()

	if len(dropped) == 0 {
		return
	}

	lost := make([]map[string]any, len(dropped))
	for i, p := range dropped {
		lost[i] = map[string]any{"rule": p.rule}
		if p.event.Type == event.CardMovedType {
			lost[i]["card"] = p.event.CardNumber
		} else {
			lost[i]["subject"] = p.event.Subject.ID
		}
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

// claim reserves key for one card. It reports false when that card is already
// queued or already running.
func (r *router) claim(key string) bool {
	r.mu.Lock()
	defer r.mu.Unlock()

	if r.claimed[key] {
		return false
	}
	if r.claimed == nil {
		r.claimed = map[string]bool{}
	}
	r.claimed[key] = true

	return true
}

func (r *router) workerContext() context.Context {
	if r.ctx == nil {
		return context.Background()
	}

	return r.ctx
}
