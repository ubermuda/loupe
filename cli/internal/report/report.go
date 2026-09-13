// Package report sends a finished worker run to Loupe, and retries the ones
// that fail.
package report

import (
	"context"
	"errors"
	"log/slog"
	"sync"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
)

// Queue takes a finished worker run and owns its delivery from there.
//
// Enqueue never blocks and never reports a failure, because the caller is a
// worker goroutine with nothing to do about a failed send. A durable queue
// replaces this one behind the same two methods.
type Queue interface {
	Enqueue(run api.WorkerRun)
	Close()
}

// SendFunc delivers one report. It returns api.ErrReportRefused for a report
// that no retry can turn into a stored row.
type SendFunc func(ctx context.Context, run api.WorkerRun) error

// capacity bounds the reports that wait in memory. A worker runs for minutes,
// so a queue this deep means the server has been unreachable for hours.
const capacity = 64

// defaultBackoff waits between attempts. The common failure is a Loupe restart,
// a laptop that wakes, or a dropped connection, so the first retries come fast
// and the ten attempts cover about four minutes in all.
var defaultBackoff = []time.Duration{
	1 * time.Second,
	2 * time.Second,
	4 * time.Second,
	8 * time.Second,
	16 * time.Second,
	32 * time.Second,
	60 * time.Second,
	60 * time.Second,
	60 * time.Second,
}

// outcome is what one report came to.
type outcome int

const (
	delivered outcome = iota
	// gaveUp reports itself, because the server refused it or the attempts ran
	// out. aborted is the bridge stopping, and the caller counts those together.
	gaveUp
	aborted
)

// Retrying is the in-memory Queue. One goroutine sends, so reports land in the
// order the workers finished, and a report under retry holds the next one back.
type Retrying struct {
	log    *slog.Logger
	send   SendFunc
	ctx    context.Context
	cancel context.CancelFunc
	in     chan api.WorkerRun
	done   chan struct{}

	// backoff and after are fields, so a test waits for no real second.
	backoff []time.Duration
	after   func(d time.Duration) <-chan time.Time

	mu     sync.Mutex
	closed bool
}

// New starts the sender. Close stops it.
func New(ctx context.Context, log *slog.Logger, send SendFunc) *Retrying {
	ctx, cancel := context.WithCancel(ctx)
	q := &Retrying{
		log:     log,
		send:    send,
		ctx:     ctx,
		cancel:  cancel,
		in:      make(chan api.WorkerRun, capacity),
		done:    make(chan struct{}),
		backoff: defaultBackoff,
		after:   time.After,
	}

	go q.run()

	return q
}

// Enqueue hands one report to the sender. A full queue, or a queue that is
// already closed, loses the report and says so.
func (q *Retrying) Enqueue(run api.WorkerRun) {
	q.mu.Lock()
	defer q.mu.Unlock()

	if q.closed {
		q.logLost([]api.WorkerRun{run})

		return
	}

	select {
	case q.in <- run:
	default:
		q.logLost([]api.WorkerRun{run})
	}
}

// Close stops the sender and names what it drops. Everything still in the queue
// dies with the bridge, so a missing record means "unknown".
func (q *Retrying) Close() {
	q.mu.Lock()
	if q.closed {
		q.mu.Unlock()

		return
	}
	q.closed = true
	close(q.in)
	q.mu.Unlock()

	q.cancel()
	<-q.done
}

// run sends each queued report in turn, and counts the ones a stopping bridge
// takes with it.
func (q *Retrying) run() {
	defer close(q.done)

	var lost []api.WorkerRun
	for run := range q.in {
		if q.ctx.Err() != nil || q.deliver(run) == aborted {
			lost = append(lost, run)
		}
	}

	q.logLost(lost)
}

// deliver sends one report until the server takes it, until the server refuses
// it, or until the attempts run out. It logs its own give-up, so no dropped
// report is silent.
func (q *Retrying) deliver(run api.WorkerRun) outcome {
	for attempt := 0; ; attempt++ {
		err := q.send(q.ctx, run)
		if err == nil {
			return delivered
		}
		if q.ctx.Err() != nil {
			return aborted
		}
		if errors.Is(err, api.ErrReportRefused) || attempt >= len(q.backoff) {
			q.log.Warn("report_failed",
				"card", run.CardNumber,
				"rule", run.RuleName,
				"attempts", attempt+1,
				"error", err.Error(),
			)

			return gaveUp
		}

		select {
		case <-q.ctx.Done():
			return aborted
		case <-q.after(q.backoff[attempt]):
		}
	}
}

// logLost names the reports the bridge loses, and counts them.
func (q *Retrying) logLost(lost []api.WorkerRun) {
	if len(lost) == 0 {
		return
	}

	dropped := make([]map[string]any, len(lost))
	for i, run := range lost {
		dropped[i] = map[string]any{"card": run.CardNumber, "rule": run.RuleName}
	}
	q.log.Warn("report_dropped", "count", len(dropped), "dropped", dropped)
}
