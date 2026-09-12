package cmd

import (
	"bytes"
	"strings"
	"testing"
)

// runBridge parses args and runs the command far enough to reach its flag
// checks, which come before anything that touches tmux or the network.
func runBridge(t *testing.T, args ...string) error {
	t.Helper()
	cmd := newBridgeRunCmd()
	cmd.SetArgs(args)
	cmd.SetOut(&bytes.Buffer{})
	cmd.SetErr(&bytes.Buffer{})
	cmd.SilenceUsage, cmd.SilenceErrors = true, true

	return cmd.Execute()
}

func TestBridgeRunRequiresExactlyOneTarget(t *testing.T) {
	for _, args := range [][]string{
		{},
		{"--dir", "/src/app", "--session", "mine"},
	} {
		err := runBridge(t, args...)
		if err == nil || !strings.Contains(err.Error(), "exactly one of --dir") {
			t.Fatalf("args %v: err = %v", args, err)
		}
	}
}

// TestBridgeRunRejectsPermissionModeWithSession is the same class of bug as the
// silently ignored --dir: a flag that cannot apply is refused, not dropped.
func TestBridgeRunRejectsPermissionModeWithSession(t *testing.T) {
	err := runBridge(t, "--session", "mine", "--permission-mode", "bypassPermissions")
	if err == nil || !strings.Contains(err.Error(), "--permission-mode only applies") {
		t.Fatalf("err = %v", err)
	}
}

// TestBridgeRunPermissionModeDefaultsToEmpty keeps the operator opting in: with
// no flag, claude prompts for permissions as it normally does.
func TestBridgeRunPermissionModeDefaultsToEmpty(t *testing.T) {
	flag := newBridgeRunCmd().Flags().Lookup("permission-mode")
	if flag == nil {
		t.Fatal("--permission-mode is not registered")
	}
	if flag.DefValue != "" {
		t.Fatalf("--permission-mode defaults to %q, want empty", flag.DefValue)
	}
}
