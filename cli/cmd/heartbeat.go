package cmd

import (
	"context"
	"errors"
	"log/slog"
	"sync"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/outbound"
)

// defaultHeartbeatInterval applies when the server shares no usable interval,
// which is what a server older than the flag does.
const defaultHeartbeatInterval = time.Minute

// minHeartbeatSeconds is the server's own floor. A shorter interval lets one
// bridge spend most of its token's rate limit.
const minHeartbeatSeconds = 10

// heartbeatLane is the latest-wins lane of the outbound queue that carries the
// heartbeat.
const heartbeatLane = "heartbeat"

// heartbeatSender sends one heartbeat. *api.Client is one.
type heartbeatSender interface {
	Heartbeat(ctx context.Context, bridgeID string, hb api.Heartbeat) (string, error)
}

// heartbeater tells Loupe that the bridge runs: once at start, then at each
// interval. Each heartbeat goes through the latest-wins lane of the outbound
// queue, so a newer one replaces one that has not gone out, a failed one waits
// for the next interval, and none of them delays a run report.
type heartbeater struct {
	ctx      context.Context
	queue    outbound.Queue
	client   heartbeatSender
	bridgeID string
	log      *slog.Logger
	// after is time.After, and a field so a test waits for no real interval.
	after func(time.Duration) <-chan time.Time
	// onRange receives the CLI range of each answer, on the goroutine of the
	// lane, so it must not block. update gives the update state of each
	// heartbeat. Either may be nil.
	onRange func(string)
	update  func() api.HeartbeatUpdate
	// onSent runs after each heartbeat the server accepted, on the goroutine
	// of the lane. It may be nil.
	onSent func()

	mu   sync.Mutex
	body api.Heartbeat
	// hooks lives apart from body, because a reload replaces body. Nil sends
	// no hooks key, which keeps the rows the server holds.
	hooks    []api.HookReport
	interval time.Duration
	// reset wakes the loop to arm its timer with a new interval.
	reset chan struct{}
	done  chan struct{}

	// The queue calls record from the one goroutine of the lane, and nothing
	// else reads or writes these.
	sent        bool
	failed      int
	unsupported bool
}

func newHeartbeater(ctx context.Context, queue outbound.Queue, client heartbeatSender, bridgeID string, body api.Heartbeat, interval time.Duration, log *slog.Logger) *heartbeater {
	return &heartbeater{
		ctx:      ctx,
		queue:    queue,
		client:   client,
		bridgeID: bridgeID,
		body:     body,
		log:      log,
		after:    time.After,
		interval: interval,
		reset:    make(chan struct{}, 1),
		done:     make(chan struct{}),
	}
}

// heartbeatInterval reads the interval from a GET /api/events answer, and falls
// back when the flag is missing or holds no whole number of at least ten.
func heartbeatInterval(events api.Events) time.Duration {
	if seconds, ok := events.Seconds(api.HeartbeatIntervalFlag); ok && seconds >= minHeartbeatSeconds {
		return time.Duration(seconds) * time.Second
	}

	return defaultHeartbeatInterval
}

// start sends the first heartbeat and runs the loop until the context ends.
func (h *heartbeater) start() {
	go h.loop()
}

// wait blocks until the loop has returned, which it does once the context ends.
func (h *heartbeater) wait() {
	<-h.done
}

// setInterval applies the interval of a fresh GET /api/events. A change arms a
// new timer at once, so a shorter interval does not wait out the old one.
func (h *heartbeater) setInterval(d time.Duration) {
	h.mu.Lock()
	if d == h.interval {
		h.mu.Unlock()

		return
	}
	h.interval = d
	h.mu.Unlock()
	h.log.Info("heartbeat_interval_changed", "interval_seconds", int(d/time.Second))

	select {
	case h.reset <- struct{}{}:
	default:
	}
}

// setBody applies a new body, and sends it at once, so the server reads the
// new projects before the next interval.
func (h *heartbeater) setBody(b api.Heartbeat) {
	h.mu.Lock()
	h.body = b
	h.mu.Unlock()

	h.send()
}

// setHooks applies the rows of the hook runner and sends them at once. A nil
// heartbeater, which a bridge with no id has, drops them.
func (h *heartbeater) setHooks(rows []api.HookReport) {
	if h == nil {
		return
	}
	h.mu.Lock()
	h.hooks = rows
	h.mu.Unlock()

	h.send()
}

func (h *heartbeater) currentInterval() time.Duration {
	h.mu.Lock()
	defer h.mu.Unlock()

	return h.interval
}

func (h *heartbeater) loop() {
	defer close(h.done)

	h.send()
	for {
		select {
		case <-h.ctx.Done():
			return
		case <-h.reset:
			continue
		case <-h.after(h.currentInterval()):
			h.send()
		}
	}
}

// send hands one heartbeat to the queue.
func (h *heartbeater) send() {
	h.mu.Lock()
	body := h.body
	body.Hooks = h.hooks
	h.mu.Unlock()
	if h.update != nil {
		if u := h.update(); u.State != "" {
			body.Update = &u
		}
	}

	h.queue.SendLatest(heartbeatLane, func(ctx context.Context) error {
		cliRange, err := h.client.Heartbeat(ctx, h.bridgeID, body)
		if err == nil && h.onRange != nil {
			h.onRange(cliRange)
		}

		return err
	}, h.record)
}

// record logs only what an operator acts on: the first heartbeat that lands,
// each run of 404 answers once, and each failure streak at its start and its end.
func (h *heartbeater) record(err error) {
	switch {
	case err == nil:
		if !h.sent || h.failed > 0 || h.unsupported {
			h.log.Info("heartbeat_sent", "bridge_id", h.bridgeID, "interval_seconds", int(h.currentInterval()/time.Second), "failed_before", h.failed)
		}
		h.sent, h.failed, h.unsupported = true, 0, false
		if h.onSent != nil {
			h.onSent()
		}
	case errors.Is(err, api.ErrHeartbeatMissing):
		if !h.unsupported {
			h.unsupported = true
			h.log.Warn("heartbeat_unsupported", "error", err.Error(),
				"message", "Loupe has no heartbeat endpoint or has agent push off, so no page shows this bridge as running. The bridge keeps working and keeps trying.")
		}
	default:
		if h.failed == 0 {
			h.log.Warn("heartbeat_failed", "error", err.Error(), "retry_in_seconds", int(h.currentInterval()/time.Second))
		}
		h.failed++
	}
}
