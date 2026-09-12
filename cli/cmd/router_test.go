package cmd

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
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
// and hold a running worker with no sleeping. peak records how many ran at
// once, which is what the bound has to hold down.
type fakeWorker struct {
	mu       sync.Mutex
	calls    []workerCall
	inFlight int
	maxSeen  int
	started  chan workerCall
	block    chan struct{}
	result   workerResult
}

func (f *fakeWorker) ops() workerOps {
	return workerOps{run: func(_ context.Context, dir, permissionMode, prompt string) workerResult {
		call := workerCall{dir, permissionMode, prompt}
		f.enter(call)

		if f.started != nil {
			f.started <- call
		}
		if f.block != nil {
			<-f.block
		}
		f.leave()

		return f.result
	}}
}

func (f *fakeWorker) enter(call workerCall) {
	f.mu.Lock()
	defer f.mu.Unlock()

	f.calls = append(f.calls, call)
	f.inFlight++
	if f.inFlight > f.maxSeen {
		f.maxSeen = f.inFlight
	}
}

func (f *fakeWorker) leave() {
	f.mu.Lock()
	defer f.mu.Unlock()

	f.inFlight--
}

func (f *fakeWorker) recorded() []workerCall {
	f.mu.Lock()
	defer f.mu.Unlock()

	return append([]workerCall(nil), f.calls...)
}

func (f *fakeWorker) peak() int {
	f.mu.Lock()
	defer f.mu.Unlock()

	return f.maxSeen
}

// syncBuffer collects the JSON log. slog serialises its own writes, and a test
// reads the buffer while a worker still runs, so the read takes the same lock.
type syncBuffer struct {
	mu  sync.Mutex
	buf bytes.Buffer
}

func (b *syncBuffer) Write(p []byte) (int, error) {
	b.mu.Lock()
	defer b.mu.Unlock()

	return b.buf.Write(p)
}

func (b *syncBuffer) String() string {
	b.mu.Lock()
	defer b.mu.Unlock()

	return b.buf.String()
}

type harness struct {
	router *router
	worker *fakeWorker
	log    *syncBuffer
}

func newHarness() *harness {
	w := &fakeWorker{}
	h := &harness{worker: w, log: &syncBuffer{}}
	h.router = &router{
		log:        newBridgeLogger(h.log),
		dir:        "/src/app",
		maxWorkers: defaultMaxWorkers,
		worker:     w.ops(),
	}

	return h
}

// lines parses the log. Every line must be one JSON object, so a test never
// matches a substring of a formatted line.
func (h *harness) lines(t *testing.T) []map[string]any {
	t.Helper()

	var out []map[string]any
	for _, raw := range strings.Split(strings.TrimSpace(h.log.String()), "\n") {
		if raw == "" {
			continue
		}
		var line map[string]any
		if err := json.Unmarshal([]byte(raw), &line); err != nil {
			t.Fatalf("log line %q is not JSON: %v", raw, err)
		}
		out = append(out, line)
	}

	return out
}

func (h *harness) events(t *testing.T, name string) []map[string]any {
	t.Helper()

	var out []map[string]any
	for _, line := range h.lines(t) {
		if line["event"] == name {
			out = append(out, line)
		}
	}

	return out
}

func (h *harness) only(t *testing.T, name string) map[string]any {
	t.Helper()

	got := h.events(t, name)
	if len(got) != 1 {
		t.Fatalf("expected one %s line, got %d: %v", name, len(got), got)
	}

	return got[0]
}

// num reads a JSON number, which decodes as a float64.
func num(t *testing.T, line map[string]any, key string) int {
	t.Helper()

	v, ok := line[key].(float64)
	if !ok {
		t.Fatalf("%v has no numeric %q", line, key)
	}

	return int(v)
}

func str(t *testing.T, line map[string]any, key string) string {
	t.Helper()

	v, ok := line[key].(string)
	if !ok {
		t.Fatalf("%v has no string %q", line, key)
	}

	return v
}

// cards reads the card list a queue_dropped line carries.
func cards(t *testing.T, line map[string]any) []int {
	t.Helper()

	raw, ok := line["cards"].([]any)
	if !ok {
		t.Fatalf("%v has no cards list", line)
	}
	out := make([]int, len(raw))
	for i, v := range raw {
		n, ok := v.(float64)
		if !ok {
			t.Fatalf("card %v is not a number", v)
		}
		out[i] = int(n)
	}

	return out
}

func startedCards(t *testing.T, h *harness) []int {
	t.Helper()

	var out []int
	for _, line := range h.events(t, "worker_started") {
		out = append(out, num(t, line, "card"))
	}

	return out
}

const testProject = "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7"

func cardMoved(number int) string {
	return fmt.Sprintf(`{"type":"board.card_moved","subject":{"type":"card","id":"0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7"},"projectId":%q,"cardNumber":%d,"fromStatus":"backlog","toStatus":"next"}`, testProject, number)
}

