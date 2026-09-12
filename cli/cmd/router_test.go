package cmd

import (
	"bytes"
	"context"
	"errors"
	"strings"
	"sync"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/event"
)

type workerCall struct {
	dir            string
	permissionMode string
	prompt         string
}

// fakeWorker stands in for a claude process: it records what the router asked
// for and answers with a fixed result. started and block make a test observe
// and hold a running worker with no sleeping.
type fakeWorker struct {
	mu      sync.Mutex
	calls   []workerCall
	started chan workerCall
	block   chan struct{}
	result  workerResult
}

func (f *fakeWorker) ops() workerOps {
	return workerOps{run: func(_ context.Context, dir, permissionMode, prompt string) workerResult {
		call := workerCall{dir, permissionMode, prompt}
		f.mu.Lock()
		f.calls = append(f.calls, call)
		f.mu.Unlock()

		if f.started != nil {
			f.started <- call
		}
		if f.block != nil {
			<-f.block
		}

		return f.result
	}}
}

func (f *fakeWorker) recorded() []workerCall {
	f.mu.Lock()
	defer f.mu.Unlock()

	return append([]workerCall(nil), f.calls...)
}

type harness struct {
	router *router
	worker *fakeWorker
	out    *bytes.Buffer
	errOut *bytes.Buffer
}

func newHarness() *harness {
	w := &fakeWorker{}
	h := &harness{worker: w, out: &bytes.Buffer{}, errOut: &bytes.Buffer{}}
	h.router = &router{out: h.out, errOut: h.errOut, dir: "/src/app", worker: w.ops()}

	return h
}

const cardMovedToNext = `{"type":"board.card_moved","subject":{"type":"card","id":"0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7"},"projectId":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7","cardNumber":87,"fromStatus":"backlog","toStatus":"next"}`

const otherCardMovedToNext = `{"type":"board.card_moved","subject":{"type":"card","id":"0192f3a1-8888-7d3e-8f10-a2b3c4d5e6f7"},"projectId":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7","cardNumber":88,"fromStatus":"backlog","toStatus":"next"}`

func TestCardMovedToNextRunsAWorker(t *testing.T) {
	h := newHarness()
	h.router.permissionMode = "acceptEdits"

	h.router.onData([]byte(cardMovedToNext))
	h.router.wg.Wait()

	calls := h.worker.recorded()
	if len(calls) != 1 {
		t.Fatalf("expected one worker, got %+v", calls)
	}
	if calls[0].dir != "/src/app" || calls[0].permissionMode != "acceptEdits" {
		t.Fatalf("unexpected worker: %+v", calls[0])
	}
	want := directive.CardDirective(event.Event{Subject: event.Subject{Type: "card", ID: "0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7"}, ProjectID: "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7", CardNumber: 87})
	if calls[0].prompt != want {
		t.Fatalf("prompt = %q, want %q", calls[0].prompt, want)
	}
	if !strings.Contains(h.out.String(), "Starting a worker for card 87") {
		t.Fatalf("no start line: out = %q", h.out.String())
	}
	if !strings.Contains(h.out.String(), "worker for card 87 exited 0") {
		t.Fatalf("no end line: out = %q", h.out.String())
	}
}

// TestAFinishedWorkerNoLongerBlocksItsCard is the bug this design fixes: the
// old check asked whether a session existed, so a card that had been worked
// once never started a worker again.
func TestAFinishedWorkerNoLongerBlocksItsCard(t *testing.T) {
	h := newHarness()

	h.router.onData([]byte(cardMovedToNext))
	h.router.wg.Wait()
	h.router.onData([]byte(cardMovedToNext))
	h.router.wg.Wait()

	if calls := h.worker.recorded(); len(calls) != 2 {
		t.Fatalf("expected two workers, got %+v", calls)
	}
	if strings.Contains(h.errOut.String(), "already running") {
		t.Fatalf("a finished worker still blocked its card: %q", h.errOut.String())
	}
}

// TestARunningWorkerBlocksASecondForTheSameCard keeps two workers off one card.
func TestARunningWorkerBlocksASecondForTheSameCard(t *testing.T) {
	h := newHarness()
	h.worker.started = make(chan workerCall, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMovedToNext))
	<-h.worker.started
	h.router.onData([]byte(cardMovedToNext))

	if !strings.Contains(h.errOut.String(), "a worker for card 87 is already running") {
		t.Fatalf("errOut = %q", h.errOut.String())
	}

	close(h.worker.block)
	h.router.wg.Wait()
	if calls := h.worker.recorded(); len(calls) != 1 {
		t.Fatalf("expected one worker, got %+v", calls)
	}
}

// TestTwoCardsRunConcurrently pins that a running worker never blocks the read
// loop or another card. Neither worker returns until both have started.
func TestTwoCardsRunConcurrently(t *testing.T) {
	h := newHarness()
	h.worker.started = make(chan workerCall, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMovedToNext))
	h.router.onData([]byte(otherCardMovedToNext))

	<-h.worker.started
	<-h.worker.started

	close(h.worker.block)
	h.router.wg.Wait()
	if calls := h.worker.recorded(); len(calls) != 2 {
		t.Fatalf("expected two workers, got %+v", calls)
	}
}

