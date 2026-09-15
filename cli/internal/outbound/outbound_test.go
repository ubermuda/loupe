package outbound

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
)

// syncBuffer collects the log while the sender goroutine writes it.
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

// harness is a queue whose every wait returns at once, so a test waits for no
// real second.
type harness struct {
	queue  *Sender
	log    *syncBuffer
	sent   chan queued
	cancel context.CancelFunc
}

// newHarness builds a queue of at most attempts attempts, and answers each send
// with the next error in answers. It repeats the last answer after that.
func newHarness(t *testing.T, retries int, answers ...error) *harness {
	t.Helper()

	ctx, cancel := context.WithCancel(context.Background())
	h := &harness{log: &syncBuffer{}, sent: make(chan queued, 32), cancel: cancel}
	var calls int
	var mu sync.Mutex

	h.queue = New(ctx, slog.New(slog.NewJSONHandler(h.log, nil)),
		func(_ context.Context, handle string, run api.WorkerRun) (bool, error) {
			mu.Lock()
			answer := error(nil)
			if len(answers) > 0 {
				answer = answers[min(calls, len(answers)-1)]
			}
			calls++
			mu.Unlock()

			h.sent <- queued{handle: handle, run: run}

			return answer == nil, answer
		})
	h.queue.backoff = make([]time.Duration, retries)
	h.queue.after = readyNow
	t.Cleanup(h.queue.Close)

	return h
}

// newHarnessWithSend builds a queue whose every wait returns at once, and whose
// answers the caller decides. Each send still reaches h.sent, so a test waits
// for an attempt rather than sleeping.
func newHarnessWithSend(t *testing.T, send SendFunc) *harness {
	t.Helper()

	ctx, cancel := context.WithCancel(context.Background())
	h := &harness{log: &syncBuffer{}, sent: make(chan queued, 32), cancel: cancel}
	h.queue = New(ctx, slog.New(slog.NewJSONHandler(h.log, nil)),
		func(ctx context.Context, handle string, run api.WorkerRun) (bool, error) {
			created, err := send(ctx, handle, run)
			h.sent <- queued{handle: handle, run: run}

			return created, err
		})
	h.queue.backoff = make([]time.Duration, 3)
	h.queue.after = readyNow
	t.Cleanup(h.queue.Close)

	return h
}

// readyNow is a wait that is over before it starts.
func readyNow(time.Duration) <-chan time.Time {
	ready := make(chan time.Time, 1)
	ready <- time.Time{}

	return ready
}

// next waits for the next send. The guard turns a queue that stops trying into
// a named failure rather than a test that hangs until the suite times out.
func (h *harness) next(t *testing.T, attempt int) queued {
	t.Helper()

	select {
	case next := <-h.sent:
		return next
	case <-time.After(5 * time.Second):
		t.Fatalf("attempt %d never reached the server", attempt)

		return queued{}
	}
}

// lines parses the log. Every line is one JSON object, so a test never matches
// a substring of a formatted line.
func (h *harness) lines(t *testing.T, event string) []map[string]any {
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
		if line["msg"] == event {
			out = append(out, line)
		}
	}

	return out
}

// waitFor waits until the log holds event. A send reaches h.sent before the
// sender reads its answer, so a Close right after h.next can cancel the queue
// first and turn a give-up into a shutdown.
func (h *harness) waitFor(t *testing.T, event string) {
	t.Helper()

	deadline := time.After(5 * time.Second)
	for len(h.lines(t, event)) == 0 {
		select {
		case <-deadline:
			t.Fatalf("the log never held %s: %s", event, h.log.String())
		case <-time.After(time.Millisecond):
		}
	}
}

const testHandle = "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7"

func run(card int) api.WorkerRun {
	return api.WorkerRun{CardNumber: card, RuleName: "plan"}
}

