package cmd

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// launchRules opens a session for a card that enters next, and runs a worker
// for a card that enters review. {launcher} is the launch command.
const launchRules = `
projects:
  loupe:
    dir: {dir}
launch:
  command: {launcher}
rules:
  - name: design
    action: interactive
    on: board.card_moved
    project: loupe
    to: next
    model: opus
    prompt: Design card {cardNumber}.
  - name: review
    on: board.card_moved
    project: loupe
    to: review
    prompt: Review {cardNumber}.
`

// launchHarness is a harness whose launch command is launcher, with the run
// state endpoints wired.
func launchHarness(t *testing.T, launcher string) (*harness, *stateRecorder) {
	t.Helper()
	h := newHarnessWith(t, strings.Replace(launchRules, "{launcher}", launcher, 1), rules.Defaults{})
	h.router.claude = "/usr/local/bin/claude"
	h.router.scriptDir = t.TempDir()

	return h, h.states()
}

// scripts lists the launch scripts left in the script directory.
func (h *harness) scripts(t *testing.T) []string {
	t.Helper()
	entries, err := os.ReadDir(h.router.scriptDir)
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		t.Fatal(err)
	}
	var out []string
	for _, e := range entries {
		out = append(out, e.Name())
	}

	return out
}

// assertNoWorkerState checks that a launch left no trace of a worker run.
func (h *harness) assertNoWorkerState(t *testing.T, rec *stateRecorder) {
	t.Helper()
	h.router.mu.Lock()
	defer h.router.mu.Unlock()
	if len(h.router.queue) != 0 || len(h.router.running) != 0 || len(h.router.held) != 0 || len(h.router.sessions) != 0 || len(h.router.live) != 0 || len(h.router.chains) != 0 {
		t.Fatalf("queue = %v, running = %v, held = %v, sessions = %v, live = %v, chains = %v", h.router.queue, h.router.running, h.router.held, h.router.sessions, h.router.live, h.router.chains)
	}
	if h.router.active != 0 || h.router.launching != 0 {
		t.Fatalf("active = %d, launching = %d", h.router.active, h.router.launching)
	}
	if got := len(rec.states()); got != 0 {
		t.Fatalf("run states = %v", rec.names())
	}
}

func TestAnInteractiveMatchLaunchesASession(t *testing.T) {
	h, rec := launchHarness(t, `[sh, -c, 'exit 0', sh, '{script}', '{dir}', '{sessionId}', '{cardNumber}', '{project}']`)

	h.send(movedPayload(87, "backlog", "next", "agent"))

	if n := h.runs(); n != 0 {
		t.Fatalf("workers = %d", n)
	}
	h.assertNoWorkerState(t, rec)
	launching := h.only(t, "session_launching")
	if str(t, launching, "session_id") != testSession || str(t, launching, "rule") != "design" || num(t, launching, "card") != 87 {
		t.Fatalf("session_launching = %v", launching)
	}
	if line := h.only(t, "session_launched"); str(t, line, "session_id") != testSession {
		t.Fatalf("session_launched = %v", line)
	}

	sent := rec.launches()
	if len(sent) != 1 {
		t.Fatalf("launches = %+v", sent)
	}
	got := sent[0]
	if got.handle != testProject || got.sessionID != testSession {
		t.Fatalf("launch = %+v", got)
	}
	r := got.report
	if r.State != api.RunRunning || r.FailureReason != "" || r.BridgeID != testBridgeID || r.CardID != cardUUID(87) || r.CardNumber != 87 || r.RuleName != "design" || r.At.IsZero() {
		t.Fatalf("report = %+v", r)
	}

	// The terminal runs the script, so a launch that worked leaves it.
	if got := h.scripts(t); !slices.Equal(got, []string{testSession + ".sh"}) {
		t.Fatalf("scripts = %v", got)
	}
	body, _ := os.ReadFile(filepath.Join(h.router.scriptDir, testSession+".sh"))
	want := "#!/bin/sh\nrm -f -- \"$0\"\ncd -- '" + h.dir + "' || exit 1\nexec '/usr/local/bin/claude' --session-id '" + testSession + "' --model 'opus' -- 'Design card 87.'\n"
	if string(body) != want {
		t.Fatalf("script = %q, want %q", body, want)
	}
}

