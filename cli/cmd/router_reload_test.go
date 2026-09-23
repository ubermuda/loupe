package cmd

import (
	"context"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// reloadEvents lists both projects that boardColumns knows.
var reloadEvents = api.Events{Projects: []api.EventsProject{{ID: testProject, Slug: "loupe"}, {ID: otherProject, Slug: "other"}}}

// source reads body as the new rule file, with {dir} as the harness directory,
// and checks it against boardColumns.
func (h *harness) source(body string) reloadSource {
	return reloadSource{
		load: func() (*rules.Set, error) {
			return rules.Parse([]byte(strings.ReplaceAll(body, "{dir}", h.dir)), rules.Defaults{})
		},
		check:  func(ctx context.Context, set *rules.Set) error { return set.Check(ctx, boardColumns{}) },
		events: func(context.Context) (api.Events, error) { return reloadEvents, nil },
	}
}

// blocked holds the check of src until the test closes release.
func blocked(src reloadSource) (reloadSource, chan struct{}, chan struct{}) {
	entered, release := make(chan struct{}), make(chan struct{})
	check := src.check
	src.check = func(ctx context.Context, set *rules.Set) error {
		close(entered)
		<-release

		return check(ctx, set)
	}

	return src, entered, release
}

func (h *harness) reload(t *testing.T, body string) reloadResult {
	t.Helper()

	return h.router.reload(context.Background(), h.source(body))
}

// queued names the waiting events as "card/rule".
func (h *harness) queued() []string {
	h.router.mu.Lock()
	defer h.router.mu.Unlock()
	var out []string
	for _, p := range h.router.queue {
		out = append(out, strings.TrimPrefix(p.key, "0192f3a1-9999-7d3e-8f10-00000000")+"/"+p.rule)
	}

	return out
}

// twoRuleFile has a rule on next and a rule on review, both of loupe.
const twoRuleFile = defaultRules + `
  - name: review
    on: board.card_moved
    project: loupe
    to: review
    prompt: Review {cardNumber}.
`

// twoProjectFile maps loupe and other, with a rule on next in each.
const twoProjectFile = `
projects:
  loupe:
    dir: {dir}
  other:
    dir: {dir}
rules:
  - name: plan
    on: board.card_moved
    project: loupe
    to: next
    prompt: Card {cardNumber} ({cardId}) entered {to}.
  - name: other-plan
    on: board.card_moved
    project: other
    to: next
    prompt: Other {cardNumber}.
`

func TestAReloadThatFailsToParseKeepsTheSet(t *testing.T) {
	h := newHarness(t)
	old := h.router.rules()

	res := h.reload(t, "projects: {}\nrules: []\n")

	if res.OK || res.Stage != "parse" || len(res.Problems) != 2 {
		t.Fatalf("result = %+v, want a parse failure with two problems", res)
	}
	if h.router.rules() != old {
		t.Fatal("a failed reload swapped the set")
	}
	line := h.only(t, "reload_failed")
	if str(t, line, "stage") != "parse" || len(line["problems"].([]any)) != 2 {
		t.Fatalf("reload_failed = %v", line)
	}
	if len(h.events(t, "reload_applied")) != 0 {
		t.Fatal("a failed reload logged reload_applied")
	}
}

// A missing file is one problem, with the example the bridge prints at start.
func TestAReloadOfAMissingFileIsOneProblem(t *testing.T) {
	h := newHarness(t)

	res := h.router.reload(context.Background(), newReloadSource(filepath.Join(t.TempDir(), "rules.yaml"), rules.Defaults{}, config.Config{}, nil))

	if res.OK || res.Stage != "parse" || len(res.Problems) != 1 || !strings.Contains(res.Problems[0], "does not exist") {
		t.Fatalf("result = %+v", res)
	}
}

func TestAReloadThatFailsTheCheckKeepsTheSet(t *testing.T) {
	h := newHarness(t)
	old := h.router.rules()

	res := h.reload(t, strings.Replace(defaultRules, "to: next", "to: ready", 1))

	if res.OK || res.Stage != "check" || len(res.Problems) != 1 || !strings.Contains(res.Problems[0], `"ready"`) {
		t.Fatalf("result = %+v", res)
	}
	if h.router.rules() != old || str(t, h.only(t, "reload_failed"), "stage") != "check" {
		t.Fatal("a failed check swapped the set or logged no failure")
	}
}

// A project the server checks but GET /api/events does not list can send no
// event, so the reload fails at the server stage.
func TestAReloadOfAProjectTheStreamMissesFails(t *testing.T) {
	h := newHarness(t)
	old := h.router.rules()
	src := h.source(twoProjectFile)
	src.events = func(context.Context) (api.Events, error) {
		return api.Events{Projects: reloadEvents.Projects[:1]}, nil
	}

	res := h.router.reload(context.Background(), src)

	if res.OK || res.Stage != "server" || len(res.Problems) != 1 || !strings.Contains(res.Problems[0], "other") {
		t.Fatalf("result = %+v", res)
	}
	if h.router.rules() != old {
		t.Fatal("a failed reload swapped the set")
	}
}

// A check that never answers fails the reload before the client gives up.
func TestAReloadThatOutlastsItsTimeoutKeepsTheSet(t *testing.T) {
	h := newHarness(t)
	h.router.buildTimeout = 20 * time.Millisecond
	old := h.router.rules()
	src := h.source(twoRuleFile)
	src.check = func(ctx context.Context, _ *rules.Set) error {
		<-ctx.Done()

		return ctx.Err()
	}

	res := h.router.reload(context.Background(), src)

	if res.OK || res.Stage != "check" || len(res.Problems) != 1 || !strings.Contains(res.Problems[0], "deadline") {
		t.Fatalf("result = %+v", res)
	}
	if h.router.rules() != old || str(t, h.only(t, "reload_failed"), "stage") != "check" {
		t.Fatal("a reload that timed out swapped the set or logged no failure")
	}
	h.router.mu.Lock()
	defer h.router.mu.Unlock()
	if h.router.reloading {
		t.Fatal("the router still reloads")
	}
}

func TestASecondReloadIsRefusedWhileOneRuns(t *testing.T) {
	h := newHarness(t)
	src, entered, release := blocked(h.source(twoRuleFile))

	done := make(chan reloadResult)
	go func() { done <- h.router.reload(context.Background(), src) }()
	<-entered

	res := h.reload(t, twoRuleFile)
	if res.OK || !slices.Equal(res.Problems, []string{"a reload is already running"}) {
		t.Fatalf("second result = %+v", res)
	}
	close(release)
	if first := <-done; !first.OK {
		t.Fatalf("first result = %+v", first)
	}
}

func TestAShutRouterRefusesAReload(t *testing.T) {
	h := newHarness(t)
	old := h.router.rules()
	h.router.shutdown()

	res := h.reload(t, twoRuleFile)

	if res.OK || len(res.Problems) != 1 || !strings.Contains(res.Problems[0], "shutting down") || h.router.rules() != old {
		t.Fatalf("result = %+v", res)
	}
}

// busy holds card 87 in the one worker slot, so later events wait.
func busy(t *testing.T, body string) *harness {
	t.Helper()
	h := newHarnessWith(t, body, rules.Defaults{})
	h.router.maxWorkers = 1
	h.worker.started = make(chan workerSpec, 4)
	h.worker.block = make(chan struct{})
	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started

	return h
}

func TestAQueuedEventWhoseRuleStillMatchesKeepsItsPlaceWithTheNewPrompt(t *testing.T) {
	h := busy(t, twoRuleFile)
	h.router.onData([]byte(cardMoved(88)))
	h.router.onData([]byte(movedPayload(89, "backlog", "review", "human")))

	res := h.reload(t, strings.Replace(twoRuleFile, "prompt: Card {cardNumber} ({cardId}) entered {to}.", "prompt: New {cardNumber}.", 1))
	if !res.OK {
		t.Fatalf("result = %+v", res)
	}
	if got := h.queued(); !slices.Equal(got, []string{"0088/plan", "0089/review"}) {
		t.Fatalf("queue = %v", got)
	}
	close(h.worker.block)
	h.router.wg.Wait()

	calls := h.worker.recorded()
	if len(calls) != 3 || !strings.HasPrefix(calls[1].prompt, "New 88.") || !strings.HasPrefix(calls[2].prompt, "Review 89.") {
		t.Fatalf("workers = %+v", calls)
	}
	if len(h.events(t, "queue_dropped")) != 0 {
		t.Fatal("a kept event was dropped")
	}
}

func TestAReloadDropsAQueuedEventItsRuleNoLongerRuns(t *testing.T) {
	h := busy(t, twoRuleFile)
	h.router.onData([]byte(cardMoved(88)))
	h.router.onData([]byte(movedPayload(89, "backlog", "review", "human")))

	// plan is gone, and review now fires on done.
	res := h.reload(t, `
projects:
  loupe:
    dir: {dir}
rules:
  - name: review
    on: board.card_moved
    project: loupe
    to: done
    prompt: Review {cardNumber}.
`)
	if !res.OK {
		t.Fatalf("result = %+v", res)
	}
	line := h.only(t, "queue_dropped")
	if got := dropped(t, line); !slices.Equal(got, []string{"88/plan", "89/review"}) || line["reason"] != "reload" {
		t.Fatalf("queue_dropped = %v", line)
	}
	close(h.worker.block)
	h.router.wg.Wait()
	if n := h.runs(); n != 1 {
		t.Fatalf("%d workers, want the first alone", n)
	}
}

func TestAReloadThatDropsACheckedResumeFreesItsCard(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	h.router.maxWorkers = 1
	c := &checks{state: api.AskState{AskID: testAsk, Closed: true, AllRead: false}}
	h.router.checkAsk = c.check
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(88)))
	<-h.worker.started
	h.router.onData([]byte(h.mine(ask{card: 87})))
	eventually(t, "the checked resume in the queue", func() bool {
		h.router.mu.Lock()
		defer h.router.mu.Unlock()

		return len(h.router.queue) == 1 && h.router.queue[0].checked
	})

	if res := h.reload(t, defaultRules); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	if got := dropped(t, h.only(t, "queue_dropped")); !slices.Equal(got, []string{"87/resume"}) {
		t.Fatalf("dropped = %v", got)
	}
	if h.cardHeld(87) {
		t.Fatal("the dropped resume still holds card 87")
	}
	close(h.worker.block)
	h.router.wg.Wait()
}

