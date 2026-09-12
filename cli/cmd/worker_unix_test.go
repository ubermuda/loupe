//go:build unix

package cmd

import (
	"errors"
	"os"
	"os/exec"
	"syscall"
	"testing"
)

// TestSetProcessGroupIsWired keeps the group kill in place. Without Setpgid and
// a Cancel of its own, cancelling the bridge kills claude and leaves the tools
// it started running in the worktree.
func TestSetProcessGroupIsWired(t *testing.T) {
	cmd := exec.Command("true")
	setProcessGroup(cmd)

	if cmd.SysProcAttr == nil || !cmd.SysProcAttr.Setpgid {
		t.Fatalf("Setpgid is not set: %+v", cmd.SysProcAttr)
	}
	if cmd.Cancel == nil {
		t.Fatal("Cancel is not set, so cancellation kills claude alone")
	}
}

// A worker that exits a moment before the cancel leaves no group to kill. Only
// os.ErrProcessDone tells os/exec that, so raw ESRCH would turn a clean exit
// into a reported fault.
func TestCancelErrTranslatesAVanishedGroup(t *testing.T) {
	if got := cancelErr(syscall.ESRCH); !errors.Is(got, os.ErrProcessDone) {
		t.Fatalf("cancelErr(ESRCH) = %v, want os.ErrProcessDone", got)
	}
	if got := cancelErr(nil); got != nil {
		t.Fatalf("cancelErr(nil) = %v", got)
	}
	if got := cancelErr(syscall.EPERM); !errors.Is(got, syscall.EPERM) {
		t.Fatalf("cancelErr(EPERM) = %v, want the real error", got)
	}
}
