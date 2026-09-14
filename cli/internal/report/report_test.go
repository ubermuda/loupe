package report

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
	queue  *Retrying
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
		func(_ context.Context, handle string, run api.WorkerRun) error {
			mu.Lock()
			answer := error(nil)
			if len(answers) > 0 {
				answer = answers[min(calls, len(answers)-1)]
			}
			calls++
			mu.Unlock()

			h.sent <- queued{handle: handle, run: run}

			return answer
		})
	h.queue.backoff = make([]time.Duration, retries)
	h.queue.after = func(time.Duration) <-chan time.Time {
		ready := make(chan time.Time, 1)
		ready <- time.Time{}

		return ready
	}
	t.Cleanup(h.queue.Close)

	return h
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
		func(ctx context.Context, handle string, run api.WorkerRun) error {
			h.sent <- queued{handle: handle, run: run}
			<-held
			<-ctx.Done()

			return ctx.Err()
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
