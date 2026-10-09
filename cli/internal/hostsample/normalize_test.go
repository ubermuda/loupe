package hostsample

import (
	"math"
	"slices"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
)

func TestNormalizeRoundsAndClampsTheReadings(t *testing.T) {
	cores := []float64{12.3456, 99.96, 100.4, -3, math.NaN(), math.Inf(1)}
	pct := -2.0
	in := api.HostSample{CPUPct: cores, MemUsed: -5, MemTotal: 4096, SwapUsed: -1, BatteryPct: &pct}

	got := Normalize(in)

	if want := []float64{12.3, 100, 100, 0, 0, 100}; !slices.Equal(got.CPUPct, want) {
		t.Fatalf("cpu = %v, want %v", got.CPUPct, want)
	}
	if got.MemUsed != 0 || got.MemTotal != 4096 || got.SwapUsed != 0 {
		t.Fatalf("memory = %d/%d, swap %d", got.MemUsed, got.MemTotal, got.SwapUsed)
	}
	if got.BatteryPct == nil || *got.BatteryPct != 0 {
		t.Fatalf("battery = %v", got.BatteryPct)
	}
	if cores[0] != 12.3456 || pct != -2 {
		t.Fatal("Normalize changed its input")
	}
}

func TestNormalizeKeepsAnUnknownBatteryUnknown(t *testing.T) {
	high, nan := 140.0, math.NaN()
	if got := Normalize(api.HostSample{BatteryPct: &high}); *got.BatteryPct != 100 {
		t.Fatalf("battery = %v", *got.BatteryPct)
	}
	if got := Normalize(api.HostSample{BatteryPct: &nan}); got.BatteryPct != nil {
		t.Fatalf("battery = %v", *got.BatteryPct)
	}
	if got := Normalize(api.HostSample{}); got.BatteryPct != nil {
		t.Fatalf("battery = %v", *got.BatteryPct)
	}
}
