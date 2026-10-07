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

// at returns the channel of the timer armed i-th.
func (f *fakeTimers) at(i int) chan time.Time {
	f.mu.Lock()
	defer f.mu.Unlock()

	return f.chans[i]
}

// fakeClock stands in for time.Now, and moves only when the test says so.
type fakeClock struct {
	mu sync.Mutex
	t  time.Time
}

func (c *fakeClock) now() time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()

	return c.t
}

func (c *fakeClock) advance(d time.Duration) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.t = c.t.Add(d)
}

// fakeHeartbeats records each heartbeat and answers with the next error of its
// list, then nil once the list runs out.
type fakeHeartbeats struct {
	mu     sync.Mutex
	sent   []api.Heartbeat
	ids    []string
	errors []error
	// cliRange, paused and commands are what each successful answer carries.
	cliRange string
	paused   *bool
	commands []api.Command
}

func (f *fakeHeartbeats) Heartbeat(_ context.Context, bridgeID string, hb api.Heartbeat) (api.HeartbeatReply, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.sent = append(f.sent, hb)
	f.ids = append(f.ids, bridgeID)
	if len(f.errors) == 0 {
		return api.HeartbeatReply{CLIRange: f.cliRange, Paused: f.paused, Commands: f.commands}, nil
	}
	err := f.errors[0]
	f.errors = f.errors[1:]

	return api.HeartbeatReply{}, err
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
	// calls counts the heartbeats handed to the queue, sent or not.
	calls int
}

func (c *countingQueue) handed() int {
	c.mu.Lock()
	defer c.mu.Unlock()

	return c.calls
}

func (c *countingQueue) Enqueue(report outbound.Report) {
	c.inner.Enqueue(report)
}

func (c *countingQueue) Pending() int {
	return c.inner.Pending()
}

func (c *countingQueue) Close() {
	c.inner.Close()
}

