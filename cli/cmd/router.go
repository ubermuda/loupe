package cmd

import (
	"context"
	"errors"
	"fmt"
	"io"
	"strings"
	"sync"

	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/transport"
)

// router turns each event into a worker process and reports what it did.
//
// Workers run in their own goroutines, so every field a goroutine touches is
// guarded: logMu serialises the two writers against each other, and mu guards
// the in-flight set.
type router struct {
	ctx            context.Context
	out, errOut    io.Writer
	dir            string
	permissionMode string
	worker         workerOps

	logMu   sync.Mutex
	mu      sync.Mutex
	running map[string]bool

	// wg counts the workers in flight. Tests wait on it instead of sleeping.
	wg sync.WaitGroup
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
		OnConnect: func() { r.logf("Connected to hub; waiting for events…\n") },
		OnError:   func(err error) { r.errf("stream error (will retry): %v\n", err) },
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
		r.errf("skipping malformed event: %v\n", err)

		return
	}

	if e.Type == event.CardMovedType {
		r.onCardMoved(e)
	}
}

// onCardMoved starts a worker for a card that entered next.
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

	// The key is claimed here rather than in the goroutine, so a second event
	// for one card is refused however the two goroutines interleave.
	key := workerKey(e.CardNumber, e.ProjectID)
	if !r.claim(key) {
		r.errf("a worker for card %d is already running; dropping event\n", e.CardNumber)

		return
	}

	prompt := directive.CardDirective(e)
	r.logf("Starting a worker for card %d in %s\n", e.CardNumber, r.dir)

	r.wg.Add(1)
	go func() {
		defer r.wg.Done()
		defer r.release(key)

		r.report(e.CardNumber, r.worker.run(r.workerContext(), r.dir, r.permissionMode, prompt))
	}()
}

// report says how a worker ended. A non-zero exit carries the output, because
// nothing else tells the operator why the worker failed.
func (r *router) report(cardNumber int, res workerResult) {
	switch {
	case res.err != nil:
		r.errf("worker for card %d failed: %v\n", cardNumber, res.err)
	case res.exitCode != 0:
		r.errf("worker for card %d exited %d\n%s\n", cardNumber, res.exitCode, res.output)
	default:
		r.logf("worker for card %d exited 0\n", cardNumber)
	}
}

// claim reserves key for one worker. It reports false when a worker holds it.
func (r *router) claim(key string) bool {
	r.mu.Lock()
	defer r.mu.Unlock()

	if r.running[key] {
		return false
	}
	if r.running == nil {
		r.running = map[string]bool{}
	}
	r.running[key] = true

	return true
}

// release frees key, so the same card can start another worker later.
func (r *router) release(key string) {
	r.mu.Lock()
	defer r.mu.Unlock()

	delete(r.running, key)
}

func (r *router) workerContext() context.Context {
	if r.ctx == nil {
		return context.Background()
	}

	return r.ctx
}

func (r *router) logf(format string, a ...any) {
	r.logMu.Lock()
	defer r.logMu.Unlock()

	fmt.Fprintf(r.out, format, a...)
}

func (r *router) errf(format string, a ...any) {
	r.logMu.Lock()
	defer r.logMu.Unlock()

	fmt.Fprintf(r.errOut, format, a...)
}