// Each placeholder of the launch command takes its value.
func TestTheLaunchCommandTakesItsValues(t *testing.T) {
	out := filepath.Join(t.TempDir(), "argv")
	h, _ := launchHarness(t, `[sh, -c, 'printf "%s|" "$@" > "$0"', '`+out+`', '{script}', '{dir}', '{sessionId}', '{cardNumber}', '{project}']`)

	h.send(movedPayload(87, "backlog", "next", "human"))

	got, err := os.ReadFile(out)
	if err != nil {
		t.Fatal(err)
	}
	want := filepath.Join(h.router.scriptDir, testSession+".sh") + "|" + h.dir + "|" + testSession + "|87|loupe|"
	if string(got) != want {
		t.Fatalf("argv = %q, want %q", got, want)
	}
}

func TestAFailedLaunchReportsNotStarted(t *testing.T) {
	h, rec := launchHarness(t, `[sh, -c, 'echo no terminal >&2; exit 3', sh, '{script}']`)

	h.send(movedPayload(87, "backlog", "next", "human"))

	if line := h.only(t, "session_launch_failed"); str(t, line, "reason") != "exit code 3: no terminal" || str(t, line, "session_id") != testSession {
		t.Fatalf("session_launch_failed = %v", line)
	}
	if n := len(h.events(t, "session_launched")); n != 0 {
		t.Fatalf("session_launched lines = %d", n)
	}
	sent := rec.launches()
	if len(sent) != 1 || sent[0].report.State != api.RunNotStarted || sent[0].report.FailureReason != "exit code 3: no terminal" {
		t.Fatalf("launches = %+v", sent)
	}
	if got := h.scripts(t); len(got) != 0 {
		t.Fatalf("scripts = %v", got)
	}
	h.assertNoWorkerState(t, rec)
}

// A launcher still running at the timeout opened its terminal. The bridge
// stops waiting and never kills it.
func TestALaunchPastItsTimeoutLaunched(t *testing.T) {
	proof := filepath.Join(t.TempDir(), "alive")
	body := strings.Replace(launchRules, "{launcher}", `[sh, -c, 'sleep 0.3; echo ok > "$1"', sh, '`+proof+`', '{script}']`+"\n  timeout: 50ms", 1)
	h := newHarnessWith(t, body, rules.Defaults{})
	h.router.claude, h.router.scriptDir = "/usr/local/bin/claude", t.TempDir()
	rec := h.states()

	h.send(movedPayload(87, "backlog", "next", "human"))

	h.only(t, "session_launched")
	if sent := rec.launches(); len(sent) != 1 || sent[0].report.State != api.RunRunning {
		t.Fatalf("launches = %+v", sent)
	}
	waitForFile(t, proof)
}

// A launch holds no card and no slot, so a worker of the same card starts
// while the launch runs.
func TestAWorkerOfTheCardStartsWhileItsLaunchRuns(t *testing.T) {
	gate := filepath.Join(t.TempDir(), "gate")
	h, rec := launchHarness(t, `[sh, -c, 'while [ ! -e "$1" ]; do sleep 0.01; done', sh, '`+gate+`', '{script}']`)
	h.router.maxWorkers = 1
	h.worker.started = make(chan workerSpec, 1)

	h.router.onData([]byte(movedPayload(87, "backlog", "next", "human")))
	h.router.onData([]byte(movedPayload(87, "next", "review", "human")))
	<-h.worker.started

	if _, _, _, _, launches := h.router.inFlight(); launches != 1 {
		t.Fatalf("launches in flight = %d", launches)
	}
	if err := os.WriteFile(gate, nil, 0o600); err != nil {
		t.Fatal(err)
	}
	h.router.wg.Wait()

	if calls := h.worker.recorded(); len(calls) != 1 || !strings.HasPrefix(calls[0].prompt, "Review 87.") || calls[0].sessionID != sessionUUID(2) {
		t.Fatalf("calls = %+v", calls)
	}
	h.only(t, "session_launched")
	if got := rec.names(); !slices.Contains(got, "launch") || !slices.Contains(got, api.RunSucceeded) {
		t.Fatalf("reports = %v", got)
	}
}

// An event matched on the old set that reaches the queue after a reload made
// its rule interactive opens a session.
func TestAnEventMatchedBeforeTheSwapLaunchesOnTheNewSet(t *testing.T) {
	h := newHarness(t)
	h.router.claude, h.router.scriptDir = "/usr/local/bin/claude", t.TempDir()
	rec := h.states()
	old := h.router.rules()

	src := h.source(strings.Replace(strings.Replace(launchRules, "{launcher}", `[sh, -c, 'exit 0', sh, '{script}']`, 1), "name: design", "name: plan", 1))
	if res := h.router.reload(context.Background(), src); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	h.router.enqueue(stale(old, testProject, 88, "next", event.ActorHuman))
	h.router.wg.Wait()

	if n := h.runs(); n != 0 {
		t.Fatalf("workers = %d", n)
	}
	if line := h.only(t, "session_launched"); num(t, line, "card") != 88 || str(t, line, "rule") != "plan" {
		t.Fatalf("session_launched = %v", line)
	}
	if sent := rec.launches(); len(sent) != 1 || sent[0].report.CardNumber != 88 {
		t.Fatalf("launches = %+v", sent)
	}
}

