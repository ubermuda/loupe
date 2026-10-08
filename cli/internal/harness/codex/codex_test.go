package codex

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/harness"
	"github.com/ubermuda/loupe/cli/internal/transcript"
)

const (
	thread = "01a11b44-0eb9-71f3-b3f1-3066d6b81ee6"
	runID  = "7f1c0d2e-1111-4222-8333-444455556666"
)

// testHarness is a harness with a Codex home that holds the fixture session and
// the openrouter profile, and a folder for the thread map.
type testHarness struct {
	Harness
	home string
}

func newHarness(t *testing.T, profile string) testHarness {
	t.Helper()
	root := t.TempDir()
	home := filepath.Join(root, "codex-home")
	sessionDir := filepath.Join(home, "sessions", "2026", "10", "08")
	if err := os.MkdirAll(sessionDir, 0o700); err != nil {
		t.Fatal(err)
	}
	copyFile(t, "testdata/session.jsonl", filepath.Join(sessionDir, "rollout-2026-10-08T07-26-47-"+thread+".jsonl"))
	copyFile(t, "testdata/openrouter.config.toml", filepath.Join(home, "openrouter.config.toml"))

	return testHarness{Harness: New(home, profile, filepath.Join(root, "threads")), home: home}
}

func copyFile(t *testing.T, from, to string) {
	t.Helper()
	b, err := os.ReadFile(from)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(to, b, 0o600); err != nil {
		t.Fatal(err)
	}
}

// runDir is a run directory that holds stdout and the last message.
func runDir(t *testing.T, stdout, last string) string {
	t.Helper()
	dir := t.TempDir()
	copyFile(t, filepath.Join("testdata", stdout), filepath.Join(dir, "stdout"))
	if last != "" {
		if err := os.WriteFile(filepath.Join(dir, lastMessageFile), []byte(last), 0o600); err != nil {
			t.Fatal(err)
		}
	}

	return dir
}

// fakeGit puts a git on PATH that prints out, or fails when out is empty.
func fakeGit(t *testing.T, out string) {
	t.Helper()
	bin := t.TempDir()
	body := "#!/bin/sh\nexit 1\n"
	if out != "" {
		body = "#!/bin/sh\necho " + shellQuote(out) + "\n"
	}
	if err := os.WriteFile(filepath.Join(bin, "git"), []byte(body), 0o700); err != nil {
		t.Fatal(err)
	}
	t.Setenv("PATH", bin+":"+os.Getenv("PATH"))
}

func TestWorkerArgs(t *testing.T) {
	fakeGit(t, "/main/.git")
	h := New("", "openrouter", "")
	run := t.TempDir()
	spec := harness.Spec{Dir: t.TempDir(), Model: "openrouter/free", Schema: `{"type":"object"}`, SessionID: runID, Prompt: "- go", RunDir: run, PermissionMode: "workspace-write"}
	tail := []string{"--json", "--skip-git-repo-check", "--output-schema", filepath.Join(run, schemaFile), "-o", filepath.Join(run, lastMessageFile)}
	net := []string{"-c", "sandbox_workspace_write.network_access=true"}

	for name, tc := range map[string]struct {
		mode   string
		resume bool
		want   []string
	}{
		"new workspace":    {"workspace-write", false, append(append(append([]string{"exec", "-p", "openrouter", "-m", "openrouter/free", "-s", "workspace-write", "--add-dir", "/main/.git"}, net...), tail...), "--", "- go")},
		"resume workspace": {"workspace-write", true, append(append(append([]string{"exec", "-p", "openrouter", "-m", "openrouter/free", "-c", `sandbox_mode="workspace-write"`, "-c", `sandbox_workspace_write.writable_roots=["/main/.git"]`}, net...), tail...), "resume", thread, "--", "- go")},
		"new read-only":    {"read-only", false, append(append([]string{"exec", "-p", "openrouter", "-m", "openrouter/free", "-s", "read-only"}, tail...), "--", "- go")},
		"resume read-only": {"read-only", true, append(append([]string{"exec", "-p", "openrouter", "-m", "openrouter/free", "-c", `sandbox_mode="read-only"`}, tail...), "resume", thread, "--", "- go")},
		"new full":         {"danger-full-access", false, append(append([]string{"exec", "-p", "openrouter", "-m", "openrouter/free", "--dangerously-bypass-approvals-and-sandbox"}, tail...), "--", "- go")},
		"resume full":      {"danger-full-access", true, append(append([]string{"exec", "-p", "openrouter", "-m", "openrouter/free", "--dangerously-bypass-approvals-and-sandbox"}, tail...), "resume", thread, "--", "- go")},
		"no mode":          {"", false, append(append([]string{"exec", "-p", "openrouter", "-m", "openrouter/free"}, tail...), "--", "- go")},
	} {
		t.Run(name, func(t *testing.T) {
			s := spec
			s.PermissionMode = tc.mode
			hh := h
			var cmd harness.Command
			if tc.resume {
				hh = newHarness(t, "openrouter").Harness
				if err := hh.remember(runID, thread); err != nil {
					t.Fatal(err)
				}
				cmd = hh.Resume(s)
			} else {
				cmd = hh.Worker(s)
			}
			if !reflect.DeepEqual(cmd.Args, tc.want) {
				t.Fatalf("args = %q\nwant   %q", cmd.Args, tc.want)
			}
			if got := cmd.Files[filepath.Join(run, schemaFile)]; got != spec.Schema {
				t.Fatalf("schema file = %q, want %q", got, spec.Schema)
			}
		})
	}
}