func (c *countingQueue) SendLatest(key string, send func(context.Context) error, done func(error)) {
	c.mu.Lock()
	c.calls++
	c.mu.Unlock()
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

func startHeartbeater(t *testing.T, client *fakeHeartbeats, interval time.Duration, setup ...func(*heartbeater)) *heartbeatHarness {
	t.Helper()
	ctx, cancel := context.WithCancel(context.Background())
	log := &syncBuffer{}
	sender := outbound.New(ctx, newBridgeLogger(log))
	hh := &heartbeatHarness{client: client, queue: &countingQueue{inner: sender}, timers: &fakeTimers{}, log: log, cancel: cancel}
	body := api.Heartbeat{Projects: []string{testProject}, CLIVersion: "b4e39aa7"}
	hh.h = newHeartbeater(ctx, hh.queue, client, testBridgeID, body, interval, newBridgeLogger(log))
	hh.h.after = hh.timers.after
	for _, f := range setup {
		f(hh.h)
	}
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

// Each answer hands its range on, and each heartbeat carries the update state
// read at its send, with none before the updater has one.
func TestTheHeartbeatCarriesTheRangeAndTheUpdateState(t *testing.T) {
	client := &fakeHeartbeats{cliRange: "^1.0"}
	var mu sync.Mutex
	var ranges []string
	state := api.HeartbeatUpdate{}
	hh := startHeartbeater(t, client, time.Minute, func(h *heartbeater) {
		h.onRange = func(r string) {
			mu.Lock()
			defer mu.Unlock()
			ranges = append(ranges, r)
		}
		h.update = func() api.HeartbeatUpdate {
			mu.Lock()
			defer mu.Unlock()

			return state
		}
	})
	mu.Lock()
	state = api.HeartbeatUpdate{State: "updating", Version: "1.2.0"}
	mu.Unlock()

	hh.tick(t, time.Minute)

	client.mu.Lock()
	defer client.mu.Unlock()
	mu.Lock()
	defer mu.Unlock()
	if !slices.Equal(ranges, []string{"^1.0", "^1.0"}) {
		t.Fatalf("ranges = %v", ranges)
	}
	if client.sent[0].Update != nil || client.sent[1].Update == nil || *client.sent[1].Update != state {
		t.Fatalf("updates = %+v, %+v", client.sent[0].Update, client.sent[1].Update)
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
// Every heartbeat says whether the bridge holds back new work, and that it
// takes commands. A pause change goes out at once, and a new body keeps both.
func TestTheHeartbeatCarriesThePauseAndTheCapabilities(t *testing.T) {
	client := &fakeHeartbeats{}
	hh := startHeartbeater(t, client, time.Minute, func(h *heartbeater) { h.paused = true })

	hh.h.setPaused(true)
	hh.h.setPaused(false)
	eventually(t, "the heartbeat of the pause change", func() bool { return hh.queue.recorded() == 2 })
	hh.h.setBody(api.Heartbeat{Projects: []string{"p2"}, CLIVersion: "b4e39aa7"})
	eventually(t, "the heartbeat of the new body", func() bool { return hh.queue.recorded() == 3 })

	client.mu.Lock()
	defer client.mu.Unlock()
	var got []bool
	for i, hb := range client.sent {
		if hb.Paused == nil || !slices.Equal(hb.Capabilities, []string{"commands", "rerun-command", "session-usage"}) {
			t.Fatalf("heartbeat %d = %+v", i, hb)
		}
		got = append(got, *hb.Paused)
	}
	if !slices.Equal(got, []bool{true, false, false}) {
		t.Fatalf("paused = %v", got)
	}
}

// Each answer the server accepted hands its pause and its commands on.
func TestTheHeartbeatHandsItsReplyOn(t *testing.T) {
	paused := true
	cmd := api.Command{CommandID: "c1"}
	client := &fakeHeartbeats{paused: &paused, commands: []api.Command{cmd}}
	var mu sync.Mutex
	var replies []api.HeartbeatReply
	startHeartbeater(t, client, time.Minute, func(h *heartbeater) {
		h.onReply = func(r api.HeartbeatReply) {
			mu.Lock()
			defer mu.Unlock()
			replies = append(replies, r)
		}
	})

	mu.Lock()
	defer mu.Unlock()
	if len(replies) != 1 || replies[0].Paused == nil || !*replies[0].Paused || len(replies[0].Commands) != 1 || replies[0].Commands[0].CommandID != "c1" {
		t.Fatalf("replies = %+v", replies)
	}
}

func TestANilHeartbeaterTakesHookRows(t *testing.T) {
	var h *heartbeater
	h.setHooks([]api.HookReport{})
}

func TestANilHeartbeaterTakesPoolRows(t *testing.T) {
	var h *heartbeater
	h.setPools([]api.WorkerPoolReport{})
}

var (
	poolsA = []api.WorkerPoolReport{{Name: "default", Size: 3, InUse: 1}}
	poolsB = []api.WorkerPoolReport{{Name: "default", Size: 3, InUse: 2}}
	poolsC = []api.WorkerPoolReport{{Name: "default", Size: 3, InUse: 3}}
	poolsD = []api.WorkerPoolReport{{Name: "default", Size: 3, InUse: 3, Queued: 1}}
)

// startPoolHeartbeater starts a heartbeater that holds poolsA before its
// first send, on a clock that moves only when the test moves it.
func startPoolHeartbeater(t *testing.T) (*heartbeatHarness, *fakeClock) {
	t.Helper()
	clock := &fakeClock{t: time.Date(2026, 9, 29, 12, 0, 0, 0, time.UTC)}
	hh := startHeartbeater(t, &fakeHeartbeats{}, time.Minute, func(h *heartbeater) {
		h.now = clock.now
		h.setPools(poolsA)
	})

	return hh, clock
}

// sentPools returns the pool rows of each heartbeat the server got.
func (hh *heartbeatHarness) sentPools() [][]api.WorkerPoolReport {
	hh.client.mu.Lock()
	defer hh.client.mu.Unlock()
	out := make([][]api.WorkerPoolReport, len(hh.client.sent))
	for i, hb := range hh.client.sent {
		out[i] = hb.WorkerPools
	}

	return out
}

// drained waits until the loop has taken the pool change signal.
func (hh *heartbeatHarness) drained(t *testing.T) {
	t.Helper()
	eventually(t, "the loop to take the pool change", func() bool { return len(hh.h.poolsChanged) == 0 })
}

func TestPoolRowsRideEachIntervalHeartbeat(t *testing.T) {
	hh, _ := startPoolHeartbeater(t)

	hh.tick(t, time.Minute)
	hh.tick(t, time.Minute)

	sent := hh.sentPools()
	if len(sent) != 3 {
		t.Fatalf("%d heartbeats, want 3", len(sent))
	}
	for i, rows := range sent {
		if !slices.Equal(rows, poolsA) {
			t.Fatalf("heartbeat %d pools = %+v", i, rows)
		}
	}
}

// A change after the window of the last send goes out at once, with no timer.
func TestAPoolChangeGoesOutAtOnce(t *testing.T) {
	hh, clock := startPoolHeartbeater(t)

	clock.advance(11 * time.Second)
	hh.h.setPools(poolsB)
	eventually(t, "the heartbeat of the change", func() bool { return hh.queue.recorded() == 2 })

	if hh.timers.count() != 1 {
		t.Fatalf("%d timers, want only the interval one", hh.timers.count())
	}
	if sent := hh.sentPools(); !slices.Equal(sent[1], poolsB) {
		t.Fatalf("pools = %+v", sent[1])
	}
}

// A change inside the window of the last send waits for the window to end.
// Later changes join it, and one heartbeat carries the latest rows. Rows equal
// to the last sent ones send nothing, and the next interval carries them.
func TestPoolChangesInsideTheWindowGoOutOnceAtItsEnd(t *testing.T) {
	hh, clock := startPoolHeartbeater(t)

	clock.advance(4 * time.Second)
	hh.h.setPools(poolsB)
	eventually(t, "a timer for the end of the window", func() bool { return hh.timers.count() == 2 })
	delay, window := hh.timers.last()
	if delay != 6*time.Second {
		t.Fatalf("window delay = %s, want 6s", delay)
	}
	hh.h.setPools(poolsC)
	hh.drained(t)
	hh.h.setPools(poolsD)
	hh.drained(t)
	if hh.queue.handed() != 1 || hh.timers.count() != 2 {
		t.Fatalf("%d heartbeats and %d timers inside the window, want 1 and 2", hh.queue.handed(), hh.timers.count())
	}

	clock.advance(6 * time.Second)
	window <- time.Now()
	eventually(t, "the heartbeat at the end of the window", func() bool { return hh.queue.recorded() == 2 })
	hh.h.setPools(slices.Clone(poolsD))
	if hh.queue.handed() != 2 {
		t.Fatalf("%d heartbeats, want no send for equal rows", hh.queue.handed())
	}

	hh.timers.at(0) <- time.Now()
	eventually(t, "the interval heartbeat", func() bool { return hh.queue.recorded() == 3 })
	sent := hh.sentPools()
	if len(sent) != 3 || !slices.Equal(sent[1], poolsD) || !slices.Equal(sent[2], poolsD) {
		t.Fatalf("pools = %+v", sent)
	}
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