// A queued worker event whose rule a reload made interactive runs no worker.
func TestAReloadDropsAQueuedEventWhoseRuleTurnsInteractive(t *testing.T) {
	h := busy(t, twoRuleFile)
	h.router.onData([]byte(cardMoved(88)))

	body := strings.Replace(strings.Replace(launchRules, "{launcher}", `[sh, -c, 'exit 0', sh, '{script}']`, 1), "name: design", "name: plan", 1)
	if res := h.reload(t, body); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	if got := h.queued(); len(got) != 0 {
		t.Fatalf("queue = %v", got)
	}
	if line := h.only(t, "queue_dropped"); !slices.Equal(dropped(t, line), []string{"88/plan"}) {
		t.Fatalf("queue_dropped = %v", line)
	}
	close(h.worker.block)
	h.router.wg.Wait()
}

func TestAShutRouterLaunchesNothing(t *testing.T) {
	h, rec := launchHarness(t, `[sh, -c, 'exit 0', sh, '{script}']`)
	h.router.shutdown()

	h.send(movedPayload(87, "backlog", "next", "human"))

	if n := len(h.events(t, "session_launching")); n != 0 {
		t.Fatalf("session_launching lines = %d", n)
	}
	if sent := rec.launches(); len(sent) != 0 {
		t.Fatalf("launches = %+v", sent)
	}
}

func TestAReloadWithAnInteractiveRuleAndNoClaudeFails(t *testing.T) {
	h := newHarness(t)
	src := h.source(strings.Replace(launchRules, "{launcher}", `[sh, -c, 'exit 0', sh, '{script}']`, 1))
	src.resolveClaude = func() (string, error) { return "", errors.New("claude is not installed or not on PATH") }
	old := h.router.rules()

	res := h.router.reload(context.Background(), src)
	if res.OK || res.Stage != "claude" || !slices.Equal(res.Problems, []string{"claude is not installed or not on PATH"}) {
		t.Fatalf("result = %+v", res)
	}
	if h.router.rules() != old {
		t.Fatal("the failed reload swapped the set")
	}
}

func TestAReloadTakesTheClaudePathOfAnInteractiveSet(t *testing.T) {
	h := newHarness(t)
	h.router.claude = "/old/claude"
	src := h.source(defaultRules)
	src.resolveClaude = func() (string, error) { return "", errors.New("not called") }
	if res := h.router.reload(context.Background(), src); !res.OK {
		t.Fatalf("a set with no interactive rule needs no claude path: %+v", res)
	}

	src = h.source(strings.Replace(launchRules, "{launcher}", `[sh, -c, 'exit 0', sh, '{script}']`, 1))
	src.resolveClaude = func() (string, error) { return "/new/claude", nil }
	if res := h.router.reload(context.Background(), src); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	h.router.mu.Lock()
	defer h.router.mu.Unlock()
	if h.router.claude != "/new/claude" {
		t.Fatalf("claude = %q", h.router.claude)
	}
}

// A server with no interactive run endpoint gets no launch report, and says so
// once.
func TestALaunchReportToAnOldServerCountsAsDelivered(t *testing.T) {
	client := &fakeRunClient{launch: func() (bool, error) { return false, api.ErrInteractiveRunsUnsupported }}
	reports, log := newTestRunReports(client)
	report := api.InteractiveLaunchReport{CardNumber: 87, RuleName: "design", State: api.RunRunning}

	for range 2 {
		if ok, err := reports.launch(testProject, testSession, report).Send(context.Background()); !ok || err != nil {
			t.Fatalf("Send = %v, %v", ok, err)
		}
	}
	if n := len(client.launches); n != 1 {
		t.Fatalf("calls = %d", n)
	}
	if n := countEvents(t, log, "interactive_runs_unsupported"); n != 1 {
		t.Fatalf("interactive_runs_unsupported lines = %d", n)
	}

	client = &fakeRunClient{launch: func() (bool, error) { return false, errors.New("HTTP 503") }}
	reports, _ = newTestRunReports(client)
	if _, err := reports.launch(testProject, testSession, report).Send(context.Background()); err == nil {
		t.Fatal("a failed send was delivered")
	}
}