// A resume sits outside the queue during its ask check. The check matches it
// again, so a resume whose rule the reload removed starts nothing.
func TestAResumeInItsCheckWhoseRuleIsRemovedStartsNothing(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	g := newGate()
	h.router.checkAsk = g.check

	h.router.onData([]byte(h.mine(ask{card: 87})))
	<-g.entered
	if res := h.reload(t, defaultRules); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	close(g.release)
	h.router.wg.Wait()

	if n := h.runs(); n != 0 {
		t.Fatalf("%d workers, want none", n)
	}
	line := h.only(t, "queue_dropped")
	if got := dropped(t, line); !slices.Equal(got, []string{"87/resume"}) || line["reason"] != "reload" {
		t.Fatalf("queue_dropped = %v", line)
	}
	if h.cardHeld(87) {
		t.Fatal("the dropped resume still holds card 87")
	}
}

// A kill after a reload during the check drops the resume with no reason, as
// any kill does.
func TestAResumeWhoseRuleAKillEndsAfterAReloadHasNoReason(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	g := newGate()
	h.router.checkAsk = g.check

	h.router.onData([]byte(h.mine(ask{card: 87})))
	<-g.entered
	if res := h.reload(t, strings.Replace(resumeRules, "prompt: Ask {askId} closed on card {cardNumber}.", "prompt: Again {askId}.", 1)); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	h.router.onData([]byte(projectRenamed()))
	close(g.release)
	h.router.wg.Wait()

	line := h.only(t, "queue_dropped")
	if got := dropped(t, line); !slices.Equal(got, []string{"87/resume"}) || line["reason"] != nil {
		t.Fatalf("queue_dropped = %v, want 87/resume with no reason", line)
	}
}