func TestQueueSendsEachReportOnce(t *testing.T) {
	h := newHarness(t, 3)

	h.queue.Enqueue(testHandle, run(42))

	if got := h.next(t, 1); got.run.CardNumber != 42 || got.handle != testHandle {
		t.Fatalf("sent card %d for handle %q", got.run.CardNumber, got.handle)
	}
	h.queue.Close()
	if lines := h.lines(t, "report_failed"); len(lines) != 0 {
		t.Fatalf("report_failed = %v, want none", lines)
	}
	if lines := h.lines(t, "report_dropped"); len(lines) != 0 {
		t.Fatalf("report_dropped = %v, want none", lines)
	}
	if lines := h.lines(t, "report_folded"); len(lines) != 0 {
		t.Fatalf("report_folded = %v, want none for a report the server stored", lines)
	}
}

// A Loupe restart is the common failure, so the report that fails once must
// land on the retry and say nothing about it.
func TestQueueRetriesAFailedSend(t *testing.T) {
	h := newHarness(t, 3, fmt.Errorf("connection refused"), nil)

	h.queue.Enqueue(testHandle, run(42))

	for attempt := 1; attempt <= 2; attempt++ {
		if got := h.next(t, attempt); got.run.CardNumber != 42 {
			t.Fatalf("attempt %d sent card %d", attempt, got.run.CardNumber)
		}
	}
	h.queue.Close()
	if lines := h.lines(t, "report_failed"); len(lines) != 0 {
		t.Fatalf("report_failed = %v, want none", lines)
	}
}

// A report the queue gives up on is lost, so the give-up is never silent.
func TestQueueLogsAGiveUp(t *testing.T) {
	h := newHarness(t, 2, fmt.Errorf("connection refused"))

	h.queue.Enqueue(testHandle, run(42))

	for attempt := 1; attempt <= 3; attempt++ {
		h.next(t, attempt)
	}
	h.waitFor(t, "report_failed")
	h.queue.Close()

	lines := h.lines(t, "report_failed")
	if len(lines) != 1 {
		t.Fatalf("report_failed = %v, want one line", lines)
	}
	if lines[0]["attempts"] != float64(3) || lines[0]["card"] != float64(42) || lines[0]["rule"] != "plan" {
		t.Fatalf("report_failed = %v", lines[0])
	}
}

// A refused report stays refused, so the queue stops at the first answer.
func TestQueueStopsAtARefusedReport(t *testing.T) {
	h := newHarness(t, 5, fmt.Errorf("%w (HTTP 422)", api.ErrReportRefused))

	h.queue.Enqueue(testHandle, run(42))
	h.next(t, 1)
	h.waitFor(t, "report_failed")
	h.queue.Close()

	lines := h.lines(t, "report_failed")
	if len(lines) != 1 || lines[0]["attempts"] != float64(1) {
		t.Fatalf("report_failed = %v, want one line of one attempt", lines)
	}
	select {
	case got := <-h.sent:
		t.Fatalf("the queue tried again and sent %v", got)
	default:
	}
}

// The server keys a run by its card and its start second, so a second run of
// one card inside one second reads as the first. That record is lost, and the
// bridge says so rather than reading the 200 as a success.
func TestQueueNamesAReportTheServerFolded(t *testing.T) {
	h := newHarnessWithSend(t, func(context.Context, string, api.WorkerRun) (bool, error) {
		return false, nil
	})

	h.queue.Enqueue(testHandle, run(42))
	h.next(t, 1)
	h.queue.Close()

	lines := h.lines(t, "report_folded")
	if len(lines) != 1 {
		t.Fatalf("report_folded = %v, want one line", lines)
	}
	if lines[0]["card"] != float64(42) || lines[0]["rule"] != "plan" {
		t.Fatalf("report_folded = %v", lines[0])
	}
}

// A retry of a report that landed also reads 200, and that is the retry working
// rather than a fold. Only the first attempt can tell the two apart.
func TestQueueReadsARetrysSecondAnswerAsSuccess(t *testing.T) {
	var calls int
	var mu sync.Mutex
	h := newHarnessWithSend(t, func(context.Context, string, api.WorkerRun) (bool, error) {
		mu.Lock()
		defer mu.Unlock()
		calls++
		if calls == 1 {
			return false, fmt.Errorf("connection reset")
		}

		return false, nil
	})

	h.queue.Enqueue(testHandle, run(42))
	h.next(t, 1)
	h.next(t, 2)
	h.queue.Close()

	if lines := h.lines(t, "report_folded"); len(lines) != 0 {
		t.Fatalf("report_folded = %v, want none for a retry that landed", lines)
	}
	if lines := h.lines(t, "report_failed"); len(lines) != 0 {
		t.Fatalf("report_failed = %v, want none", lines)
	}
}