func TestANonZeroExitIsReported(t *testing.T) {
	h := newHarness()
	h.worker.result = workerResult{exitCode: 2, output: "claude: permission denied"}

	h.router.onData([]byte(cardMovedToNext))
	h.router.wg.Wait()

	if !strings.Contains(h.errOut.String(), "worker for card 87 exited 2") {
		t.Fatalf("errOut = %q", h.errOut.String())
	}
	if !strings.Contains(h.errOut.String(), "claude: permission denied") {
		t.Fatalf("the failure report dropped the output: %q", h.errOut.String())
	}
}

func TestAWorkerThatNeverStartsIsReported(t *testing.T) {
	h := newHarness()
	h.worker.result = workerResult{err: errors.New("boom")}

	h.router.onData([]byte(cardMovedToNext))
	h.router.wg.Wait()

	if !strings.Contains(h.errOut.String(), "worker for card 87 did not start: boom") {
		t.Fatalf("errOut = %q", h.errOut.String())
	}
}

// TestCardMovedElsewhereIsIgnored also closes the feedback loop: the worker's
// own move to in-progress publishes an event this filter rejects.
func TestCardMovedElsewhereIsIgnored(t *testing.T) {
	for _, to := range []string{"backlog", "in-progress", "done", ""} {
		h := newHarness()

		h.router.onData([]byte(`{"type":"board.card_moved","subject":{"type":"card","id":"0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7"},"projectId":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7","cardNumber":87,"fromStatus":"next","toStatus":"` + to + `"}`))
		h.router.wg.Wait()

		if calls := h.worker.recorded(); len(calls) != 0 {
			t.Fatalf("to=%q acted on: %+v", to, calls)
		}
	}
}

// Dragging a card to a new rank inside the next column submits a move with next
// on both sides. This is the real payload the producer writes for that drag.
func TestAReorderInsideNextStartsNoWorker(t *testing.T) {
	h := newHarness()

	h.router.onData([]byte(`{"type":"board.card_moved","subject":{"type":"card","id":"01a0928e-9ea9-7358-aef5-4e1a629da79a"},"projectId":"01a0926f-fa42-7f8c-bfc4-18d17dd8cffb","cardNumber":1,"fromStatus":"next","toStatus":"next"}`))
	h.router.wg.Wait()

	if calls := h.worker.recorded(); len(calls) != 0 {
		t.Fatalf("a reorder inside next started a worker: %+v", calls)
	}
}

// TestUnknownTypeIsDroppedQuietly keeps an older binary usable against a newer
// server, which publishes types this build has never heard of. A submitted site
// review is one of them now.
func TestUnknownTypeIsDroppedQuietly(t *testing.T) {
	for _, payload := range []string{
		`{"type":"board.card_created","projectId":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7","cardNumber":87}`,
		`{"type":"site_review.submitted"}`,
	} {
		h := newHarness()

		h.router.onData([]byte(payload))
		h.router.wg.Wait()

		if h.errOut.Len() != 0 || h.out.Len() != 0 {
			t.Fatalf("%s was reported: out=%q errOut=%q", payload, h.out.String(), h.errOut.String())
		}
		if calls := h.worker.recorded(); len(calls) != 0 {
			t.Fatalf("%s acted on: %+v", payload, calls)
		}
	}
}

func TestMalformedEventIsReported(t *testing.T) {
	h := newHarness()

	h.router.onData([]byte(`not json`))

	if !strings.Contains(h.errOut.String(), "skipping malformed event") {
		t.Fatalf("errOut = %q", h.errOut.String())
	}
}

func TestIncompleteCardEventIsReportedAndDropped(t *testing.T) {
	h := newHarness()

	h.router.onData([]byte(`{"type":"board.card_moved","subject":{"type":"card","id":"0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7"},"cardNumber":87,"toStatus":"next"}`))
	h.router.wg.Wait()

	if !strings.Contains(h.errOut.String(), "skipping malformed event") {
		t.Fatalf("errOut = %q", h.errOut.String())
	}
	if calls := h.worker.recorded(); len(calls) != 0 {
		t.Fatalf("acted on an incomplete card event: %+v", calls)
	}
}

// Two projects number their cards from 1 independently, so the key must
// separate them or one project's card 87 blocks the other's.
func TestWorkerKeySeparatesProjects(t *testing.T) {
	const a = "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7"
	const b = "0192f3a1-4b2c-7d3e-8f10-ffffffffffff"

	if got := workerKey(87, a); got != "card-87-a2b3c4d5e6f7" {
		t.Fatalf("workerKey(87, a) = %q", got)
	}
	if workerKey(87, a) == workerKey(87, b) {
		t.Fatalf("card 87 in two projects collided on %q", workerKey(87, a))
	}
}

// These ids are uuidv7, so the leading digits are a millisecond timestamp and
// two projects created close together share them. Keying on a leading prefix
// would reintroduce the collision.
func TestWorkerKeyIgnoresTheTimestampPrefix(t *testing.T) {
	const sameMillisecond = "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7"
	const alsoSameMillisecond = "0192f3a1-4b2c-7d3e-8f10-0000000000ff"

	if workerKey(87, sameMillisecond) == workerKey(87, alsoSameMillisecond) {
		t.Fatalf("two projects sharing a timestamp prefix collided")
	}
}
