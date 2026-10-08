package cmd

import (
	"os"
	"path/filepath"
	"reflect"
	"regexp"
	"slices"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/config"
	harn "github.com/ubermuda/loupe/cli/internal/harness"
	"github.com/ubermuda/loupe/cli/internal/transcript"
)

const (
	ceilingEnv = "CLAUDE_CODE_PRINT_BG_WAIT_CEILING_MS"
	ceilingVar = ceilingEnv + "="
	sessionVar = "LOUPE_SESSION_ID="
)

// The bridge names the session of each worker, so the server can name the run
// that moves a card. An inherited value names another session. The harness
// then adds what it needs.
func TestWorkerEnvReplacesAnInheritedSession(t *testing.T) {
	base := []string{sessionVar + "outer", "HOME=/home/a", sessionVar + "again"}

	got := workerSpec{sessionID: "s1"}.harnessCommand(workerEnv(base, "s1", nil)).Env
	if !slices.Equal(got, []string{"HOME=/home/a", sessionVar + "s1", ceilingVar + "0"}) {
		t.Fatalf("workerEnv = %q", got)
	}
	if !slices.Equal(base, []string{sessionVar + "outer", "HOME=/home/a", sessionVar + "again"}) {
		t.Fatalf("workerEnv changed the caller's array: %q", base)
	}
}

// The helper prints the agent's login and the token from GH_TOKEN, and only
// for a get.
const agentHelper = `!f() { test "$1" = get && echo username=loupe-bot && echo "password=$GH_TOKEN"; }; f`

func TestWorkerEnvPushesAsTheAgentAccount(t *testing.T) {
	base := []string{
		"HOME=/home/a", "GH_TOKEN=owner", "GITHUB_TOKEN=owner", "GIT_AUTHOR_NAME=Owner",
		"GIT_AUTHOR_EMAIL=owner@example.test", "GIT_COMMITTER_NAME=Owner", "GIT_COMMITTER_EMAIL=owner@example.test",
	}
	account := &config.AgentAccount{Token: "ghp_agent", Login: "loupe-bot", ID: 4242}

	got := workerEnv(base, "s1", account)
	want := []string{
		"HOME=/home/a", sessionVar + "s1",
		"GH_TOKEN=ghp_agent",
		"GIT_AUTHOR_NAME=loupe-bot", "GIT_COMMITTER_NAME=loupe-bot",
		"GIT_AUTHOR_EMAIL=4242+loupe-bot@users.noreply.github.com",
		"GIT_COMMITTER_EMAIL=4242+loupe-bot@users.noreply.github.com",
		"GIT_CONFIG_KEY_0=credential.https://github.com.helper", "GIT_CONFIG_VALUE_0=",
		"GIT_CONFIG_KEY_1=credential.https://github.com.helper", "GIT_CONFIG_VALUE_1=" + agentHelper,
		"GIT_CONFIG_KEY_2=url.https://github.com/.insteadOf", "GIT_CONFIG_VALUE_2=git@github.com:",
		"GIT_CONFIG_KEY_3=url.https://github.com/.insteadOf", "GIT_CONFIG_VALUE_3=ssh://git@github.com/",
		"GIT_CONFIG_COUNT=4",
	}
	if !slices.Equal(got, want) {
		t.Fatalf("workerEnv =\n%q\nwant\n%q", got, want)
	}
}

// An operator's own GIT_CONFIG entries stay, and the agent's follow them.
func TestWorkerEnvKeepsTheInheritedGitConfig(t *testing.T) {
	base := []string{"GIT_CONFIG_COUNT=1", "GIT_CONFIG_KEY_0=core.editor", "GIT_CONFIG_VALUE_0=vi"}
	account := &config.AgentAccount{Token: "ghp_agent", Login: "loupe-bot", ID: 4242}

	got := workerEnv(base, "s1", account)
	for _, e := range []string{
		"GIT_CONFIG_KEY_0=core.editor", "GIT_CONFIG_VALUE_0=vi",
		"GIT_CONFIG_KEY_1=credential.https://github.com.helper", "GIT_CONFIG_VALUE_1=",
		"GIT_CONFIG_KEY_2=credential.https://github.com.helper", "GIT_CONFIG_VALUE_2=" + agentHelper,
		"GIT_CONFIG_KEY_3=url.https://github.com/.insteadOf", "GIT_CONFIG_VALUE_3=git@github.com:",
		"GIT_CONFIG_KEY_4=url.https://github.com/.insteadOf", "GIT_CONFIG_VALUE_4=ssh://git@github.com/",
		"GIT_CONFIG_COUNT=5",
	} {
		if !slices.Contains(got, e) {
			t.Fatalf("workerEnv = %q, want %q in it", got, e)
		}
	}
	counts := 0
	for _, e := range got {
		if strings.HasPrefix(e, "GIT_CONFIG_COUNT=") {
			counts++
		}
	}
	if counts != 1 {
		t.Fatalf("workerEnv = %q, want one GIT_CONFIG_COUNT", got)
	}
}

