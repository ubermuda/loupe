package cmd

import (
	"context"
	"errors"
	"log/slog"
	"sync"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
)

// defaultHeartbeatInterval applies when the server shares no usable interval,
// which is what a server older than the flag does.
const defaultHeartbeatInterval = time.Minute

// heartbeatSender sends one heartbeat. *api.Client is one.
type heartbeatSender interface {
	Heartbeat(ctx context.Context, bridgeID string, hb api.Heartbeat) error
}

// heartbeater tells Loupe that the bridge runs: once at start, then at each
// interval. A heartbeat is current state, so a failed one is not retried on its
// own. The next interval sends a fresh one, and the server keeps no history
// that a lost heartbeat would leave a gap in.
type heartbeater struct {
	ctx      context.Context
	client   heartbeatSender
	bridgeID string
	body     api.Heartbeat
	log      *slog.Logger
	// after is time.After, and a field so a test waits for no real interval.
	after func(time.Duration) <-chan time.Time

	mu       sync.Mutex
	interval time.Duration
	// reset wakes the loop to arm its timer with a new interval.
	reset chan struct{}
	done  chan struct{}

	// The loop goroutine alone reads and writes these.
	sent        bool
	failed      int
	unsupported bool
}

func newHeartbeater(ctx context.Context, client heartbeatSender, bridgeID string, body api.Heartbeat, interval time.Duration, log *slog.Logger) *heartbeater {
	return &heartbeater{
		ctx:      ctx,
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
// back when the flag is missing or holds no positive whole number.
func heartbeatInterval(events api.Events) time.Duration {
	if seconds, ok := events.Seconds(api.HeartbeatIntervalFlag); ok {
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

// send posts one heartbeat and logs only what an operator acts on: the first
// one that lands, a server with no endpoint once, and each failure streak at
// its start and at its end.
func (h *heartbeater) send() {
	err := h.client.Heartbeat(h.ctx, h.bridgeID, h.body)
	switch {
	case err == nil:
		if !h.sent || h.failed > 0 {
			h.log.Info("heartbeat_sent", "bridge_id", h.bridgeID, "interval_seconds", int(h.currentInterval()/time.Second), "failed_before", h.failed)
		}
		h.sent, h.failed = true, 0
	case h.ctx.Err() != nil:
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