// A resume in its check takes the new prompt of a rule the reload changed.
func TestAResumeInItsCheckTakesTheNewPrompt(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	g := newGate()
	h.router.checkAsk = g.check

	h.router.onData([]byte(h.mine(ask{card: 87})))
	<-g.entered
	if res := h.reload(t, strings.Replace(resumeRules, "prompt: Ask {askId} closed on card {cardNumber}.", "prompt: Again {askId}.", 1)); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	close(g.release)
	h.router.wg.Wait()

	calls := h.worker.recorded()
	if len(calls) != 1 || !strings.HasPrefix(calls[0].prompt, "Again "+testAsk+".") || !calls[0].resume || calls[0].sessionID != askSession {
		t.Fatalf("workers = %+v", calls)
	}
}

func TestAReloadPrunesTheChainsOfRemovedRules(t *testing.T) {
	h := newHarnessWith(t, twoRuleFile, rules.Defaults{})
	h.router.mu.Lock()
	h.router.chains = map[string]map[string]int{cardUUID(87): {"plan": 2, "review": 1}}
	h.router.mu.Unlock()

	if res := h.reload(t, defaultRules); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	h.router.mu.Lock()
	defer h.router.mu.Unlock()
	if got := h.router.chains[cardUUID(87)]; len(got) != 1 || got["plan"] != 2 {
		t.Fatalf("chains = %v", got)
	}
}

