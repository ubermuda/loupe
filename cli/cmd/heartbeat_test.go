package cmd

import (
	"context"
	"errors"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/outbound"
)

// fakeTimers stands in for time.After. Each call records the delay it was
// asked for, and the test fires the channel.
type fakeTimers struct {
	mu     sync.Mutex
	delays []time.Duration
	chans  []chan time.Time
}

func (f *fakeTimers) after(d time.Duration) <-chan time.Time {
	f.mu.Lock()
	defer f.mu.Unlock()
	ch := make(chan time.Time, 1)
	f.delays = append(f.delays, d)
	f.chans = append(f.chans, ch)

	return ch
}

func (f *fakeTimers) count() int {
	f.mu.Lock()
	defer f.mu.Unlock()

	return len(f.chans)
}

// last returns the delay and the channel of the newest timer.
func (f *fakeTimers) last() (time.Duration, chan time.Time) {
	f.mu.Lock()
	defer f.mu.Unlock()

	return f.delays[len(f.delays)-1], f.chans[len(f.chans)-1]
}

// fakeHeartbeats records each heartbeat and answers with the next error of its
// list, then nil once the list runs out.
type fakeHeartbeats struct {
	mu     sync.Mutex
	sent   []api.Heartbeat
	ids    []string
	errors []error
}

func (f *fakeHeartbeats) Heartbeat(_ context.Context, bridgeID string, hb api.Heartbeat) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.sent = append(f.sent, hb)
	f.ids = append(f.ids, bridgeID)
	if len(f.errors) == 0 {
		return nil
	}
	err := f.errors[0]
	f.errors = f.errors[1:]

	return err
}

func (f *fakeHeartbeats) count() int {
	f.mu.Lock()
	defer f.mu.Unlock()

	return len(f.sent)
}

// countingQueue is the real outbound queue, and it counts each heartbeat whose
// result the heartbeater has recorded, so a test reads the log only after that.
type countingQueue struct {
	inner *outbound.Sender

	mu sync.Mutex
	n  int
}

func (c *countingQueue) Enqueue(report outbound.Report) {
	c.inner.Enqueue(report)
}

func (c *countingQueue) Close() {
	c.inner.Close()
}

func (c *countingQueue) SendLatest(key string, send func(context.Context) error, done func(error)) {
	c.inner.SendLatest(key, send, func(err error) {
		done(err)
		c.mu.Lock()
		c.n++
		c.mu.Unlock()
	})
}

func (c *countingQueue) recorded() int {
	c.mu.Lock()
	defer c.mu.Unlock()

	return c.n
}

type heartbeatHarness struct {
	h      *heartbeater
	client *fakeHeartbeats
	queue  *countingQueue
	timers *fakeTimers
	log    *syncBuffer
	cancel context.CancelFunc
}

func startHeartbeater(t *testing.T, client *fakeHeartbeats, interval time.Duration) *heartbeatHarness {
	t.Helper()
	ctx, cancel := context.WithCancel(context.Background())
	log := &syncBuffer{}
	sender := outbound.New(ctx, newBridgeLogger(log))
	hh := &heartbeatHarness{client: client, queue: &countingQueue{inner: sender}, timers: &fakeTimers{}, log: log, cancel: cancel}
	body := api.Heartbeat{Projects: []string{testProject}, CLIVersion: "b4e39aa7"}
	hh.h = newHeartbeater(ctx, hh.queue, client, testBridgeID, body, interval, newBridgeLogger(log))
	hh.h.after = hh.timers.after
	hh.h.start()
	t.Cleanup(func() {
		cancel()
		hh.h.wait()
		sender.Close()
	})
	eventually(t, "the start heartbeat", func() bool { return hh.queue.recorded() == 1 && hh.timers.count() == 1 })

	return hh
}

// tick fires the newest timer after checking its delay, and waits until the
// heartbeat it causes is sent and recorded.
func (hh *heartbeatHarness) tick(t *testing.T, want time.Duration) {
	t.Helper()
	recorded, armed := hh.queue.recorded(), hh.timers.count()
	delay, ch := hh.timers.last()
	if delay != want {
		t.Fatalf("timer delay = %s, want %s", delay, want)
	}
	ch <- time.Now()
	eventually(t, "the heartbeat of the tick", func() bool {
		return hh.queue.recorded() == recorded+1 && hh.timers.count() == armed+1
	})
}

func TestTheBridgeSendsAHeartbeatAtStartAndAtEachInterval(t *testing.T) {
	client := &fakeHeartbeats{}
	hh := startHeartbeater(t, client, time.Minute)

	hh.tick(t, time.Minute)
	hh.tick(t, time.Minute)

	client.mu.Lock()
	defer client.mu.Unlock()
	if len(client.sent) != 3 {
		t.Fatalf("%d heartbeats, want 3", len(client.sent))
	}
	for i, hb := range client.sent {
		if client.ids[i] != testBridgeID || len(hb.Projects) != 1 || hb.Projects[0] != testProject || hb.CLIVersion != "b4e39aa7" {
			t.Fatalf("heartbeat %d = %s %+v", i, client.ids[i], hb)
		}
	}
}

