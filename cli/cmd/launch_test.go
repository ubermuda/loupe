package cmd

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestTheLaunchScriptIsPrivate(t *testing.T) {
	root := filepath.Join(t.TempDir(), "loupe-sessions")
	path, err := writeLaunchScript(root, "s1", "#!/bin/sh\n")
	if err != nil {
		t.Fatal(err)
	}
	if path != filepath.Join(root, "s1.sh") {
		t.Fatalf("path = %s", path)
	}
	for _, p := range []string{root, path} {
		info, err := os.Stat(p)
		if err != nil {
			t.Fatal(err)
		}
		if info.Mode().Perm() != 0o700 {
			t.Fatalf("%s mode = %v", p, info.Mode().Perm())
		}
	}
	if _, err := writeLaunchScript(root, "s1", "#!/bin/sh\n"); err == nil {
		t.Fatal("a second script of one session was written over the first")
	}
}

func TestTheLaunchScriptDirectoryIsMadePrivate(t *testing.T) {
	root := filepath.Join(t.TempDir(), "loupe-sessions")
	if err := os.Mkdir(root, 0o755); err != nil {
		t.Fatal(err)
	}
	if _, err := writeLaunchScript(root, "s1", "#!/bin/sh\n"); err != nil {
		t.Fatal(err)
	}
	if info, _ := os.Stat(root); info.Mode().Perm() != 0o700 {
		t.Fatalf("mode = %v", info.Mode().Perm())
	}
}

func TestStartDeletesTheLaunchScriptsOlderThanADay(t *testing.T) {
	root := t.TempDir()
	now := time.Now()
	for name, age := range map[string]time.Duration{"old.sh": 25 * time.Hour, "new.sh": time.Hour} {
		path := filepath.Join(root, name)
		if err := os.WriteFile(path, nil, 0o700); err != nil {
			t.Fatal(err)
		}
		if err := os.Chtimes(path, now.Add(-age), now.Add(-age)); err != nil {
			t.Fatal(err)
		}
	}

	log := &syncBuffer{}
	cleanLaunchScripts(root, now, newBridgeLogger(log))
	cleanLaunchScripts(filepath.Join(root, "missing"), now, newBridgeLogger(log))

	entries, _ := os.ReadDir(root)
	if len(entries) != 1 || entries[0].Name() != "new.sh" {
		t.Fatalf("entries = %v", entries)
	}
	if log.String() != "" {
		t.Fatalf("log = %s", log)
	}
}

func TestTheLauncherOutcome(t *testing.T) {
	for name, tc := range map[string]struct {
		argv []string
		want string
	}{
		"exit 0":      {[]string{"sh", "-c", "echo fine"}, ""},
		"exit 3":      {[]string{"sh", "-c", "echo boom >&2; exit 3"}, "exit code 3: boom"},
		"no output":   {[]string{"false"}, "exit code 1"},
		"long output": {[]string{"sh", "-c", "printf 'é%.0s' $(seq 2000); exit 2"}, "exit code 2: " + strings.Repeat("é", maxLaunchReason-len("exit code 2: "))},
		"no binary":   {[]string{"/nonexistent/launcher"}, "fork/exec /nonexistent/launcher: no such file or directory"},
	} {
		t.Run(name, func(t *testing.T) {
			if got := runLauncher(context.Background(), tc.argv, 10*time.Second, nil); got != tc.want {
				t.Fatalf("runLauncher = %q, want %q", got, tc.want)
			}
		})
	}
}

// A launcher that outlives its timeout opened a terminal, and the bridge never
// kills it.
func TestALauncherPastItsTimeoutLaunchedAndRunsOn(t *testing.T) {
	proof := filepath.Join(t.TempDir(), "alive")
	if got := runLauncher(context.Background(), []string{"sh", "-c", `sleep 0.3; echo ok > "$1"`, "sh", proof}, 50*time.Millisecond, nil); got != "" {
		t.Fatalf("runLauncher = %q", got)
	}
	waitForFile(t, proof)
}

// A launcher that leaves a child holding its output still reports its exit.
func TestALauncherWhoseChildKeepsItsOutputReportsItsExit(t *testing.T) {
	old := launchWaitDelay
	t.Cleanup(func() { launchWaitDelay = old })
	launchWaitDelay = 50 * time.Millisecond

	if got := runLauncher(context.Background(), []string{"sh", "-c", "sleep 2 & exit 3"}, time.Second, nil); got != "exit code 3" {
		t.Fatalf("runLauncher = %q", got)
	}
	if got := runLauncher(context.Background(), []string{"sh", "-c", "sleep 2 & exit 0"}, time.Second, nil); got != "" {
		t.Fatalf("runLauncher = %q", got)
	}
}

func waitForFile(t *testing.T, path string) {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for {
		if _, err := os.Stat(path); err == nil {
			return
		}
		if time.Now().After(deadline) {
			t.Fatalf("%s never appeared", path)
		}
		time.Sleep(10 * time.Millisecond)
	}
}

func TestResolveClaudeIsAbsolute(t *testing.T) {
	old := lookPath
	t.Cleanup(func() { lookPath = old })

	lookPath = func(string) (string, error) { return "bin/claude", nil }
	got, err := resolveClaude()
	if err != nil || !filepath.IsAbs(got) || !strings.HasSuffix(got, "/bin/claude") {
		t.Fatalf("resolveClaude = %q, %v", got, err)
	}

	lookPath = func(string) (string, error) { return "", exec.ErrNotFound }
	if _, err := resolveClaude(); err == nil || err.Error() != "claude is not installed or not on PATH" {
		t.Fatalf("err = %v", err)
	}
}
