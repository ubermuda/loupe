package cmd

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// accountRules runs plan as account a and review as account b. Each account
// has its own config folder and env file, and a global env file comes first.
const accountRules = `
envFile: {global}
accounts:
  a:
    harness: claude-code
    model: sonnet
    configDir: {configA}
    envFile: {envA}
  b:
    harness: claude-code
    model: haiku
    permissionMode: acceptEdits
    configDir: {configB}
    envFile: {envB}
defaults:
  account: a
projects:
  loupe:
    dir: {dir}
work:
  plan:
    prompt: Card {cardNumber}.
  review:
    account: b
    model: opus
    prompt: Review {cardNumber}.
`

// accountFiles holds the paths that accountRules names.
type accountFiles struct {
	global, envA, envB, configA, configB string
}

// fill puts the paths of f in a rule file.
func (f accountFiles) fill(body string) string {
	return strings.NewReplacer("{global}", f.global, "{envA}", f.envA, "{envB}", f.envB, "{configA}", f.configA, "{configB}", f.configB).Replace(body)
}

func writeEnv(t *testing.T, path, body string) {
	t.Helper()
	if err := os.WriteFile(path, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
}

// withAccounts gives the harness accountRules edited by edit, and
// env files that each set a key of their own and SHARED.
func withAccounts(t *testing.T, edit func(string) string) (*harness, *stateRecorder, accountFiles) {
	t.Helper()
	root := t.TempDir()
	f := accountFiles{
		global: filepath.Join(root, "global.env"), envA: filepath.Join(root, "a.env"), envB: filepath.Join(root, "b.env"),
		configA: filepath.Join(root, "claude-a"), configB: filepath.Join(root, "claude-b"),
	}
	writeEnv(t, f.global, "SHARED=global\nGLOBAL_ONLY=g\n")
	writeEnv(t, f.envA, "SHARED=a\nA_ONLY=a\n")
	writeEnv(t, f.envB, "SHARED=b\nB_ONLY=b\n")
	body := accountRules
	if edit != nil {
		body = edit(body)
	}
	h := newHarnessWith(t, f.fill(body), rules.Defaults{})

	return h, h.states(), f
}

// envOf is the value of key in env, and whether env sets it.
func envOf(env []string, key string) (string, bool) {
	for _, e := range env {
		if v, ok := strings.CutPrefix(e, key+"="); ok {
			return v, true
		}
	}

	return "", false
}

// A worker sees the global env file and the file of its own account, and no
// key that only another account's file sets.
func TestAKeyStaysWithItsAccount(t *testing.T) {
	h, _, f := withAccounts(t, nil)

	h.send(cardMoved(87))
	h.send(offerPayload(88, "review"))

	calls := h.worker.recorded()
	if len(calls) != 2 {
		t.Fatalf("workers = %+v", calls)
	}
	a, b := calls[0].env, calls[1].env
	for _, tc := range []struct {
		env   []string
		key   string
		value string
		set   bool
	}{
		{a, "GLOBAL_ONLY", "g", true}, {a, "SHARED", "a", true}, {a, "A_ONLY", "a", true}, {a, "B_ONLY", "", false},
		{a, "CLAUDE_CONFIG_DIR", f.configA, true},
		{b, "GLOBAL_ONLY", "g", true}, {b, "SHARED", "b", true}, {b, "B_ONLY", "b", true}, {b, "A_ONLY", "", false},
		{b, "CLAUDE_CONFIG_DIR", f.configB, true},
	} {
		if v, ok := envOf(tc.env, tc.key); v != tc.value || ok != tc.set {
			t.Fatalf("%s = %q, %v in %q; want %q, %v", tc.key, v, ok, tc.env, tc.value, tc.set)
		}
	}
	if calls[0].account != "a" || calls[0].configDir != f.configA || calls[1].account != "b" || calls[1].configDir != f.configB {
		t.Fatalf("workers = %+v", calls)
	}
}

// The bridge reads the env files at each run start, so an edit reaches the
// next run with no reload.
func TestAChangedEnvFileReachesTheNextRun(t *testing.T) {
	h, _, f := withAccounts(t, nil)

	h.send(cardMoved(87))
	writeEnv(t, f.envA, "SHARED=edited\n")
	h.send(cardMoved(88))

	calls := h.worker.recorded()
	if len(calls) != 2 {
		t.Fatalf("workers = %+v", calls)
	}
	if v, _ := envOf(calls[1].env, "SHARED"); v != "edited" {
		t.Fatalf("SHARED = %q", v)
	}
	if _, ok := envOf(calls[1].env, "A_ONLY"); ok {
		t.Fatalf("env = %q", calls[1].env)
	}
}

// An env file the bridge cannot read fails the run before it starts, with a
// reason that names the file.
func TestAMissingEnvFileFailsTheRun(t *testing.T) {
	h, rec, f := withAccounts(t, nil)
	if err := os.Remove(f.envA); err != nil {
		t.Fatal(err)
	}

	h.send(cardMoved(87))

	if got := h.worker.recorded(); len(got) != 0 {
		t.Fatalf("workers = %+v", got)
	}
	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunNotStarted)
	if r := sent[1].report; r.FailureReason == nil || !strings.Contains(*r.FailureReason, f.envA) {
		t.Fatalf("report = %+v", r)
	}
}

