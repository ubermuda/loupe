package cmd

import (
	"context"
	"errors"
	"path/filepath"
	"slices"
	"strings"
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

// twoRuleFile runs plan and review work, both of loupe.
const twoRuleFile = defaultRules + `
  review:
    prompt: Review {cardNumber}.
`

// twoProjectFile maps loupe and other, with a rule on next in each.
const twoProjectFile = `
accounts:
  claude:
    harness: claude-code
defaults:
  account: claude
projects:
  loupe:
    dir: {dir}
  other:
    dir: {dir}
work:
  plan:
    prompt: Card {cardNumber} ({cardId}) entered next.
`

func TestAReloadThatFailsToParseKeepsTheSet(t *testing.T) {
	h := newHarness(t)
	old := h.router.rules()

	res := h.reload(t, "projects: {}\nwork:\n  plan: {}\n")

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

	res := h.reload(t, strings.Replace(defaultRules, "  loupe:\n", "  ready:\n", 1))

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

// The account checks of a reload get their own time, so a slow build does not
// turn off a healthy account, and the bound keeps the answer in time.
func TestTheAccountChecksOfAReloadHaveTheirOwnTime(t *testing.T) {
	h := newHarness(t)
	h.router.buildTimeout = time.Hour
	src := h.source(defaultRules)
	var left time.Duration
	src.checkAccounts = func(ctx context.Context, _ *rules.Set) []accountResult {
		if d, ok := ctx.Deadline(); ok {
			left = time.Until(d)
		}

		return nil
	}

	if res := h.router.reload(context.Background(), src); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	if left <= reloadAccountsTimeout-5*time.Second || left > reloadAccountsTimeout {
		t.Fatalf("the checks had %s, want %s of their own", left, reloadAccountsTimeout)
	}
	if reloadBuildTimeout+reloadAccountsTimeout >= reloadTimeout {
		t.Fatalf("build %s and checks %s do not answer before %s", reloadBuildTimeout, reloadAccountsTimeout, reloadTimeout)
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
	h := newHarnessWith(t, withMaxWorkers(body, 1), rules.Defaults{})
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

	res := h.reload(t, withMaxWorkers(strings.Replace(twoRuleFile, "prompt: Card {cardNumber} ({cardId}) entered next.", "prompt: New {cardNumber}.", 1), 1))
	if !res.OK {
		t.Fatalf("result = %+v", res)
	}
	if got := h.queued(); !slices.Equal(got, []string{"0088/work:plan", "0089/work:review"}) {
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

	// plan is gone, and review now runs a command.
	res := h.reload(t, `
accounts:
  claude:
    harness: claude-code
defaults:
  account: claude
projects:
  loupe:
    dir: {dir}
work:
  review:
    action: command
    run: [review, '{cardNumber}']
`)
	if !res.OK {
		t.Fatalf("result = %+v", res)
	}
	line := h.only(t, "queue_dropped")
	if got := dropped(t, line); !slices.Equal(got, []string{"88/work:plan", "89/work:review"}) || line["reason"] != "reload" {
		t.Fatalf("queue_dropped = %v", line)
	}
	close(h.worker.block)
	h.router.wg.Wait()
	if n := h.runs(); n != 1 {
		t.Fatalf("%d workers, want the first alone", n)
	}
}

func TestReloadAppliedNamesWhatChanged(t *testing.T) {
	h := newHarnessWith(t, twoRuleFile+`
  extra:
    prompt: Extra.
`, rules.Defaults{})

	// plan keeps its entry, so it is the same.
	res := h.reload(t, `
accounts:
  claude:
    harness: claude-code
defaults:
  account: claude
projects:
  loupe:
    dir: {dir}
work:
  plan:
    prompt: Card {cardNumber} ({cardId}) entered next.
  review:
    prompt: Look at {cardNumber}.
  ship:
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

	if res := h.reload(t, strings.Replace(defaultRules, "harness: claude-code\n", "harness: claude-code\n    permissionMode: yolo\n", 1)); !res.OK {
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

// withFlags makes src answer with the projects of reloadEvents and flags.
func withFlags(src reloadSource, flags map[string]any) reloadSource {
	events := reloadEvents
	events.Flags = flags
	src.events = func(context.Context) (api.Events, error) { return events, nil }

	return src
}

func TestAReloadAppliesTheHostSamplingFlag(t *testing.T) {
	sh := startSamplerHarness(t, defaultRules)
	h := &harness{router: sh.r, dir: t.TempDir()}

	res := h.router.reload(context.Background(), withFlags(h.source(defaultRules), samplingFlags(true, 30).Flags))

	if !res.OK {
		t.Fatalf("reload = %+v", res)
	}
	if sh.running() == nil {
		t.Fatal("the sampler does not run after a reload turned the flag on")
	}
}

func TestAReloadAppliesTheHeartbeatInterval(t *testing.T) {
	h := newHarness(t)
	hh := startHeartbeater(t, &fakeHeartbeats{}, time.Minute)
	h.router.heartbeat = hh.h

	res := h.router.reload(context.Background(), withFlags(h.source(defaultRules), map[string]any{api.HeartbeatIntervalFlag: float64(15)}))

	if !res.OK {
		t.Fatalf("reload = %+v", res)
	}
	hh.h.mu.Lock()
	defer hh.h.mu.Unlock()
	if hh.h.interval != 15*time.Second {
		t.Fatalf("interval = %s", hh.h.interval)
	}
}

func TestAWorkerTheReloadStartsReadsTheNewFlags(t *testing.T) {
	h := busy(t, twoRuleFile)
	h.router.onData([]byte(cardMoved(88)))

	res := h.router.reload(context.Background(), withFlags(h.source(withMaxWorkers(twoRuleFile, 2)), map[string]any{api.InboxFlag: true}))

	if !res.OK {
		t.Fatalf("reload = %+v", res)
	}
	spec := <-h.worker.started
	if !strings.Contains(spec.prompt, "Pass both to inbox_ask.") {
		t.Fatalf("prompt = %q, want the inbox line", spec.prompt)
	}
}

func TestAFailedEventsReadKeepsTheFlags(t *testing.T) {
	h := newHarness(t)
	h.router.applyFlags(api.Events{Flags: map[string]any{api.InboxFlag: true, api.HostSamplingFlag: true}})
	src := h.source(defaultRules)
	src.events = func(context.Context) (api.Events, error) { return api.Events{}, errors.New("server down") }

	res := h.router.reload(context.Background(), src)

	if res.OK || res.Stage != "server" {
		t.Fatalf("result = %+v", res)
	}
	h.router.mu.Lock()
	defer h.router.mu.Unlock()
	if !h.router.inbox || !h.router.hostSampling {
		t.Fatal("a failed events read changed the flags")
	}
}

// stale is an offer of the kind for the card, matched on old before a
// reload.
func stale(old *rules.Set, project string, number int, kind, _ string) pending {
	n := int(offerSeq.Add(1))
	w := api.WorkRequest{
		Type: event.WorkRequestType, ProjectID: project, Subject: api.WorkRequestSubject{Type: "work-request", ID: offerID(n)},
		WorkRequestID: offerID(n), Kind: kind, State: api.WorkRequestOpen, SubjectType: api.SubjectCard, SubjectID: cardUUID(number), CardNumber: number, RuleID: kind + "-rule",
		CreatedAt: time.Date(2026, 10, 2, 8, 0, 0, 0, time.UTC),
	}
	offered.Store(w.WorkRequestID, w)
	p := pending{key: cardUUID(number), event: workEvent(w), work: w, set: old}
	p.apply(old.MatchWork(w))

	return p
}

// An event matched on the old set that reaches the queue after the swap
// matches again on the new set.
func TestAnEventMatchedBeforeTheSwapMatchesTheNewSet(t *testing.T) {
	h := busy(t, twoRuleFile)
	old := h.router.rules()

	if res := h.reload(t, withMaxWorkers(strings.Replace(defaultRules, "prompt: Card {cardNumber} ({cardId}) entered next.", "prompt: New {cardNumber}.", 1), 1)); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	h.router.enqueue(stale(old, testProject, 88, "plan", event.ActorHuman))
	h.router.enqueue(stale(old, testProject, 89, "review", event.ActorHuman))

	if got := h.queued(); !slices.Equal(got, []string{"0088/work:plan"}) {
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

// An event of a project the new set no longer maps logs project_unmapped
// once, as a fresh event does.
func TestAnEventOfAProjectTheNewSetDropsIsLoggedOnce(t *testing.T) {
	h := newHarnessWith(t, twoProjectFile, rules.Defaults{})
	old := h.router.rules()

	if res := h.reload(t, defaultRules); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	h.router.enqueue(stale(old, otherProject, 88, "plan", event.ActorHuman))
	h.router.enqueue(stale(old, otherProject, 89, "plan", event.ActorHuman))

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

	if h.router.rules().WorkDead("loupe") == "" {
		t.Fatal("the new set keeps the work of a gone project")
	}
	for _, line := range h.events(t, "work_dead") {
		if str(t, line, "project_slug") != "loupe" || str(t, line, "project") != testProject || str(t, line, "reason") != api.ReasonProjectGone {
			t.Fatalf("work_dead = %v", line)
		}
	}
	if n := len(h.events(t, "work_dead")); n != 1 {
		t.Fatalf("%d work_dead lines, want the one of the refresh", n)
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

	if !(h.router.rules().WorkDead("loupe") == "") {
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

// A project marked gone by an older answer during the check, and gone again in
// an answer newer than the reload's, is gone in the new set.
func TestANewerGoneAnswerForAMarkedProjectIsReplayed(t *testing.T) {
	h := newHarness(t)
	src, entered, release := blocked(h.source(defaultRules))
	src, heldEntered, heldRelease := held(src)

	done := make(chan reloadResult)
	go func() { done <- h.router.reload(context.Background(), src) }()
	<-entered
	h.router.onRefresh(api.Events{})
	close(release)
	<-heldEntered
	h.router.onRefresh(api.Events{})
	close(heldRelease)
	if res := <-done; !res.OK {
		t.Fatalf("result = %+v", res)
	}

	if h.router.rules().WorkDead("loupe") == "" {
		t.Fatal("the newest answer omits the project, and its rule stayed live")
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

	if !(h.router.rules().WorkDead("loupe") == "") {
		t.Fatal("an older answer killed the rule of the new set")
	}
	if n := len(h.events(t, "project_gone")) + len(h.events(t, "work_dead")); n != 0 {
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

	if !(h.router.rules().WorkDead("loupe") == "") {
		t.Fatal("the gone old project killed the work of the new one")
	}
	if n := len(h.events(t, "work_dead")); n != 1 {
		t.Fatalf("%d work_dead lines, want the old set's alone", n)
	}
	h.send(offerPayloadIn(recreatedProject, 88, "plan"))
	if n := h.runs(); n != 1 {
		t.Fatalf("the new project started %d workers, want 1", n)
	}

	// The gone mark names the old id, so the new project going too is news.
	h.router.onRefresh(api.Events{})
	if h.router.rules().WorkDead("loupe") == "" {
		t.Fatal("the new project went, and its work stayed")
	}
}

// The variants of an entry are part of it, so a new variant model changes it.
func TestDiffRulesSeesAChangedVariant(t *testing.T) {
	parse := func(model string) *rules.Set {
		t.Helper()
		body := "accounts:\n  claude:\n    harness: claude-code\ndefaults:\n  account: claude\nprojects:\n  loupe:\n    dir: " + t.TempDir() + "\n" +
			"work:\n  plan:\n    prompt: go\n    variants:\n      - {name: a, weight: 1, model: " + model + "}\n"
		set, err := rules.Parse([]byte(body), rules.Defaults{})
		if err != nil {
			t.Fatal(err)
		}

		return set
	}

	if res := diffRules(parse("opus"), parse("sonnet")); !slices.Equal(res.Changed, []string{"plan"}) {
		t.Fatalf("result = %+v", res)
	}
}
