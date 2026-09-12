package cmd

import (
	"bytes"
	"errors"
	"strings"
	"testing"
)

// runBridge parses args and runs the command far enough to reach its flag
// checks, which come before anything that starts a process or opens a socket.
func runBridge(t *testing.T, args ...string) error {
	t.Helper()
	cmd := newBridgeRunCmd()
	cmd.SetArgs(args)
	cmd.SetOut(&bytes.Buffer{})
	cmd.SetErr(&bytes.Buffer{})
	cmd.SilenceUsage, cmd.SilenceErrors = true, true

	return cmd.Execute()
}

func TestBridgeRunRequiresDir(t *testing.T) {
	err := runBridge(t)
	if err == nil || !strings.Contains(err.Error(), "--dir is required") {
		t.Fatalf("err = %v", err)
	}
}

// TestBridgeRunFailsFastWithoutClaude keeps the missing-binary error at the
// start of the run, where the operator sees it, rather than on the first card.
// The --dir check comes first, so this test never depends on the host's PATH.
func TestBridgeRunFailsFastWithoutClaude(t *testing.T) {
	original := lookPath
	lookPath = func(string) (string, error) { return "", errors.New("not found") }
	t.Cleanup(func() { lookPath = original })

	err := runBridge(t, "--dir", "/src/app")
	if err == nil || !strings.Contains(err.Error(), "claude is not installed") {
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

// TestBridgeRunHasNoTmuxFlags pins the removal: --session and --attach are gone
// with the tmux path, so a stale invocation fails instead of running unattended
// in a shape the binary no longer has.
func TestBridgeRunHasNoTmuxFlags(t *testing.T) {
	flags := newBridgeRunCmd().Flags()
	for _, name := range []string{"session", "attach"} {
		if flags.Lookup(name) != nil {
			t.Fatalf("--%s is still registered", name)
		}
	}
}
