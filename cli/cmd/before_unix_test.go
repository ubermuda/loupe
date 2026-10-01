//go:build unix

package cmd

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"
)

// before runs argv as a before command in a new project directory, with a
// short config home, and returns the result and the project directory.
func before(t *testing.T, ctx context.Context, argv ...string) (beforeResult, string) {
	t.Helper()
	shortConfigHome(t)
	project := t.TempDir()
	started := false
	res := runBefore(ctx, beforeSpec{argv: argv, dir: project, runID: "run-1"}, func(proc workerProc) {
		started = proc.pid > 0 && proc.dir != ""
	})
	if res.err == nil && !started {
		t.Fatal("onStart got no process")
	}

	return res, project
}

// The last non-empty line of stdout names the folder. The rest of stdout and
// stderr go to the output.
func TestRunBeforeReadsTheFolderFromTheLastLine(t *testing.T) {
	folder := t.TempDir()
	res, _ := before(t, context.Background(), "sh", "-c", "echo cloning; echo warn >&2; echo \"$1\"; echo; echo '  '", "sh", folder)

	if res.failure() != "" || res.dir != folder {
		t.Fatalf("runBefore = %+v, failure %q", res, res.failure())
	}
	if !strings.Contains(res.output, "warn") || !strings.Contains(res.output, "cloning") || strings.Contains(res.output, folder) {
		t.Fatalf("output = %q", res.output)
	}
}

func TestRunBeforeKeepsTheProjectDirOnEmptyOutput(t *testing.T) {
	res, project := before(t, context.Background(), "true")
	if res.failure() != "" || res.dir != project {
		t.Fatalf("runBefore = %+v, want dir %s", res, project)
	}
}

// A relative folder resolves against the project dir, where the command runs.
func TestRunBeforeResolvesARelativeFolder(t *testing.T) {
	res, project := before(t, context.Background(), "sh", "-c", "mkdir -p trees/a && echo trees/a")
	if res.failure() != "" || res.dir != filepath.Join(project, "trees/a") {
		t.Fatalf("runBefore = %+v, failure %q", res, res.failure())
	}
}

func TestRunBeforeRefusesAFolderThatIsNoDirectory(t *testing.T) {
	res, _ := before(t, context.Background(), "sh", "-c", "touch plain && echo plain")
	if want := `the before command printed "plain", which is not a directory`; !strings.HasPrefix(res.failure(), want) {
		t.Fatalf("failure = %q, want %q", res.failure(), want)
	}
	res, _ = before(t, context.Background(), "echo", "/no/such/folder")
	if !strings.Contains(res.failure(), "/no/such/folder") {
		t.Fatalf("failure = %q", res.failure())
	}
}

// A failed command keeps its exit code, and all of stdout goes to the output.
func TestRunBeforeKeepsTheExitCode(t *testing.T) {
	res, _ := before(t, context.Background(), "sh", "-c", "echo npm ci failed; echo /tmp; exit 3")
	if res.exitCode != 3 || res.killed || res.failure() != "the before command exited with code 3" {
		t.Fatalf("runBefore = %+v, failure %q", res, res.failure())
	}
	if !strings.Contains(res.output, "npm ci failed") || !strings.Contains(res.output, "/tmp") {
		t.Fatalf("output = %q", res.output)
	}
}

// A long output keeps its end, after one truncation marker, because a failed
// command prints why at the end.
func TestRunBeforeKeepsTheEndOfALongOutput(t *testing.T) {
	for name, script := range map[string]string{
		"stdout": "head -c 9000 /dev/zero | tr '\\0' x; echo; echo the real error; exit 1",
		"stderr": "head -c 9000 /dev/zero | tr '\\0' x >&2; echo the real error >&2; exit 1",
	} {
		t.Run(name, func(t *testing.T) {
			res, _ := before(t, context.Background(), "sh", "-c", script)
			if !strings.HasPrefix(res.output, truncatedMark) || !strings.HasSuffix(res.output, "the real error") || len(res.output) > maxOutput {
				t.Fatalf("output = %d bytes, %q…%q", len(res.output), res.output[:min(40, len(res.output))], res.output[max(0, len(res.output)-40):])
			}
			if n := strings.Count(res.output, "truncated"); n != 1 {
				t.Fatalf("output holds %d truncation markers", n)
			}
		})
	}
}

// No shell parses an argument, so a value with spaces and quotes stays one.
func TestRunBeforePassesEachArgumentAsIs(t *testing.T) {
	res, _ := before(t, context.Background(), "sh", "-c", "[ \"$#\" = 1 ] && [ \"$1\" = 'a b \"c\" $HOME' ] || exit 9", "sh", `a b "c" $HOME`)
	if res.exitCode != 0 {
		t.Fatalf("runBefore = %+v", res)
	}
}

