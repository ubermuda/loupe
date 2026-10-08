//go:build unix

package cmd

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
)

const codexThread = "01a11b44-0eb9-71f3-b3f1-3066d6b81ee6"

// codexFixtures is the path of the fixtures of the codex harness package.
const codexFixtures = "../internal/harness/codex/testdata"

// codexHome makes a Codex home folder with the fixture session and profile, and
// a fake codex on PATH. The fake logs its argv to args.log in the home, writes
// the last message the script names, and prints the JSONL of a finished turn
// with the usage given.
func codexHome(t *testing.T, usage string) string {
	t.Helper()
	shortConfigHome(t)
	home := filepath.Join(t.TempDir(), "codex-home")
	sessions := filepath.Join(home, "sessions", "2026", "10", "08")
	if err := os.MkdirAll(sessions, 0o700); err != nil {
		t.Fatal(err)
	}
	for from, to := range map[string]string{
		"session.jsonl":          filepath.Join(sessions, "rollout-2026-10-08T07-26-47-"+codexThread+".jsonl"),
		"openrouter.config.toml": filepath.Join(home, "openrouter.config.toml"),
	} {
		b, err := os.ReadFile(filepath.Join(codexFixtures, from))
		if err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(to, b, 0o600); err != nil {
			t.Fatal(err)
		}
	}

	bin := t.TempDir()
	script := `#!/bin/sh
printf '%s\n' "$@" > "$CODEX_HOME/args.log"
last=""
while [ $# -gt 0 ]; do
  if [ "$1" = "-o" ]; then last="$2"; fi
  shift
done
printf '%s\n' '{"status":"finished","summary":"all done"}' > "$last"
if [ -f "$CODEX_HOME/append" ]; then
  for f in "$CODEX_HOME"/sessions/2026/10/08/rollout-*.jsonl; do cat "$CODEX_HOME/append" >> "$f"; done
fi
echo '{"type":"thread.started","thread_id":"` + codexThread + `"}'
echo '{"type":"turn.started"}'
echo '{"type":"turn.completed","usage":` + usage + `}'
`
	if err := os.WriteFile(filepath.Join(bin, "codex"), []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}
	t.Setenv("PATH", bin+string(os.PathListSeparator)+os.Getenv("PATH"))

	return home
}

func codexSpec(home string) workerSpec {
	return workerSpec{
		dir: home, sessionID: testSession, prompt: "go", runID: "codex-run", harnessName: "codex", configDir: home,
		profile: "openrouter", model: "openrouter/free", permissionMode: "read-only", account: "cdx",
		schema: `{"type":"object"}`, env: []string{"OPENROUTER_API_KEY=not-a-real-key", "CODEX_HOME=" + home},
	}
}

func TestRunWorkerRunsCodexAndReadsItsFiles(t *testing.T) {
	home := codexHome(t, `{"input_tokens":1000,"cached_input_tokens":200,"cache_write_input_tokens":0,"output_tokens":50,"reasoning_output_tokens":10}`)

	res := runWorker(context.Background(), codexSpec(home), nil)

	if res.err != nil || res.exitCode != 0 || !res.hasResult || res.status != "finished" || res.output != "all done" {
		t.Fatalf("runWorker = %+v", res)
	}
	if !res.streamed || len(res.calls) != 0 {
		t.Fatalf("a Codex run reads the calls of its session file, and this one holds none: %+v", res)
	}
	model, ok := res.usage.Models["openrouter/free"]
	if res.usage.Source != api.UsageReported || !ok || model.InputTokens != 100062-4352 || model.CacheReadTokens != 4352 || model.OutputTokens != 128 || model.CostUSD != nil {
		t.Fatalf("usage = %+v", res.usage)
	}
	rec, err := readRunRecord(res.dir)
	if err != nil || rec.Harness != "codex" || rec.Profile != "openrouter" || rec.ConfigDir != home {
		t.Fatalf("run record = %+v, %v", rec, err)
	}
	schema, err := os.ReadFile(filepath.Join(res.dir, "schema.json"))
	if err != nil || string(schema) != `{"type":"object"}` {
		t.Fatalf("schema file = %q, %v", schema, err)
	}
	threads, _ := config.CodexThreadsDir()
	if b, err := os.ReadFile(filepath.Join(threads, testSession)); err != nil || strings.TrimSpace(string(b)) != codexThread {
		t.Fatalf("thread map = %q, %v", b, err)
	}
	args, _ := os.ReadFile(filepath.Join(home, "args.log"))
	for _, want := range []string{"exec\n-p\nopenrouter\n-m\nopenrouter/free\n-s\nread-only\n--json\n", "--output-schema\n", "--\ngo\n"} {
		if !strings.Contains(string(args), want) {
			t.Fatalf("args = %q, want %q", args, want)
		}
	}
}