// An account with no config folder leaves the inherited CLAUDE_CONFIG_DIR alone.
func TestAnAccountWithNoConfigDirSetsNone(t *testing.T) {
	h, _, _ := withAccounts(t, func(body string) string {
		return strings.Replace(body, "    configDir: {configA}\n", "", 1)
	})

	h.send(cardMoved(87))

	calls := h.worker.recorded()
	if len(calls) != 1 || calls[0].configDir != "" {
		t.Fatalf("workers = %+v", calls)
	}
	if _, ok := envOf(calls[0].env, "CLAUDE_CONFIG_DIR"); ok {
		t.Fatalf("env = %q", calls[0].env)
	}
}

// A rule's model beats the model of its account, and the running report and
// the outcome name the harness, the account and the model that ran.
func TestARunReportsItsAccountAndModel(t *testing.T) {
	h, rec, _ := withAccounts(t, nil)

	h.send(offerPayload(88, "review"))

	calls := h.worker.recorded()
	if len(calls) != 1 || calls[0].model != "opus" || calls[0].permissionMode != "acceptEdits" {
		t.Fatalf("workers = %+v", calls)
	}
	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunSucceeded)
	if r := sent[0].report; r.Harness != "" || r.Account != "" || r.Model != "" {
		t.Fatalf("queued = %+v", r)
	}
	for _, s := range sent[1:] {
		if r := s.report; r.Harness != "claude-code" || r.Account != "b" || r.Model != "opus" {
			t.Fatalf("%s = %+v", r.State, r)
		}
	}
}

// The run-start log line names the account and the native permission mode.
func TestTheStartLineNamesTheAccountAndTheMode(t *testing.T) {
	h, _, _ := withAccounts(t, nil)

	h.send(offerPayload(88, "review"))

	line := h.only(t, "worker_started")
	if str(t, line, "account") != "b" || str(t, line, "permission_mode") != "acceptEdits" {
		t.Fatalf("worker_started = %v", line)
	}
}

// A variant that names another account runs with all of that account's
// settings: its config folder, its env file, its model and its mode.
func TestAVariantRunsWithTheSettingsOfItsAccount(t *testing.T) {
	h, rec, f := withAccounts(t, func(body string) string {
		return strings.Replace(body, "    prompt: Card {cardNumber}.\n",
			"    prompt: Card {cardNumber}.\n    variants:\n      - {name: one, weight: 1, account: a}\n      - {name: two, weight: 1, account: b}\n", 1)
	})
	h.pins(func(context.Context, string) (string, string, error) { return "two", "", nil })

	h.send(cardMoved(87))

	calls := h.worker.recorded()
	if len(calls) != 1 {
		t.Fatalf("workers = %+v", calls)
	}
	c := calls[0]
	if c.account != "b" || c.configDir != f.configB || c.model != "haiku" || c.permissionMode != "acceptEdits" || !slices.Equal(c.envFiles, []string{f.global, f.envB}) {
		t.Fatalf("worker = %+v", c)
	}
	if v, _ := envOf(c.env, "B_ONLY"); v != "b" {
		t.Fatalf("env = %q", c.env)
	}
	for _, s := range rec.states()[1:] {
		if r := s.report; r.Account != "b" || r.Model != "haiku" || r.Variant != "two" {
			t.Fatalf("%s = %+v", r.State, r)
		}
	}
	line := h.only(t, "worker_variant")
	if str(t, line, "account") != "b" || str(t, line, "permission_mode") != "acceptEdits" || str(t, line, "variant") != "two" {
		t.Fatalf("worker_variant = %v", line)
	}
}

