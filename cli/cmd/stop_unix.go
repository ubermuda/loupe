//go:build unix

package cmd

import (
	"errors"
	"fmt"
	"syscall"
)

// The steps of the stop ladder, in order. stopProbe sends no signal and only
// asks whether the group still runs.
const (
	stopProbe stopSignal = iota
	stopInt
	stopTerm
	stopKill
)

// stopSignal is one step of the stop ladder.
type stopSignal int

func (s stopSignal) String() string {
	return [...]string{"probe", "SIGINT", "SIGTERM", "SIGKILL"}[s]
}

// errGroupGone marks a process group that has no process left.
var errGroupGone = errors.New("the process group has no process")

// signalGroup sends sig to the process group that pid leads. An adopted worker
// is no child of this image, so the signal goes by group id and not through
// exec.Cmd. A pid below 2 names no worker: kill(0) reaches the bridge's own
// group, and kill(-1) every process the user owns.
func signalGroup(pid int, sig stopSignal) error {
	if pid < 2 {
		return fmt.Errorf("pid %d leads no worker group", pid)
	}
	signals := [...]syscall.Signal{0, syscall.SIGINT, syscall.SIGTERM, syscall.SIGKILL}
	err := syscall.Kill(-pid, signals[sig])
	if errors.Is(err, syscall.ESRCH) {
		return errGroupGone
	}

	return err
}