// A resume continues the thread the first run mapped, and reports what the
// resume spent: the session total less the total before it.
func TestRunWorkerResumesTheCodexThread(t *testing.T) {
	home := codexHome(t, `{"input_tokens":101062,"cached_input_tokens":4352,"cache_write_input_tokens":0,"output_tokens":178,"reasoning_output_tokens":0}`)
	threads, _ := config.CodexThreadsDir()
	if err := os.MkdirAll(threads, 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(threads, testSession), []byte(codexThread+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	// The resume adds a turn to the session file.
	turn := `{"timestamp":"2026-10-08T12:00:00.000Z","type":"event_msg","payload":{"type":"token_count","info":{"total_token_usage":{"input_tokens":101062,"cached_input_tokens":4352,"cache_write_input_tokens":0,"output_tokens":178},"last_token_usage":{"input_tokens":1000,"cached_input_tokens":0,"cache_write_input_tokens":0,"output_tokens":50}}}}` + "\n"
	if err := os.WriteFile(filepath.Join(home, "append"), []byte(turn), 0o600); err != nil {
		t.Fatal(err)
	}
	spec := codexSpec(home)
	spec.resume, spec.permissionMode = true, "workspace-write"

	res := runWorker(context.Background(), spec, nil)

	if res.err != nil || !res.hasResult {
		t.Fatalf("runWorker = %+v", res)
	}
	model := res.usage.Models["openrouter/free"]
	if res.usage.Source != api.UsageReported || model.InputTokens != 1000 || model.OutputTokens != 50 || model.CacheReadTokens != 0 {
		t.Fatalf("usage = %+v", res.usage)
	}
	args, _ := os.ReadFile(filepath.Join(home, "args.log"))
	if want := "-c\nsandbox_mode=\"workspace-write\"\n"; !strings.Contains(string(args), want) {
		t.Fatalf("args = %q, want %q", args, want)
	}
	if want := "resume\n" + codexThread + "\n--\ngo\n"; !strings.HasSuffix(string(args), want) {
		t.Fatalf("args = %q, want the end %q", args, want)
	}
	if !strings.Contains(string(args), "\n-p\nopenrouter\n-m\nopenrouter/free\n") {
		t.Fatalf("options must come before resume: %q", args)
	}
}

func TestRunWorkerFailsACodexRunOnTheWrongProvider(t *testing.T) {
	home := codexHome(t, `{"input_tokens":10,"cached_input_tokens":0,"cache_write_input_tokens":0,"output_tokens":5,"reasoning_output_tokens":0}`)
	if err := os.WriteFile(filepath.Join(home, "openrouter.config.toml"), []byte("model_provider = \"openai\"\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	res := runWorker(context.Background(), codexSpec(home), nil)

	if res.hasResult || res.output != "codex ran on provider openrouter but profile openrouter names openai" {
		t.Fatalf("runWorker = %+v", res)
	}
}

func TestAccountEnvSetsCodexHomeAndRefusesItInAnEnvFile(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "a.env")
	if err := os.WriteFile(path, []byte("OPENROUTER_API_KEY=k\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	env, err := workerSpec{harnessName: "codex", configDir: "/codex-home", envFiles: []string{path}}.accountEnv()
	if err != nil || strings.Join(env, " ") != "OPENROUTER_API_KEY=k CODEX_HOME=/codex-home" {
		t.Fatalf("env = %v, %v", env, err)
	}

	bad := filepath.Join(dir, "bad.env")
	if err := os.WriteFile(bad, []byte("CODEX_HOME=/elsewhere\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	_, err = workerSpec{harnessName: "codex", envFiles: []string{bad}}.accountEnv()
	if err == nil || !strings.Contains(err.Error(), "CODEX_HOME is not allowed in an env file; set the codexHome of the account") {
		t.Fatalf("err = %v", err)
	}
	// Claude Code's variable is no concern of a codex account, and the reverse.
	if _, err := (workerSpec{harnessName: "claude-code", envFiles: []string{bad}}).accountEnv(); err != nil {
		t.Fatalf("CODEX_HOME in the env file of a claude-code account: %v", err)
	}
}

func TestHarnessByNameKnowsCodex(t *testing.T) {
	h, err := harnessByName("codex", "/home", "openrouter")
	if err != nil || h.Name() != "codex" || h.Program() != "codex" {
		t.Fatalf("harnessByName = %v, %v", h, err)
	}
	if got := recordHarness(runRecord{Harness: "codex", ConfigDir: "/home", Profile: "p"}).Name(); got != "codex" {
		t.Fatalf("recordHarness = %q", got)
	}
}