// A run in its before command crosses a handover with its account. The next
// image starts claude with that account, though its own file changed it.
func TestAHandoverKeepsTheAccountOfARun(t *testing.T) {
	root := t.TempDir()
	configDir, envPath := filepath.Join(root, "claude-a"), filepath.Join(root, "a.env")
	writeEnv(t, envPath, "A_ONLY=a\n")
	account := "    harness: claude-code\n"
	body := strings.Replace(strings.Replace(beforeRules, "TIMEOUT", "1m", 1), account,
		account+"    configDir: "+configDir+"\n    envFile: "+envPath+"\n", 1)
	h := newHarnessWith(t, body, rules.Defaults{})
	block := make(chan struct{})
	defer close(block)
	h.router.worker.before = (&fakeBefore{result: procResult{dir: t.TempDir()}, block: block}).run
	h.states()
	st := roundTrip(t, freezeInBefore(t, h))
	if len(st.Live) != 1 {
		t.Fatalf("live = %+v", st.Live)
	}
	if run := st.Live[0]; run.Account != "claude" || run.Harness != "claude-code" || run.ConfigDir != configDir || !slices.Equal(run.EnvFiles, []string{envPath}) {
		t.Fatalf("live = %+v", run)
	}

	h2, _, _ := withBefore(t, "1m")
	rec := h2.states()
	h2.router.worker.adoptBefore = func(context.Context, string) procResult { return procResult{dir: t.TempDir()} }
	h2.router.adopt(st)
	h2.router.wg.Wait()

	calls := h2.worker.recorded()
	if len(calls) != 1 || calls[0].account != "claude" || calls[0].configDir != configDir {
		t.Fatalf("workers = %+v", calls)
	}
	if v, _ := envOf(calls[0].env, "A_ONLY"); v != "a" {
		t.Fatalf("env = %q", calls[0].env)
	}
	if r := finalOf(t, rec.states(), st.Live[0].RunID); r.Account != "claude" || r.Harness != "claude-code" {
		t.Fatalf("final = %+v", r)
	}
}

// A handover record of an older image names no account, and the adopted run
// reports none.
func TestAnOlderHandoverRecordHasNoAccount(t *testing.T) {
	var run handoverRun
	if err := json.Unmarshal([]byte(`{"runId":"r","key":"k","rule":"work:plan","phase":"before","model":"opus"}`), &run); err != nil {
		t.Fatal(err)
	}
	if run.Account != "" || run.ConfigDir != "" || run.Harness != "" || run.Model != "opus" {
		t.Fatalf("run = %+v", run)
	}
}

// launchAccountRules opens a session as an account with a config folder and
// an env file. The launcher copies the script to {copy}.
const launchAccountRules = `
accounts:
  claude:
    harness: claude-code
    model: sonnet
    configDir: {configDir}
    envFile: {envFile}
defaults:
  account: claude
projects:
  loupe:
    dir: {dir}
launch:
  command: {launcher}
work:
  design:
    action: interactive
    prompt: Design card {cardNumber}.
`