// A column rename can land while the reload checks the new file against the
// board. The old set does not map the project, and the new set learns of the
// rename at the swap.
func TestASlugChangeDuringTheCheckKillsTheRuleOfTheNewSet(t *testing.T) {
	h := newHarness(t)
	src, entered, release := blocked(h.source(twoProjectFile))

	done := make(chan reloadResult)
	go func() { done <- h.router.reload(context.Background(), src) }()
	<-entered
	h.router.onData([]byte(strings.Replace(slugPayload(event.ColumnRenamedType, `"fromSlug":"next","toSlug":"ready"`), testProject, otherProject, 1)))
	close(release)
	if res := <-done; !res.OK {
		t.Fatalf("result = %+v", res)
	}

	line := h.only(t, "rule_dead")
	if str(t, line, "rule") != "other-plan" || str(t, line, "project") != otherProject || !strings.Contains(str(t, line, "message"), "run loupe bridge reload") {
		t.Fatalf("rule_dead = %v", line)
	}
	h.send(strings.Replace(cardMoved(88), testProject, otherProject, 1))
	if n := h.runs(); n != 0 {
		t.Fatalf("the dead rule started %d workers", n)
	}
	h.send(cardMoved(89))
	if n := h.runs(); n != 1 {
		t.Fatalf("the live rule started %d workers, want 1", n)
	}
}

// ruleReports records each health report by project id.
type ruleReports struct {
	mu    sync.Mutex
	calls map[string][][]api.RuleHealth
}

func (f *ruleReports) ReportRules(_ context.Context, handle, _ string, rules []api.RuleHealth) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.calls == nil {
		f.calls = map[string][][]api.RuleHealth{}
	}
	f.calls[handle] = append(f.calls[handle], rules)

	return nil
}

func (f *ruleReports) last(handle string) ([]api.RuleHealth, bool) {
	f.mu.Lock()
	defer f.mu.Unlock()
	c := f.calls[handle]
	if len(c) == 0 {
		return nil, false
	}

	return c[len(c)-1], true
}

func TestAProjectTheReloadDropsGetsAnEmptyReport(t *testing.T) {
	h := newHarnessWith(t, twoProjectFile, rules.Defaults{})
	f := &ruleReports{}
	ctx, cancel := context.WithCancel(context.Background())
	h.router.health = newHealthReporter(ctx, f, testBridgeID, h.router.log)
	t.Cleanup(func() {
		cancel()
		h.router.health.wait()
	})

	if res := h.reload(t, defaultRules); !res.OK {
		t.Fatalf("result = %+v", res)
	}

	eventually(t, "the empty report of other", func() bool {
		got, ok := f.last(otherProject)

		return ok && got != nil && len(got) == 0
	})
	eventually(t, "the report of loupe", func() bool {
		got, ok := f.last(testProject)

		return ok && len(got) == 1 && got[0].Name == "plan"
	})
}

