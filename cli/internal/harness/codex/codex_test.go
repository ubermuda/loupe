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

func TestInteractiveRunsCodexInTheFolderWithTheRunID(t *testing.T) {
	h := New("", "openrouter", "")
	body := h.Interactive("/bin/codex", harness.Spec{
		Dir: "/work/it's", Model: "openrouter/free", PermissionMode: modeReadOnly, SessionID: runID, Prompt: "do it",
		Env: []string{"CODEX_HOME=/home/b"},
	})
	for _, want := range []string{
		"rm -f -- \"$0\"\n",
		"export CODEX_HOME='/home/b'\n",
		"export LOUPE_SESSION_ID='" + runID + "'\n",
		"cd -- '/work/it'\\''s' || exit 1\n",
		"exec '/bin/codex' -p 'openrouter' -m 'openrouter/free' '-s' 'read-only' -- 'do it'\n",
	} {
		if !strings.Contains(body, want) {
			t.Fatalf("script lacks %q:\n%s", want, body)
		}
	}
}

func TestInteractiveWorkspaceAddsTheNetwork(t *testing.T) {
	body := New("", "", "").Interactive("codex", harness.Spec{Dir: t.TempDir(), PermissionMode: modeWorkspace})
	if !strings.Contains(body, "'-s' 'workspace-write'") || !strings.Contains(body, "'"+networkAccess+"'") {
		t.Fatalf("script = %q", body)
	}
}

