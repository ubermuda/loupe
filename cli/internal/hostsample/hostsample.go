// Package hostsample reads the load of the host the bridge runs on: the CPU
// of each core, the memory, the swap and the battery.
package hostsample

import (
	"context"
	"math"
	"time"

	"github.com/shirou/gopsutil/v4/cpu"
	"github.com/shirou/gopsutil/v4/mem"
	"github.com/ubermuda/loupe/cli/internal/api"
)

// Prime starts the CPU counters, so the first Read measures from this call
// and not from the start of the process.
func Prime(ctx context.Context) {
	_, _ = cpu.PercentWithContext(ctx, 0, true)
}

// Read takes one sample at now. It returns an error when the CPU, the memory
// or the swap cannot be read, because a zero there would read as a real value.
// A battery that cannot be read gives nil fields.
func Read(ctx context.Context, now time.Time) (api.HostSample, error) {
	cores, err := cpu.PercentWithContext(ctx, 0, true)
	if err != nil {
		return api.HostSample{}, err
	}
	vm, err := mem.VirtualMemoryWithContext(ctx)
	if err != nil {
		return api.HostSample{}, err
	}
	swap, err := mem.SwapMemoryWithContext(ctx)
	if err != nil {
		return api.HostSample{}, err
	}
	pct, onAC := battery(ctx)

	return api.HostSample{
		SampledAt:  now.UTC().Truncate(time.Second),
		CPUPct:     cores,
		MemUsed:    toInt64(vm.Used),
		MemTotal:   toInt64(vm.Total),
		SwapUsed:   toInt64(swap.Used),
		BatteryPct: pct,
		OnAC:       onAC,
	}, nil
}

func toInt64(n uint64) int64 {
	return int64(min(n, math.MaxInt64))
}