func TestReloadAppliedNamesWhatChanged(t *testing.T) {
	h := newHarnessWith(t, twoRuleFile+`
  - name: extra
    on: board.card_moved
    project: loupe
    to: done
    prompt: Extra.
`, rules.Defaults{})

	// plan sets the default maxChain by hand, so it is the same rule.
	res := h.reload(t, `
projects:
  loupe:
    dir: {dir}
rules:
  - name: plan
    on: board.card_moved
    project: loupe
    to: next
    maxChain: 3
    prompt: Card {cardNumber} ({cardId}) entered {to}.
  - name: review
    on: board.card_moved
    project: loupe
    to: review
    prompt: Look at {cardNumber}.
  - name: ship
    on: board.card_moved
    project: loupe
    to: done
    prompt: Ship.
`)
	if !res.OK || !slices.Equal(res.Added, []string{"ship"}) || !slices.Equal(res.Removed, []string{"extra"}) || !slices.Equal(res.Changed, []string{"review"}) || !slices.Equal(res.Projects, []string{"loupe"}) || len(res.Dirs) != 0 {
		t.Fatalf("result = %+v", res)
	}
	line := h.only(t, "reload_applied")
	for key, want := range map[string]string{"added": "ship", "removed": "extra", "changed": "review", "projects": "loupe"} {
		if got, _ := line[key].([]any); len(got) != 1 || got[0] != want {
			t.Fatalf("reload_applied %s = %v, want [%s]", key, line[key], want)
		}
	}
}

func TestReloadAppliedNamesAProjectWhoseDirChanged(t *testing.T) {
	h := newHarnessWith(t, twoProjectFile, rules.Defaults{})

	res := h.reload(t, strings.Replace(twoProjectFile, "  other:\n    dir: {dir}", "  other:\n    dir: "+t.TempDir(), 1))
	if !res.OK || !slices.Equal(res.Dirs, []string{"other"}) || len(res.Added)+len(res.Removed)+len(res.Changed) != 0 {
		t.Fatalf("result = %+v", res)
	}
	if got, _ := h.only(t, "reload_applied")["dirs"].([]any); len(got) != 1 || got[0] != "other" {
		t.Fatalf("reload_applied dirs = %v", got)
	}
}

func TestAReloadWarnsOfAnUnknownPermissionMode(t *testing.T) {
	h := newHarness(t)

	if res := h.reload(t, strings.Replace(defaultRules, "    to: next\n", "    to: next\n    permissionMode: yolo\n", 1)); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	if line := h.only(t, "permission_mode_unknown"); str(t, line, "mode") != "yolo" {
		t.Fatalf("permission_mode_unknown = %v", line)
	}
}

func TestAReloadSendsTheNewHeartbeatBody(t *testing.T) {
	h := newHarness(t)
	client := &fakeHeartbeats{}
	hh := startHeartbeater(t, client, time.Minute)
	h.router.heartbeat = hh.h

	if res := h.reload(t, twoProjectFile); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	eventually(t, "the heartbeat of the new body", func() bool {
		client.mu.Lock()
		defer client.mu.Unlock()

		return len(client.sent) == 2 && slices.Equal(client.sent[1].Projects, []string{testProject, otherProject})
	})
}

// stale is a move of the card into to, matched on old before a reload.
func stale(old *rules.Set, project string, number int, to, actor string) pending {
	e := event.Event{Type: event.CardMovedType, Subject: event.Subject{Type: "card", ID: cardUUID(number)}, ProjectID: project, CardNumber: number, FromStatus: "backlog", ToStatus: to, Actor: actor}
	p := pending{key: cardUUID(number), event: e, set: old}
	p.apply(old.Match(e))

	return p
}

// An event matched on the old set that reaches the queue after the swap
// matches again on the new set.
func TestAnEventMatchedBeforeTheSwapMatchesTheNewSet(t *testing.T) {
	h := busy(t, twoRuleFile)
	old := h.router.rules()

	if res := h.reload(t, strings.Replace(defaultRules, "prompt: Card {cardNumber} ({cardId}) entered {to}.", "prompt: New {cardNumber}.", 1)); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	h.router.enqueue(stale(old, testProject, 88, "next", event.ActorHuman))
	h.router.enqueue(stale(old, testProject, 89, "review", event.ActorHuman))

	if got := h.queued(); !slices.Equal(got, []string{"0088/plan"}) {
		t.Fatalf("queue = %v", got)
	}
	h.router.mu.Lock()
	prompt := h.router.queue[0].spec.prompt
	h.router.mu.Unlock()
	if !strings.HasPrefix(prompt, "New 88.") {
		t.Fatalf("prompt = %q", prompt)
	}
	close(h.worker.block)
	h.router.wg.Wait()
}