// The result lines claude -p --output-format stream-json --json-schema prints.
// A run that --max-turns cuts off exits 1 with is_error and a null
// structured_output.
func TestDecodeWorkerOutput(t *testing.T) {
	long := strings.Repeat("x", maxOutput+10)
	for name, tc := range map[string]struct {
		stdout   string
		overflow bool
		stderr   string
		want     workerResult
	}{
		"finished": {
			stdout: `{"type":"result","subtype":"success","is_error":false,"result":"Done.","structured_output":{"status":"finished","summary":"Wrote the plan."}}`,
			want:   workerResult{hasResult: true, status: "finished", output: "Wrote the plan.", fields: map[string]any{}},
		},
		"unfinished": {
			stdout: `{"type":"result","is_error":false,"result":"","structured_output":{"status":"unfinished","summary":"Tests still run."}}`,
			want:   workerResult{hasResult: true, status: "unfinished", output: "Tests still run.", fields: map[string]any{}},
		},
		"blocked": {
			stdout: `{"type":"result","is_error":false,"result":"","structured_output":{"status":"blocked","summary":"Asked the owner."}}` + "\n",
			want:   workerResult{hasResult: true, status: "blocked", output: "Asked the owner.", fields: map[string]any{}},
		},
		"extras": {
			stdout: `{"type":"result","structured_output":{"status":"finished","summary":"Opened a PR.","prUrl":"https://x.test/1","card":87}}`,
			want:   workerResult{hasResult: true, status: "finished", output: "Opened a PR.", fields: map[string]any{"prUrl": "https://x.test/1", "card": float64(87)}},
		},
		"a reason": {
			stdout: `{"type":"result","structured_output":{"status":"blocked","summary":"Asked the owner.","reason":"needs-owner","prUrl":"https://x.test/1"}}`,
			want:   workerResult{hasResult: true, status: "blocked", reason: "needs-owner", output: "Asked the owner.", fields: map[string]any{"prUrl": "https://x.test/1"}},
		},
		"a reason that is no string": {
			stdout: `{"type":"result","structured_output":{"status":"finished","summary":"Done.","reason":3}}`,
			want:   workerResult{hasResult: true, status: "finished", output: "Done.", fields: map[string]any{}},
		},
		"a reason with no result": {
			stdout: `{"type":"result","result":"r","structured_output":{"status":"done","summary":"x","reason":"needs-owner"}}`,
			want:   workerResult{output: "r"},
		},
		"an empty summary falls back to the result": {
			stdout: `{"type":"result","result":"All done.","structured_output":{"status":"finished","summary":""}}`,
			want:   workerResult{hasResult: true, status: "finished", output: "All done.", fields: map[string]any{}},
		},
		"max turns": {
			stdout: `{"type":"result","subtype":"error_max_turns","is_error":true,"result":"","structured_output":null}`,
			stderr: "claude: reached the turn limit",
			want:   workerResult{output: "claude: reached the turn limit"},
		},
		"an unknown status": {
			stdout: `{"type":"result","result":"I did it.","structured_output":{"status":"done","summary":"x"}}`,
			want:   workerResult{output: "I did it."},
		},
		"a summary that is no string": {
			stdout: `{"type":"result","result":"r","structured_output":{"status":"finished","summary":3}}`,
			want:   workerResult{output: "r"},
		},
		"structured output that is no object": {
			stdout: `{"type":"result","result":"r","structured_output":"finished"}`,
			want:   workerResult{output: "r"},
		},
		"broken JSON": {
			stdout: `{"type":"result","structured_output":{"status":"finished","summary":"x"}`,
			stderr: "stream closed",
			want:   workerResult{output: "stream closed"},
		},
		"text after the document": {
			stdout: `{"type":"result","structured_output":{"status":"finished","summary":"x"}} STAGE RESULT: done`,
			want:   workerResult{},
		},
		"stream lines with no result stay out": {
			stdout: `{"type":"assistant","message":{"content":[{"type":"tool_use","id":"t1","name":"Bash","input":{"command":"echo secret"}}]}}` + "\nclaude: killed\n",
			want:   workerResult{output: "claude: killed"},
		},
		"a cut stream line stays out": {
			stdout:   `{"type":"assistant","message":{"content":[{"type":"tool_use","id":"t1","name":"Bash","input":{"command":"echo sec`,
			overflow: true,
			want:     workerResult{},
		},
		"undecoded stdout with no stderr is capped": {
			stdout:   long,
			overflow: true,
			want:     workerResult{output: long[:maxOutput] + "… (truncated)"},
		},
		"a decoded document with no text stays empty": {
			stdout: `{"type":"result","result":"","structured_output":null}`,
			want:   workerResult{},
		},
		"overflow": {
			stdout:   `{"structured_output":{"status":"finished","summary":"x"}}`,
			overflow: true,
			stderr:   "too much",
			want:     workerResult{output: "too much"},
		},
		"usage": {
			stdout: `{"type":"result","result":"r","total_cost_usd":0.5,"modelUsage":{"claude-opus-5-5":{"inputTokens":1,"outputTokens":2,"cacheReadInputTokens":3,"cacheCreationInputTokens":4,"costUSD":0.5}}}`,
			want: workerResult{output: "r", reported: transcript.Usage{
				"claude-opus-5-5": {InputTokens: 1, OutputTokens: 2, CacheReadTokens: 3, CacheWriteTokens: 4, CostUSD: ptr(0.5)},
			}},
		},
		"usage of nothing": {
			stdout: `{"type":"result","result":"r","modelUsage":{}}`,
			want:   workerResult{output: "r", reported: transcript.Usage{}},
		},
		"null usage": {
			stdout: `{"type":"result","result":"r","modelUsage":null}`,
			want:   workerResult{output: "r"},
		},
		"usage that is no object": {
			stdout: `{"type":"result","result":"r","modelUsage":[]}`,
			want:   workerResult{output: "r"},
		},
		"a long summary is capped": {
			stdout: `{"type":"result","structured_output":{"status":"finished","summary":"` + long + `"}}`,
			want:   workerResult{hasResult: true, status: "finished", output: long[:maxOutput] + "… (truncated)", fields: map[string]any{}},
		},
	} {
		t.Run(name, func(t *testing.T) {
			dir := t.TempDir()
			if err := os.WriteFile(filepath.Join(dir, "stdout"), []byte(tc.stdout), 0o600); err != nil {
				t.Fatal(err)
			}
			got := decodeWorkerOutput(defaultHarness().ReadRun(dir, harn.RunInfo{}), []byte(tc.stdout), tc.overflow, tc.stderr)
			if !reflect.DeepEqual(got, tc.want) {
				t.Fatalf("decodeWorkerOutput = %+v, want %+v", got, tc.want)
			}
		})
	}
}