func TestWorkspaceSandboxAddsNoRootForAGitFolderInsideTheProject(t *testing.T) {
	dir := t.TempDir()
	for name, git := range map[string]string{
		"a git folder inside the project": filepath.Join(dir, ".git"),
		"no repository":                   "",
	} {
		t.Run(name, func(t *testing.T) {
			fakeGit(t, git)
			cmd := New("", "", "").Worker(harness.Spec{Dir: dir, PermissionMode: "workspace-write", Prompt: "go", RunDir: t.TempDir()})
			args := strings.Join(cmd.Args, " ")
			if strings.Contains(args, "--add-dir") || strings.Contains(args, "--output-schema") || len(cmd.Files) != 0 {
				t.Fatalf("args = %q, files = %v", cmd.Args, cmd.Files)
			}
			if !strings.Contains(args, "-s workspace-write -c sandbox_workspace_write.network_access=true") {
				t.Fatalf("args = %q", cmd.Args)
			}
		})
	}
}

func TestResumeOfAnUnknownRunUsesTheRunID(t *testing.T) {
	cmd := newHarness(t, "").Resume(harness.Spec{SessionID: "unknown", Prompt: "go"})
	want := []string{"resume", "unknown", "--", "go"}
	if got := cmd.Args[len(cmd.Args)-4:]; !reflect.DeepEqual(got, want) {
		t.Fatalf("args = %q", cmd.Args)
	}
}

func TestInteractiveRefuses(t *testing.T) {
	body := New("", "", "").Interactive("codex", harness.Spec{})
	if !strings.Contains(body, "not supported yet") || !strings.Contains(body, "exit 1") {
		t.Fatalf("script = %q", body)
	}
}

func TestReadRunDecodesTheRunAndMapsTheThread(t *testing.T) {
	h := newHarness(t, "openrouter")
	dir := runDir(t, "stdout.jsonl", `{"status":"done","summary":"again"}`)

	got := h.ReadRun(dir, harness.RunInfo{SessionID: runID, Model: "requested"})

	if !got.Decoded || string(got.StructuredOutput) != `{"status":"done","summary":"again"}` {
		t.Fatalf("output = %+v", got)
	}
	want := transcript.Usage{"openrouter/free": {InputTokens: 100062 - 4352, CacheReadTokens: 4352, OutputTokens: 128}}
	if !reflect.DeepEqual(got.Usage, want) {
		t.Fatalf("usage = %+v, want %+v", got.Usage, want)
	}
	if id, err := h.thread(runID); err != nil || id != thread {
		t.Fatalf("thread = %q, %v", id, err)
	}
	if err := h.HasSession(runID); err != nil {
		t.Fatalf("HasSession = %v", err)
	}
}

