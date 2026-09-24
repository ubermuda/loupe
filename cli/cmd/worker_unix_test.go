//go:build unix

package cmd

import (
	"context"
	"errors"
	"os"
	"os/exec"
	"syscall"
	"testing"
	"time"
)

// A stand-in claude prints its result with the ceiling it got on stdout, and
// noise on stderr, so the test sees the environment and the split streams.
func TestRunWorkerLiftsTheCeilingAndDecodesStdout(t *testing.T) {
	fakeClaude(t, "echo '{\"structured_output\":{\"status\":\"finished\",\"summary\":\"ceiling='\"$"+ceilingEnv+"\"'\"}}'\necho 'a warning' >&2\n")
	t.Setenv(ceilingEnv, "")
	if err := os.Unsetenv(ceilingEnv); err != nil {
		t.Fatal(err)
	}

	res := runWorker(context.Background(), workerSpec{dir: t.TempDir(), sessionID: testSession, prompt: "go"}, nil)
	if res.err != nil || res.exitCode != 0 {
		t.Fatalf("runWorker = %+v", res)
	}
	if !res.hasResult || res.status != "finished" || res.output != "ceiling=0" {
		t.Fatalf("runWorker = %+v", res)
	}
}

// Stdout that holds no result leaves stderr as the output.
func TestRunWorkerFallsBackToStderr(t *testing.T) {
	fakeClaude(t, "echo 'not json'\necho 'claude: no such option' >&2\nexit 2\n")

	res := runWorker(context.Background(), workerSpec{dir: t.TempDir(), sessionID: testSession, prompt: "go"}, nil)
	if res.hasResult || res.exitCode != 2 || res.output != "claude: no such option" {
		t.Fatalf("runWorker = %+v", res)
	}
}

// Stdout past the bound reads as no result, and the worker still runs to its
// end rather than failing on a short write.
func TestRunWorkerBoundsStdout(t *testing.T) {
	fakeClaude(t, "head -c 1100000 /dev/zero\necho 'late' >&2\n")

	res := runWorker(context.Background(), workerSpec{dir: t.TempDir(), sessionID: testSession, prompt: "go"}, nil)
	if res.hasResult || res.exitCode != 0 || res.output != "late" {
		t.Fatalf("runWorker = %+v", res)
	}
}

// A claude that the bridge's context kills reads as killed, and one that ends
// on its own does not.
func TestRunWorkerSaysWhetherTheBridgeKilledIt(t *testing.T) {
	fakeClaude(t, "case \"$*\" in *--permission-mode*) exec sleep 30;; esac\n")

	if res := runWorker(context.Background(), workerSpec{dir: t.TempDir(), sessionID: testSession, prompt: "go"}, nil); res.killed {
		t.Fatalf("a worker that exited on its own reads as killed: %+v", res)
	}

	ctx, cancel := context.WithTimeout(context.Background(), 200*time.Millisecond)
	defer cancel()
	res := runWorker(ctx, workerSpec{dir: t.TempDir(), permissionMode: "plan", sessionID: testSession, prompt: "go"}, nil)
	if !res.killed {
		t.Fatalf("a worker the context ended does not read as killed: %+v", res)
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