// The launch script exports the account's variables and config folder, and
// the launch report names the harness, the account and the model.
func TestALaunchRunsAsItsAccount(t *testing.T) {
	root := t.TempDir()
	configDir, envPath, copied := filepath.Join(root, "claude-a"), filepath.Join(root, "a.env"), filepath.Join(root, "copy.sh")
	writeEnv(t, envPath, "A_ONLY=a\n")
	body := strings.NewReplacer("{configDir}", configDir, "{envFile}", envPath).Replace(launchAccountRules)
	h, rec := launchHarnessWith(t, body, `[cp, '{script}', '`+copied+`']`)
	f := h.withWork()

	h.offer(f, workRequest(1, 87, "design", api.WorkRequestOpen))

	launches := rec.launches()
	if len(launches) != 1 {
		t.Fatalf("launches = %+v", launches)
	}
	if r := launches[0].report; r.State != api.RunRunning || r.Harness != "claude-code" || r.Account != "claude" || r.Model != "sonnet" {
		t.Fatalf("launch = %+v", r)
	}
	script, err := os.ReadFile(copied)
	if err != nil {
		t.Fatal(err)
	}
	for _, line := range []string{"export A_ONLY='a'\n", "export CLAUDE_CONFIG_DIR='" + configDir + "'\n"} {
		if !strings.Contains(string(script), line) {
			t.Fatalf("script = %s, want %q", script, line)
		}
	}
}

// A launch whose env file is gone does not start, and its reason names the file.
func TestALaunchWithAMissingEnvFileDoesNotStart(t *testing.T) {
	root := t.TempDir()
	envPath := filepath.Join(root, "absent.env")
	body := strings.NewReplacer("{configDir}", filepath.Join(root, "claude-a"), "{envFile}", envPath).Replace(launchAccountRules)
	h, rec := launchHarnessWith(t, body, `[sh, -c, 'exit 0', sh, '{script}']`)
	f := h.withWork()

	h.offer(f, workRequest(1, 87, "design", api.WorkRequestOpen))

	launches := rec.launches()
	if len(launches) != 1 || launches[0].report.State != api.RunNotStarted || !strings.Contains(launches[0].report.FailureReason, envPath) {
		t.Fatalf("launches = %+v", launches)
	}
	if got := h.scripts(t); len(got) != 0 {
		t.Fatalf("scripts = %v", got)
	}
}

// An env file cannot set CLAUDE_CONFIG_DIR, because the bridge would then
// read the sessions of the run in another folder than claude writes them.
func TestAnEnvFileCannotSetTheConfigFolder(t *testing.T) {
	h, rec, f := withAccounts(t, nil)
	writeEnv(t, f.global, "CLAUDE_CONFIG_DIR=/elsewhere\n")

	h.send(cardMoved(87))

	if got := h.worker.recorded(); len(got) != 0 {
		t.Fatalf("workers = %+v", got)
	}
	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunNotStarted)
	if r := sent[1].report; r.FailureReason == nil || !strings.Contains(*r.FailureReason, f.global) || !strings.Contains(*r.FailureReason, "configDir") {
		t.Fatalf("report = %+v", r)
	}
}

// resumeOnB is a person's resume of a plan run that started on account b,
// while the plan entry now runs on account a.
func resumeOnB() api.Command {
	c := resumeOf(endedRunKey)
	c.Account, c.Harness, c.Model = "b", rules.HarnessClaudeCode, "opus"

	return c
}

// A resume runs on the account the run started on, whatever account the rule
// names now. It finds the session in the config folder of that account, and
// keeps the model the run started with.
func TestAResumeRunsOnTheAccountTheRunStartedOn(t *testing.T) {
	h, _, f := withAccounts(t, nil)
	writeTranscript(t, f.configB, testSession, "{}")

	if state, reason := h.resume(resumeOnB()); state != api.CommandDone {
		t.Fatalf("resume = %s %q", state, reason)
	}

	calls := h.worker.recorded()
	if len(calls) != 1 {
		t.Fatalf("workers = %+v", calls)
	}
	c := calls[0]
	if !c.resume || c.account != "b" || c.configDir != f.configB || c.model != "opus" || c.permissionMode != "acceptEdits" {
		t.Fatalf("worker = %+v", c)
	}
	if v, _ := envOf(c.env, "SHARED"); v != "b" {
		t.Fatalf("SHARED = %q", v)
	}
	if _, ok := envOf(c.env, "A_ONLY"); ok {
		t.Fatalf("env %q holds the key of account a", c.env)
	}
}

