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
	"slices"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// runBridge parses args and runs the command far enough to reach its start
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

// writeRules writes a rule file mapping each slug to a real directory.
func writeRules(t *testing.T, slugs ...string) string {
	t.Helper()
	var b strings.Builder
	b.WriteString("projects:\n")
	for _, slug := range slugs {
		b.WriteString("  " + slug + ":\n    dir: " + t.TempDir() + "\n")
	}
	b.WriteString("rules:\n  - on: board.card_moved\n    project: " + slugs[0] + "\n    to: next\n    prompt: go\n")

	path := filepath.Join(t.TempDir(), "rules.yaml")
	if err := os.WriteFile(path, []byte(b.String()), 0o600); err != nil {
		t.Fatal(err)
	}

	return path
}

// The rule file is mandatory. The error shows an example, so the operator can
// start from it.
func TestBridgeRunRefusesToStartWithoutARuleFile(t *testing.T) {
	missing := filepath.Join(t.TempDir(), "rules.yaml")

	err := runBridge(t, "--rules", missing)
	if !errors.Is(err, rules.ErrMissing) {
		t.Fatalf("err = %v", err)
	}
	if strings.Count(err.Error(), missing) != 1 || !strings.Contains(err.Error(), rules.Example) {
		t.Fatalf("the error must name the path once and show the example: %v", err)
	}
}

// A misspelt flag fails at start, before the rule file is read, and the error
// names the flag.
func TestBridgeRunRefusesAnInvalidDefault(t *testing.T) {
	for flag, want := range map[string]string{
		"--permission-mode=acceptedits": `--permission-mode "acceptedits" is not a permission mode claude accepts`,
		"--model=claude opus":           `--model "claude opus" holds whitespace`,
	} {
		err := runBridge(t, "--rules", writeRules(t, "loupe"), flag)
		if err == nil || !strings.Contains(err.Error(), want) || strings.Contains(err.Error(), "rule file") {
			t.Fatalf("%s: err = %v", flag, err)
		}
	}
}

// With no --rules, the file sits beside config.json.
func TestBridgeRunReadsRulesFromTheConfigDir(t *testing.T) {
	t.Setenv("XDG_CONFIG_HOME", t.TempDir())
	t.Setenv("HOME", t.TempDir())

	path, err := defaultRulesPath()
	if err != nil {
		t.Fatal(err)
	}
	if filepath.Base(path) != "rules.yaml" || filepath.Base(filepath.Dir(path)) != "loupe" {
		t.Fatalf("defaultRulesPath() = %q", path)
	}

	if err := runBridge(t); err == nil || !strings.Contains(err.Error(), path) {
		t.Fatalf("err = %v, want it to name %s", err, path)
	}
}

func TestBridgeRunRefusesAnInvalidRuleFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), "rules.yaml")
	if err := os.WriteFile(path, []byte("projects: {}\nrules: []\nsite: loupe\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	if err := runBridge(t, "--rules", path); err == nil || !strings.Contains(err.Error(), "field site not found") {
		t.Fatalf("err = %v", err)
	}
}

// One connection per bridge serves one project until the stream endpoint takes
// several, so a second project is refused rather than ignored.
func TestBridgeRunRefusesSeveralProjects(t *testing.T) {
	err := runBridge(t, "--rules", writeRules(t, "loupe", "other"))
	if err == nil || !strings.Contains(err.Error(), "maps 2 projects (loupe, other)") {
		t.Fatalf("err = %v", err)
	}
}

// The rule file key is a slug, and the stream is read by the id the columns
// answer returns, so a later slug change cannot break a reconnect.
func TestTheStreamIsReadByTheIDTheColumnsCheckResolved(t *testing.T) {
	const id = "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7"
	var paths []string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		paths = append(paths, r.URL.EscapedPath())
		switch r.URL.EscapedPath() {
		case "/api/projects/loupe/board/columns":
			fmt.Fprint(w, `{"project":{"id":"`+id+`","slug":"loupe"},"columns":[{"slug":"next"}]}`)
		case "/api/projects/" + id + "/stream":
			fmt.Fprint(w, `{"hubUrl":"https://hub.example/.well-known/mercure","topic":"t","jwt":"j"}`)
		default:
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	t.Cleanup(server.Close)
	cfg := config.Config{BaseURL: server.URL, Token: "t"}

	set, err := rules.Load(writeRules(t, "loupe"), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	if err := set.Check(context.Background(), apiClient(cfg)); err != nil {
		t.Fatal(err)
	}
	jwt, err := jwtRefresher(cfg, set.ProjectID("loupe"))(context.Background())
	if err != nil || jwt != "j" {
		t.Fatalf("jwt = %q, err = %v, paths = %v", jwt, err, paths)
	}
	want := []string{"/api/projects/loupe/board/columns", "/api/projects/" + id + "/stream"}
	if !slices.Equal(paths, want) {
		t.Fatalf("paths = %v, want %v", paths, want)
	}
}

// TestBridgeRunFailsFastWithoutClaude keeps the missing-binary error at the
// start of the run. Reaching it also proves a valid rule file passes its check.
func TestBridgeRunFailsFastWithoutClaude(t *testing.T) {
	original := lookPath
	lookPath = func(string) (string, error) { return "", errors.New("not found") }
	t.Cleanup(func() { lookPath = original })

	err := runBridge(t, "--rules", writeRules(t, "loupe"))
	if err == nil || !strings.Contains(err.Error(), "claude is not installed") {
		t.Fatalf("err = %v", err)
	}
}

// The projects map replaces --site and --dir, so a stale invocation fails
// instead of running with flags the binary ignores.
func TestBridgeRunHasNoSiteOrDirFlags(t *testing.T) {
	flags := newBridgeRunCmd().Flags()
	for _, name := range []string{"site", "dir", "session", "attach"} {
		if flags.Lookup(name) != nil {
			t.Fatalf("--%s is still registered", name)
		}
	}
	if err := runBridge(t, "--dir", t.TempDir()); err == nil || !strings.Contains(err.Error(), "unknown flag: --dir") {
		t.Fatalf("err = %v", err)
	}
}

// --permission-mode and --model are defaults for rules. Empty passes no flag,
// which keeps the operator opting in.
func TestBridgeRunDefaultFlagsAreEmpty(t *testing.T) {
	for _, name := range []string{"permission-mode", "model", "rules", "log-file"} {
		flag := newBridgeRunCmd().Flags().Lookup(name)
		if flag == nil {
			t.Fatalf("--%s is not registered", name)
		}
		if flag.DefValue != "" {
			t.Fatalf("--%s defaults to %q, want empty", name, flag.DefValue)
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

// A bound below 1 runs nothing and looks healthy, so it fails at startup.
func TestBridgeRunRejectsABoundBelowOne(t *testing.T) {
	for _, bound := range []string{"0", "-1"} {
		err := runBridge(t, "--rules", writeRules(t, "loupe"), "--max-workers", bound)
		if err == nil || !strings.Contains(err.Error(), "--max-workers must be at least 1") {
			t.Fatalf("--max-workers %s: err = %v", bound, err)
		}
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
