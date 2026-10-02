//go:build unix

package cmd

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// command runs argv as the command of a command rule in a new project
// directory, with a short config home.
func command(t *testing.T, ctx context.Context, argv ...string) procResult {
	t.Helper()
	shortConfigHome(t)

	return runRuleCommand(ctx, procSpec{argv: argv, dir: t.TempDir(), runID: "run-1"}, nil)
}

// The whole of stdout goes to the output, its last line too, after stderr. The
// files take the name of the command.
func TestRunRuleCommandKeepsAllOfItsOutput(t *testing.T) {
	res := command(t, context.Background(), "sh", "-c", "echo removed; echo warn >&2; echo /tmp")

	if res.failureOf(commandProc) != "" || res.dir != "" || res.output != "warn\nremoved\n/tmp" {
		t.Fatalf("runRuleCommand = %+v", res)
	}
	for _, name := range []string{"command.json", "command.stdout", "command.stderr", "command.exit"} {
		if _, err := os.Stat(filepath.Join(res.runDir, name)); err != nil {
			t.Fatalf("%s: %v", name, err)
		}
	}
	if got := adoptRuleCommand(context.Background(), res.runDir); got.output != res.output || got.exitCode != 0 {
		t.Fatalf("adoptRuleCommand = %+v, want %+v", got, res)
	}
}

func TestRunRuleCommandKeepsTheExitCode(t *testing.T) {
	res := command(t, context.Background(), "sh", "-c", "echo gone >&2; exit 4")
	if got := commandResult(res); got.exitCode != 4 || got.output != "the command exited with code 4\ngone" || !got.command {
		t.Fatalf("commandResult = %+v", got)
	}
}

// The timeout kills the group, and the run fails with exit code -1.
func TestRunRuleCommandTimeoutFailsTheRun(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 300*time.Millisecond)
	defer cancel()
	res := command(t, ctx, "sleep", "30")

	got := commandResult(res)
	if !res.timedOut || got.exitCode != -1 || got.killed || !strings.HasPrefix(got.output, "the command ran past its timeout") {
		t.Fatalf("commandResult = %+v", got)
	}
}

func TestAdoptRuleCommandWithNoRecordFails(t *testing.T) {
	got := commandResult(adoptRuleCommand(context.Background(), t.TempDir()))
	if got.exitCode != -1 || !strings.HasPrefix(got.output, "the bridge lost the command across a handover") {
		t.Fatalf("commandResult = %+v", got)
	}
}