// Ctrl-C kills the workers, and a killed worker writes nothing to its card. The
// bridge is the only witness of those runs, so the shutdown still sends them.
func TestQueueDeliversWhatAShutdownFinds(t *testing.T) {
	h := newHarness(t, 3)

	// A cancelled bridge stops every normal attempt, so the grace window is the
	// one path left that can deliver these two.
	h.cancel()
	h.queue.Enqueue(testHandle, run(1))
	h.queue.Enqueue(testHandle, run(2))
	h.queue.Close()

	for attempt := 1; attempt <= 2; attempt++ {
		h.next(t, attempt)
	}
	if lines := h.lines(t, "report_dropped"); len(lines) != 0 {
		t.Fatalf("report_dropped = %v, want the shutdown to have delivered both", lines)
	}
}

// A shutdown that cannot reach the server drops what it holds. The count is
// what tells an operator that a missing record means "unknown".
func TestQueueCountsWhatAShutdownDrops(t *testing.T) {
	held := make(chan struct{})
	log := &syncBuffer{}
	h := &harness{log: log, sent: make(chan queued, 32)}
	h.queue = New(context.Background(), slog.New(slog.NewJSONHandler(log, nil)),
		func(ctx context.Context, handle string, run api.WorkerRun) (bool, error) {
			h.sent <- queued{handle: handle, run: run}
			<-held
			<-ctx.Done()

			return false, ctx.Err()
		})
	// No grace window, which is the bridge that cannot reach Loupe at all.
	h.queue.grace = 0

	h.queue.Enqueue(testHandle, run(1))
	h.next(t, 1)
	h.queue.Enqueue(testHandle, run(2))
	h.queue.Enqueue(testHandle, run(3))

	close(held)
	h.queue.Close()

	lines := h.lines(t, "report_dropped")
	if len(lines) != 1 {
		t.Fatalf("report_dropped = %v, want one line", lines)
	}
	if lines[0]["count"] != float64(3) {
		t.Fatalf("count = %v, want the three reports the bridge takes with it", lines[0]["count"])
	}
}

// A report that arrives after the bridge stops is lost as well, and says so.
func TestQueueCountsAReportThatArrivesAfterTheClose(t *testing.T) {
	h := newHarness(t, 1)
	h.queue.Close()

	h.queue.Enqueue(testHandle, run(42))

	lines := h.lines(t, "report_dropped")
	if len(lines) != 1 || lines[0]["count"] != float64(1) {
		t.Fatalf("report_dropped = %v, want one line of one report", lines)
	}
}

// Close is called twice on a bridge that shuts down by itself, once by the
// caller and once by the deferred call.
func TestQueueTakesASecondClose(t *testing.T) {
	h := newHarness(t, 1)

	h.queue.Close()
	h.queue.Close()
}

// blockedHeartbeat is a latest-wins send that holds its lane until the test
// releases it, then fails. It stands for a Loupe that does not answer.
func blockedHeartbeat(started chan<- string, release <-chan struct{}, name string) func(context.Context) error {
	return func(ctx context.Context) error {
		started <- name
		select {
		case <-release:
		case <-ctx.Done():
		}

		return fmt.Errorf("heartbeat %s: connection refused", name)
	}
}

