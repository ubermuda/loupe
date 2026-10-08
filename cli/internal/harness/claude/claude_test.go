package claude

import (
	"encoding/json"
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/harness"
	"github.com/ubermuda/loupe/cli/internal/transcript"
)

const (
	testSession = "0199a0e2-0000-4000-8000-000000000001"
	ceilingVar  = "CLAUDE_CODE_PRINT_BG_WAIT_CEILING_MS="
	sessionVar  = "LOUPE_SESSION_ID="
)

var _ harness.Harness = Harness{}

func ptr[T any](v T) *T { return &v }

func TestTheAdapterIsClaudeCode(t *testing.T) {
	if got := New().Name(); got != "claude-code" {
		t.Fatalf("Name = %q", got)
	}
	if got := New().Program(); got != "claude" {
		t.Fatalf("Program = %q", got)
	}
}

// claude -p ends a worker at its background wait ceiling and exits 0. The
// adapter lifts the ceiling unless the operator set it.
func TestWorkerEnvLiftsTheWaitCeiling(t *testing.T) {
	base := make([]string, 2, 4)
	base[0], base[1] = "HOME=/home/a", sessionVar+"s1"

	got := New().Worker(harness.Spec{SessionID: "s1", Env: base}).Env
	if !slices.Equal(got, []string{"HOME=/home/a", sessionVar + "s1", ceilingVar + "0"}) {
		t.Fatalf("Env = %q", got)
	}
	if extended := base[:4]; extended[2] != "" || extended[3] != "" {
		t.Fatalf("Worker wrote into the caller's array: %q", extended)
	}
	if got := New().Resume(harness.Spec{SessionID: "s1", Env: base}).Env; !slices.Equal(got, []string{"HOME=/home/a", sessionVar + "s1", ceilingVar + "0"}) {
		t.Fatalf("Resume Env = %q", got)
	}
}

func TestWorkerEnvKeepsTheOperatorsCeiling(t *testing.T) {
	for _, set := range []string{ceilingVar + "5000", ceilingVar + "0", ceilingVar} {
		base := []string{"HOME=/home/a", set, sessionVar + "s1"}
		want := []string{"HOME=/home/a", set, sessionVar + "s1"}
		if got := New().Worker(harness.Spec{SessionID: "s1", Env: base}).Env; !slices.Equal(got, want) {
			t.Fatalf("Env(%q) = %q", base, got)
		}
	}
}

// A rule's permission mode and model reach claude as flags, and an empty value
// passes none. The session id always follows -p, and the prompt is always the
// last argument.
func TestWorkerArgsCarryTheRulesSettings(t *testing.T) {
	for _, tc := range []struct {
		spec harness.Spec
		want string
	}{
		{harness.Spec{SessionID: testSession, Prompt: "go"}, "--verbose --output-format stream-json -p --session-id " + testSession + " -- go"},
		{harness.Spec{SessionID: testSession, PermissionMode: "plan", Prompt: "go"}, "--permission-mode plan --verbose --output-format stream-json -p --session-id " + testSession + " -- go"},
		{harness.Spec{SessionID: testSession, Model: "opus", Prompt: "go"}, "--model opus --verbose --output-format stream-json -p --session-id " + testSession + " -- go"},
		{harness.Spec{SessionID: testSession, Schema: `{"type":"object"}`, Prompt: "go"}, `--verbose --output-format stream-json --json-schema {"type":"object"} -p --session-id ` + testSession + " -- go"},
		{harness.Spec{SessionID: testSession, Effort: "xhigh", Prompt: "go"}, "--effort xhigh --verbose --output-format stream-json -p --session-id " + testSession + " -- go"},
		{harness.Spec{SessionID: testSession, PermissionMode: "plan", Model: "opus", Effort: "low", Schema: "{}", Prompt: "go"}, "--permission-mode plan --model opus --effort low --verbose --output-format stream-json --json-schema {} -p --session-id " + testSession + " -- go"},
	} {
		if got := strings.Join(New().Worker(tc.spec).Args, " "); got != tc.want {
			t.Fatalf("Worker(%+v) = %q, want %q", tc.spec, got, tc.want)
		}
	}
}

func TestResumeArgsContinueTheSession(t *testing.T) {
	spec := harness.Spec{SessionID: testSession, PermissionMode: "plan", Model: "opus", Schema: "{}", Prompt: "go"}
	want := "--permission-mode plan --model opus --verbose --output-format stream-json --json-schema {} -p --resume " + testSession + " -- go"
	if got := strings.Join(New().Resume(spec).Args, " "); got != want {
		t.Fatalf("Resume = %q, want %q", got, want)
	}
}

