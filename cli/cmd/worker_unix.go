//go:build unix

package cmd

import (
	"context"
	"errors"
	"os"
	"os/exec"
	"sync"
	"syscall"
	"time"
)

// setProcessGroup gives the worker its own process group and kills the whole
// group on cancellation.
//
// claude runs its tools as child processes. Killing claude alone leaves them
// editing the directory after the bridge has stopped, which is the opposite of
// what an unattended run needs.
//
// os/exec reads only os.ErrProcessDone as "it finished first", so a group that
// has already gone must be translated. Raw ESRCH makes Run report a fault for a
// worker that exited cleanly a moment before the cancel.
func setProcessGroup(cmd *exec.Cmd) {
	newProcessGroup(cmd)
	cmd.Cancel = func() error {
		return cancelErr(syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL))
	}
}

// newProcessGroup gives cmd its own process group, so a Ctrl-C at the bridge's
// terminal never reaches it. It sets no Cancel, which exec.Command refuses.
func newProcessGroup(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
}

func cancelErr(err error) error {
	if errors.Is(err, syscall.ESRCH) {
		return os.ErrProcessDone
	}

	return err
}

// procInfo is what the OS says of a process: an opaque start time that tells
// a reused pid apart, and whether it exited unreaped.
type procInfo struct {
	start  string
	zombie bool
}

// adoptPoll spaces the checks on an adopted worker that is not our child.
var adoptPoll = 2 * time.Second

// processStart is the start time of pid, or "" when the OS does not say.
func processStart(pid int) string {
	info, err := processInfo(pid)
	if err != nil {
		return ""
	}

	return info.start
}

// processAlive reports whether pid still runs the process that started at
// start. An empty start matches any process. A pid we may not signal still
// runs, and so does one the OS cannot describe.
func processAlive(pid int, start string) bool {
	if errors.Is(syscall.Kill(pid, 0), syscall.ESRCH) {
		return false
	}
	info, err := processInfo(pid)
	if err != nil {
		return !errors.Is(err, os.ErrNotExist)
	}

	return !info.zombie && (start == "" || info.start == start)
}

// awaitProcess waits for a worker that another image of the bridge started.
// Across an exec it is still our child, so Wait4 reaps it. A worker whose
// parent died is not, and Wait4 fails, so its pid is polled instead. The end
// of ctx kills its process group, as the cancel of a started worker does.
func awaitProcess(ctx context.Context, pid int, start string) bool {
	// mu spans the kill, so a worker the kill ended never reads as unkilled.
	var mu sync.Mutex
	killed := false
	stop := context.AfterFunc(ctx, func() {
		mu.Lock()
		defer mu.Unlock()
		killed = processAlive(pid, start) && syscall.Kill(-pid, syscall.SIGKILL) == nil
	})
	wasKilled := func() bool {
		stop()
		mu.Lock()
		defer mu.Unlock()

		return killed
	}

	for {
		var ws syscall.WaitStatus
		_, err := syscall.Wait4(pid, &ws, 0, nil)
		if errors.Is(err, syscall.EINTR) {
			continue
		}
		if err == nil {
			return wasKilled()
		}

		break
	}
	// A group the kill could not reach must not hold the shutdown for long.
	var late <-chan time.Time
	for processAlive(pid, start) {
		if late == nil && ctx.Err() != nil {
			late = time.After(waitDelay)
		}
		select {
		case <-late:
			return wasKilled()
		case <-time.After(adoptPoll):
		}
	}

	return wasKilled()
}
