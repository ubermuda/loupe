// Package outbound queues the run reports and heartbeats the bridge sends to
// Loupe. The ask check is a direct call, and rule health has its own retry.
// Each kind of item has its own delivery policy:
//
//   - A report, such as a state of a worker run, waits in line, is sent in order
//     and is retried. A shutdown gives it one last attempt.
//   - A latest-wins item, such as a heartbeat, has a lane of its own. A newer
//     item replaces one that has not gone out, and a failed one is not retried.
//
// The lanes never wait on each other, so a stuck heartbeat cannot delay a report.
package outbound

import (
	"context"
	"errors"
	"log/slog"
	"sync"
	"sync/atomic"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
)

// Queue takes what the bridge sends to Loupe and owns its delivery from there.
// Each method names a kind of item, and each kind has its own delivery policy.
//
// Enqueue takes a report. It never blocks and never reports a failure, because
// the caller is a worker goroutine with nothing to do about a failed send.
//
// SendLatest takes a latest-wins item, such as a heartbeat. See Sender.SendLatest.
//
// Pending counts the reports not yet done with, so a handover can wait for them.
//
// A durable queue can replace the in-memory one behind the same methods.
type Queue interface {
	Enqueue(report Report)
	SendLatest(key string, send func(context.Context) error, done func(error))
	Pending() int
	Close()
}

// Report is one item of the ordered lane. Card and Rule name it in the log.
//
// Send delivers the report, and says whether the server wrote something new. It
// returns api.ErrReportRefused for a report that no retry can turn into one.
type Report struct {
	Card int
	Rule string
	Send func(ctx context.Context) (bool, error)
}

// latest is the one item a latest-wins lane holds.
type latest struct {
	send func(context.Context) error
	done func(error)
}

// lane holds the pending item of one latest-wins key. Sender.mu guards pending.
type lane struct {
	pending *latest
	wake    chan struct{}
}

// capacity bounds the reports that wait in memory. A run sends about four, and
// a worker runs for minutes, so a full queue means a long outage.
const capacity = 256

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

// Sender is the in-memory queue. One goroutine sends the reports, so they land
// in the order the bridge made them, and a report under retry holds the next
// one back. Each latest-wins key has a goroutine of its own.
type Sender struct {
	log    *slog.Logger
	ctx    context.Context
	cancel context.CancelFunc
	in     chan Report
	done   chan struct{}

	// backoff, after and grace are fields, so a test waits for no real second.
	backoff []time.Duration
	after   func(d time.Duration) <-chan time.Time
	grace   time.Duration

	// pending counts each report from Enqueue until run is done with it.
	pending atomic.Int64

	mu     sync.Mutex
	closed bool
	lanes  map[string]*lane
	laneWG sync.WaitGroup
}

