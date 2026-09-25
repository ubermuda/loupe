//go:build unix && !linux && !darwin

package cmd

import "errors"

// processInfo has no source on this system, so the caller trusts kill(pid, 0).
func processInfo(int) (procInfo, error) {
	return procInfo{}, errors.New("no process start time on this system")
}
