//go:build unix

package cmd

import (
	"os/exec"
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