func TestCardMovedToNextRunsAWorker(t *testing.T) {
	h := newHarness()
	h.router.permissionMode = "acceptEdits"

	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()

	calls := h.worker.recorded()
	if len(calls) != 1 {
		t.Fatalf("expected one worker, got %+v", calls)
	}
	if calls[0].dir != "/src/app" || calls[0].permissionMode != "acceptEdits" {
		t.Fatalf("unexpected worker: %+v", calls[0])
	}
	want := directive.CardDirective(event.Event{Subject: event.Subject{Type: "card", ID: "0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7"}, ProjectID: testProject, CardNumber: 87})
	if calls[0].prompt != want {
		t.Fatalf("prompt = %q, want %q", calls[0].prompt, want)
	}

	started := h.only(t, "worker_started")
	if num(t, started, "card") != 87 || str(t, started, "project") != testProject {
		t.Fatalf("worker_started = %v", started)
	}
	finished := h.only(t, "worker_finished")
	if num(t, finished, "exit") != 0 {
		t.Fatalf("worker_finished = %v", finished)
	}
	if _, ok := finished["duration_ms"].(float64); !ok {
		t.Fatalf("worker_finished carries no duration_ms: %v", finished)
	}
}

// Every line has to be one JSON object named by a stable event key. slog calls
// that key "msg" by default, so this pins the rename.
func TestEveryLogLineIsJSONNamedByAnEventKey(t *testing.T) {
	h := newHarness()

	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()
	h.router.onData([]byte(`not json`))

	lines := h.lines(t)
	if len(lines) == 0 {
		t.Fatal("nothing was logged")
	}
	for _, line := range lines {
		if str(t, line, "event") == "" {
			t.Fatalf("line %v has an empty event", line)
		}
		if _, ok := line["msg"]; ok {
			t.Fatalf("line %v still carries msg", line)
		}
	}
}

// The bridge owns the worker's streams, so a report that drops the output on
// success leaves the operator no view of what claude answered.
func TestASuccessfulWorkerReportsWhatItSaid(t *testing.T) {
	h := newHarness()
	h.worker.result = workerResult{output: "moved card 87 to in-progress"}

	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()

	if got := str(t, h.only(t, "worker_finished"), "output"); got != "moved card 87 to in-progress" {
		t.Fatalf("output = %q", got)
	}
}

// TestAFinishedWorkerNoLongerBlocksItsCard is the bug this design fixes: the
// old check asked whether a session existed, so a card that had been worked
// once never started a worker again.
func TestAFinishedWorkerNoLongerBlocksItsCard(t *testing.T) {
	h := newHarness()

	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()
	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()

	if calls := h.worker.recorded(); len(calls) != 2 {
		t.Fatalf("expected two workers, got %+v", calls)
	}
	if got := h.events(t, "worker_refused"); len(got) != 0 {
		t.Fatalf("a finished worker still blocked its card: %v", got)
	}
}

// TestARunningWorkerBlocksASecondForTheSameCard keeps two workers off one card.
func TestARunningWorkerBlocksASecondForTheSameCard(t *testing.T) {
	h := newHarness()
	h.worker.started = make(chan workerCall, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(87)))

	refused := h.only(t, "worker_refused")
	if num(t, refused, "card") != 87 {
		t.Fatalf("worker_refused = %v", refused)
	}

	close(h.worker.block)
	h.router.wg.Wait()
	if calls := h.worker.recorded(); len(calls) != 1 {
		t.Fatalf("expected one worker, got %+v", calls)
	}
}

// A card that waits in the queue is claimed as firmly as one that runs. Without
// that, a card moved into next twice while the bound is reached queues twice
// and runs twice.
func TestACardAlreadyQueuedIsRefused(t *testing.T) {
	h := newHarness()
	h.router.maxWorkers = 1
	h.worker.started = make(chan workerCall, 3)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(88)))
	h.router.onData([]byte(cardMoved(88)))

	refused := h.only(t, "worker_refused")
	if num(t, refused, "card") != 88 {
		t.Fatalf("worker_refused = %v", refused)
	}
	if got := h.events(t, "worker_queued"); len(got) != 2 {
		t.Fatalf("card 88 was queued twice: %v", got)
	}

	close(h.worker.block)
	h.router.wg.Wait()
	if got := startedCards(t, h); len(got) != 2 {
		t.Fatalf("started %v, want one run each for 87 and 88", got)
	}
}

