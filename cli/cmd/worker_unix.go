//go:build unix

package cmd

import (
	"os/exec"
	"syscall"
)

// setProcessGroup gives the worker its own process group and kills the whole
// group on cancellation.
//
// claude runs its tools as child processes. Killing claude alone leaves them
// editing the directory after the bridge has stopped, which is the opposite of
// what an unattended run needs.
func setProcessGroup(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	cmd.Cancel = func() error {
		return syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL)
	}
}