// A new interval takes effect at once, rather than after the timer armed with
// the old one fires.
func TestAChangedIntervalRearmsTheTimer(t *testing.T) {
	client := &fakeHeartbeats{}
	hh := startHeartbeater(t, client, time.Minute)
	_, stale := hh.timers.last()

	hh.h.setInterval(time.Minute)
	hh.h.setInterval(15 * time.Second)
	eventually(t, "a timer armed with the new interval", func() bool { return hh.timers.count() == 2 })

	stale <- time.Now()
	hh.tick(t, 15*time.Second)
	if client.count() != 2 {
		t.Fatalf("the stale timer sent a heartbeat: %d sends", client.count())
	}
	if strings.Count(hh.log.String(), `"event":"heartbeat_interval_changed"`) != 1 || !strings.Contains(hh.log.String(), `"interval_seconds":15`) {
		t.Fatalf("log = %s", hh.log.String())
	}
}

func TestTheIntervalFallsBackToSixtySeconds(t *testing.T) {
	for _, tc := range []struct {
		flags map[string]any
		want  time.Duration
	}{
		{flags: map[string]any{api.HeartbeatIntervalFlag: float64(90)}, want: 90 * time.Second},
		{flags: nil, want: time.Minute},
		{flags: map[string]any{api.InboxFlag: true}, want: time.Minute},
		{flags: map[string]any{api.HeartbeatIntervalFlag: float64(0)}, want: time.Minute},
		{flags: map[string]any{api.HeartbeatIntervalFlag: float64(-30)}, want: time.Minute},
		{flags: map[string]any{api.HeartbeatIntervalFlag: 2.5}, want: time.Minute},
		{flags: map[string]any{api.HeartbeatIntervalFlag: "90"}, want: time.Minute},
		{flags: map[string]any{api.HeartbeatIntervalFlag: float64(1)}, want: time.Minute},
		{flags: map[string]any{api.HeartbeatIntervalFlag: float64(9)}, want: time.Minute},
		{flags: map[string]any{api.HeartbeatIntervalFlag: float64(10)}, want: 10 * time.Second},
	} {
		if got := heartbeatInterval(api.Events{Flags: tc.flags}); got != tc.want {
			t.Fatalf("flags %v: interval = %s, want %s", tc.flags, got, tc.want)
		}
	}
}

// A new body goes out at once, with no wait for the interval, and every later
// heartbeat carries it.
func TestANewBodyGoesOutAtOnce(t *testing.T) {
	client := &fakeHeartbeats{}
	hh := startHeartbeater(t, client, time.Minute)

	hh.h.setBody(api.Heartbeat{Projects: []string{"p2", "p3"}, CLIVersion: "b4e39aa7"})
	eventually(t, "the heartbeat of the new body", func() bool { return hh.queue.recorded() == 2 })
	hh.tick(t, time.Minute)

	client.mu.Lock()
	defer client.mu.Unlock()
	if len(client.sent) != 3 {
		t.Fatalf("%d heartbeats, want 3", len(client.sent))
	}
	for i, hb := range client.sent[1:] {
		if !slices.Equal(hb.Projects, []string{"p2", "p3"}) {
			t.Fatalf("heartbeat %d = %+v", i+1, hb)
		}
	}
}

// New hook rows go out at once, and a new body from a reload keeps them.
func TestHookRowsGoOutAtOnceAndOutliveANewBody(t *testing.T) {
	client := &fakeHeartbeats{}
	hh := startHeartbeater(t, client, time.Minute)
	rows := []api.HookReport{{Package: "acme/tool", Ref: "v1", Event: "busy", Outcome: "never"}}

	hh.h.setHooks(rows)
	eventually(t, "the heartbeat of the rows", func() bool { return hh.queue.recorded() == 2 })
	hh.h.setBody(api.Heartbeat{Projects: []string{"p2"}, CLIVersion: "b4e39aa7"})
	eventually(t, "the heartbeat of the new body", func() bool { return hh.queue.recorded() == 3 })
	hh.tick(t, time.Minute)

	client.mu.Lock()
	defer client.mu.Unlock()
	if client.sent[0].Hooks != nil {
		t.Fatalf("the start heartbeat carries hooks: %+v", client.sent[0])
	}
	for i, hb := range client.sent[1:] {
		if !slices.Equal(hb.Hooks, rows) {
			t.Fatalf("heartbeat %d = %+v", i+1, hb)
		}
	}
	if !slices.Equal(client.sent[3].Projects, []string{"p2"}) {
		t.Fatalf("last heartbeat = %+v", client.sent[3])
	}
}

// The runner reports to a heartbeater that a bridge with no id never makes.
func TestANilHeartbeaterTakesHookRows(t *testing.T) {
	var h *heartbeater
	h.setHooks([]api.HookReport{})
}

