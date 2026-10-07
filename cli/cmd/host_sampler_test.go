package cmd

import (
	"context"
	"errors"
	"slices"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

var sampleEpoch = time.Date(2026, 10, 7, 9, 0, 0, 0, time.UTC)

func hostSampleAt(i int) api.HostSample {
	return api.HostSample{SampledAt: sampleEpoch.Add(time.Duration(i) * time.Minute), CPUPct: []float64{float64(i)}}
}

// sampledTimes lists the minute of each sample a heartbeat carried.
func sampledTimes(hb api.Heartbeat) []int {
	var out []int
	for _, s := range hb.HostSamples {
		out = append(out, int(s.SampledAt.Sub(sampleEpoch)/time.Minute))
	}

	return out
}

func (f *fakeHeartbeats) at(i int) api.Heartbeat {
	f.mu.Lock()
	defer f.mu.Unlock()

	return f.sent[i]
}

func pendingSamples(h *heartbeater) []api.HostSample {
	h.mu.Lock()
	defer h.mu.Unlock()

	return append([]api.HostSample(nil), h.samples...)
}

// A failed heartbeat keeps its samples for the next one, and an accepted one
// drops them.
func TestHostSamplesStayUntilAHeartbeatLands(t *testing.T) {
	client := &fakeHeartbeats{errors: []error{nil, errors.New("offline")}}
	hh := startHeartbeater(t, client, time.Minute)
	hh.h.addHostSample(hostSampleAt(1))
	hh.h.addHostSample(hostSampleAt(2))

	hh.tick(t, time.Minute)
	hh.h.addHostSample(hostSampleAt(3))
	hh.tick(t, time.Minute)
	hh.tick(t, time.Minute)

	if got := sampledTimes(client.at(0)); got != nil {
		t.Fatalf("start heartbeat carried %v", got)
	}
	if got := sampledTimes(client.at(1)); !equalInts(got, []int{1, 2}) {
		t.Fatalf("failed heartbeat carried %v", got)
	}
	if got := sampledTimes(client.at(2)); !equalInts(got, []int{1, 2, 3}) {
		t.Fatalf("retry carried %v", got)
	}
	if got := sampledTimes(client.at(3)); got != nil {
		t.Fatalf("heartbeat after the landed one carried %v", got)
	}
}

// gatedHeartbeats holds the first heartbeat after start until the test opens
// the gate, so the test can replace the heartbeat that waits behind it.
type gatedHeartbeats struct {
	fakeHeartbeats
	entered chan struct{}
	gate    chan struct{}
	once    sync.Once
}

func (g *gatedHeartbeats) Heartbeat(ctx context.Context, bridgeID string, hb api.Heartbeat) (api.HeartbeatReply, error) {
	if g.count() == 1 {
		g.once.Do(func() { close(g.entered) })
		<-g.gate
	}

	return g.fakeHeartbeats.Heartbeat(ctx, bridgeID, hb)
}

// A heartbeat that a newer one replaced never goes out, and the newer one
// carries every sample that has not landed, each once.
func TestAReplacedHeartbeatLosesNoHostSample(t *testing.T) {
	client := &gatedHeartbeats{entered: make(chan struct{}), gate: make(chan struct{})}
	hh := startHeartbeaterWith(t, client, time.Minute)
	hh.h.addHostSample(hostSampleAt(1))
	_, ch := hh.timers.last()
	ch <- time.Now()
	<-client.entered

	hh.h.addHostSample(hostSampleAt(2))
	hh.h.send()
	hh.h.addHostSample(hostSampleAt(3))
	hh.h.send()
	close(client.gate)
	eventually(t, "the heartbeat that replaced the other", func() bool { return client.count() == 3 })

	if got := sampledTimes(client.at(1)); !equalInts(got, []int{1}) {
		t.Fatalf("held heartbeat carried %v", got)
	}
	if got := sampledTimes(client.at(2)); !equalInts(got, []int{2, 3}) {
		t.Fatalf("newest heartbeat carried %v", got)
	}
	eventually(t, "the buffer to empty", func() bool { return len(pendingSamples(hh.h)) == 0 })
}

// The buffer keeps the newest samples the server takes.
func TestTheHostSampleBufferDropsTheOldest(t *testing.T) {
	h := newHeartbeater(context.Background(), nil, nil, testBridgeID, api.Heartbeat{}, time.Minute, newBridgeLogger(&syncBuffer{}))
	for i := range api.MaxHostSamples + 5 {
		h.addHostSample(hostSampleAt(i))
	}

	got := pendingSamples(h)
	if len(got) != api.MaxHostSamples || !got[0].SampledAt.Equal(hostSampleAt(5).SampledAt) {
		t.Fatalf("%d samples, first at %s", len(got), got[0].SampledAt)
	}
}

// A backlog goes out oldest first, at most api.HostSamplesPerHeartbeat at a
// time, and only an accepted heartbeat removes what it carried.
func TestABacklogOfHostSamplesDrainsOverSeveralHeartbeats(t *testing.T) {
	client := &fakeHeartbeats{errors: []error{nil, errors.New("offline")}}
	hh := startHeartbeater(t, client, time.Minute)
	for i := range 150 {
		hh.h.addHostSample(hostSampleAt(i))
	}

	hh.tick(t, time.Minute)
	if got := len(pendingSamples(hh.h)); got != 150 {
		t.Fatalf("a failed heartbeat left %d samples", got)
	}
	for range 4 {
		hh.tick(t, time.Minute)
	}

	ranges := [][2]int{{0, 60}, {0, 60}, {60, 120}, {120, 150}}
	for i, r := range ranges {
		var want []int
		for m := r[0]; m < r[1]; m++ {
			want = append(want, m)
		}
		if got := sampledTimes(client.at(i + 1)); !equalInts(got, want) {
			t.Fatalf("heartbeat %d carried %v", i+1, got)
		}
	}
	if got := sampledTimes(client.at(5)); got != nil {
		t.Fatalf("heartbeat after the drain carried %v", got)
	}
}

func TestANilHeartbeaterDropsAHostSample(t *testing.T) {
	var h *heartbeater
	h.addHostSample(hostSampleAt(1))
}

func equalInts(a, b []int) bool {
	if len(a) != len(b) {
		return false
	}
	for i := range a {
		if a[i] != b[i] {
			return false
		}
	}

	return true
}

// samplerHarness is a router with a heartbeat, fake sampler timers and a fake
// host reader.
type samplerHarness struct {
	r      *router
	hh     *heartbeatHarness
	timers *fakeTimers
	taken  chan struct{}
}

func startSamplerHarness(t *testing.T, body string) *samplerHarness {
	t.Helper()
	set, _ := loadRules(t, body, rules.Defaults{})
	sh := &samplerHarness{hh: startHeartbeater(t, &fakeHeartbeats{}, time.Minute), timers: &fakeTimers{}, taken: make(chan struct{}, 100)}
	n := 0
	sh.r = withRules(&router{log: sh.hh.h.log, heartbeat: sh.hh.h, hostAfter: sh.timers.after, hostSample: func(context.Context) (api.HostSample, error) {
		n++
		defer func() { sh.taken <- struct{}{} }()

		return hostSampleAt(n), nil
	}}, set)
	t.Cleanup(func() { sh.r.applyFlags(api.Events{}) })

	return sh
}

func (sh *samplerHarness) running() *hostSampler {
	sh.r.samplerMu.Lock()
	defer sh.r.samplerMu.Unlock()

	return sh.r.sampler
}

func samplingFlags(on bool, seconds float64) api.Events {
	return api.Events{Flags: map[string]any{api.HostSamplingFlag: on, api.HostSampleIntervalFlag: seconds}}
}

// The flag starts the sampler, each tick adds a sample to the heartbeat, and
// the flag off stops it.
func TestTheHostSamplingFlagStartsAndStopsTheSampler(t *testing.T) {
	sh := startSamplerHarness(t, defaultRules)

	sh.r.applyFlags(api.Events{})
	if sh.running() != nil {
		t.Fatal("the sampler runs with the flag off")
	}

	sh.r.applyFlags(samplingFlags(true, 30))
	eventually(t, "a sampler timer", func() bool { return sh.timers.count() == 1 })
	delay, ch := sh.timers.last()
	if delay != 30*time.Second {
		t.Fatalf("delay = %s", delay)
	}
	ch <- time.Now()
	<-sh.taken
	eventually(t, "the sample in the heartbeat buffer", func() bool { return len(pendingSamples(sh.hh.h)) == 1 })

	stopped := sh.running()
	sh.r.applyFlags(samplingFlags(false, 30))
	if got := pendingSamples(sh.hh.h); len(got) != 0 {
		t.Fatalf("the flag off left %d samples", len(got))
	}
	if sh.running() != nil {
		t.Fatal("the sampler runs after the flag went off")
	}
	select {
	case <-stopped.done:
	default:
		t.Fatal("the sampler loop still runs")
	}
}

// A new interval restarts the sampler with it, and a value below the floor
// falls back to a minute.
func TestTheHostSamplerTakesANewInterval(t *testing.T) {
	sh := startSamplerHarness(t, defaultRules)

	sh.r.applyFlags(samplingFlags(true, 30))
	eventually(t, "the first timer", func() bool { return sh.timers.count() == 1 })
	first := sh.running()
	sh.hh.h.addHostSample(hostSampleAt(1))
	sh.r.applyFlags(samplingFlags(true, 30))
	if sh.running() != first {
		t.Fatal("the same interval restarted the sampler")
	}

	sh.r.applyFlags(samplingFlags(true, 4))
	eventually(t, "the fallback timer", func() bool { return sh.timers.count() == 2 })
	if delay, _ := sh.timers.last(); delay != time.Minute {
		t.Fatalf("delay = %s", delay)
	}
	if got := pendingSamples(sh.hh.h); len(got) != 1 {
		t.Fatalf("a new interval left %d samples", len(got))
	}
}

// collect: false keeps the sampler off, and a reload that sets it stops a
// running one.
func TestCollectFalseStopsTheHostSampler(t *testing.T) {
	sh := startSamplerHarness(t, "collect: false\n"+defaultRules)
	sh.r.applyFlags(samplingFlags(true, 30))
	if sh.running() != nil {
		t.Fatal("the sampler runs with collect: false")
	}

	h := &harness{router: sh.r, dir: t.TempDir()}
	if res := h.reload(t, defaultRules); !res.OK {
		t.Fatalf("reload = %+v", res)
	}
	if sh.running() == nil {
		t.Fatal("the sampler does not run after collect went back on")
	}
	sh.hh.h.addHostSample(hostSampleAt(1))
	if res := h.reload(t, "collect: false\n"+defaultRules); !res.OK {
		t.Fatalf("reload = %+v", res)
	}
	if sh.running() != nil {
		t.Fatal("the sampler runs after a reload set collect: false")
	}
	if got := pendingSamples(sh.hh.h); len(got) != 0 {
		t.Fatalf("collect: false left %d samples", len(got))
	}
}

// The sampler rounds and clamps each reading before the buffer keeps it.
func TestTheHostSamplerNormalizesEachSample(t *testing.T) {
	sh := startSamplerHarness(t, defaultRules)
	pct := 104.2
	sh.r.hostSample = func(context.Context) (api.HostSample, error) {
		defer func() { sh.taken <- struct{}{} }()

		return api.HostSample{SampledAt: sampleEpoch, CPUPct: []float64{12.3456, 100.04, -0.2}, MemUsed: -1, BatteryPct: &pct}, nil
	}
	sh.r.applyFlags(samplingFlags(true, 30))
	eventually(t, "a sampler timer", func() bool { return sh.timers.count() == 1 })
	_, ch := sh.timers.last()
	ch <- time.Now()
	<-sh.taken
	eventually(t, "the sample in the buffer", func() bool { return len(pendingSamples(sh.hh.h)) == 1 })

	got := pendingSamples(sh.hh.h)[0]
	if !slices.Equal(got.CPUPct, []float64{12.3, 100, 0}) || got.MemUsed != 0 || *got.BatteryPct != 100 {
		t.Fatalf("sample = %+v, battery %v", got, *got.BatteryPct)
	}
}

// A failed read adds nothing, so the heartbeat never sends a zero reading.
func TestAFailedHostReadAddsNoSample(t *testing.T) {
	sh := startSamplerHarness(t, defaultRules)
	sh.r.hostSample = func(context.Context) (api.HostSample, error) {
		defer func() { sh.taken <- struct{}{} }()

		return api.HostSample{}, errors.New("no sysctl")
	}
	sh.r.applyFlags(samplingFlags(true, 30))
	eventually(t, "a sampler timer", func() bool { return sh.timers.count() == 1 })
	_, ch := sh.timers.last()
	ch <- time.Now()
	<-sh.taken
	eventually(t, "the next timer", func() bool { return sh.timers.count() == 2 })

	if got := pendingSamples(sh.hh.h); len(got) != 0 {
		t.Fatalf("buffer = %v", got)
	}
}