// A heartbeat that hangs and then fails holds its own lane only, so the reports
// queued behind it still go out at once.
func TestAStuckHeartbeatDoesNotDelayReports(t *testing.T) {
	h := newHarness(t, 3)
	started := make(chan string, 4)
	release := make(chan struct{})
	failed := make(chan error, 1)

	h.queue.SendLatest("heartbeat", blockedHeartbeat(started, release, "a"), func(err error) { failed <- err })
	if got := <-started; got != "a" {
		t.Fatalf("started %q", got)
	}
	h.queue.Enqueue(testHandle, run(1))
	h.queue.Enqueue(testHandle, run(2))

	for attempt := 1; attempt <= 2; attempt++ {
		if got := h.next(t, attempt); got.run.CardNumber != attempt {
			t.Fatalf("attempt %d sent card %d", attempt, got.run.CardNumber)
		}
	}

	close(release)
	select {
	case err := <-failed:
		if err == nil || !strings.Contains(err.Error(), "connection refused") {
			t.Fatalf("done got %v", err)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("the heartbeat never reported its failure")
	}
}

// A heartbeat that has not gone out is replaced by a newer one. The replaced one
// is never sent and never reported, and a failed one is not sent again.
func TestANewerHeartbeatReplacesAPendingOne(t *testing.T) {
	h := newHarness(t, 3)
	started := make(chan string, 4)
	release := make(chan struct{})
	var mu sync.Mutex
	var reported []string
	done := func(name string) func(error) {
		return func(error) {
			mu.Lock()
			defer mu.Unlock()
			reported = append(reported, name)
		}
	}

	h.queue.SendLatest("heartbeat", blockedHeartbeat(started, release, "a"), done("a"))
	<-started
	h.queue.SendLatest("heartbeat", blockedHeartbeat(started, release, "b"), done("b"))
	h.queue.SendLatest("heartbeat", blockedHeartbeat(started, release, "c"), done("c"))
	close(release)

	if got := <-started; got != "c" {
		t.Fatalf("the lane sent %q after the first heartbeat, want the newest", got)
	}
	deadline := time.After(5 * time.Second)
	for {
		mu.Lock()
		n := len(reported)
		mu.Unlock()
		if n == 2 {
			break
		}
		select {
		case <-deadline:
			t.Fatalf("reported = %v", reported)
		case <-time.After(5 * time.Millisecond):
		}
	}
	h.queue.Close()

	select {
	case got := <-started:
		t.Fatalf("the lane sent %q again", got)
	default:
	}
	mu.Lock()
	defer mu.Unlock()
	if strings.Join(reported, ",") != "a,c" {
		t.Fatalf("reported = %v, want a then c", reported)
	}
}

// A shutdown gives the reports their last attempt as before, and a heartbeat that
// hangs on the network neither holds the shutdown nor reaches its callback.
func TestShutdownDrainsReportsWhileAHeartbeatHangs(t *testing.T) {
	h := newHarness(t, 3)
	started := make(chan string, 4)
	never := make(chan struct{})
	h.queue.SendLatest("heartbeat", blockedHeartbeat(started, never, "a"), func(err error) {
		t.Errorf("a heartbeat still sending when Close began reported %v", err)
	})
	<-started

	h.cancel()
	h.queue.Enqueue(testHandle, run(1))
	h.queue.Enqueue(testHandle, run(2))
	closed := make(chan struct{})
	go func() {
		h.queue.Close()
		close(closed)
	}()

	for attempt := 1; attempt <= 2; attempt++ {
		h.next(t, attempt)
	}
	select {
	case <-closed:
	case <-time.After(5 * time.Second):
		t.Fatal("Close waited on the hanging heartbeat")
	}
	if lines := h.lines(t, "report_dropped"); len(lines) != 0 {
		t.Fatalf("report_dropped = %v, want the shutdown to have delivered both", lines)
	}
}

// A closed queue opens no lane, so an item of a new key never runs and no
// goroutine outlives Close.
func TestAClosedQueueOpensNoLane(t *testing.T) {
	h := newHarness(t, 1)
	h.queue.Close()
	ran := make(chan struct{}, 1)

	h.queue.SendLatest("after-close", func(context.Context) error {
		ran <- struct{}{}

		return nil
	}, nil)

	h.queue.mu.Lock()
	_, opened := h.queue.lanes["after-close"]
	h.queue.mu.Unlock()
	if opened {
		t.Fatal("a closed queue opened a lane")
	}
	h.queue.laneWG.Wait()
	select {
	case <-ran:
		t.Fatal("a closed queue ran the send")
	default:
	}
}
