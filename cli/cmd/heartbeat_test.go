package cmd

import (
	"context"
	"errors"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
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

func startHeartbeater(t *testing.T, client *fakeHeartbeats, interval time.Duration) (*heartbeater, *fakeTimers, *syncBuffer, context.CancelFunc) {
	t.Helper()
	ctx, cancel := context.WithCancel(context.Background())
	timers := &fakeTimers{}
	log := &syncBuffer{}
	body := api.Heartbeat{Projects: []string{testProject}, CLIVersion: "b4e39aa7"}
	h := newHeartbeater(ctx, client, testBridgeID, body, interval, newBridgeLogger(log))
	h.after = timers.after
	h.start()
	t.Cleanup(func() {
		cancel()
		h.wait()
	})

	return h, timers, log, cancel
}

// tick fires the newest timer after checking its delay, and waits for the send
// it causes.
func tick(t *testing.T, client *fakeHeartbeats, timers *fakeTimers, want time.Duration) {
	t.Helper()
	sends, armed := client.count(), timers.count()
	delay, ch := timers.last()
	if delay != want {
		t.Fatalf("timer delay = %s, want %s", delay, want)
	}
	ch <- time.Now()
	eventually(t, "the heartbeat of the tick", func() bool { return client.count() == sends+1 && timers.count() == armed+1 })
}

func TestTheBridgeSendsAHeartbeatAtStartAndAtEachInterval(t *testing.T) {
	client := &fakeHeartbeats{}
	_, timers, _, _ := startHeartbeater(t, client, time.Minute)

	eventually(t, "the start heartbeat", func() bool { return client.count() == 1 && timers.count() == 1 })
	tick(t, client, timers, time.Minute)
	tick(t, client, timers, time.Minute)

	client.mu.Lock()
	defer client.mu.Unlock()
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
	h, timers, log, _ := startHeartbeater(t, client, time.Minute)
	eventually(t, "the start heartbeat", func() bool { return client.count() == 1 && timers.count() == 1 })
	_, stale := timers.last()

	h.setInterval(time.Minute)
	h.setInterval(15 * time.Second)
	eventually(t, "a timer armed with the new interval", func() bool { return timers.count() == 2 })

	stale <- time.Now()
	tick(t, client, timers, 15*time.Second)
	if client.count() != 2 {
		t.Fatalf("the stale timer sent a heartbeat: %d sends", client.count())
	}
	if strings.Count(log.String(), `"event":"heartbeat_interval_changed"`) != 1 || !strings.Contains(log.String(), `"interval_seconds":15`) {
		t.Fatalf("log = %s", log.String())
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

// A refresh that carries a new interval reaches the running heartbeat, and a
// refresh that carries none sets the fallback.
func TestARefreshAppliesTheIntervalToTheHeartbeat(t *testing.T) {
	client := &fakeHeartbeats{}
	h, timers, _, _ := startHeartbeater(t, client, time.Minute)
	eventually(t, "the start heartbeat", func() bool { return client.count() == 1 && timers.count() == 1 })
	r := &router{log: h.log, heartbeat: h}

	r.applyFlags(api.Events{Flags: map[string]any{api.HeartbeatIntervalFlag: float64(20)}})
	eventually(t, "a timer armed with the refreshed interval", func() bool { return timers.count() == 2 })
	if delay, _ := timers.last(); delay != 20*time.Second {
		t.Fatalf("delay = %s", delay)
	}

	r.applyFlags(api.Events{})
	eventually(t, "a timer armed with the fallback", func() bool { return timers.count() == 3 })
	if delay, _ := timers.last(); delay != time.Minute {
		t.Fatalf("delay = %s", delay)
	}
}

// An older server answers 404 to every heartbeat. The bridge says so once and
// keeps sending, so an upgraded server hears from it with no restart.
func TestAServerWithNoHeartbeatEndpointIsLoggedOnce(t *testing.T) {
	client := &fakeHeartbeats{errors: []error{api.ErrHeartbeatMissing, api.ErrHeartbeatMissing, api.ErrHeartbeatMissing}}
	_, timers, log, _ := startHeartbeater(t, client, time.Minute)

	eventually(t, "the start heartbeat", func() bool { return client.count() == 1 && timers.count() == 1 })
	tick(t, client, timers, time.Minute)
	tick(t, client, timers, time.Minute)
	tick(t, client, timers, time.Minute)

	out := log.String()
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
	_, timers, log, _ := startHeartbeater(t, client, time.Minute)

	eventually(t, "the start heartbeat", func() bool { return client.count() == 1 && timers.count() == 1 })
	for range 5 {
		tick(t, client, timers, time.Minute)
	}

	out := log.String()
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
	_, timers, log, _ := startHeartbeater(t, client, time.Minute)

	eventually(t, "the start heartbeat", func() bool { return client.count() == 1 && timers.count() == 1 })
	tick(t, client, timers, time.Minute)
	tick(t, client, timers, time.Minute)
	tick(t, client, timers, time.Minute)

	out := log.String()
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
	client := &fakeHeartbeats{}
	_, timers, log, _ := startHeartbeater(t, client, time.Minute)

	eventually(t, "the start heartbeat", func() bool { return client.count() == 1 && timers.count() == 1 })
	tick(t, client, timers, time.Minute)
	tick(t, client, timers, time.Minute)

	if n := strings.Count(log.String(), "\n"); n != 1 {
		t.Fatalf("%d log lines: %s", n, log.String())
	}
}

func TestShutdownStopsTheHeartbeat(t *testing.T) {
	client := &fakeHeartbeats{}
	h, timers, _, cancel := startHeartbeater(t, client, time.Minute)
	eventually(t, "the start heartbeat", func() bool { return client.count() == 1 && timers.count() == 1 })

	cancel()
	done := make(chan struct{})
	go func() {
		h.wait()
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