// A program that does not exist fails with the shell's exit code and message.
func TestRunBeforeFailsOnAMissingProgram(t *testing.T) {
	res, _ := before(t, context.Background(), "no-such-program-for-loupe")
	if res.err != nil || res.exitCode != 127 || !strings.Contains(res.output, "no-such-program-for-loupe") {
		t.Fatalf("runBefore = %+v", res)
	}
}

// The timeout kills the whole process group, so a child the command started
// dies too.
func TestRunBeforeTimeoutKillsTheGroup(t *testing.T) {
	pidFile := filepath.Join(t.TempDir(), "child")
	ctx, cancel := context.WithTimeout(context.Background(), 300*time.Millisecond)
	defer cancel()
	began := time.Now()
	res, _ := before(t, ctx, "sh", "-c", "sleep 30 & echo $! > \"$1\"; wait", "sh", pidFile)

	if !res.timedOut || !res.killed || res.exitCode != -1 || time.Since(began) > 10*time.Second {
		t.Fatalf("runBefore = %+v", res)
	}
	if !strings.HasPrefix(res.failure(), "the before command ran past its timeout") {
		t.Fatalf("failure = %q", res.failure())
	}
	b, err := os.ReadFile(pidFile)
	if err != nil {
		t.Fatal(err)
	}
	eventually(t, "the child to die", func() bool { return !processAlive(atoi(t, strings.TrimSpace(string(b))), "") })
}

// A cancel of the bridge's context is a kill and no timeout.
func TestRunBeforeCancelIsAKill(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	time.AfterFunc(200*time.Millisecond, cancel)
	res, _ := before(t, ctx, "sleep", "30")
	if res.timedOut || !res.killed || !strings.Contains(res.failure(), "stopped the before command") {
		t.Fatalf("runBefore = %+v, failure %q", res, res.failure())
	}
}

// The files stay in the run directory under their own names, so an older
// image that reads run.json never takes the command for a worker.
func TestRunBeforeWritesItsOwnFiles(t *testing.T) {
	res, project := before(t, context.Background(), "echo", "hi")
	for _, name := range []string{"before.json", "before.stdout", "before.stderr", "before.exit"} {
		if _, err := os.Stat(filepath.Join(res.runDir, name)); err != nil {
			t.Fatalf("%s: %v", name, err)
		}
	}
	if _, err := os.Stat(filepath.Join(res.runDir, "run.json")); !os.IsNotExist(err) {
		t.Fatalf("run.json: %v, want none", err)
	}
	rec, err := readBeforeRecord(res.runDir)
	if err != nil || rec.PID <= 0 || rec.Dir != project || len(rec.Argv) != 2 || rec.StartedAt.IsZero() {
		t.Fatalf("record = %+v, %v", rec, err)
	}
}

// An adopter reads a before command that ended as the starter would.
func TestAdoptBeforeReadsAnEndedCommand(t *testing.T) {
	folder := t.TempDir()
	res, _ := before(t, context.Background(), "sh", "-c", "echo note >&2; echo \"$1\"", "sh", folder)

	got := adoptBeforeProc(context.Background(), res.runDir)
	if got.failure() != "" || got.dir != folder || got.output != res.output || got.runDir != res.runDir {
		t.Fatalf("adoptBeforeProc = %+v, want %+v", got, res)
	}
}

func TestAdoptBeforeWithNoRecordFails(t *testing.T) {
	got := adoptBeforeProc(context.Background(), t.TempDir())
	if !strings.HasPrefix(got.failure(), "the bridge lost the before command across a handover") {
		t.Fatalf("failure = %q", got.failure())
	}
}

// An adopter keeps the deadline the starter set, and kills the group then.
func TestAdoptBeforeKeepsTheDeadline(t *testing.T) {
	cmd := exec.Command("sleep", "30")
	newProcessGroup(cmd)
	if err := cmd.Start(); err != nil {
		t.Fatal(err)
	}
	dir := t.TempDir()
	rec := beforeRecord{PID: cmd.Process.Pid, StartedAt: time.Now(), StartTime: processStart(cmd.Process.Pid), Dir: t.TempDir(), Deadline: time.Now().Add(300 * time.Millisecond)}
	if err := writeBeforeRecord(dir, rec); err != nil {
		t.Fatal(err)
	}

	got := adoptBeforeProc(context.Background(), dir)
	if !got.timedOut || !got.killed || got.exitCode != -1 {
		t.Fatalf("adoptBeforeProc = %+v, want a timeout", got)
	}
}

func atoi(t *testing.T, s string) int {
	t.Helper()
	n, err := strconv.Atoi(s)
	if err != nil {
		t.Fatal(err)
	}

	return n
}
