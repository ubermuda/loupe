//go:build unix

package cmd

import (
	"context"
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"testing"
)

// A stand-in claude prints the ceiling it got on stdout and its result line on
// stderr, so the test sees the environment and the shared stream wiring.
func TestRunWorkerLiftsTheCeilingAndReadsBothStreams(t *testing.T) {
	bin := t.TempDir()
	script := "#!/bin/sh\necho \"ceiling=$" + ceilingEnv + "\"\necho 'STAGE RESULT: done' >&2\n"
	if err := os.WriteFile(filepath.Join(bin, "claude"), []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}
	t.Setenv("PATH", bin+string(os.PathListSeparator)+os.Getenv("PATH"))
	t.Setenv(ceilingEnv, "")
	if err := os.Unsetenv(ceilingEnv); err != nil {
		t.Fatal(err)
	}

	res := runWorker(context.Background(), workerSpec{dir: t.TempDir(), sessionID: testSession, prompt: "go"})
	if res.err != nil || res.exitCode != 0 {
		t.Fatalf("runWorker = %+v", res)
	}
	if !res.hasResult || res.output != "ceiling=0\nSTAGE RESULT: done" {
		t.Fatalf("runWorker = %+v", res)
	}
}

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