func TestReadRunTakesAFencedDocument(t *testing.T) {
	h := newHarness(t, "")
	dir := runDir(t, "stdout.jsonl", "\n```json\n{\"status\":\"done\",\"summary\":\"x\"}\n```")

	got := h.ReadRun(dir, harness.RunInfo{SessionID: runID})

	if !got.Decoded || string(got.StructuredOutput) != `{"status":"done","summary":"x"}` {
		t.Fatalf("output = %+v", got)
	}
}

func TestReadRunWithoutADocumentKeepsTheText(t *testing.T) {
	h := newHarness(t, "")
	got := h.ReadRun(runDir(t, "stdout.jsonl", "plain words"), harness.RunInfo{SessionID: runID})
	if got.Decoded || got.Result != "plain words" {
		t.Fatalf("output = %+v", got)
	}
	// Valid JSON that is no object is no document.
	got = h.ReadRun(runDir(t, "stdout.jsonl", "[1]"), harness.RunInfo{SessionID: runID})
	if got.Decoded {
		t.Fatalf("output = %+v", got)
	}
}

func TestReadRunOfAFailedRunHoldsTheErrorMessage(t *testing.T) {
	h := newHarness(t, "")
	dir := runDir(t, "stdout-failed.jsonl", "")
	got := h.ReadRun(dir, harness.RunInfo{SessionID: runID})
	if got.Decoded || got.Result != "stream disconnected before completion" {
		t.Fatalf("output = %+v", got)
	}
}

func TestReadRunOfARunWithNoSessionFileHasNoUsageFromIt(t *testing.T) {
	h := New(t.TempDir(), "", filepath.Join(t.TempDir(), "threads"))
	got := h.ReadRun(runDir(t, "stdout.jsonl", `{"status":"done","summary":"s"}`), harness.RunInfo{SessionID: runID, Model: "openrouter/free"})
	// stdout still reports the usage, under the requested model.
	if !got.Decoded || got.Usage["openrouter/free"].OutputTokens != 128 {
		t.Fatalf("output = %+v", got)
	}
	if _, err := h.SessionTotal(runID); !errors.Is(err, transcript.ErrNotFound) {
		t.Fatalf("SessionTotal err = %v, want ErrNotFound", err)
	}
}