// A refresh that carries a new interval reaches the running heartbeat, and a
// refresh that carries none sets the fallback.
func TestARefreshAppliesTheIntervalToTheHeartbeat(t *testing.T) {
	hh := startHeartbeater(t, &fakeHeartbeats{}, time.Minute)
	r := &router{log: hh.h.log, heartbeat: hh.h}

	r.applyFlags(api.Events{Flags: map[string]any{api.HeartbeatIntervalFlag: float64(20)}})
	eventually(t, "a timer armed with the refreshed interval", func() bool { return hh.timers.count() == 2 })
	if delay, _ := hh.timers.last(); delay != 20*time.Second {
		t.Fatalf("delay = %s", delay)
	}

	r.applyFlags(api.Events{})
	eventually(t, "a timer armed with the fallback", func() bool { return hh.timers.count() == 3 })
	if delay, _ := hh.timers.last(); delay != time.Minute {
		t.Fatalf("delay = %s", delay)
	}
}

// An older server answers 404 to every heartbeat. The bridge says so once and
// keeps sending, so an upgraded server hears from it with no restart.
func TestAServerWithNoHeartbeatEndpointIsLoggedOnce(t *testing.T) {
	client := &fakeHeartbeats{errors: []error{api.ErrHeartbeatMissing, api.ErrHeartbeatMissing, api.ErrHeartbeatMissing}}
	hh := startHeartbeater(t, client, time.Minute)

	hh.tick(t, time.Minute)
	hh.tick(t, time.Minute)
	hh.tick(t, time.Minute)

	out := hh.log.String()
	if strings.Count(out, `"event":"heartbeat_unsupported"`) != 1 || strings.Contains(out, "heartbeat_failed") {
		t.Fatalf("log = %s", out)
	}
	if !strings.Contains(out, `"event":"heartbeat_sent"`) {
		t.Fatalf("the heartbeat that landed after the upgrade is not logged: %s", out)
	}
}

// A server that comes back from a 404 logs its recovery once, and a later 404
// is news again.
func TestARecoveryFromA404IsLoggedOnceAndALater404Again(t *testing.T) {
	client := &fakeHeartbeats{errors: []error{nil, api.ErrHeartbeatMissing, api.ErrHeartbeatMissing, nil, nil, api.ErrHeartbeatMissing}}
	hh := startHeartbeater(t, client, time.Minute)

	for range 5 {
		hh.tick(t, time.Minute)
	}

	out := hh.log.String()
	if n := strings.Count(out, `"event":"heartbeat_sent"`); n != 2 {
		t.Fatalf("%d heartbeat_sent lines, want the start and the recovery: %s", n, out)
	}
	if n := strings.Count(out, `"event":"heartbeat_unsupported"`); n != 2 {
		t.Fatalf("%d heartbeat_unsupported lines, want one per run of 404 answers: %s", n, out)
	}
}

// A failure is retried at the next interval. A run of failures logs once, and
// the heartbeat that ends it says how many went before.
func TestAFailureStreakLogsOnceAndItsEnd(t *testing.T) {
	down := errors.New("connection refused")
	client := &fakeHeartbeats{errors: []error{down, down, down}}
	hh := startHeartbeater(t, client, time.Minute)

	hh.tick(t, time.Minute)
	hh.tick(t, time.Minute)
	hh.tick(t, time.Minute)

	out := hh.log.String()
	if client.count() != 4 {
		t.Fatalf("%d sends, want one per tick and no retry of its own", client.count())
	}
	if strings.Count(out, `"event":"heartbeat_failed"`) != 1 || !strings.Contains(out, "connection refused") {
		t.Fatalf("log = %s", out)
	}
	if strings.Count(out, `"event":"heartbeat_sent"`) != 1 || !strings.Contains(out, `"failed_before":3`) {
		t.Fatalf("log = %s", out)
	}
}

// Only the first heartbeat and the end of a failure streak are logged, so a
// bridge that runs all day does not write a line a minute.
func TestARoutineHeartbeatWritesNoLogLine(t *testing.T) {
	hh := startHeartbeater(t, &fakeHeartbeats{}, time.Minute)

	hh.tick(t, time.Minute)
	hh.tick(t, time.Minute)

	if n := strings.Count(hh.log.String(), "\n"); n != 1 {
		t.Fatalf("%d log lines: %s", n, hh.log.String())
	}
}

func TestShutdownStopsTheHeartbeat(t *testing.T) {
	client := &fakeHeartbeats{}
	hh := startHeartbeater(t, client, time.Minute)

	hh.cancel()
	done := make(chan struct{})
	go func() {
		hh.h.wait()
		close(done)
	}()
	select {
	case <-done:
	case <-time.After(5 * time.Second):
		t.Fatal("wait did not return after the context ended")
	}

	if client.count() != 1 {
		t.Fatalf("%d heartbeats after shutdown", client.count()-1)
	}
}
