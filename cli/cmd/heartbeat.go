package cmd

import (
	"context"
	"errors"
	"log/slog"
	"slices"
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

// poolsWindow is the shortest time between a heartbeat and one that a pool
// change sends.
const poolsWindow = 10 * time.Second

// bridgeCapabilities names what this bridge supports to the server.
var bridgeCapabilities = []string{"commands", "rerun-command"}

// heartbeatSender sends one heartbeat. *api.Client is one.
type heartbeatSender interface {
	Heartbeat(ctx context.Context, bridgeID string, hb api.Heartbeat) (api.HeartbeatReply, error)
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
	// onSent runs after each heartbeat the server accepted, and onReply gets
	// its reply first, both on the goroutine of the lane. Either may be nil.
	onSent  func()
	onReply func(api.HeartbeatReply)

	mu   sync.Mutex
	body api.Heartbeat
	// hooks lives apart from body, because a reload replaces body. Nil sends
	// no hooks key, which keeps the rows the server holds.
	hooks []api.HookReport
	// pools lives apart from body too. poolsSent and sentAt are the rows and
	// the time of the last send.
	pools     []api.WorkerPoolReport
	poolsSent []api.WorkerPoolReport
	// paused is the pause of a person that the router applies. It lives apart
	// from body too.
	paused   bool
	sentAt   time.Time
	interval time.Duration
	// reset wakes the loop to arm its timer with a new interval.
	reset chan struct{}
	// poolsChanged wakes the loop to send changed pool rows.
	poolsChanged chan struct{}
	done         chan struct{}
	// now is time.Now, and a field so a test controls the pool window.
	now func() time.Time

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

		poolsChanged: make(chan struct{}, 1),
		now:          time.Now,
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

// setPaused applies the pause the router holds, and sends a change at once.
func (h *heartbeater) setPaused(paused bool) {
	h.mu.Lock()
	same := paused == h.paused
	h.paused = paused
	h.mu.Unlock()
	if !same {
		h.send()
	}
}

// setPools applies the rows of the worker pools. The loop sends a change, at
// most once per poolsWindow. It never blocks, so the router calls it under its
// lock. A nil heartbeater drops the rows.
func (h *heartbeater) setPools(rows []api.WorkerPoolReport) {
	if h == nil {
		return
	}
	h.mu.Lock()
	same := slices.Equal(rows, h.pools)
	h.pools = rows
	h.mu.Unlock()
	if same {
		return
	}

	select {
	case h.poolsChanged <- struct{}{}:
	default:
	}
}

// sendPools sends the pool rows when they differ from the last sent ones.
// Inside the window of the last send, it returns a timer for the window end.
func (h *heartbeater) sendPools() <-chan time.Time {
	h.mu.Lock()
	changed := !slices.Equal(h.pools, h.poolsSent)
	wait := poolsWindow - h.now().Sub(h.sentAt)
	h.mu.Unlock()
	if !changed {
		return nil
	}
	if wait > 0 {
		return h.after(wait)
	}
	h.send()

	return nil
}

func (h *heartbeater) currentInterval() time.Duration {
	h.mu.Lock()
	defer h.mu.Unlock()

	return h.interval
}

func (h *heartbeater) loop() {
	defer close(h.done)

	h.send()
	tick := h.after(h.currentInterval())
	// window fires at the end of the window a pool change waits for.
	var window <-chan time.Time
	for {
		select {
		case <-h.ctx.Done():
			return
		case <-h.reset:
			tick = h.after(h.currentInterval())
		case <-tick:
			h.send()
			tick = h.after(h.currentInterval())
		case <-h.poolsChanged:
			if window == nil {
				window = h.sendPools()
			}
		case <-window:
			window = h.sendPools()
		}
	}
}

// send hands one heartbeat to the queue.
func (h *heartbeater) send() {
	h.mu.Lock()
	body := h.body
	body.Hooks = h.hooks
	body.WorkerPools = h.pools
	paused := h.paused
	body.Paused, body.Capabilities = &paused, bridgeCapabilities
	h.poolsSent, h.sentAt = h.pools, h.now()
	h.mu.Unlock()
	if h.update != nil {
		if u := h.update(); u.State != "" {
			body.Update = &u
		}
	}

	h.queue.SendLatest(heartbeatLane, func(ctx context.Context) error {
		reply, err := h.client.Heartbeat(ctx, h.bridgeID, body)
		if err == nil && h.onRange != nil {
			h.onRange(reply.CLIRange)
		}
		if err == nil && h.onReply != nil {
			h.onReply(reply)
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
