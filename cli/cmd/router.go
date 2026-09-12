package cmd

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"strings"
	"sync"
	"time"

	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/transport"
)

// router turns each event into a worker process and reports what it did.
//
// Workers run in their own goroutines. mu guards the queue, the claimed keys,
// the running count and the closed flag. The logger needs no lock, because slog
// serialises its own writes.
type router struct {
	ctx            context.Context
	log            *slog.Logger
	dir            string
	site           string
	topic          string
	permissionMode string
	maxWorkers     int
	worker         workerOps

	mu      sync.Mutex
	claimed map[string]bool
	queue   []pending
	active  int
	closed  bool

	// wg counts the workers in flight. Tests wait on it instead of sleeping.
	wg sync.WaitGroup
}

// pending is an accepted event that waits for a free worker slot.
type pending struct {
	key   string
	event event.Event
}

// workerKey identifies the worker for one card. One card gets one worker at a
// time, and the key is what the bridge compares to refuse a second.
//
// The card number alone is not enough. It counts from 1 inside a project and
// repeats across them, so two projects would share a key for their card 87.
//
// The project is identified by the last 12 hex digits of its id rather than the
// first. These ids are uuidv7, whose leading bits are a millisecond timestamp,
// so two projects created in the same minute share a leading prefix. The
// trailing digits come from the random block.
func workerKey(cardNumber int, projectID string) string {
	digits := strings.ReplaceAll(projectID, "-", "")
	if len(digits) > 12 {
		digits = digits[len(digits)-12:]
	}

	return fmt.Sprintf("card-%d-%s", cardNumber, digits)
}

func (r *router) handler() transport.Handler {
	return transport.Handler{
		OnConnect: func() { r.log.Info("connected", "topic", r.topic, "site", r.site) },
		OnError:   func(err error) { r.log.Error("stream_error", "error", err.Error()) },
		OnData:    r.onData,
	}
}

// onData routes one Mercure payload.
//
// A type this build does not handle is dropped in silence: a newer server
// publishes types an older binary never heard of, which is normal.
func (r *router) onData(data []byte) {
	e, err := event.Parse(data)
	if err != nil {
		if errors.Is(err, event.ErrUnknownType) {
			return
		}
		r.log.Error("event_malformed", "error", err.Error())

		return
	}

	if e.Type == event.CardMovedType {
		r.onCardMoved(e)
	}
}

// onCardMoved accepts a card that entered next.
//
// Entered, not sits in: a card dragged to a new rank inside the next column
// submits a move with next on both sides, and prioritising that column is the
// most ordinary thing a person does with it. Reacting to the target alone would
// start a worker for every card they reorder.
//
// Every other move is dropped, which also closes the feedback loop: the
// worker's own first act moves the card to in-progress and publishes a second
// event that this filter rejects.
func (r *router) onCardMoved(e event.Event) {
	if e.ToStatus != event.StatusNext || e.FromStatus == event.StatusNext {
		return
	}

	r.enqueue(e)
}

// enqueue claims the card's key and puts the event at the back of the queue.
//
// The claim covers queued work as well as running work. A card moved into next
// twice while every slot is busy would otherwise queue twice and run twice.
//
// queue_depth counts the accepted events that wait at this moment, this one
// included.
func (r *router) enqueue(e event.Event) {
	key := workerKey(e.CardNumber, e.ProjectID)
	if !r.claim(key) {
		r.log.Warn("worker_refused", "card", e.CardNumber, "project", e.ProjectID)

		return
	}

	r.mu.Lock()
	r.queue = append(r.queue, pending{key: key, event: e})
	depth := len(r.queue)
	r.mu.Unlock()

	r.log.Info("worker_queued", "card", e.CardNumber, "project", e.ProjectID, "queue_depth", depth)
	r.dispatch()
}

// dispatch starts queued workers while a slot is free. It runs on the goroutine
// that accepted an event, and again on the one that finished a worker.
//
// A plain counter under the existing mutex bounds the workers. A channel
// semaphore would add a second primitive to the lock this function already
// takes to pop the queue.
func (r *router) dispatch() {
	for {
		r.mu.Lock()
		if r.closed {
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
		r.mu.Unlock()

		r.start(next)
	}
}

// start runs one worker. wg counts it before the goroutine exists, and the
// finish call that admits the next worker runs before wg.Done, so a waiter
// never sees the count reach zero between two queued workers.
func (r *router) start(p pending) {
	e := p.event
	r.log.Info("worker_started", "card", e.CardNumber, "project", e.ProjectID)

	r.wg.Add(1)
	go func() {
		defer r.wg.Done()

		began := time.Now()
		res := r.worker.run(r.workerContext(), r.dir, r.permissionMode, directive.CardDirective(e))
		r.report(e, res, time.Since(began))
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

	cards := make([]int, len(dropped))
	for i, p := range dropped {
		cards[i] = p.event.CardNumber
	}
	r.log.Warn("queue_dropped", "count", len(cards), "cards", cards)
}

// report says how a worker ended, and carries the output it captured. The
// bridge owns the worker's streams, so this report is the operator's only view
// of what claude answered or why it failed.
func (r *router) report(e event.Event, res workerResult, elapsed time.Duration) {
	if res.err != nil {
		r.log.Error("worker_failed", "card", e.CardNumber, "project", e.ProjectID, "error", res.err.Error())

		return
	}

	args := []any{
		"card", e.CardNumber,
		"project", e.ProjectID,
		"exit", res.exitCode,
		"duration_ms", elapsed.Milliseconds(),
		"output", res.output,
	}
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
