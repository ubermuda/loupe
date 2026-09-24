//go:build unix

package cmd

import (
	"context"
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/config"
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
	shortConfigHome(t)
	t.Setenv(ceilingEnv, "")
	if err := os.Unsetenv(ceilingEnv); err != nil {
		t.Fatal(err)
	}

	res := runWorker(context.Background(), workerSpec{dir: t.TempDir(), sessionID: testSession, prompt: "go"}, nil)
	if res.err != nil || res.exitCode != 0 {
		t.Fatalf("runWorker = %+v", res)
	}
	if !res.hasResult || res.output != "ceiling=0\nSTAGE RESULT: done" {
		t.Fatalf("runWorker = %+v", res)
	}
}

// A claude that the bridge's context kills reads as killed, and one that ends
// on its own does not.
func TestRunWorkerSaysWhetherTheBridgeKilledIt(t *testing.T) {
	bin := t.TempDir()
	script := "#!/bin/sh\n[ \"$1\" = -p ] && exit 0\nexec sleep 30\n"
	if err := os.WriteFile(filepath.Join(bin, "claude"), []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}
	t.Setenv("PATH", bin+string(os.PathListSeparator)+os.Getenv("PATH"))
	shortConfigHome(t)

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

// workerClaude puts a claude script on PATH and points the config dir at a temp
// dir.
func workerClaude(t *testing.T, script string) {
	t.Helper()
	bin := t.TempDir()
	if err := os.WriteFile(filepath.Join(bin, "claude"), []byte("#!/bin/sh\n"+script), 0o755); err != nil {
		t.Fatal(err)
	}
	t.Setenv("PATH", bin+string(os.PathListSeparator)+os.Getenv("PATH"))
	shortConfigHome(t)
}

// The worker writes to a file the bridge does not own, and the bridge reads
// the exit code the shell recorded. The run directory stays for the router,
// which removes it once it reported the run.
func TestRunWorkerReadsTheExitCodeAndTheOutputFromItsRunDirectory(t *testing.T) {
	workerClaude(t, "echo working\necho 'STAGE RESULT: ok'\nexit 3\n")

	res := runWorker(context.Background(), workerSpec{dir: t.TempDir(), sessionID: testSession, runID: "run-1", prompt: "go"}, nil)
	if res.err != nil || res.killed || res.exitCode != 3 || !res.hasResult {
		t.Fatalf("runWorker = %+v", res)
	}
	if res.output != "working\nSTAGE RESULT: ok" {
		t.Fatalf("output = %q", res.output)
	}
	runs, err := config.RunsDir()
	if err != nil {
		t.Fatal(err)
	}
	if res.dir != filepath.Join(runs, "run-1") {
		t.Fatalf("dir = %q", res.dir)
	}
	rec, err := readRunRecord(res.dir)
	if err != nil || rec.RunID != "run-1" || rec.PID <= 0 || rec.StartTime == "" {
		t.Fatalf("run record = %+v, %v", rec, err)
	}
}

// The router removes the run directory once it reported the run.
func TestTheRouterRemovesTheRunDirectoryOfAFinishedRun(t *testing.T) {
	workerClaude(t, "echo 'STAGE RESULT: ok'\n")
	h := newHarness(t)
	h.router.worker = defaultWorkerOps()

	h.send(cardMoved(87))

	runs, err := config.RunsDir()
	if err != nil {
		t.Fatal(err)
	}
	left, err := os.ReadDir(runs)
	if err != nil || len(left) != 0 {
		t.Fatalf("runs dir holds %v, %v; want it empty", left, err)
	}
	if line := h.only(t, "worker_finished"); num(t, line, "exit") != 0 {
		t.Fatalf("worker_finished = %v", line)
	}
}

// The start time of a process stays the same while it runs, and a process that
// exited unreaped reads as a zombie, so an adopter never waits on it forever.
func TestProcessInfoTellsAZombieApart(t *testing.T) {
	cmd := exec.Command("/bin/sh", "-c", "read x")
	stdin, err := cmd.StdinPipe()
	if err != nil {
		t.Fatal(err)
	}
	if err := cmd.Start(); err != nil {
		t.Fatal(err)
	}
	pid := cmd.Process.Pid
	first, err := processInfo(pid)
	if err != nil || first.start == "" || first.zombie {
		t.Fatalf("processInfo = %+v, %v", first, err)
	}
	if again := processStart(pid); again != first.start {
		t.Fatalf("start time moved from %q to %q", first.start, again)
	}
	if !processAlive(pid, first.start) || processAlive(pid, first.start+"0") {
		t.Fatal("processAlive does not compare the start time")
	}

	stdin.Close()
	deadline := time.Now().Add(5 * time.Second)
	for processAlive(pid, first.start) {
		if time.Now().After(deadline) {
			t.Fatal("the exited shell still reads as alive")
		}
		time.Sleep(10 * time.Millisecond)
	}
	if info, err := processInfo(pid); err != nil || !info.zombie {
		t.Fatalf("processInfo of the unreaped shell = %+v, %v; want a zombie", info, err)
	}
	_ = cmd.Wait()
	if processAlive(pid, "") {
		t.Fatal("a reaped pid reads as alive")
	}
}

// The prompt reaches claude as one argv element, so no shell reads it.
func TestRunWorkerPassesThePromptUnchanged(t *testing.T) {
	out := filepath.Join(t.TempDir(), "args")
	t.Setenv("ARGS_FILE", out)
	workerClaude(t, "for a in \"$@\"; do printf '%s\\n' \"$a\"; done > \"$ARGS_FILE\"\n")

	prompt := "-x \"double\" 'single' $HOME `id` $(id) ; exit 9"
	spec := workerSpec{dir: t.TempDir(), sessionID: testSession, prompt: prompt}
	if res := runWorker(context.Background(), spec, nil); res.err != nil || res.exitCode != 0 {
		t.Fatalf("runWorker = %+v", res)
	}
	b, err := os.ReadFile(out)
	if err != nil {
		t.Fatal(err)
	}
	got := strings.Split(strings.TrimSuffix(string(b), "\n"), "\n")
	if want := workerArgs(spec); !slices.Equal(got, want) {
		t.Fatalf("claude got %q, want %q", got, want)
	}
}

// A shell that ends without an exit status, and that the bridge did not kill,
// reads as a failure with a reason.
func TestRunWorkerReportsAMissingExitStatus(t *testing.T) {
	workerClaude(t, "echo 'STAGE RESULT: ok'\nkill -9 $PPID\n")

	res := runWorker(context.Background(), workerSpec{dir: t.TempDir(), sessionID: testSession, prompt: "go"}, nil)
	if res.err != nil || res.killed || res.exitCode == 0 {
		t.Fatalf("runWorker = %+v", res)
	}
	if !strings.Contains(res.output, "no exit status") {
		t.Fatalf("output = %q, want the missing status named", res.output)
	}
}

// A claude the bridge cannot find never starts, which the server keeps apart
// from a run that failed.
func TestRunWorkerWithNoClaudeNeverStarts(t *testing.T) {
	shortConfigHome(t)
	t.Setenv("PATH", t.TempDir())

	started := false
	res := runWorker(context.Background(), workerSpec{dir: t.TempDir(), sessionID: testSession, prompt: "go"}, func(workerProc) { started = true })
	if res.err == nil || started {
		t.Fatalf("runWorker = %+v, started = %v", res, started)
	}
}

// The run record round-trips, so a later bridge can read what this one wrote.
func TestRunRecordRoundTrips(t *testing.T) {
	dir := t.TempDir()
	want := runRecord{PID: 42, StartedAt: time.Now().UTC().Truncate(time.Second), RunID: "r", Dir: "/w", PermissionMode: "plan", Model: "opus", SessionID: testSession, Resume: true, Prompt: "go"}
	if err := writeRunRecord(dir, want); err != nil {
		t.Fatal(err)
	}
	got, err := readRunRecord(dir)
	if err != nil || got != want {
		t.Fatalf("readRunRecord = %+v, %v; want %+v", got, err, want)
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