// TestCapWriterBoundsWhatItKeeps pins the memory bound: a chatty worker must
// not be buffered whole just to report 4 KB of it.
func TestCapWriterBoundsWhatItKeeps(t *testing.T) {
	w := &capWriter{limit: 8}

	n, err := w.Write([]byte("0123456789"))
	if n != 10 || err != nil {
		t.Fatalf("Write = %d, %v; a short write would stop the worker", n, err)
	}
	if w.buf.Len() != 8 {
		t.Fatalf("kept %d bytes, want 8", w.buf.Len())
	}
	if !strings.HasPrefix(w.text(), "01234567") || !strings.HasSuffix(w.text(), "(truncated)") {
		t.Fatalf("text = %q", w.text())
	}
}

// A write that arrives after the cap is reached still reports its full length,
// so the worker keeps running rather than failing on a short write.
func TestCapWriterAcceptsWritesPastTheCap(t *testing.T) {
	w := &capWriter{limit: 4}
	_, _ = w.Write([]byte("abcd"))

	n, err := w.Write([]byte("efgh"))
	if n != 4 || err != nil {
		t.Fatalf("Write = %d, %v", n, err)
	}
	if w.buf.Len() != 4 {
		t.Fatalf("kept %d bytes, want 4", w.buf.Len())
	}
}

var v4UUID = regexp.MustCompile(`\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z`)

// claude refuses a session id that is not a uuid, and two workers must never
// share one, so the real generator is pinned apart from the fake in the tests.
func TestTheDefaultOpsGiveEachWorkerANewV4SessionID(t *testing.T) {
	ops := defaultWorkerOps()

	first, second := ops.sessionID(), ops.sessionID()
	for _, id := range []string{first, second} {
		if !v4UUID.MatchString(id) {
			t.Fatalf("session id %q is not a lower-case version 4 uuid", id)
		}
	}
	if first == second {
		t.Fatalf("two workers got the same session id %q", first)
	}
}

func TestCapWriterKeepsShortOutputWhole(t *testing.T) {
	w := &capWriter{limit: maxOutput}
	_, _ = w.Write([]byte("all done\n"))

	if got := w.text(); got != "all done" {
		t.Fatalf("text = %q", got)
	}
}

// A run record of an older image names no harness, so it reads as Claude Code.
func TestHarnessByName(t *testing.T) {
	for _, name := range []string{"", "claude-code"} {
		if h, err := harnessByName(name, "", ""); err != nil || h.Name() != "claude-code" {
			t.Fatalf("harnessByName(%q) = %v, %v", name, h, err)
		}
	}
	if _, err := harnessByName("other", "", ""); err == nil {
		t.Fatal("an unknown harness resolved")
	}
	if got := recordHarness(runRecord{Harness: "other"}).Name(); got != "claude-code" {
		t.Fatalf("recordHarness of an unknown name = %q", got)
	}
}

// A stdout that does not read names its error in the output once, though the
// capped read and the harness both fail on it.
func TestAnOutcomeWithNoStdoutNamesTheReadErrorOnce(t *testing.T) {
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "stderr"), nil, 0o600); err != nil {
		t.Fatal(err)
	}

	res := workerOutcome(dir, false, nil)

	if n := strings.Count(res.output, "read worker output"); n != 1 || res.streamed {
		t.Fatalf("output = %q, streamed = %v", res.output, res.streamed)
	}
}