// A reviewer's event that the old set let through, and the new set does not,
// logs event_untrusted as a fresh event does.
func TestAnEventTheNewSetDoesNotTrustIsLogged(t *testing.T) {
	h := newHarnessWith(t, strings.Replace(defaultRules, "    to: next\n", "    to: next\n    allowUntrusted: true\n", 1), rules.Defaults{})
	old := h.router.rules()

	if res := h.reload(t, defaultRules); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	h.router.enqueue(stale(old, testProject, 88, "next", event.ActorReviewer))

	if got := h.queued(); len(got) != 0 {
		t.Fatalf("queue = %v", got)
	}
	if line := h.only(t, "event_untrusted"); str(t, line, "rule") != "plan" || num(t, line, "card") != 88 {
		t.Fatalf("event_untrusted = %v", line)
	}
}

// An event of a project the new set no longer maps logs project_unmapped
// once, as a fresh event does.
func TestAnEventOfAProjectTheNewSetDropsIsLoggedOnce(t *testing.T) {
	h := newHarnessWith(t, twoProjectFile, rules.Defaults{})
	old := h.router.rules()

	if res := h.reload(t, defaultRules); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	h.router.enqueue(stale(old, otherProject, 88, "next", event.ActorHuman))
	h.router.enqueue(stale(old, otherProject, 89, "next", event.ActorHuman))

	if got := h.queued(); len(got) != 0 {
		t.Fatalf("queue = %v", got)
	}
	if line := h.only(t, "project_unmapped"); str(t, line, "project") != otherProject {
		t.Fatalf("project_unmapped = %v", line)
	}
}

// A reload clears the unmapped mark of a project it now maps, so a later loss
// of the mapping is logged again.
func TestAReloadForgetsTheUnmappedMarkOfAProjectItMaps(t *testing.T) {
	h := newHarness(t)
	h.send(strings.Replace(cardMoved(87), testProject, otherProject, 1))
	h.only(t, "project_unmapped")

	if res := h.reload(t, twoProjectFile); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	h.router.mu.Lock()
	defer h.router.mu.Unlock()
	if h.router.unmapped[otherProject] {
		t.Fatal("the reload kept the unmapped mark of other")
	}
}

// held holds src after its events answer and before the swap, until the test
// closes release.
func held(src reloadSource) (reloadSource, chan struct{}, chan struct{}) {
	entered, release := make(chan struct{}), make(chan struct{})
	src.lock = func() (func() error, func(bool), error) {
		return func() error {
			close(entered)
			<-release

			return nil
		}, func(bool) {}, nil
	}

	return src, entered, release
}

// A refresh answer newer than the reload's can find a project gone before the
// swap. The new set learns of it at the swap, and a later refresh does not log
// it again.
func TestANewerGoneAnswerIsReplayedOnTheNewSet(t *testing.T) {
	h := newHarness(t)
	src, entered, release := held(h.source(defaultRules))

	done := make(chan reloadResult)
	go func() { done <- h.router.reload(context.Background(), src) }()
	<-entered
	h.router.onRefresh(api.Events{})
	close(release)
	if res := <-done; !res.OK {
		t.Fatalf("result = %+v", res)
	}

	if h.router.rules().Live("plan") {
		t.Fatal("the new set keeps plan live for a gone project")
	}
	for _, line := range h.events(t, "rule_dead") {
		if str(t, line, "rule") != "plan" || str(t, line, "project") != testProject || str(t, line, "reason") != api.ReasonProjectGone {
			t.Fatalf("rule_dead = %v", line)
		}
	}
	if n := len(h.events(t, "rule_dead")); n != 2 {
		t.Fatalf("%d rule_dead lines, want one for each set", n)
	}
	h.send(cardMoved(88))
	if n := h.runs(); n != 0 {
		t.Fatalf("a gone project started %d workers", n)
	}
	h.router.onRefresh(api.Events{})
	h.only(t, "project_gone")
}

