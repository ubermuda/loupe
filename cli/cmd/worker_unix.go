//go:build unix

package cmd

import (
	"errors"
	"os"
	"os/exec"
	"syscall"
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
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	cmd.Cancel = func() error {
		return cancelErr(syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL))
	}
}

func cancelErr(err error) error {
	if errors.Is(err, syscall.ESRCH) {
		return os.ErrProcessDone
	}

	return err
}