func TestReadRunFailsAProfileThatNamesAnotherProvider(t *testing.T) {
	h := newHarness(t, "openrouter")
	if err := os.WriteFile(filepath.Join(h.home, "openrouter.config.toml"), []byte("model_provider = \"openai\"\n[model_providers.x]\nmodel_provider = \"ignored\"\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	got := h.ReadRun(runDir(t, "stdout.jsonl", `{"status":"done","summary":"s"}`), harness.RunInfo{SessionID: runID})

	if got.Decoded || got.Result != "codex ran on provider openrouter but profile openrouter names openai" {
		t.Fatalf("output = %+v", got)
	}
}

func TestReadRunFailsAMissingProfileFile(t *testing.T) {
	h := newHarness(t, "gone")
	got := h.ReadRun(runDir(t, "stdout.jsonl", `{"status":"done","summary":"s"}`), harness.RunInfo{SessionID: runID})
	if got.Decoded || got.Result != "codex profile file gone.config.toml is missing" {
		t.Fatalf("output = %+v", got)
	}
}

func TestReadRunTakesTheProviderOfTheBaseConfigWhenTheProfileNamesNone(t *testing.T) {
	h := newHarness(t, "openrouter")
	if err := os.WriteFile(filepath.Join(h.home, "openrouter.config.toml"), []byte("model = \"x\"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(h.home, "config.toml"), []byte("model_provider = \"openrouter\"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if got := h.ReadRun(runDir(t, "stdout.jsonl", `{"status":"done","summary":"s"}`), harness.RunInfo{SessionID: runID}); !got.Decoded {
		t.Fatalf("output = %+v", got)
	}
}

func TestSessionReads(t *testing.T) {
	h := newHarness(t, "")
	if err := h.remember(runID, thread); err != nil {
		t.Fatal(err)
	}

	total, err := h.SessionTotal(runID)
	if err != nil || !reflect.DeepEqual(total, transcript.Usage{"openrouter/free": {InputTokens: 100062 - 4352, CacheReadTokens: 4352, OutputTokens: 128}}) {
		t.Fatalf("total = %+v, %v", total, err)
	}
	from := time.Date(2026, 10, 8, 11, 28, 0, 0, time.UTC)
	second, err := h.SessionUsage(runID, from, time.Time{})
	if err != nil || !reflect.DeepEqual(second, transcript.Usage{"openrouter/free": {InputTokens: 52495, OutputTokens: 59}}) {
		t.Fatalf("second turn = %+v, %v", second, err)
	}
	first, err := h.SessionUsage(runID, time.Time{}, from)
	if err != nil || !reflect.DeepEqual(first, transcript.Usage{"openrouter/free": {InputTokens: 47567 - 4352, CacheReadTokens: 4352, OutputTokens: 69}}) {
		t.Fatalf("first turn = %+v, %v", first, err)
	}
	if dir, err := h.StartDir(runID); err != nil || dir != "/work/project" {
		t.Fatalf("StartDir = %q, %v", dir, err)
	}
	if dir, err := h.StartDir("other"); err != nil || dir != "" {
		t.Fatalf("StartDir of an unknown run = %q, %v", dir, err)
	}
	if err := h.HasSession("other"); !errors.Is(err, transcript.ErrNotFound) {
		t.Fatalf("HasSession of an unknown run = %v", err)
	}
	if _, err := h.SessionUsage("../x", time.Time{}, time.Time{}); !errors.Is(err, transcript.ErrNotFound) {
		t.Fatalf("SessionUsage of a path = %v", err)
	}
}

func TestSessionUsageCountsARepeatedTokenLineOnce(t *testing.T) {
	h := newHarness(t, "")
	if err := h.remember(runID, thread); err != nil {
		t.Fatal(err)
	}
	path, err := h.find(thread)
	if err != nil {
		t.Fatal(err)
	}
	b, _ := os.ReadFile(path)
	lines := strings.Split(strings.TrimSpace(string(b)), "\n")
	repeated := append(lines[:3:3], lines[2])
	repeated = append(repeated, lines[3:]...)
	if err := os.WriteFile(path, []byte(strings.Join(repeated, "\n")+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	total, err := h.SessionTotal(runID)
	if err != nil || total["openrouter/free"].OutputTokens != 128 {
		t.Fatalf("total = %+v, %v", total, err)
	}
}

func TestSessionUsageOfAFileWithNoTokenCountIsUnknown(t *testing.T) {
	h := newHarness(t, "")
	if err := h.remember(runID, thread); err != nil {
		t.Fatal(err)
	}
	path, _ := h.find(thread)
	if err := os.WriteFile(path, []byte("not json\n{\"type\":\"session_meta\"}\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := h.SessionTotal(runID); err == nil {
		t.Fatal("SessionTotal of an unreadable session = nil error")
	}
}

func TestUsageIsPricedForAKnownModel(t *testing.T) {
	h := newHarness(t, "")
	if err := h.remember(runID, thread); err != nil {
		t.Fatal(err)
	}
	path, _ := h.find(thread)
	b, _ := os.ReadFile(path)
	if err := os.WriteFile(path, []byte(strings.ReplaceAll(string(b), "openrouter/free", "gpt-5.5")), 0o600); err != nil {
		t.Fatal(err)
	}

	total, err := h.SessionTotal(runID)
	if err != nil || total["gpt-5.5"].CostUSD == nil {
		t.Fatalf("total = %+v, %v", total, err)
	}
	want := (float64(100062-4352)*5 + 128*30 + 4352*0.5) / 1e6
	if got := *total["gpt-5.5"].CostUSD; got < want-1e-9 || got > want+1e-9 {
		t.Fatalf("cost = %v, want %v", got, want)
	}
}

// fakeCodex puts a codex on PATH whose login status exits with loginExit.
func fakeCodex(t *testing.T, loginExit string) []string {
	t.Helper()
	bin := t.TempDir()
	body := "#!/bin/sh\n[ \"$1 $2\" = \"login status\" ] && exit " + loginExit + "\nexit 2\n"
	if err := os.WriteFile(filepath.Join(bin, "codex"), []byte(body), 0o700); err != nil {
		t.Fatal(err)
	}

	return []string{"PATH=" + bin + ":/bin:/usr/bin"}
}

func reasons(problems []harness.Problem) []string {
	var out []string
	for _, p := range problems {
		out = append(out, p.Reason)
	}

	return out
}

func TestCheck(t *testing.T) {
	ctx := context.Background()
	t.Run("ready with a login", func(t *testing.T) {
		got := New("", "", "").Check(ctx, harness.CheckSpec{Env: fakeCodex(t, "0")})
		if len(got) != 0 {
			t.Fatalf("problems = %v", got)
		}
	})
	t.Run("not logged in", func(t *testing.T) {
		got := New("", "", "").Check(ctx, harness.CheckSpec{Env: fakeCodex(t, "1")})
		if !reflect.DeepEqual(reasons(got), []string{"not logged in"}) {
			t.Fatalf("problems = %v", got)
		}
	})
	t.Run("not on PATH", func(t *testing.T) {
		got := New("", "", "").Check(ctx, harness.CheckSpec{Env: []string{"PATH=" + t.TempDir()}})
		if !reflect.DeepEqual(reasons(got), []string{"codex is not on PATH"}) {
			t.Fatalf("problems = %v", got)
		}
	})
	t.Run("no home folder", func(t *testing.T) {
		got := New("", "", "").Check(ctx, harness.CheckSpec{ConfigDir: filepath.Join(t.TempDir(), "gone"), Env: fakeCodex(t, "0")})
		if !reflect.DeepEqual(reasons(got), []string{"codex home not found"}) {
			t.Fatalf("problems = %v", got)
		}
	})
	t.Run("no profile file", func(t *testing.T) {
		h := newHarness(t, "gone")
		got := h.Check(ctx, harness.CheckSpec{ConfigDir: h.home, Env: fakeCodex(t, "1")})
		if !reflect.DeepEqual(reasons(got), []string{"codex profile not found"}) {
			t.Fatalf("problems = %v", got)
		}
	})
	t.Run("the key variable is missing", func(t *testing.T) {
		h := newHarness(t, "openrouter")
		got := h.Check(ctx, harness.CheckSpec{ConfigDir: h.home, Env: append(fakeCodex(t, "1"), "OPENROUTER_API_KEY=  ")})
		if !reflect.DeepEqual(reasons(got), []string{"codex API key variable OPENROUTER_API_KEY is not set"}) {
			t.Fatalf("problems = %v", got)
		}
	})
	t.Run("the key variable is set", func(t *testing.T) {
		h := newHarness(t, "openrouter")
		got := h.Check(ctx, harness.CheckSpec{ConfigDir: h.home, Env: append(fakeCodex(t, "1"), "OPENROUTER_API_KEY=secret-value")})
		if len(got) != 0 {
			t.Fatalf("problems = %v", got)
		}
	})
}

func TestReadRunSubtractsTheBaselineModelByModel(t *testing.T) {
	h := newHarness(t, "")
	if err := h.remember(runID, thread); err != nil {
		t.Fatal(err)
	}
	path, err := h.find(thread)
	if err != nil {
		t.Fatal(err)
	}
	full, err := os.ReadFile("testdata/session-two-models.jsonl")
	if err != nil {
		t.Fatal(err)
	}
	// Before the resume, the thread holds its first turn alone.
	if err := os.WriteFile(path, []byte(strings.Join(strings.Split(string(full), "\n")[:4], "\n")+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	baseline, err := h.SessionTotal(runID)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, full, 0o600); err != nil {
		t.Fatal(err)
	}

	got := h.ReadRun(runDir(t, "stdout.jsonl", `{"status":"done","summary":"s"}`), harness.RunInfo{SessionID: runID})

	want := transcript.Usage{"gpt-5.5": {InputTokens: 52495, OutputTokens: 59, CostUSD: transcript.Cost("gpt-5.5", 52495, 59, 0, 0, 0)}}
	if diff := got.Usage.Minus(baseline); !reflect.DeepEqual(diff, want) {
		t.Fatalf("reported minus baseline = %+v, want %+v", diff, want)
	}
}

func TestEnvKeyComesFromTheSelectedProvidersTable(t *testing.T) {
	home := t.TempDir()
	profile := "model_provider = \"second\"\n\n[model_providers.first]\nenv_key = \"FIRST_KEY\"\n\n[model_providers.\"second\"]\nname = \"S\"\nenv_key = \"SECOND_KEY\"\n\n[model_providers.third]\nenv_key = \"THIRD_KEY\"\n"
	if err := os.WriteFile(filepath.Join(home, "p.config.toml"), []byte(profile), 0o600); err != nil {
		t.Fatal(err)
	}
	if got := configEnvKey(home, "p"); got != "SECOND_KEY" {
		t.Fatalf("env key = %q, want SECOND_KEY", got)
	}
	// The table can sit in config.toml when the profile only selects the provider.
	if err := os.WriteFile(filepath.Join(home, "p.config.toml"), []byte("model_provider = \"base\"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(home, "config.toml"), []byte("[model_providers.other]\nenv_key = \"OTHER\"\n[model_providers.base]\nenv_key = \"BASE_KEY\"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if got := configEnvKey(home, "p"); got != "BASE_KEY" {
		t.Fatalf("env key = %q, want BASE_KEY", got)
	}
}

func TestConfigReadsLiteralStringsAndComments(t *testing.T) {
	home := t.TempDir()
	profile := "model_provider = 'second' # picked\n\n[model_providers.'first']\nenv_key = 'FIRST_KEY'\n\n[model_providers.'second'] # the one\nname = \"S\"\nenv_key = 'SECOND_KEY' # the key\n"
	if err := os.WriteFile(filepath.Join(home, "p.config.toml"), []byte(profile), 0o600); err != nil {
		t.Fatal(err)
	}
	if got := configProvider(home, "p"); got != "second" {
		t.Fatalf("provider = %q", got)
	}
	if got := configEnvKey(home, "p"); got != "SECOND_KEY" {
		t.Fatalf("env key = %q", got)
	}
	// A double-quoted value with a trailing comment reads too.
	if err := os.WriteFile(filepath.Join(home, "q.config.toml"), []byte("model_provider = \"x\" # c\n[model_providers.x]\nenv_key = \"X_KEY\" # c\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if got := configEnvKey(home, "q"); got != "X_KEY" {
		t.Fatalf("env key = %q", got)
	}
}

func TestConfigReadsAProviderNameWithAnApostrophe(t *testing.T) {
	home := t.TempDir()
	profile := "model_provider = \"it's\"\n[model_providers.\"it's\"]\nenv_key = \"APOS_KEY\"\n[model_providers.other]\nenv_key = \"OTHER\"\n"
	if err := os.WriteFile(filepath.Join(home, "p.config.toml"), []byte(profile), 0o600); err != nil {
		t.Fatal(err)
	}
	if got := configEnvKey(home, "p"); got != "APOS_KEY" {
		t.Fatalf("env key = %q", got)
	}
}

func TestAProfileThatDoesNotParseFailsTheCheckAndSetsNoProviderExpectation(t *testing.T) {
	h := newHarness(t, "openrouter")
	if err := os.WriteFile(filepath.Join(h.home, "openrouter.config.toml"), []byte("model_provider = \n"), 0o600); err != nil {
		t.Fatal(err)
	}
	got := h.Check(context.Background(), harness.CheckSpec{ConfigDir: h.home, Env: fakeCodex(t, "0")})
	if !reflect.DeepEqual(reasons(got), []string{"codex profile does not parse"}) || got[0].Detail == "" {
		t.Fatalf("problems = %v", got)
	}
	run := h.ReadRun(runDir(t, "stdout.jsonl", `{"status":"done","summary":"s"}`), harness.RunInfo{SessionID: runID})
	if !run.Decoded {
		t.Fatalf("output = %+v", run)
	}
}