// TestTwoCardsRunConcurrently pins that a running worker never blocks the read
// loop or another card. Neither worker returns until both have started.
func TestTwoCardsRunConcurrently(t *testing.T) {
	h := newHarness()
	h.worker.started = make(chan workerCall, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	h.router.onData([]byte(cardMoved(88)))

	<-h.worker.started
	<-h.worker.started

	close(h.worker.block)
	h.router.wg.Wait()
	if calls := h.worker.recorded(); len(calls) != 2 {
		t.Fatalf("expected two workers, got %+v", calls)
	}
}

// TestTheBoundLimitsConcurrentWorkers is the whole point of --max-workers. peak
// is monotonic, so the check after wg.Wait reads the highest concurrency the
// run ever reached.
func TestTheBoundLimitsConcurrentWorkers(t *testing.T) {
	h := newHarness()
	h.router.maxWorkers = 2
	h.worker.started = make(chan workerCall, 4)
	h.worker.block = make(chan struct{})

	for _, card := range []int{87, 88, 89, 90} {
		h.router.onData([]byte(cardMoved(card)))
	}

	// onData dispatches on this goroutine and nothing finishes while block is
	// held, so the counts here are settled rather than sampled.
	h.router.mu.Lock()
	active, queued := h.router.active, len(h.router.queue)
	h.router.mu.Unlock()
	if active != 2 || queued != 2 {
		t.Fatalf("active = %d, queued = %d; want 2 running and 2 waiting", active, queued)
	}

	<-h.worker.started
	<-h.worker.started

	close(h.worker.block)
	h.router.wg.Wait()

	if got := h.worker.peak(); got != 2 {
		t.Fatalf("peak concurrency = %d, want 2", got)
	}
	if got := h.worker.recorded(); len(got) != 4 {
		t.Fatalf("ran %d workers, want all 4", len(got))
	}
}

// A bound that never releases is a stall. The fourth card has to run once a
// slot frees, and the queue depth has to say how much work waits.
func TestAQueuedEventRunsWhenASlotFrees(t *testing.T) {
	h := newHarness()
	h.router.maxWorkers = 1
	h.worker.started = make(chan workerCall, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(88)))

	if got := h.worker.recorded(); len(got) != 1 {
		t.Fatalf("the bound let %d workers run", len(got))
	}
	queued := h.events(t, "worker_queued")
	if len(queued) != 2 || num(t, queued[1], "queue_depth") != 1 {
		t.Fatalf("worker_queued = %v", queued)
	}

	close(h.worker.block)
	<-h.worker.started
	h.router.wg.Wait()

	if got := startedCards(t, h); len(got) != 2 || got[1] != 88 {
		t.Fatalf("started %v, want card 88 second", got)
	}
}

// The queue is FIFO, so a card that waited longest starts first.
func TestTheQueueIsFirstInFirstOut(t *testing.T) {
	h := newHarness()
	h.router.maxWorkers = 1
	h.worker.started = make(chan workerCall, 4)
	h.worker.block = make(chan struct{})

	for _, card := range []int{87, 88, 89, 90} {
		h.router.onData([]byte(cardMoved(card)))
	}
	<-h.worker.started

	close(h.worker.block)
	h.router.wg.Wait()

	want := []int{87, 88, 89, 90}
	got := startedCards(t, h)
	if len(got) != len(want) {
		t.Fatalf("started %v, want %v", got, want)
	}
	for i := range want {
		if got[i] != want[i] {
			t.Fatalf("started %v, want %v", got, want)
		}
	}
}

// Shutdown drops what never started. The count and the card numbers have to
// reach the operator, because a dropped trigger nobody is told about is the
// silence this design removes.
func TestShutdownDropsTheQueueAndSaysSo(t *testing.T) {
	h := newHarness()
	h.router.maxWorkers = 1
	h.worker.started = make(chan workerCall, 3)
	h.worker.block = make(chan struct{})

	for _, card := range []int{87, 88, 89} {
		h.router.onData([]byte(cardMoved(card)))
	}
	<-h.worker.started

	h.router.shutdown()

	dropped := h.only(t, "queue_dropped")
	if num(t, dropped, "count") != 2 {
		t.Fatalf("queue_dropped = %v", dropped)
	}
	if got := cards(t, dropped); len(got) != 2 || got[0] != 88 || got[1] != 89 {
		t.Fatalf("dropped cards = %v, want [88 89]", got)
	}

	close(h.worker.block)
	h.router.wg.Wait()

	if got := h.worker.recorded(); len(got) != 1 {
		t.Fatalf("a dropped card still ran: %d workers", len(got))
	}
	if got := startedCards(t, h); len(got) != 1 || got[0] != 87 {
		t.Fatalf("started %v, want only card 87", got)
	}
}

