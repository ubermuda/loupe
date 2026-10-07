package hostsample

import (
	"os"
	"path/filepath"
	"testing"
)

func TestParsePmset(t *testing.T) {
	cases := []struct {
		name string
		out  string
		pct  *float64
		onAC *bool
	}{
		{"laptop on AC", "Now drawing from 'AC Power'\n -InternalBattery-0 (id=4653155)\t100%; charged; 0:00 remaining present: true\n", new(100.0), new(true)},
		{"laptop on battery", "Now drawing from 'Battery Power'\n -InternalBattery-0 (id=4653155)\t87%; discharging; 5:12 remaining present: true\n", new(87.0), new(false)},
		{"desktop with no battery", "Now drawing from 'AC Power'\n", nil, new(true)},
		{"empty output", "", nil, nil},
		{"garbage output", "pmset: command failed\n -InternalBattery-0 (id=1)\t999%; charging\n", nil, nil},
		{"unknown source", "Now drawing from 'UPS Power'\n", nil, nil},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			pct, onAC := parsePmset(tc.out)
			assertPower(t, pct, onAC, tc.pct, tc.onAC)
		})
	}
}

// supply is one directory of the power_supply class: its name and its files.
type supply struct {
	name  string
	files map[string]string
}

func TestReadPowerSupply(t *testing.T) {
	cases := []struct {
		name     string
		supplies []supply
		pct      *float64
		onAC     *bool
	}{
		{"laptop on AC", []supply{
			{"AC", map[string]string{"type": "Mains\n", "online": "1\n"}},
			{"BAT0", map[string]string{"type": "Battery\n", "capacity": "93\n"}},
		}, new(93.0), new(true)},
		{"laptop on battery", []supply{
			{"AC", map[string]string{"type": "Mains\n", "online": "0\n"}},
			{"BAT0", map[string]string{"type": "Battery\n", "capacity": "41\n"}},
			{"ucsi-source-psy-USBC000:001", map[string]string{"type": "USB\n", "online": "0\n"}},
		}, new(41.0), new(false)},
		{"desktop with no battery", []supply{
			{"ACAD", map[string]string{"type": "Mains\n", "online": "1\n"}},
		}, nil, new(true)},
		{"a USB supply online beats an offline mains", []supply{
			{"AC", map[string]string{"type": "Mains\n", "online": "0\n"}},
			{"usb", map[string]string{"type": "USB\n", "online": "1\n"}},
		}, nil, new(true)},
		{"the battery of a mouse is not the host battery", []supply{
			{"hidpp_battery_0", map[string]string{"type": "Battery\n", "scope": "Device\n", "capacity": "20\n"}},
		}, nil, nil},
		{"garbage values", []supply{
			{"AC", map[string]string{"type": "Mains\n", "online": "maybe\n"}},
			{"BAT0", map[string]string{"type": "Battery\n", "capacity": "-3\n"}},
		}, nil, nil},
		{"no supply", nil, nil, nil},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			root := t.TempDir()
			for _, s := range tc.supplies {
				dir := filepath.Join(root, s.name)
				if err := os.Mkdir(dir, 0o755); err != nil {
					t.Fatal(err)
				}
				for name, content := range s.files {
					if err := os.WriteFile(filepath.Join(dir, name), []byte(content), 0o644); err != nil {
						t.Fatal(err)
					}
				}
			}
			pct, onAC := readPowerSupply(root)
			assertPower(t, pct, onAC, tc.pct, tc.onAC)
		})
	}
}

func TestReadPowerSupplyWithNoSysfsIsUnknown(t *testing.T) {
	pct, onAC := readPowerSupply(filepath.Join(t.TempDir(), "missing"))
	assertPower(t, pct, onAC, nil, nil)
}

func assertPower(t *testing.T, pct *float64, onAC *bool, wantPct *float64, wantOnAC *bool) {
	t.Helper()
	if (pct == nil) != (wantPct == nil) || (pct != nil && *pct != *wantPct) {
		t.Fatalf("pct = %v, want %v", deref(pct), deref(wantPct))
	}
	if (onAC == nil) != (wantOnAC == nil) || (onAC != nil && *onAC != *wantOnAC) {
		t.Fatalf("onAC = %v, want %v", deref(onAC), deref(wantOnAC))
	}
}

func deref[T any](p *T) any {
	if p == nil {
		return nil
	}

	return *p
}