// sessionFile writes a session file whose first line is a session_meta.
func sessionFile(t *testing.T, home, id, cwd string, start time.Time) {
	t.Helper()
	dir := filepath.Join(home, "sessions", "2026", "10", "08")
	if err := os.MkdirAll(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	line := `{"timestamp":"` + start.UTC().Format(time.RFC3339Nano) + `","type":"session_meta","payload":{"id":"` + id + `","cwd":"` + cwd + `"}}` + "\n"
	if err := os.WriteFile(filepath.Join(dir, "rollout-x-"+id+".jsonl"), []byte(line), 0o600); err != nil {
		t.Fatal(err)
	}
}

func TestAnInteractiveRunFindsItsSessionByFolderAndTime(t *testing.T) {
	h := newHarness(t, "")
	at := time.Now()
	work := t.TempDir()
	other := "aaaaaaaa-0000-4000-8000-000000000001"
	late := "aaaaaaaa-0000-4000-8000-000000000002"
	early := "aaaaaaaa-0000-4000-8000-000000000003"
	sessionFile(t, h.home, other, t.TempDir(), at.Add(time.Second))
	sessionFile(t, h.home, early, work, at.Add(-time.Minute))
	sessionFile(t, h.home, late, work, at.Add(5*time.Second))
	if err := h.RecordLaunch(runID, work, at); err != nil {
		t.Fatal(err)
	}

	got, err := h.thread(runID)
	if err != nil || got != late {
		t.Fatalf("thread = %q, %v; want %q", got, err, late)
	}
	// The match is kept, so a later session in the folder does not take it.
	sessionFile(t, h.home, "aaaaaaaa-0000-4000-8000-000000000004", work, at.Add(time.Second))
	if again, err := h.thread(runID); err != nil || again != late {
		t.Fatalf("thread again = %q, %v", again, err)
	}
	if err := h.HasSession(runID); err != nil {
		t.Fatal(err)
	}
}

func TestAnInteractiveRunSkipsASessionAnotherRunClaimed(t *testing.T) {
	h := newHarness(t, "")
	at := time.Now()
	work := t.TempDir()
	first := "aaaaaaaa-0000-4000-8000-000000000001"
	second := "aaaaaaaa-0000-4000-8000-000000000002"
	sessionFile(t, h.home, first, work, at.Add(time.Second))
	sessionFile(t, h.home, second, work, at.Add(2*time.Second))
	for _, id := range []string{"run-one", "run-two"} {
		if err := h.RecordLaunch(id, work, at); err != nil {
			t.Fatal(err)
		}
	}

	one, err1 := h.thread("run-one")
	two, err2 := h.thread("run-two")
	if err1 != nil || err2 != nil || one != first || two != second {
		t.Fatalf("threads = %q %q, errors %v %v", one, two, err1, err2)
	}
}

func TestALaterLaunchWaitsForTheSessionOfAnEarlierOne(t *testing.T) {
	h := newHarness(t, "")
	at := time.Now()
	work := t.TempDir()
	first := "aaaaaaaa-0000-4000-8000-000000000001"
	second := "aaaaaaaa-0000-4000-8000-000000000002"
	sessionFile(t, h.home, first, work, at.Add(time.Second))
	sessionFile(t, h.home, second, work, at.Add(2*time.Second))
	if err := h.RecordLaunch("run-one", work, at); err != nil {
		t.Fatal(err)
	}
	if err := h.RecordLaunch("run-two", work, at.Add(time.Millisecond)); err != nil {
		t.Fatal(err)
	}

	// The later launch asks first and still gets the later session.
	two, err2 := h.thread("run-two")
	one, err1 := h.thread("run-one")
	if err1 != nil || err2 != nil || one != first || two != second {
		t.Fatalf("threads = %q %q, errors %v %v", one, two, err1, err2)
	}
}

func TestAForgottenOrStaleLaunchHoldsNoSession(t *testing.T) {
	h := newHarness(t, "")
	at := time.Now()
	work := t.TempDir()
	id := "aaaaaaaa-0000-4000-8000-000000000001"
	sessionFile(t, h.home, id, work, at.Add(time.Second))
	if err := h.RecordLaunch("failed", work, at.Add(-time.Minute)); err != nil {
		t.Fatal(err)
	}
	if err := h.RecordLaunch("stale", work, at.Add(-48*time.Hour)); err != nil {
		t.Fatal(err)
	}
	if err := h.RecordLaunch("run", work, at); err != nil {
		t.Fatal(err)
	}
	h.ForgetLaunch("failed")

	if got, err := h.thread("run"); err != nil || got != id {
		t.Fatalf("thread = %q, %v", got, err)
	}
}

func TestAnInteractiveRunWithNoSessionYetHasNone(t *testing.T) {
	h := newHarness(t, "")
	work := t.TempDir()
	if err := h.RecordLaunch(runID, work, time.Now()); err != nil {
		t.Fatal(err)
	}
	if _, err := h.thread(runID); !errors.Is(err, transcript.ErrNotFound) {
		t.Fatalf("err = %v", err)
	}
	if _, err := h.thread("never-launched"); !errors.Is(err, transcript.ErrNotFound) {
		t.Fatalf("err = %v", err)
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

// mcpFixture is a project folder, a bin folder with a fake codex and loupe, and
// a log of the codex calls.
type mcpFixture struct {
	env     []string
	project string
	log     string
}

// fakeCodexMcp puts a logged-in codex on PATH whose `mcp get loupe --json`
// runs body. It logs the profile arguments, CODEX_HOME and the folder of each call.
func fakeCodexMcp(t *testing.T, body string, withLoupe bool) mcpFixture {
	t.Helper()
	bin, project := t.TempDir(), t.TempDir()
	log := filepath.Join(t.TempDir(), "calls")
	script := "#!/bin/sh\n[ \"$1 $2\" = \"login status\" ] && exit 0\n" +
		"case \"$*\" in *\"mcp get loupe --json\") echo \"$* | $CODEX_HOME | $PWD\" >> '" + log + "'\n" + body + "\nexit 0\n;; esac\nexit 2\n"
	if err := os.WriteFile(filepath.Join(bin, "codex"), []byte(script), 0o700); err != nil {
		t.Fatal(err)
	}
	if withLoupe {
		if err := os.WriteFile(filepath.Join(bin, "loupe"), []byte("#!/bin/sh\n"), 0o700); err != nil {
			t.Fatal(err)
		}
	}

	return mcpFixture{env: []string{"PATH=" + bin + ":/bin:/usr/bin"}, project: project, log: log}
}

const (
	stdioLoupe = `echo '{"enabled":true,"transport":{"type":"stdio","command":"loupe","args":["mcp"]}}'`
	approveCfg = "[mcp_servers.loupe]\ndefault_tools_approval_mode = \"approve\"\n"
)

func TestCheckLoupeMcpServer(t *testing.T) {
	ctx := context.Background()
	check := func(t *testing.T, h Harness, home string, f mcpFixture) []harness.Problem {
		t.Helper()

		return h.Check(ctx, harness.CheckSpec{ConfigDir: home, Env: f.env, Projects: map[string]string{"loupe": f.project}})
	}
	plain := func(t *testing.T, cfg string) (Harness, string) {
		t.Helper()
		home := t.TempDir()
		if err := os.WriteFile(filepath.Join(home, "config.toml"), []byte(cfg), 0o600); err != nil {
			t.Fatal(err)
		}

		return New(home, "", ""), home
	}
	one := func(t *testing.T, got []harness.Problem, reason, detail string) {
		t.Helper()
		if len(got) != 1 || got[0].Reason != reason || !strings.Contains(got[0].Detail, detail) {
			t.Fatalf("problems = %+v, want %q with %q", got, reason, detail)
		}
	}

	t.Run("passes and runs in the project with the resolved home", func(t *testing.T) {
		h, home := plain(t, approveCfg)
		f := fakeCodexMcp(t, stdioLoupe, true)
		if got := check(t, h, home, f); len(got) != 0 {
			t.Fatalf("problems = %+v", got)
		}
		b, _ := os.ReadFile(f.log)
		project, _ := filepath.EvalSymlinks(f.project)
		if want := "mcp get loupe --json | " + home + " | "; !strings.HasPrefix(string(b), want) || !strings.Contains(string(b), filepath.Base(project)) {
			t.Fatalf("call = %q, want prefix %q", b, want)
		}
	})
	t.Run("the server is not declared", func(t *testing.T) {
		h, home := plain(t, approveCfg)
		got := check(t, h, home, fakeCodexMcp(t, "exit 1", true))
		one(t, got, "loupe MCP server not declared for project loupe", "run CODEX_HOME="+home+" codex mcp add loupe -- loupe mcp")
	})
	t.Run("the server is not loupe mcp", func(t *testing.T) {
		h, home := plain(t, approveCfg)
		got := check(t, h, home, fakeCodexMcp(t, `echo '{"enabled":true,"transport":{"type":"stdio","command":"npx","args":["-y","x","--token","SECRET"]}}'`, true))
		one(t, got, "loupe MCP server is not loupe mcp for project loupe", "command npx")
		if strings.Contains(got[0].Detail, "SECRET") {
			t.Fatalf("detail leaks an argument: %q", got[0].Detail)
		}
		got = check(t, h, home, fakeCodexMcp(t, `echo '{"enabled":true,"transport":{"type":"streamable_http","url":"https://x/?token=SECRET"}}'`, true))
		one(t, got, "loupe MCP server is not loupe mcp for project loupe", "type streamable_http")
		if strings.Contains(got[0].Detail, "SECRET") {
			t.Fatalf("detail leaks a secret: %q", got[0].Detail)
		}
		got = check(t, h, home, fakeCodexMcp(t, `echo '{"enabled":true,"transport":{"type":"stdio","command":"loupe","args":["other"]}}'`, true))
		one(t, got, "loupe MCP server is not loupe mcp for project loupe", "codex mcp add loupe -- loupe mcp")
	})
	t.Run("the server is disabled", func(t *testing.T) {
		h, home := plain(t, approveCfg)
		got := check(t, h, home, fakeCodexMcp(t, `echo '{"enabled":false,"transport":{"type":"stdio","command":"loupe","args":["mcp"]}}'`, true))
		one(t, got, "loupe MCP server is disabled for project loupe", "config.toml")
	})
	t.Run("a configured loupe path that does not exist fails", func(t *testing.T) {
		h, home := plain(t, approveCfg)
		got := check(t, h, home, fakeCodexMcp(t, `echo '{"enabled":true,"transport":{"type":"stdio","command":"/nonexistent/loupe","args":["mcp"]}}'`, true))
		one(t, got, "loupe is not on PATH for project loupe", "/nonexistent/loupe")
	})
	t.Run("loupe is not on PATH", func(t *testing.T) {
		h, home := plain(t, approveCfg)
		one(t, check(t, h, home, fakeCodexMcp(t, stdioLoupe, false)), "loupe is not on PATH for project loupe", "")
	})
	t.Run("the check times out", func(t *testing.T) {
		h, home := plain(t, approveCfg)
		old := checkTimeout
		checkTimeout = 50 * time.Millisecond
		defer func() { checkTimeout = old }()
		one(t, check(t, h, home, fakeCodexMcp(t, "sleep 5", true)), "MCP check timed out for project loupe", "codex mcp get did not answer in")
	})
	t.Run("tools need approval", func(t *testing.T) {
		h, home := plain(t, "[mcp_servers.loupe]\ncommand = \"loupe\"\n")
		want := `add default_tools_approval_mode = "approve" under [mcp_servers.loupe] in ` + filepath.Join(home, "config.toml")
		one(t, check(t, h, home, fakeCodexMcp(t, stdioLoupe, true)), "loupe MCP tools need approval for project loupe", want)
	})
	t.Run("a mode other than approve fails", func(t *testing.T) {
		h, home := plain(t, "[mcp_servers.loupe]\ndefault_tools_approval_mode = \"prompt\"\n")
		one(t, check(t, h, home, fakeCodexMcp(t, stdioLoupe, true)), "loupe MCP tools need approval for project loupe", "config.toml")
	})
	t.Run("the project file wins over config.toml", func(t *testing.T) {
		h, home := plain(t, "[mcp_servers.loupe]\ndefault_tools_approval_mode = \"prompt\"\n")
		f := fakeCodexMcp(t, stdioLoupe, true)
		if err := os.MkdirAll(filepath.Join(f.project, ".codex"), 0o700); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(filepath.Join(f.project, ".codex", "config.toml"), []byte(approveCfg), 0o600); err != nil {
			t.Fatal(err)
		}
		if got := check(t, h, home, f); len(got) != 0 {
			t.Fatalf("problems = %+v", got)
		}
	})
	t.Run("a config file that does not parse fails", func(t *testing.T) {
		h, home := plain(t, "[mcp_servers.loupe\n")
		one(t, check(t, h, home, fakeCodexMcp(t, stdioLoupe, true)), "codex config does not parse for project loupe", "config.toml")
	})
	t.Run("a profile account runs the check with -p and reads the profile file", func(t *testing.T) {
		h := newHarness(t, "openrouter")
		f := fakeCodexMcp(t, stdioLoupe, true)
		env := append(f.env, "OPENROUTER_API_KEY=k")
		spec := harness.CheckSpec{ConfigDir: h.home, Env: env, Projects: map[string]string{"loupe": f.project}}
		file := profileFile(h.home, "openrouter")
		one(t, h.Check(ctx, spec), "loupe MCP tools need approval for project loupe", file)
		body, _ := os.ReadFile(file)
		if err := os.WriteFile(file, append(body, []byte("\n"+approveCfg)...), 0o600); err != nil {
			t.Fatal(err)
		}
		if got := h.Check(ctx, spec); len(got) != 0 {
			t.Fatalf("problems = %+v", got)
		}
		if b, _ := os.ReadFile(f.log); !strings.HasPrefix(string(b), "-p openrouter mcp get loupe --json | "+h.home) {
			t.Fatalf("call = %q", b)
		}
	})
	t.Run("a login failure skips the MCP check", func(t *testing.T) {
		h, home := plain(t, "")
		f := fakeCodexMcp(t, stdioLoupe, true)
		bin := strings.TrimSuffix(strings.TrimPrefix(f.env[0], "PATH="), ":/bin:/usr/bin")
		if err := os.WriteFile(filepath.Join(bin, "codex"), []byte("#!/bin/sh\nexit 1\n"), 0o700); err != nil {
			t.Fatal(err)
		}
		if got := check(t, h, home, f); !reflect.DeepEqual(reasons(got), []string{"not logged in"}) {
			t.Fatalf("problems = %+v", got)
		}
	})
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
