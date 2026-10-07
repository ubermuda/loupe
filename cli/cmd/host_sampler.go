package cmd

import (
	"context"
	"log/slog"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/hostsample"
)

// defaultHostSampleInterval applies when the server shares no interval of at
// least minHostSampleSeconds.
const (
	defaultHostSampleInterval = time.Minute
	minHostSampleSeconds      = 5
)

// hostSampleInterval reads the sampling interval from a GET /api/events answer.
func hostSampleInterval(events api.Events) time.Duration {
	if seconds, ok := events.Seconds(api.HostSampleIntervalFlag); ok && seconds >= minHostSampleSeconds {
		return time.Duration(seconds) * time.Second
	}

	return defaultHostSampleInterval
}

// readHostSample reads the host at the current time.
func readHostSample(ctx context.Context) (api.HostSample, error) {
	return hostsample.Read(ctx, time.Now())
}

// hostSampler reads the host at each interval and hands each sample to add,
// until stop.
type hostSampler struct {
	interval time.Duration
	cancel   context.CancelFunc
	done     chan struct{}
}

func startHostSampler(ctx context.Context, interval time.Duration, after func(time.Duration) <-chan time.Time, take func(context.Context) (api.HostSample, error), add func(api.HostSample), log *slog.Logger) *hostSampler {
	ctx, cancel := context.WithCancel(ctx)
	s := &hostSampler{interval: interval, cancel: cancel, done: make(chan struct{})}
	go func() {
		defer close(s.done)
		failing := false
		for {
			select {
			case <-ctx.Done():
				return
			case <-after(interval):
			}
			sample, err := take(ctx)
			if err != nil {
				if !failing {
					log.Warn("host_sample_failed", "error", err.Error())
				}
				failing = true

				continue
			}
			failing = false
			add(hostsample.Normalize(sample))
		}
	}()

	return s
}

// stop ends the loop and waits for it.
func (s *hostSampler) stop() {
	s.cancel()
	<-s.done
}

// syncHostSampler runs the sampler while the flag is on, the rule file
// collects, and a heartbeat carries the samples. A new interval restarts it.
func (r *router) syncHostSampler() {
	r.samplerMu.Lock()
	defer r.samplerMu.Unlock()
	r.mu.Lock()
	on, interval, hb := r.hostSampling, r.hostInterval, r.heartbeat
	r.mu.Unlock()
	if set := r.rules(); set != nil && !set.Collect() {
		on = false
	}
	on = on && hb != nil

	if s := r.sampler; s != nil {
		if on && s.interval == interval {
			return
		}
		s.stop()
		r.sampler = nil
		if !on {
			hb.clearHostSamples()
			r.log.Info("host_sampling_stopped")
		}
	}
	if !on {
		return
	}

	ctx := r.ctx
	if ctx == nil {
		ctx = context.Background()
	}
	after, take := r.hostAfter, r.hostSample
	if after == nil {
		after = time.After
	}
	if take == nil {
		hostsample.Prime(ctx)
		take = readHostSample
	}
	r.sampler = startHostSampler(ctx, interval, after, take, hb.addHostSample, r.log)
	r.log.Info("host_sampling_started", "interval_seconds", int(interval/time.Second))
}
