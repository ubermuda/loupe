package cmd

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
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

// TestBridgeRunRejectsAnUnusableDir keeps the fault at the start of the run.
// The bridge otherwise subscribes, looks healthy, and fails on the first card.
func TestBridgeRunRejectsAnUnusableDir(t *testing.T) {
	file := filepath.Join(t.TempDir(), "notadir")
	if err := os.WriteFile(file, []byte("x"), 0o600); err != nil {
		t.Fatal(err)
	}

	for _, dir := range []string{filepath.Join(t.TempDir(), "missing"), file} {
		err := runBridge(t, "--dir", dir)
		if err == nil || !strings.Contains(err.Error(), "--dir "+dir) {
			t.Fatalf("dir %q: err = %v", dir, err)
		}
	}
}

// TestBridgeRunFailsFastWithoutClaude keeps the missing-binary error at the
// start of the run too. Reaching it also proves a real --dir passes its check.
func TestBridgeRunFailsFastWithoutClaude(t *testing.T) {
	original := lookPath
	lookPath = func(string) (string, error) { return "", errors.New("not found") }
	t.Cleanup(func() { lookPath = original })

	err := runBridge(t, "--dir", t.TempDir())
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

// One worker at a time surprises a person who drags several cards, and no bound
// starts twenty agents by accident.
func TestBridgeRunBoundsWorkersByDefault(t *testing.T) {
	flag := newBridgeRunCmd().Flags().Lookup("max-workers")
	if flag == nil {
		t.Fatal("--max-workers is not registered")
	}
	if flag.DefValue != "3" {
		t.Fatalf("--max-workers defaults to %q, want 3", flag.DefValue)
	}
}

// A bound below 1 runs nothing and looks healthy, so it fails at startup beside
// the --dir check rather than on the first card.
func TestBridgeRunRejectsABoundBelowOne(t *testing.T) {
	for _, bound := range []string{"0", "-1"} {
		err := runBridge(t, "--dir", t.TempDir(), "--max-workers", bound)
		if err == nil || !strings.Contains(err.Error(), "--max-workers must be at least 1") {
			t.Fatalf("--max-workers %s: err = %v", bound, err)
		}
	}
}

func TestBridgeRunTakesALogFilePath(t *testing.T) {
	flag := newBridgeRunCmd().Flags().Lookup("log-file")
	if flag == nil {
		t.Fatal("--log-file is not registered")
	}
	if flag.DefValue != "" {
		t.Fatalf("--log-file defaults to %q, want empty", flag.DefValue)
	}
}

func TestDefaultLogPathSitsUnderTheConfigDir(t *testing.T) {
	got := defaultLogPath()
	if filepath.Base(got) != "bridge.log" || filepath.Base(filepath.Dir(got)) != "loupe" {
		t.Fatalf("defaultLogPath() = %q", got)
	}
}

// A supervisor's log is a history. Truncating it per run loses the record of
// every worker the previous run reported.
func TestOpenLogFileAppends(t *testing.T) {
	path := filepath.Join(t.TempDir(), "nested", "bridge.log")

	for _, line := range []string{"first\n", "second\n"} {
		f, err := openLogFile(path)
		if err != nil {
			t.Fatal(err)
		}
		if _, err := f.WriteString(line); err != nil {
			t.Fatal(err)
		}
		f.Close()
	}

	got, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if string(got) != "first\nsecond\n" {
		t.Fatalf("log = %q, want both runs", got)
	}
}

// stdout carries the JSON log and nothing else, so the site picker prompts on
// stderr. A reader piped to jq would otherwise get prose first.
func TestTheSitePickerPromptsOnStderr(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprint(w, `{"sites":[{"id":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7","name":"Loupe"}]}`)
	}))
	t.Cleanup(server.Close)

	cmd := newBridgeRunCmd()
	cmd.SetContext(context.Background())
	var stdout, stderr bytes.Buffer
	cmd.SetOut(&stdout)
	cmd.SetErr(&stderr)

	id, err := pickSite(cmd, api.New(server.URL, "token", server.Client()))
	if err != nil {
		t.Fatal(err)
	}
	if id != "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7" {
		t.Fatalf("id = %q", id)
	}
	if stdout.Len() != 0 {
		t.Fatalf("the picker wrote prose to stdout: %q", stdout.String())
	}
	if !strings.Contains(stderr.String(), "Using your only site") {
		t.Fatalf("stderr = %q", stderr.String())
	}
}

// brokenPipe stands in for a stdout whose reader has left, such as a `jq` the
// operator stopped.
type brokenPipe struct{}

func (brokenPipe) Write([]byte) (int, error) { return 0, errors.New("broken pipe") }

// A reader that leaves must not take the history with it. io.MultiWriter stops
// at the first writer that fails, so the file has to come first.
func TestTheLogFileOutlivesAFailedStdout(t *testing.T) {
	var file bytes.Buffer

	newBridgeLogger(bridgeLogWriter(&file, brokenPipe{})).Info("worker_queued", "card", 87)

	if file.Len() == 0 {
		t.Fatal("a failed stdout took the log file with it")
	}
}

// The log reaches stdout and the file alike, so a terminal and a history never
// disagree about what happened.
func TestTheBridgeLoggerWritesJSONToEveryWriter(t *testing.T) {
	var stdout, file bytes.Buffer

	newBridgeLogger(bridgeLogWriter(&file, &stdout)).Info("worker_queued", "card", 87)

	if stdout.String() != file.String() {
		t.Fatalf("stdout = %q, file = %q", stdout.String(), file.String())
	}
	var line map[string]any
	if err := json.Unmarshal(stdout.Bytes(), &line); err != nil {
		t.Fatalf("line %q is not JSON: %v", stdout.String(), err)
	}
	if line["event"] != "worker_queued" || line["card"] != float64(87) {
		t.Fatalf("line = %v", line)
	}
}