// New starts the report sender. Close stops it and every latest-wins lane.
func New(ctx context.Context, log *slog.Logger) *Sender {
	ctx, cancel := context.WithCancel(ctx)
	q := &Sender{
		log:     log,
		ctx:     ctx,
		cancel:  cancel,
		in:      make(chan Report, capacity),
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
func (q *Sender) Enqueue(report Report) {
	q.mu.Lock()
	defer q.mu.Unlock()

	if q.closed {
		q.logLost([]Report{report})

		return
	}

	// The count rises first, so run never takes it below zero.
	q.pending.Add(1)
	select {
	case q.in <- report:
	default:
		q.pending.Add(-1)
		q.logLost([]Report{report})
	}
}

// Pending counts the reports that wait or are under delivery.
func (q *Sender) Pending() int {
	return int(q.pending.Load())
}

// SendLatest puts send in the lane of key, in place of an item of that key that
// has not gone out yet. send runs once, and done then receives its error. done
// is not called for an item a newer one replaced, nor for one whose send was
// still running when Close began. After Close, the item is dropped. The lane
// goroutine calls done, so done may call SendLatest, and a Close inside done
// deadlocks.
func (q *Sender) SendLatest(key string, send func(context.Context) error, done func(error)) {
	q.mu.Lock()
	if q.closed {
		q.mu.Unlock()

		return
	}
	l := q.lanes[key]
	if l == nil {
		if q.lanes == nil {
			q.lanes = map[string]*lane{}
		}
		l = &lane{wake: make(chan struct{}, 1)}
		q.lanes[key] = l
		q.laneWG.Add(1)
		go q.runLane(l)
	}
	l.pending = &latest{send: send, done: done}
	q.mu.Unlock()

	select {
	case l.wake <- struct{}{}:
	default:
	}
}

// Close stops the sender, gives the reports it still holds one last attempt,
// and names what it drops after that. A dropped record means "unknown". A
// latest-wins item that has not gone out is dropped with no log line, because
// it holds current state and the next start sends a fresh one.
func (q *Sender) Close() {
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
	q.laneWG.Wait()
}

// runLane sends the pending item of one latest-wins key, until the bridge stops.
func (q *Sender) runLane(l *lane) {
	defer q.laneWG.Done()

	for {
		select {
		case <-q.ctx.Done():
			return
		case <-l.wake:
		}

		for {
			q.mu.Lock()
			next := l.pending
			l.pending = nil
			q.mu.Unlock()
			if next == nil || q.ctx.Err() != nil {
				break
			}

			err := next.send(q.ctx)
			// Close has begun, so the error may be the cancellation and not an
			// answer from Loupe. done is not told.
			if q.ctx.Err() != nil {
				return
			}
			if next.done != nil {
				next.done(err)
			}
		}
	}
}

// run sends each queued report in turn, and collects the ones a stopping bridge
// leaves behind for one last attempt.
func (q *Sender) run() {
	defer close(q.done)

	var lost []Report
	for next := range q.in {
		if q.ctx.Err() != nil || q.deliver(next) == aborted {
			lost = append(lost, next)
		}
		q.pending.Add(-1)
	}

	q.logLost(q.flush(lost))
}

// flush gives each report a stopping bridge still holds one attempt, inside the
// grace window, and returns the ones that do not land. It sends on a context of
// its own, because the bridge's is cancelled by the time it runs.
//
// A report the shutdown interrupted is sent again here. The server identifies a
// run by its own key, so a second copy of a report that landed changes nothing.
func (q *Sender) flush(lost []Report) []Report {
	if len(lost) == 0 {
		return nil
	}

	ctx, cancel := context.WithTimeout(context.Background(), q.grace)
	defer cancel()

	var dropped []Report
	for _, next := range lost {
		if ctx.Err() != nil {
			dropped = append(dropped, next)

			continue
		}
		if _, err := next.Send(ctx); err != nil {
			dropped = append(dropped, next)
		}
	}

	return dropped
}

// deliver sends one report until the server takes it, until the server refuses
// it, or until the attempts run out. It logs its own give-up, so no dropped
// report is silent.
func (q *Sender) deliver(next Report) outcome {
	for attempt := 0; ; attempt++ {
		created, err := next.Send(q.ctx)
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
				"card", next.Card,
				"rule", next.Rule,
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
// it. On the old endpoint, that is a second run of one card folded into the
// first. A later attempt that reads the same answer is the retry working, so
// only the first one says anything.
func (q *Sender) logFolded(next Report) {
	q.log.Warn("report_folded",
		"card", next.Card,
		"rule", next.Rule,
		"message", "Loupe already held this report, so it recorded nothing new",
	)
}

// logLost names the reports the bridge loses, and counts them.
func (q *Sender) logLost(lost []Report) {
	if len(lost) == 0 {
		return
	}

	dropped := make([]map[string]any, len(lost))
	for i, next := range lost {
		dropped[i] = map[string]any{"card": next.Card, "rule": next.Rule}
	}
	q.log.Warn("report_dropped", "count", len(dropped), "dropped", dropped)
}