// A refresh during the check has an answer older than the reload's, which
// lists the project. The swap does not replay it, and a later refresh that
// finds the project gone is news again.
func TestAnOlderGoneAnswerDuringTheCheckIsNotReplayed(t *testing.T) {
	h := newHarness(t)
	src, entered, release := blocked(h.source(defaultRules))

	done := make(chan reloadResult)
	go func() { done <- h.router.reload(context.Background(), src) }()
	<-entered
	h.router.onRefresh(api.Events{})
	close(release)
	if res := <-done; !res.OK {
		t.Fatalf("result = %+v", res)
	}

	if !h.router.rules().Live("plan") {
		t.Fatal("an older answer killed the rule of the new set")
	}
	h.send(cardMoved(88))
	if n := h.runs(); n != 1 {
		t.Fatalf("the live rule started %d workers, want 1", n)
	}
	h.router.onRefresh(api.Events{})
	if n := len(h.events(t, "project_gone")); n != 2 {
		t.Fatalf("%d project_gone lines, want one for each answer", n)
	}
}

// A refresh answer that arrived before the reload's, and is handled after the
// swap, kills nothing.
func TestAnOlderGoneAnswerAfterTheSwapKillsNothing(t *testing.T) {
	h := newHarness(t)
	h.router.mu.Lock()
	seq := h.router.stampLocked()
	h.router.mu.Unlock()

	if res := h.reload(t, defaultRules); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	h.router.refreshGone(api.Events{}, seq)

	if !h.router.rules().Live("plan") {
		t.Fatal("an older answer killed the rule of the new set")
	}
	if n := len(h.events(t, "project_gone")) + len(h.events(t, "rule_dead")); n != 0 {
		t.Fatalf("%d gone lines, want none", n)
	}
}

// recreatedProject is the id of a project deleted and created again with the
// slug loupe.
const recreatedProject = "0192f3a1-4b2c-7d3e-8f10-000000000003"

// recreated answers the check as boardColumns does, with a new id for loupe.
type recreated struct{ boardColumns }

func (c recreated) Columns(ctx context.Context, handle string) (api.ProjectColumns, error) {
	pc, err := c.boardColumns.Columns(ctx, handle)
	if handle == "loupe" {
		pc.Project.ID = recreatedProject
	}

	return pc, err
}

// A gone decision names a project id. A project created again with the same
// slug has a new id, so the kill leaves its rules live.
func TestAGoneProjectDoesNotKillAProjectWithItsSlugAndANewID(t *testing.T) {
	h := newHarness(t)
	src := h.source(defaultRules)
	src.check = func(ctx context.Context, set *rules.Set) error { return set.Check(ctx, recreated{}) }
	src.events = func(context.Context) (api.Events, error) {
		return api.Events{Projects: []api.EventsProject{{ID: recreatedProject, Slug: "loupe"}}}, nil
	}
	src, entered, release := held(src)

	done := make(chan reloadResult)
	go func() { done <- h.router.reload(context.Background(), src) }()
	<-entered
	h.router.onRefresh(api.Events{Projects: []api.EventsProject{{ID: recreatedProject, Slug: "loupe"}}})
	close(release)
	if res := <-done; !res.OK {
		t.Fatalf("result = %+v", res)
	}

	if !h.router.rules().Live("plan") {
		t.Fatal("the gone old project killed the rule of the new one")
	}
	if n := len(h.events(t, "rule_dead")); n != 1 {
		t.Fatalf("%d rule_dead lines, want the old set's alone", n)
	}
	h.send(strings.Replace(cardMoved(88), testProject, recreatedProject, 1))
	if n := h.runs(); n != 1 {
		t.Fatalf("the new project started %d workers, want 1", n)
	}

	// The gone mark names the old id, so the new project going too is news.
	h.router.onRefresh(api.Events{})
	if h.router.rules().Live("plan") {
		t.Fatal("the new project went, and its rule stayed live")
	}
}
