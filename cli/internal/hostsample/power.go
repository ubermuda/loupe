package hostsample

import (
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
)

// pmsetPercent finds the charge on the line of an internal battery.
var pmsetPercent = regexp.MustCompile(`(\d{1,3})%`)

// parsePmset reads the output of `pmset -g batt`. A host with no battery line
// gives a nil percent, and an unknown power source a nil onAC.
func parsePmset(out string) (pct *float64, onAC *bool) {
	for line := range strings.Lines(out) {
		switch {
		case strings.Contains(line, "Now drawing from 'AC Power'"):
			onAC = new(true)
		case strings.Contains(line, "Now drawing from 'Battery Power'"):
			onAC = new(false)
		case strings.Contains(line, "-InternalBattery-") && pct == nil:
			if m := pmsetPercent.FindStringSubmatch(line); m != nil {
				pct = percent(m[1])
			}
		}
	}

	return pct, onAC
}

// readPowerSupply reads the power_supply class of sysfs under root. The first
// system battery gives the percent. A mains or USB supply that is online makes
// onAC true, and one that is offline makes it false when no other is online.
func readPowerSupply(root string) (pct *float64, onAC *bool) {
	entries, err := os.ReadDir(root)
	if err != nil {
		return nil, nil
	}
	for _, e := range entries {
		dir := filepath.Join(root, e.Name())
		kind, ok := readTrimmed(dir, "type")
		if !ok {
			continue
		}
		// A wireless mouse or headset reports a battery with the Device scope.
		if scope, _ := readTrimmed(dir, "scope"); scope == "Device" {
			continue
		}
		switch {
		case kind == "Battery" && pct == nil:
			if v, ok := readTrimmed(dir, "capacity"); ok {
				pct = percent(v)
			}
		case kind == "Mains" || strings.HasPrefix(kind, "USB"):
			v, ok := readTrimmed(dir, "online")
			if !ok || (v != "0" && v != "1") {
				continue
			}
			if onAC == nil || !*onAC {
				onAC = new(v == "1")
			}
		}
	}

	return pct, onAC
}

func readTrimmed(dir, name string) (string, bool) {
	b, err := os.ReadFile(filepath.Join(dir, name))
	if err != nil {
		return "", false
	}

	return strings.TrimSpace(string(b)), true
}

// percent reads s as a whole percent from 0 to 100, and nil for anything else.
func percent(s string) *float64 {
	n, err := strconv.Atoi(s)
	if err != nil || n < 0 || n > 100 {
		return nil
	}

	return new(float64(n))
}
