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

// Queue takes a finished worker run and owns its delivery from there. handle
// names the project the run belongs to, because one bridge follows several.
//
// Enqueue never blocks and never reports a failure, because the caller is a
// worker goroutine with nothing to do about a failed send. A durable queue
// replaces this one behind the same two methods.
type Queue interface {
	Enqueue(handle string, run api.WorkerRun)
	Close()
}

// SendFunc delivers one report, and says whether the server wrote a new row. It
// returns api.ErrReportRefused for a report that no retry can turn into one.
type SendFunc func(ctx context.Context, handle string, run api.WorkerRun) (bool, error)

// queued is one report waiting to be sent.
type queued struct {
	handle string
	run    api.WorkerRun
}

// capacity bounds the reports that wait in memory. A worker runs for minutes,
// so a queue this deep means the server has been unreachable for hours.
const capacity = 64

// grace bounds the last delivery a shutdown allows. Ctrl-C kills the workers,
// and a killed worker writes nothing to its card, so the bridge is the only
// witness of those runs. Each one gets one more attempt inside this window.
const grace = 5 * time.Second

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
	in     chan queued
	done   chan struct{}

	// backoff, after and grace are fields, so a test waits for no real second.
	backoff []time.Duration
	after   func(d time.Duration) <-chan time.Time
	grace   time.Duration

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
		in:      make(chan queued, capacity),
		done:    make(chan struct{}),
		backoff: defaultBackoff,
		after:   time.After,
		grace:   grace,
	}

	go q.run()

	return q
}

// Enqueue hands one report to the sender. A full queue, or a queue that is
// already closed, loses the report and says so.
func (q *Retrying) Enqueue(handle string, run api.WorkerRun) {
	q.mu.Lock()
	defer q.mu.Unlock()

	next := queued{handle: handle, run: run}
	if q.closed {
		q.logLost([]queued{next})

		return
	}

	select {
	case q.in <- next:
	default:
		q.logLost([]queued{next})
	}
}

// Close stops the sender, gives what it still holds one last attempt, and names
// what it drops after that. A dropped record means "unknown".
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

// run sends each queued report in turn, and collects the ones a stopping bridge
// leaves behind for one last attempt.
func (q *Retrying) run() {
	defer close(q.done)

	var lost []queued
	for next := range q.in {
		if q.ctx.Err() != nil || q.deliver(next) == aborted {
			lost = append(lost, next)
		}
	}

	q.logLost(q.flush(lost))
}

// flush gives each report a stopping bridge still holds one attempt, inside the
// grace window, and returns the ones that do not land. It sends on a context of
// its own, because the bridge's is cancelled by the time it runs.
//
// A report the shutdown interrupted is sent again here. The server identifies a
// run by its own key, so a second copy of a report that landed changes nothing.
func (q *Retrying) flush(lost []queued) []queued {
	if len(lost) == 0 {
		return nil
	}

	ctx, cancel := context.WithTimeout(context.Background(), q.grace)
	defer cancel()

	var dropped []queued
	for _, next := range lost {
		if ctx.Err() != nil {
			dropped = append(dropped, next)

			continue
		}
		if _, err := q.send(ctx, next.handle, next.run); err != nil {
			dropped = append(dropped, next)
		}
	}

	return dropped
}

// deliver sends one report until the server takes it, until the server refuses
// it, or until the attempts run out. It logs its own give-up, so no dropped
// report is silent.
func (q *Retrying) deliver(next queued) outcome {
	for attempt := 0; ; attempt++ {
		created, err := q.send(q.ctx, next.handle, next.run)
		if err == nil {
			if attempt == 0 && !created {
				q.logFolded(next)
			}

			return delivered
		}
		if q.ctx.Err() != nil {
			return aborted
		}
		if errors.Is(err, api.ErrReportRefused) || attempt >= len(q.backoff) {
			q.log.Warn("report_failed",
				"card", next.run.CardNumber,
				"rule", next.run.RuleName,
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

// logFolded names a report the server already held when the bridge first sent
// it. The server keys a run by its project, its bridge, its card and its start
// second, so a second run of one card inside one second reads as the first, and
// this record is lost. A later attempt that reads the same answer is the retry
// working, so only the first one says anything.
func (q *Retrying) logFolded(next queued) {
	q.log.Warn("report_folded",
		"card", next.run.CardNumber,
		"rule", next.run.RuleName,
		"message", "Loupe already held a run of this card at this second, so this one is not recorded",
	)
}

// logLost names the reports the bridge loses, and counts them.
func (q *Retrying) logLost(lost []queued) {
	if len(lost) == 0 {
		return
	}

	dropped := make([]map[string]any, len(lost))
	for i, next := range lost {
		dropped[i] = map[string]any{"card": next.run.CardNumber, "rule": next.run.RuleName}
	}
	q.log.Warn("report_dropped", "count", len(dropped), "dropped", dropped)
}
