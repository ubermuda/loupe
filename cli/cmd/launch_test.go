package cmd

import (
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"
)

// stubClaude writes a claude that records its directory and its arguments,
// one per NUL, to out.
func stubClaude(t *testing.T, out string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "claude")
	body := "#!/bin/sh\npwd > " + shellQuote(out+".pwd") + "\nprintf '%s\\0' \"$@\" > " + shellQuote(out) + "\n"
	if err := os.WriteFile(path, []byte(body), 0o700); err != nil {
		t.Fatal(err)
	}

	return path
}

// runScript writes the launch script of spec, runs it through /bin/sh, and
// returns the arguments the stub claude read.
func runScript(t *testing.T, spec workerSpec) []string {
	t.Helper()
	out := filepath.Join(t.TempDir(), "args")
	path, err := writeLaunchScript(t.TempDir(), spec.sessionID, launchScript(stubClaude(t, out), spec))
	if err != nil {
		t.Fatal(err)
	}
	if b, err := exec.Command("/bin/sh", path).CombinedOutput(); err != nil {
		t.Fatalf("script: %v: %s", err, b)
	}
	if _, err := os.Stat(path); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("the script did not delete itself: %v", err)
	}
	pwd, err := os.ReadFile(out + ".pwd")
	if err != nil {
		t.Fatal(err)
	}
	want, _ := filepath.EvalSymlinks(spec.dir)
	if got, _ := filepath.EvalSymlinks(strings.TrimSpace(string(pwd))); got != want {
		t.Fatalf("pwd = %q, want %q", got, want)
	}
	raw, err := os.ReadFile(out)
	if err != nil {
		t.Fatal(err)
	}

	return strings.Split(strings.TrimSuffix(string(raw), "\x00"), "\x00")
}

// hard is every character a shell reads as syntax, in single and double
// quotes and bare.
const hard = "it's \"quoted\" $HOME `id` $(id) \\n \\ back\nnew line\n\n%s %d 100% * ? ~ ; & | < > # ! {a,b} [x] '' '\\''"

func TestTheLaunchScriptPassesEachValueAsItIs(t *testing.T) {
	dir := filepath.Join(t.TempDir(), "it's a \"dir\" $x")
	if err := os.Mkdir(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	spec := workerSpec{dir: dir, sessionID: "0199a0e2-0000-4000-8000-000000000001", model: "op'us $x", permissionMode: "accept`Edits`", prompt: "Design card 87.\n" + hard}

	got := runScript(t, spec)
	want := []string{"--session-id", spec.sessionID, "--model", spec.model, "--permission-mode", spec.permissionMode, "--", spec.prompt}
	if !slices.Equal(got, want) {
		t.Fatalf("args = %q, want %q", got, want)
	}
}

func TestTheLaunchScriptLeavesOutAnUnsetModelAndMode(t *testing.T) {
	spec := workerSpec{dir: t.TempDir(), sessionID: "s1", prompt: "-starts with a dash"}

	got := runScript(t, spec)
	if want := []string{"--session-id", "s1", "--", "-starts with a dash"}; !slices.Equal(got, want) {
		t.Fatalf("args = %q, want %q", got, want)
	}
}

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
			if got := runLauncher(tc.argv, 10*time.Second); got != tc.want {
				t.Fatalf("runLauncher = %q, want %q", got, tc.want)
			}
		})
	}
}

// A launcher that outlives its timeout opened a terminal, and the bridge never
// kills it.
func TestALauncherPastItsTimeoutLaunchedAndRunsOn(t *testing.T) {
	proof := filepath.Join(t.TempDir(), "alive")
	if got := runLauncher([]string{"sh", "-c", `sleep 0.3; echo ok > "$1"`, "sh", proof}, 50*time.Millisecond); got != "" {
		t.Fatalf("runLauncher = %q", got)
	}
	waitForFile(t, proof)
}

// A launcher that leaves a child holding its output still reports its exit.
func TestALauncherWhoseChildKeepsItsOutputReportsItsExit(t *testing.T) {
	old := launchWaitDelay
	t.Cleanup(func() { launchWaitDelay = old })
	launchWaitDelay = 50 * time.Millisecond

	if got := runLauncher([]string{"sh", "-c", "sleep 2 & exit 3"}, time.Second); got != "exit code 3" {
		t.Fatalf("runLauncher = %q", got)
	}
	if got := runLauncher([]string{"sh", "-c", "sleep 2 & exit 0"}, time.Second); got != "" {
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