// A resume of a run whose account is gone from rules.yaml, or whose account
// now names another harness, is refused with a reason that names the account.
func TestAResumeIsRefusedWhenItsAccountChanged(t *testing.T) {
	for name, tc := range map[string]struct {
		change func(c *api.Command)
		reason string
	}{
		"a removed account": {change: func(c *api.Command) { c.Account = "c" }, reason: "The account c that the run started on is no longer in rules.yaml."},
		"another harness": {change: func(c *api.Command) { c.Harness = "codex" },
			reason: "The account b that the run started on now names the harness claude-code, and the run started on codex."},
	} {
		t.Run(name, func(t *testing.T) {
			h, _, _ := withAccounts(t, nil)
			h.transcripts(true)
			c := resumeOnB()
			tc.change(&c)

			state, reason := h.resume(c)

			if state != api.CommandRefused || reason != tc.reason {
				t.Fatalf("resume = %s %q, want refused %q", state, reason, tc.reason)
			}
			if h.runs() != 0 {
				t.Fatalf("workers = %d after a refused resume", h.runs())
			}
		})
	}
}

// A run of an older bridge names no account, so its resume runs on the
// account the rule names now.
func TestAResumeOfARunWithNoAccountTakesTheAccountOfTheRule(t *testing.T) {
	h, _, f := withAccounts(t, nil)
	writeTranscript(t, f.configA, testSession, "{}")

	if state, reason := h.resume(resumeOf(endedRunKey)); state != api.CommandDone {
		t.Fatalf("resume = %s %q", state, reason)
	}

	calls := h.worker.recorded()
	if len(calls) != 1 || calls[0].account != "a" || calls[0].configDir != f.configA || calls[0].model != "sonnet" {
		t.Fatalf("workers = %+v", calls)
	}
}

// A reload and a handover match a queued resume again, and it keeps the
// account the run started on.
func TestAQueuedResumeKeepsItsAccountThroughAReloadAndAHandover(t *testing.T) {
	h, _, f := withAccounts(t, nil)
	h.transcripts(true)
	h.reply(pausedReply(true))
	if state, reason := h.resume(resumeOnB()); state != api.CommandDone {
		t.Fatalf("resume = %s %q", state, reason)
	}
	if res := h.reload(t, f.fill(strings.Replace(accountRules, "  a:\n    harness: claude-code\n    model: sonnet\n", "  a:\n    harness: claude-code\n    model: haiku\n", 1))); !res.OK {
		t.Fatalf("reload = %+v", res)
	}
	st := h.router.freeze()

	h2 := newHarnessWith(t, f.fill(accountRules), rules.Defaults{})
	h2.states()
	h2.router.adopt(roundTrip(t, st))
	h2.reply(pausedReply(false))

	calls := h2.worker.recorded()
	if len(calls) != 1 || calls[0].account != "b" || calls[0].configDir != f.configB || calls[0].model != "opus" {
		t.Fatalf("workers = %+v", calls)
	}
}

// A reload that removes the account of a queued resume drops the resume.
func TestAReloadDropsAQueuedResumeWhoseAccountIsGone(t *testing.T) {
	h, _, f := withAccounts(t, nil)
	h.transcripts(true)
	h.reply(pausedReply(true))
	if state, reason := h.resume(resumeOnB()); state != api.CommandDone {
		t.Fatalf("resume = %s %q", state, reason)
	}
	body := strings.Replace(accountRules, "  review:\n    account: b\n", "  review:\n", 1)
	body = body[:strings.Index(body, "  b:\n")] + body[strings.Index(body, "defaults:"):]
	if res := h.reload(t, f.fill(body)); !res.OK {
		t.Fatalf("reload = %+v", res)
	}
	h.reply(pausedReply(false))

	if h.runs() != 0 || len(h.queued()) != 0 {
		t.Fatalf("workers = %d, queue = %v", h.runs(), h.queued())
	}
}