// claude reads `-p "- x"` as the unknown option "- x". After --, any prompt
// text is the prompt.
func TestAPromptThatLooksLikeAnOptionFollowsTheSeparator(t *testing.T) {
	for _, prompt := range []string{"- x", "--version", "-p"} {
		args := New().Worker(harness.Spec{Model: "opus", SessionID: testSession, Prompt: prompt}).Args
		if len(args) < 2 || args[len(args)-2] != "--" || args[len(args)-1] != prompt {
			t.Fatalf("Worker(%q) = %q, want the prompt right after --", prompt, args)
		}
	}
}

// The result lines claude -p --output-format stream-json prints. No line, or
// an undecodable one, holds no document.
func TestOutput(t *testing.T) {
	for name, tc := range map[string]struct {
		stdout string
		none   bool
		want   harness.Output
	}{
		"finished": {
			stdout: `{"is_error":false,"result":"Done.","structured_output":{"status":"finished"}}`,
			want:   harness.Output{Decoded: true, Result: "Done.", StructuredOutput: json.RawMessage(`{"status":"finished"}`)},
		},
		"null structured output": {
			stdout: `{"is_error":true,"result":"","structured_output":null}`,
			want:   harness.Output{Decoded: true, StructuredOutput: json.RawMessage(`null`)},
		},
		"broken JSON": {
			stdout: `{"result":"r"`,
		},
		"is_error that is no bool": {
			stdout: `{"result":"r","is_error":"yes","structured_output":{}}`,
			want:   harness.Output{Result: "r"},
		},
		"no result line": {
			none: true,
		},
		"usage": {
			stdout: `{"result":"r","total_cost_usd":0.5,"modelUsage":{"claude-opus-5-5":{"inputTokens":1,"outputTokens":2,"cacheReadInputTokens":3,"cacheCreationInputTokens":4,"costUSD":0.5}}}`,
			want: harness.Output{Decoded: true, Result: "r", Usage: transcript.Usage{
				"claude-opus-5-5": {InputTokens: 1, OutputTokens: 2, CacheReadTokens: 3, CacheWriteTokens: 4, CostUSD: ptr(0.5)},
			}},
		},
		"usage of nothing": {
			stdout: `{"result":"r","modelUsage":{}}`,
			want:   harness.Output{Decoded: true, Result: "r", Usage: transcript.Usage{}},
		},
		"null usage": {
			stdout: `{"result":"r","modelUsage":null}`,
			want:   harness.Output{Decoded: true, Result: "r"},
		},
		"usage that is no object": {
			stdout: `{"result":"r","modelUsage":[]}`,
			want:   harness.Output{Decoded: true, Result: "r"},
		},
	} {
		t.Run(name, func(t *testing.T) {
			var result []byte
			if !tc.none {
				result = []byte(tc.stdout)
			}
			if got := New().Output(result); !reflect.DeepEqual(got, tc.want) {
				t.Fatalf("Output = %+v, want %+v", got, tc.want)
			}
		})
	}
}

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
func runScript(t *testing.T, spec harness.Spec) []string {
	t.Helper()
	out := filepath.Join(t.TempDir(), "args")
	path := filepath.Join(t.TempDir(), spec.SessionID+".sh")
	if err := os.WriteFile(path, []byte(New().Interactive(stubClaude(t, out), spec)), 0o700); err != nil {
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
	want, _ := filepath.EvalSymlinks(spec.Dir)
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
	spec := harness.Spec{Dir: dir, SessionID: "0199a0e2-0000-4000-8000-000000000001", Model: "op'us $x", PermissionMode: "accept`Edits`", Prompt: "Design card 87.\n" + hard}

	got := runScript(t, spec)
	want := []string{"--session-id", spec.SessionID, "--model", spec.Model, "--permission-mode", spec.PermissionMode, "--", spec.Prompt}
	if !slices.Equal(got, want) {
		t.Fatalf("args = %q, want %q", got, want)
	}
}

func TestTheLaunchScriptPassesTheEffort(t *testing.T) {
	spec := harness.Spec{Dir: t.TempDir(), SessionID: "s1", Model: "opus", Effort: "high", Prompt: "go"}

	got := runScript(t, spec)
	want := []string{"--session-id", "s1", "--model", "opus", "--effort", "high", "--", "go"}
	if !slices.Equal(got, want) {
		t.Fatalf("args = %q, want %q", got, want)
	}
}

func TestTheLaunchScriptLeavesOutAnUnsetModelAndMode(t *testing.T) {
	spec := harness.Spec{Dir: t.TempDir(), SessionID: "s1", Prompt: "-starts with a dash"}

	got := runScript(t, spec)
	if want := []string{"--session-id", "s1", "--", "-starts with a dash"}; !slices.Equal(got, want) {
		t.Fatalf("args = %q, want %q", got, want)
	}
}

func TestTheLaunchScriptBody(t *testing.T) {
	spec := harness.Spec{Dir: "/w", SessionID: "s1", Model: "opus", PermissionMode: "plan", Prompt: "go"}
	want := "#!/bin/sh\nrm -f -- \"$0\"\ncd -- '/w' || exit 1\nexec '/bin/claude' --session-id 's1' --model 'opus' --permission-mode 'plan' -- 'go'\n"
	if got := New().Interactive("/bin/claude", spec); got != want {
		t.Fatalf("Interactive = %q, want %q", got, want)
	}
}

const (
	costState = `{"type":"cost-state","cwd":"/start","modelUsage":{"claude-opus-5-5":{"inputTokens":100,"outputTokens":10,"cacheReadInputTokens":1000,"cacheCreationInputTokens":50,"costUSD":1.25}}}`
	first     = `{"type":"assistant","timestamp":"2099-01-01T00:00:01Z","message":{"id":"m1","model":"claude-opus-5-5","usage":{"input_tokens":10,"output_tokens":1,"cache_read_input_tokens":0,"cache_creation_input_tokens":0}}}`
	later     = `{"type":"assistant","timestamp":"2099-01-01T00:00:03Z","message":{"id":"m2","model":"claude-opus-5-5","usage":{"input_tokens":5,"output_tokens":5,"cache_read_input_tokens":0,"cache_creation_input_tokens":0}}}`
)

// claudeHome points CLAUDE_CONFIG_DIR at a new directory, and writes the
// transcript lines of session there when lines are given.
func claudeHome(t *testing.T, session string, lines ...string) {
	t.Helper()
	dir := t.TempDir()
	t.Setenv("CLAUDE_CONFIG_DIR", dir)
	if len(lines) == 0 {
		return
	}
	path := filepath.Join(dir, "projects", "-work", session+".jsonl")
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(strings.Join(lines, "\n")+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
}

func TestTheSessionReadsTheTranscript(t *testing.T) {
	claudeHome(t, testSession, costState, first, later)
	h := New()

	if err := h.HasSession(testSession); err != nil {
		t.Fatalf("HasSession = %v", err)
	}
	if dir, err := h.StartDir(testSession); dir != "/start" || err != nil {
		t.Fatalf("StartDir = %q, %v", dir, err)
	}
	total, err := h.SessionTotal(testSession)
	if want := (transcript.Usage{"claude-opus-5-5": {InputTokens: 100, OutputTokens: 10, CacheReadTokens: 1000, CacheWriteTokens: 50, CostUSD: ptr(1.25)}}); err != nil || !reflect.DeepEqual(total, want) {
		t.Fatalf("SessionTotal = %v, %v", total, err)
	}
	from := time.Date(2099, 1, 1, 0, 0, 2, 0, time.UTC)
	if usage, err := h.SessionUsage(testSession, from, time.Time{}); err != nil || usage["claude-opus-5-5"].InputTokens != 5 {
		t.Fatalf("SessionUsage = %v, %v", usage, err)
	}
	if usage, err := h.SessionUsage(testSession, time.Time{}, from); err != nil || usage["claude-opus-5-5"].InputTokens != 10 {
		t.Fatalf("SessionUsage before %s = %v, %v", from, usage, err)
	}
}

func TestAMissingSessionHasNoTranscript(t *testing.T) {
	claudeHome(t, testSession)
	h := New()

	if err := h.HasSession(testSession); !errors.Is(err, transcript.ErrNotFound) {
		t.Fatalf("HasSession = %v", err)
	}
	if dir, err := h.StartDir(testSession); dir != "" || err != nil {
		t.Fatalf("StartDir = %q, %v", dir, err)
	}
	if _, err := h.SessionTotal(testSession); err == nil {
		t.Fatal("SessionTotal of a missing session read")
	}
	if _, err := h.SessionUsage(testSession, time.Time{}, time.Time{}); err == nil {
		t.Fatal("SessionUsage of a missing session read")
	}
}