// A shut queue starts nothing. Without that, a worker that finishes after the
// shutdown admits the next card and starts an agent nobody watches.
func TestAnEventAfterShutdownIsDropped(t *testing.T) {
	h := newHarness()

	h.router.shutdown()
	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()

	dropped := h.only(t, "queue_dropped")
	if num(t, dropped, "count") != 1 {
		t.Fatalf("queue_dropped = %v", dropped)
	}
	if got := cards(t, dropped); len(got) != 1 || got[0] != 87 {
		t.Fatalf("dropped cards = %v, want [87]", got)
	}
	if got := h.worker.recorded(); len(got) != 0 {
		t.Fatalf("a shut queue still ran %d workers", len(got))
	}
	if got := h.events(t, "worker_started"); len(got) != 0 {
		t.Fatalf("a shut queue still started %v", got)
	}
}

// Ctrl-C cancels the context before the stream unwinds, so a worker can finish
// before shutdown runs. The cancelled context has to stop the queue by itself,
// or that worker admits a card no process can run.
func TestACancelledContextStopsTheQueue(t *testing.T) {
	h := newHarness()
	h.router.maxWorkers = 1
	ctx, cancel := context.WithCancel(context.Background())
	h.router.ctx = ctx
	h.worker.started = make(chan workerCall, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(88)))

	cancel()
	close(h.worker.block)
	h.router.wg.Wait()

	if got := h.worker.recorded(); len(got) != 1 {
		t.Fatalf("a cancelled bridge still ran %d workers", len(got))
	}
	dropped := h.only(t, "queue_dropped")
	if num(t, dropped, "count") != 1 {
		t.Fatalf("queue_dropped = %v", dropped)
	}
	if got := cards(t, dropped); len(got) != 1 || got[0] != 88 {
		t.Fatalf("dropped cards = %v, want [88]", got)
	}
}

// A shutdown with nothing waiting says nothing.
func TestShutdownWithAnEmptyQueueLogsNothing(t *testing.T) {
	h := newHarness()

	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()
	h.router.shutdown()

	if got := h.events(t, "queue_dropped"); len(got) != 0 {
		t.Fatalf("queue_dropped = %v", got)
	}
}

func TestANonZeroExitIsReported(t *testing.T) {
	h := newHarness()
	h.worker.result = workerResult{exitCode: 2, output: "claude: permission denied"}

	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()

	finished := h.only(t, "worker_finished")
	if num(t, finished, "exit") != 2 {
		t.Fatalf("worker_finished = %v", finished)
	}
	if str(t, finished, "output") != "claude: permission denied" {
		t.Fatalf("the failure report dropped the output: %v", finished)
	}
	if str(t, finished, "level") != "ERROR" {
		t.Fatalf("a failed worker logged at %q", finished["level"])
	}
}

// A worker that never ran reports no exit code, so the fault itself is all the
// operator gets. It is a different event from a process that ran and failed.
func TestAWorkerThatNeverRanIsReported(t *testing.T) {
	h := newHarness()
	h.worker.result = workerResult{err: errors.New("boom")}

	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()

	failed := h.only(t, "worker_failed")
	if str(t, failed, "error") != "boom" {
		t.Fatalf("worker_failed = %v", failed)
	}
	if got := h.events(t, "worker_finished"); len(got) != 0 {
		t.Fatalf("a worker that never ran also reported finishing: %v", got)
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

		if h.log.String() != "" {
			t.Fatalf("%s was reported: %q", payload, h.log.String())
		}
		if calls := h.worker.recorded(); len(calls) != 0 {
			t.Fatalf("%s acted on: %+v", payload, calls)
		}
	}
}

func TestMalformedEventIsReported(t *testing.T) {
	h := newHarness()

	h.router.onData([]byte(`not json`))

	if got := str(t, h.only(t, "event_malformed"), "error"); !strings.Contains(got, "parse event") {
		t.Fatalf("event_malformed error = %q", got)
	}
}

func TestIncompleteCardEventIsReportedAndDropped(t *testing.T) {
	h := newHarness()

	h.router.onData([]byte(`{"type":"board.card_moved","subject":{"type":"card","id":"0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7"},"cardNumber":87,"toStatus":"next"}`))
	h.router.wg.Wait()

	h.only(t, "event_malformed")
	if calls := h.worker.recorded(); len(calls) != 0 {
		t.Fatalf("acted on an incomplete card event: %+v", calls)
	}
}

// The stream reports its own faults through the handler, so a retry is visible.
func TestAStreamErrorIsReported(t *testing.T) {
	h := newHarness()
	h.router.site, h.router.topic = "Loupe", "https://loupe.test/board"

	h.router.handler().OnConnect()
	h.router.handler().OnError(errors.New("hub returned HTTP 401"))

	connected := h.only(t, "connected")
	if str(t, connected, "site") != "Loupe" || str(t, connected, "topic") != "https://loupe.test/board" {
		t.Fatalf("connected = %v", connected)
	}
	if got := str(t, h.only(t, "stream_error"), "error"); got != "hub returned HTTP 401" {
		t.Fatalf("stream_error = %q", got)
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
