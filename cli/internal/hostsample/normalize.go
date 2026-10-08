package hostsample

import (
	"math"

	"github.com/ubermuda/loupe/cli/internal/api"
)

// Normalize rounds each core to one decimal and clamps the percents to
// [0, 100] and the byte counts to 0 or more. A NaN core reads as 0, and a
// NaN battery as unknown, because JSON has no NaN.
func Normalize(s api.HostSample) api.HostSample {
	cores := make([]float64, len(s.CPUPct))
	for i, v := range s.CPUPct {
		if !math.IsNaN(v) {
			cores[i] = clampPct(math.Round(v*10) / 10)
		}
	}
	s.CPUPct = cores
	s.MemUsed, s.MemTotal, s.SwapUsed = max(s.MemUsed, 0), max(s.MemTotal, 0), max(s.SwapUsed, 0)
	if s.BatteryPct != nil {
		if math.IsNaN(*s.BatteryPct) {
			s.BatteryPct = nil
		} else {
			pct := clampPct(*s.BatteryPct)
			s.BatteryPct = &pct
		}
	}

	return s
}

func clampPct(v float64) float64 {
	return max(0, min(100, v))
}
