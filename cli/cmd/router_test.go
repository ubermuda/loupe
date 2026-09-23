package cmd

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"sync"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// fakeWorker stands in for a claude process: it records what the router asked
// for and answers with a fixed result. started and block make a test observe
// and hold a running worker with no sleeping. peak records how many ran at
// once, which is what the bound has to hold down.
type fakeWorker struct {
	mu       sync.Mutex
	calls    []workerSpec
	inFlight int
	maxSeen  int
	started  chan workerSpec
	block    chan struct{}
	result   workerResult
	sessions int
}

// testSession is the first session id a fakeWorker hands out.
const testSession = "0199a0e2-0000-4000-8000-000000000001"

// sessionUUID is the nth session id a fakeWorker hands out, counting from 1.
func sessionUUID(n int) string {
	return fmt.Sprintf("0199a0e2-0000-4000-8000-%012d", n)
}

func (f *fakeWorker) ops() workerOps {
	return workerOps{sessionID: f.nextSession, run: func(_ context.Context, spec workerSpec) workerResult {
		f.enter(spec)

		if f.started != nil {
			f.started <- spec
		}
		if f.block != nil {
			<-f.block
		}
		f.leave()

		return f.result
	}}
}

func (f *fakeWorker) nextSession() string {
	f.mu.Lock()
	defer f.mu.Unlock()

	f.sessions++

	return sessionUUID(f.sessions)
}

func (f *fakeWorker) enter(spec workerSpec) {
	f.mu.Lock()
	defer f.mu.Unlock()

	f.calls = append(f.calls, spec)
	f.inFlight++
	if f.inFlight > f.maxSeen {
		f.maxSeen = f.inFlight
	}
}

func (f *fakeWorker) leave() {
	f.mu.Lock()
	defer f.mu.Unlock()

	f.inFlight--
}

func (f *fakeWorker) recorded() []workerSpec {
	f.mu.Lock()
	defer f.mu.Unlock()

	return append([]workerSpec(nil), f.calls...)
}

func (f *fakeWorker) peak() int {
	f.mu.Lock()
	defer f.mu.Unlock()

	return f.maxSeen
}

// syncBuffer collects the JSON log. slog serialises its own writes, and a test
// reads the buffer while a worker still runs, so the read takes the same lock.
type syncBuffer struct {
	mu  sync.Mutex
	buf bytes.Buffer
}

func (b *syncBuffer) Write(p []byte) (int, error) {
	b.mu.Lock()
	defer b.mu.Unlock()

	return b.buf.Write(p)
}

func (b *syncBuffer) String() string {
	b.mu.Lock()
	defer b.mu.Unlock()

	return b.buf.String()
}

const (
	testProject = "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7"
	testCard    = "0192f3a1-9999-7d3e-8f10-000000000087"
)

// otherProject is the id of the second mapped project, other.
const otherProject = "0192f3a1-4b2c-7d3e-8f10-000000000002"

// boardColumns answers the start check for the loupe and other projects.
type boardColumns struct{}

func (boardColumns) Columns(_ context.Context, handle string) (api.ProjectColumns, error) {
	var pc api.ProjectColumns
	switch handle {
	case "loupe":
		pc.Project.ID = testProject
	case "other":
		pc.Project.ID = otherProject
	default:
		return pc, api.ErrProjectNotFound
	}
	pc.Project.Slug = handle
	for _, slug := range []string{"backlog", "next", "in-progress", "review", "done"} {
		pc.Columns = append(pc.Columns, api.Column{Slug: slug})
	}

	return pc, nil
}

func (boardColumns) Sites(context.Context) ([]api.Site, error) {
	return []api.Site{{ID: testProject, Slug: "loupe"}, {ID: otherProject, Slug: "other"}}, nil
}

// defaultRules starts a worker for a card that enters next, as the bridge did
// before rules existed.
const defaultRules = `
projects:
  loupe:
    dir: {dir}
rules:
  - name: plan
    on: board.card_moved
    project: loupe
    to: next
    prompt: Card {cardNumber} ({cardId}) entered {to}.
`

// loadRules parses body with {dir} replaced by a real directory, and checks it.
func loadRules(t *testing.T, body string, defaults rules.Defaults) (*rules.Set, string) {
	t.Helper()
	dir := t.TempDir()
	set, err := rules.Parse([]byte(strings.ReplaceAll(body, "{dir}", dir)), defaults)
	if err != nil {
		t.Fatal(err)
	}
	if err := set.Check(context.Background(), boardColumns{}); err != nil {
		t.Fatal(err)
	}

	return set, dir
}

type harness struct {
	router *router
	worker *fakeWorker
	log    *syncBuffer
	dir    string
}

func newHarness(t *testing.T) *harness {
	t.Helper()

	return newHarnessWith(t, defaultRules, rules.Defaults{})
}

func newHarnessWith(t *testing.T, body string, defaults rules.Defaults) *harness {
	t.Helper()
	set, dir := loadRules(t, body, defaults)
	w := &fakeWorker{}
	h := &harness{worker: w, log: &syncBuffer{}, dir: dir}
	h.router = withRules(&router{
		log:        newBridgeLogger(h.log),
		maxWorkers: defaultMaxWorkers,
		worker:     w.ops(),
		bridgeID:   testBridgeID,
	}, set)

	return h
}

// withRules stores the rule set in a router literal, which cannot set an
// atomic pointer.
func withRules(r *router, set *rules.Set) *router {
	r.set.Store(set)

	return r
}

// lines parses the log. Every line must be one JSON object, so a test never
// matches a substring of a formatted line.
func (h *harness) lines(t *testing.T) []map[string]any {
	t.Helper()

	var out []map[string]any
	for _, raw := range strings.Split(strings.TrimSpace(h.log.String()), "\n") {
		if raw == "" {
			continue
		}
		var line map[string]any
		if err := json.Unmarshal([]byte(raw), &line); err != nil {
			t.Fatalf("log line %q is not JSON: %v", raw, err)
		}
		out = append(out, line)
	}

	return out
}

func (h *harness) events(t *testing.T, name string) []map[string]any {
	t.Helper()

	var out []map[string]any
	for _, line := range h.lines(t) {
		if line["event"] == name {
			out = append(out, line)
		}
	}

	return out
}

func (h *harness) only(t *testing.T, name string) map[string]any {
	t.Helper()

	got := h.events(t, name)
	if len(got) != 1 {
		t.Fatalf("expected one %s line, got %d: %v", name, len(got), got)
	}

	return got[0]
}

// num reads a JSON number, which decodes as a float64.
func num(t *testing.T, line map[string]any, key string) int {
	t.Helper()

	v, ok := line[key].(float64)
	if !ok {
		t.Fatalf("%v has no numeric %q", line, key)
	}

	return int(v)
}

func str(t *testing.T, line map[string]any, key string) string {
	t.Helper()

	v, ok := line[key].(string)
	if !ok {
		t.Fatalf("%v has no string %q", line, key)
	}

	return v
}

// dropped reads the card and rule pairs a queue_dropped line carries, as
// "card/rule".
func dropped(t *testing.T, line map[string]any) []string {
	t.Helper()

	raw, ok := line["dropped"].([]any)
	if !ok {
		t.Fatalf("%v has no dropped list", line)
	}
	out := make([]string, len(raw))
	for i, v := range raw {
		entry, ok := v.(map[string]any)
		if !ok {
			t.Fatalf("dropped entry %v is not an object", v)
		}
		out[i] = fmt.Sprintf("%d/%s", num(t, entry, "card"), str(t, entry, "rule"))
	}

	return out
}

func startedCards(t *testing.T, h *harness) []int {
	t.Helper()

	var out []int
	for _, line := range h.events(t, "worker_started") {
		out = append(out, num(t, line, "card"))
	}

	return out
}

// cardUUID gives each card number its own card id, as the server does.
func cardUUID(number int) string {
	return fmt.Sprintf("0192f3a1-9999-7d3e-8f10-%012d", number)
}

func movedPayload(number int, from, to, actor string) string {
	return fmt.Sprintf(`{"type":"board.card_moved","subject":{"type":"card","id":%q},"projectId":%q,"cardNumber":%d,"fromStatus":%q,"toStatus":%q,"actor":%q}`, cardUUID(number), testProject, number, from, to, actor)
}

func cardMoved(number int) string {
	return movedPayload(number, "backlog", "next", "human")
}

func TestAMatchingRuleRunsAWorker(t *testing.T) {
	h := newHarnessWith(t, defaultRules, rules.Defaults{PermissionMode: "acceptEdits", Model: "sonnet"})

	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()

	calls := h.worker.recorded()
	if len(calls) != 1 {
		t.Fatalf("expected one worker, got %+v", calls)
	}
	if calls[0].dir != h.dir || calls[0].permissionMode != "acceptEdits" || calls[0].model != "sonnet" {
		t.Fatalf("unexpected worker: %+v", calls[0])
	}
	want := "Card 87 (" + testCard + ") entered next.\n\n" + directive.Footer
	if calls[0].prompt != want {
		t.Fatalf("prompt = %q, want %q", calls[0].prompt, want)
	}

	started := h.only(t, "worker_started")
	if num(t, started, "card") != 87 || str(t, started, "project") != testProject || str(t, started, "rule") != "plan" {
		t.Fatalf("worker_started = %v", started)
	}
	finished := h.only(t, "worker_finished")
	if num(t, finished, "exit") != 0 || str(t, finished, "rule") != "plan" {
		t.Fatalf("worker_finished = %v", finished)
	}
	if _, ok := finished["duration_ms"].(float64); !ok {
		t.Fatalf("worker_finished carries no duration_ms: %v", finished)
	}
}

// Each rule carries its own directory, permission mode and model, and the
// worker runs with the ones of the rule that matched.
func TestTheWorkerRunsWithTheMatchingRulesSettings(t *testing.T) {
	h := newHarnessWith(t, defaultRules+`
  - name: review
    on: board.card_moved
    project: loupe
    to: review
    permissionMode: plan
    model: opus
    prompt: Review {cardId} in {project}, from {from}.
`, rules.Defaults{PermissionMode: "acceptEdits"})

	h.router.onData([]byte(movedPayload(87, "in-progress", "review", "agent")))
	h.router.wg.Wait()

	calls := h.worker.recorded()
	if len(calls) != 1 {
		t.Fatalf("expected one worker, got %+v", calls)
	}
	want := workerSpec{dir: h.dir, permissionMode: "plan", model: "opus", sessionID: testSession, prompt: "Review " + testCard + " in loupe, from in-progress.\n\n" + directive.Footer}
	if calls[0] != want {
		t.Fatalf("worker = %+v, want %+v", calls[0], want)
	}
	if got := str(t, h.only(t, "worker_started"), "rule"); got != "review" {
		t.Fatalf("rule = %q", got)
	}
}

// A rule on a type this build knows no fields of runs on project alone, and its
// log line names the subject because the event carries no card number.
func TestARuleOnAGenericTypeRunsAWorker(t *testing.T) {
	h := newHarnessWith(t, defaultRules+`
  - name: created
    on: board.card_created
    project: loupe
    prompt: Something was created in {project}.
`, rules.Defaults{})

	h.router.onData([]byte(`{"type":"board.card_created","subject":{"type":"card","id":"` + testCard + `"},"projectId":"` + testProject + `","actor":"human"}`))
	h.router.wg.Wait()

	if calls := h.worker.recorded(); len(calls) != 1 || !strings.HasPrefix(calls[0].prompt, "Something was created in loupe.") {
		t.Fatalf("workers = %+v", calls)
	}
	started := h.only(t, "worker_started")
	if str(t, started, "subject") != testCard || str(t, started, "rule") != "created" {
		t.Fatalf("worker_started = %v", started)
	}
}

// The widget cannot direct an agent unless the rule says so. The skip is
// logged, so a trigger that produced nothing is still visible.
func TestAReviewersEventIsSkippedUnlessTheRuleAllowsIt(t *testing.T) {
	h := newHarness(t)

	h.router.onData([]byte(movedPayload(87, "backlog", "next", "reviewer")))
	h.router.wg.Wait()

	if calls := h.worker.recorded(); len(calls) != 0 {
		t.Fatalf("a reviewer's event started %+v", calls)
	}
	skipped := h.only(t, "event_untrusted")
	if num(t, skipped, "card") != 87 || str(t, skipped, "rule") != "plan" {
		t.Fatalf("event_untrusted = %v", skipped)
	}

	allowed := newHarnessWith(t, strings.Replace(defaultRules, "    to: next\n", "    to: next\n    allowUntrusted: true\n", 1), rules.Defaults{})
	allowed.router.onData([]byte(movedPayload(87, "backlog", "next", "reviewer")))
	allowed.router.wg.Wait()

	if calls := allowed.worker.recorded(); len(calls) != 1 {
		t.Fatalf("allowUntrusted still skipped the event: %+v", calls)
	}
}

// An event for a project the file does not map is logged once per project, so
// a busy board does not flood the log.
func TestAnUnmappedProjectIsLoggedOnce(t *testing.T) {
	h := newHarness(t)
	const other = "0192f3a1-4b2c-7d3e-8f10-ffffffffffff"
	payload := strings.Replace(cardMoved(87), testProject, other, 1)

	h.router.onData([]byte(payload))
	h.router.onData([]byte(payload))
	h.router.wg.Wait()

	if got := str(t, h.only(t, "project_unmapped"), "project"); got != other {
		t.Fatalf("project_unmapped = %q", got)
	}
	if calls := h.worker.recorded(); len(calls) != 0 {
		t.Fatalf("an unmapped project started %+v", calls)
	}
}

// Every line has to be one JSON object named by a stable event key. slog calls
// that key "msg" by default, so this pins the rename.
func TestEveryLogLineIsJSONNamedByAnEventKey(t *testing.T) {
	h := newHarness(t)

	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()
	h.router.onData([]byte(`not json`))

	lines := h.lines(t)
	if len(lines) == 0 {
		t.Fatal("nothing was logged")
	}
	for _, line := range lines {
		if str(t, line, "event") == "" {
			t.Fatalf("line %v has an empty event", line)
		}
		if _, ok := line["msg"]; ok {
			t.Fatalf("line %v still carries msg", line)
		}
	}
}

// The bridge owns the worker's streams, so a report that drops the output on
// success leaves the operator no view of what claude answered.
func TestASuccessfulWorkerReportsWhatItSaid(t *testing.T) {
	h := newHarness(t)
	h.worker.result = workerResult{output: "moved card 87 to in-progress"}

	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()

	if got := str(t, h.only(t, "worker_finished"), "output"); got != "moved card 87 to in-progress" {
		t.Fatalf("output = %q", got)
	}
}

// A card that has been worked once starts a worker again the next time a rule
// matches it.
func TestAFinishedWorkerNoLongerBlocksItsCard(t *testing.T) {
	h := newHarness(t)

	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()
	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()

	if calls := h.worker.recorded(); len(calls) != 2 {
		t.Fatalf("expected two workers, got %+v", calls)
	}
	if got := h.events(t, "worker_coalesced"); len(got) != 0 {
		t.Fatalf("a finished worker still held its card: %v", got)
	}
}

// An event for a card with a running worker waits and runs after that worker
// exits, and never beside it, even with free slots.
func TestAnEventForABusyCardRunsAfterItsWorker(t *testing.T) {
	h := newHarness(t)
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(87)))

	// onData dispatches on this goroutine, so these counts are settled.
	h.router.mu.Lock()
	active, waiting := h.router.active, len(h.router.queue)
	h.router.mu.Unlock()
	if active != 1 || waiting != 1 {
		t.Fatalf("active = %d, waiting = %d; want the second event to wait", active, waiting)
	}
	queued := h.events(t, "worker_queued")
	if len(queued) != 2 || num(t, queued[1], "card") != 87 || num(t, queued[1], "queue_depth") != 1 {
		t.Fatalf("worker_queued = %v", queued)
	}

	close(h.worker.block)
	h.router.wg.Wait()
	if calls := h.worker.recorded(); len(calls) != 2 {
		t.Fatalf("expected the waiting event to run after the first, got %+v", calls)
	}
	if got := h.worker.peak(); got != 1 {
		t.Fatalf("peak concurrency for one card = %d, want 1", got)
	}
}

// A person who drags a card back and forth sends several events in seconds.
// While the card's worker runs, they become one follow-up run for the rule, and
// each event that gets no run of its own says so.
func TestEventsForABusyCardCoalescePerRule(t *testing.T) {
	h := newHarnessWith(t, strings.Replace(defaultRules, "{to}.", "{to} from {from}.", 1), rules.Defaults{})
	h.worker.started = make(chan workerSpec, 4)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	for _, from := range []string{"backlog", "review", "in-progress"} {
		h.router.onData([]byte(movedPayload(87, from, "next", "human")))
	}

	coalesced := h.events(t, "worker_coalesced")
	if len(coalesced) != 2 {
		t.Fatalf("worker_coalesced = %v, want two lines", coalesced)
	}
	for _, line := range coalesced {
		if num(t, line, "card") != 87 || str(t, line, "rule") != "plan" {
			t.Fatalf("worker_coalesced = %v", line)
		}
	}

	close(h.worker.block)
	h.router.wg.Wait()

	calls := h.worker.recorded()
	if len(calls) != 2 {
		t.Fatalf("ran %d workers, want the first and exactly one follow-up", len(calls))
	}
	if !strings.HasPrefix(calls[1].prompt, "Card 87 ("+testCard+") entered next from in-progress.") {
		t.Fatalf("the follow-up did not run the newest event: %q", calls[1].prompt)
	}
}

// A card queued behind the bound waits once per rule too, so it cannot run
// twice when a slot frees.
func TestACardAlreadyQueuedCoalesces(t *testing.T) {
	h := newHarness(t)
	h.router.maxWorkers = 1
	h.worker.started = make(chan workerSpec, 3)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(88)))
	h.router.onData([]byte(cardMoved(88)))

	if num(t, h.only(t, "worker_coalesced"), "card") != 88 {
		t.Fatal("card 88 did not coalesce")
	}
	if got := h.events(t, "worker_queued"); len(got) != 2 {
		t.Fatalf("card 88 was queued twice: %v", got)
	}

	close(h.worker.block)
	h.router.wg.Wait()
	if got := startedCards(t, h); len(got) != 2 {
		t.Fatalf("started %v, want one run each for 87 and 88", got)
	}
}

const reviewRule = `
  - name: review
    on: board.card_moved
    project: loupe
    to: review
    prompt: Review {cardId}.
`

// Two rules on one busy card each keep a waiting slot. They run one after
// another in arrival order, and another card is not held up behind them.
func TestTwoRulesOnABusyCardRunInOrder(t *testing.T) {
	h := newHarnessWith(t, defaultRules+reviewRule, rules.Defaults{})
	h.worker.started = make(chan workerSpec, 5)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(movedPayload(87, "next", "review", "agent")))
	h.router.onData([]byte(movedPayload(87, "review", "next", "agent")))
	h.router.onData([]byte(cardMoved(88)))
	<-h.worker.started

	if got := h.events(t, "worker_coalesced"); len(got) != 0 {
		t.Fatalf("two rules shared one slot: %v", got)
	}
	if got := startedCards(t, h); len(got) != 2 || got[1] != 88 {
		t.Fatalf("started %v, want card 88 to start while card 87 waits", got)
	}

	close(h.worker.block)
	h.router.wg.Wait()

	var order []string
	for _, line := range h.events(t, "worker_started") {
		if num(t, line, "card") == 87 {
			order = append(order, str(t, line, "rule"))
		}
	}
	if strings.Join(order, " ") != "plan review plan" {
		t.Fatalf("card 87 ran %v, want plan review plan", order)
	}
}

// chainRules caps each rule at two agent-triggered runs in a row.
const chainRules = `
projects:
  loupe:
    dir: {dir}
rules:
  - name: plan
    on: board.card_moved
    project: loupe
    to: next
    maxChain: 2
    prompt: Plan {cardId}.
  - name: review
    on: board.card_moved
    project: loupe
    to: review
    maxChain: 2
    prompt: Review {cardId}.
`

// send delivers one payload and waits for any worker it started.
func (h *harness) send(payload string) {
	h.router.onData([]byte(payload))
	h.router.wg.Wait()
}

func (h *harness) runs() int {
	return len(h.worker.recorded())
}

// A rule stops starting runs for a card once agents' events have started
// maxChain of them in a row. A person's move resets the count; a reviewer's
// event does not.
func TestTheChainCapStopsAnAgentLoopUntilAPersonActs(t *testing.T) {
	h := newHarnessWith(t, chainRules, rules.Defaults{})

	h.send(movedPayload(87, "backlog", "next", "human"))
	h.send(movedPayload(87, "backlog", "next", "agent"))
	h.send(movedPayload(87, "backlog", "next", "agent"))
	if h.runs() != 3 {
		t.Fatalf("ran %d, want a person's run and two agent runs", h.runs())
	}

	h.send(movedPayload(87, "backlog", "next", "agent"))
	if h.runs() != 3 {
		t.Fatalf("the third agent run in a row still started: %d runs", h.runs())
	}
	capped := h.only(t, "chain_capped")
	if num(t, capped, "card") != 87 || str(t, capped, "rule") != "plan" || num(t, capped, "max_chain") != 2 {
		t.Fatalf("chain_capped = %v", capped)
	}
	if got := str(t, capped, "message"); got != "card 87 hit the chain cap of rule plan, waiting for a person" {
		t.Fatalf("message = %q", got)
	}

	h.send(movedPayload(88, "backlog", "next", "agent"))
	if h.runs() != 4 {
		t.Fatalf("card 87's cap held card 88 back: %d runs", h.runs())
	}

	h.send(movedPayload(87, "next", "backlog", "reviewer"))
	h.send(movedPayload(87, "backlog", "next", "agent"))
	if h.runs() != 4 {
		t.Fatalf("a reviewer's event reset the cap: %d runs", h.runs())
	}

	h.send(movedPayload(87, "next", "done", "human"))
	h.send(movedPayload(87, "backlog", "next", "agent"))
	if h.runs() != 5 {
		t.Fatalf("a person's move did not reset the cap: %d runs", h.runs())
	}
}

// The app moves a card when a person approves a document. Nobody judged that
// move as a move, so it starts a run, spends no chain budget and resets none.
func TestASystemMoveNeitherSpendsNorResetsTheChain(t *testing.T) {
	h := newHarnessWith(t, chainRules, rules.Defaults{})

	h.send(movedPayload(87, "backlog", "next", "system"))
	h.send(movedPayload(87, "backlog", "next", "system"))
	h.send(movedPayload(87, "backlog", "next", "system"))
	if h.runs() != 3 {
		t.Fatalf("a system move hit the cap: %d runs", h.runs())
	}

	h.send(movedPayload(87, "backlog", "next", "agent"))
	h.send(movedPayload(87, "backlog", "next", "agent"))
	if h.runs() != 5 {
		t.Fatalf("the agent runs after a system move did not start: %d runs", h.runs())
	}

	h.send(movedPayload(87, "backlog", "next", "agent"))
	if h.runs() != 5 {
		t.Fatalf("the agent chain was not capped: %d runs", h.runs())
	}

	h.send(movedPayload(87, "backlog", "next", "system"))
	h.send(movedPayload(87, "backlog", "next", "agent"))
	if h.runs() != 6 {
		t.Fatalf("a system move reset the cap, so only a person's move must: %d runs", h.runs())
	}
}

// Each rule counts its own runs, and a person's move resets every rule's count
// on that card.
func TestEachRuleCountsItsOwnChain(t *testing.T) {
	h := newHarnessWith(t, chainRules, rules.Defaults{})

	for range 2 {
		h.send(movedPayload(87, "review", "next", "agent"))
		h.send(movedPayload(87, "next", "review", "agent"))
	}
	if h.runs() != 4 {
		t.Fatalf("ran %d, want two runs for each rule", h.runs())
	}

	h.send(movedPayload(87, "review", "next", "agent"))
	h.send(movedPayload(87, "next", "review", "agent"))
	if h.runs() != 4 || len(h.events(t, "chain_capped")) != 2 {
		t.Fatalf("runs = %d, chain_capped = %v", h.runs(), h.events(t, "chain_capped"))
	}

	h.send(movedPayload(87, "review", "backlog", "human"))
	h.send(movedPayload(87, "review", "next", "agent"))
	h.send(movedPayload(87, "next", "review", "agent"))
	if h.runs() != 6 {
		t.Fatalf("a person's move did not reset both rules: %d runs", h.runs())
	}
}

// A count follows runs, not events. An event that replaces a waiting one adds
// nothing, so a burst of agent events for a busy card uses one step of the cap.
func TestACoalescedEventDoesNotCountTowardTheCap(t *testing.T) {
	h := newHarnessWith(t, chainRules, rules.Defaults{})
	h.worker.started = make(chan workerSpec, 4)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(movedPayload(87, "backlog", "next", "human")))
	<-h.worker.started
	for range 3 {
		h.router.onData([]byte(movedPayload(87, "backlog", "next", "agent")))
	}
	close(h.worker.block)
	h.router.wg.Wait()

	h.worker.started, h.worker.block = nil, nil
	h.send(movedPayload(87, "backlog", "next", "agent"))
	if h.runs() != 3 || len(h.events(t, "chain_capped")) != 0 {
		t.Fatalf("runs = %d, chain_capped = %v; want the second agent run to start", h.runs(), h.events(t, "chain_capped"))
	}
}

// TestTwoCardsRunConcurrently pins that a running worker never blocks the read
// loop or another card. Neither worker returns until both have started.
func TestTwoCardsRunConcurrently(t *testing.T) {
	h := newHarness(t)
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	h.router.onData([]byte(cardMoved(88)))

	<-h.worker.started
	<-h.worker.started

	close(h.worker.block)
	h.router.wg.Wait()
	if calls := h.worker.recorded(); len(calls) != 2 {
		t.Fatalf("expected two workers, got %+v", calls)
	}
}

// TestTheBoundLimitsConcurrentWorkers is the whole point of --max-workers. peak
// is monotonic, so the check after wg.Wait reads the highest concurrency the
// run ever reached.
func TestTheBoundLimitsConcurrentWorkers(t *testing.T) {
	h := newHarness(t)
	h.router.maxWorkers = 2
	h.worker.started = make(chan workerSpec, 4)
	h.worker.block = make(chan struct{})

	for _, card := range []int{87, 88, 89, 90} {
		h.router.onData([]byte(cardMoved(card)))
	}

	// onData dispatches on this goroutine and nothing finishes while block is
	// held, so the counts here are settled rather than sampled.
	h.router.mu.Lock()
	active, queued := h.router.active, len(h.router.queue)
	h.router.mu.Unlock()
	if active != 2 || queued != 2 {
		t.Fatalf("active = %d, queued = %d; want 2 running and 2 waiting", active, queued)
	}

	<-h.worker.started
	<-h.worker.started

	close(h.worker.block)
	h.router.wg.Wait()

	if got := h.worker.peak(); got != 2 {
		t.Fatalf("peak concurrency = %d, want 2", got)
	}
	if got := h.worker.recorded(); len(got) != 4 {
		t.Fatalf("ran %d workers, want all 4", len(got))
	}
}

// A bound that never releases is a stall. The second card has to run once a
// slot frees, and the queue depth has to say how much work waits.
func TestAQueuedEventRunsWhenASlotFrees(t *testing.T) {
	h := newHarness(t)
	h.router.maxWorkers = 1
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(88)))

	if got := h.worker.recorded(); len(got) != 1 {
		t.Fatalf("the bound let %d workers run", len(got))
	}
	queued := h.events(t, "worker_queued")
	if len(queued) != 2 || num(t, queued[1], "queue_depth") != 1 {
		t.Fatalf("worker_queued = %v", queued)
	}

	close(h.worker.block)
	<-h.worker.started
	h.router.wg.Wait()

	if got := startedCards(t, h); len(got) != 2 || got[1] != 88 {
		t.Fatalf("started %v, want card 88 second", got)
	}
}

// With no busy card in the way, the card that waited longest starts first.
func TestTheQueueIsFirstInFirstOut(t *testing.T) {
	h := newHarness(t)
	h.router.maxWorkers = 1
	h.worker.started = make(chan workerSpec, 4)
	h.worker.block = make(chan struct{})

	for _, card := range []int{87, 88, 89, 90} {
		h.router.onData([]byte(cardMoved(card)))
	}
	<-h.worker.started

	close(h.worker.block)
	h.router.wg.Wait()

	want := []int{87, 88, 89, 90}
	got := startedCards(t, h)
	if len(got) != len(want) {
		t.Fatalf("started %v, want %v", got, want)
	}
	for i := range want {
		if got[i] != want[i] {
			t.Fatalf("started %v, want %v", got, want)
		}
	}
}

// Shutdown drops what never started. The count and each card with its rule
// have to reach the operator, because a dropped trigger nobody is told about is
// a silent failure.
func TestShutdownDropsTheQueueAndSaysSo(t *testing.T) {
	h := newHarness(t)
	h.router.maxWorkers = 1
	h.worker.started = make(chan workerSpec, 3)
	h.worker.block = make(chan struct{})

	for _, card := range []int{87, 88, 89} {
		h.router.onData([]byte(cardMoved(card)))
	}
	<-h.worker.started

	h.router.shutdown()

	line := h.only(t, "queue_dropped")
	if num(t, line, "count") != 2 {
		t.Fatalf("queue_dropped = %v", line)
	}
	if got := strings.Join(dropped(t, line), " "); got != "88/plan 89/plan" {
		t.Fatalf("dropped = %s, want 88/plan 89/plan", got)
	}

	close(h.worker.block)
	h.router.wg.Wait()

	if got := h.worker.recorded(); len(got) != 1 {
		t.Fatalf("a dropped card still ran: %d workers", len(got))
	}
	if got := startedCards(t, h); len(got) != 1 || got[0] != 87 {
		t.Fatalf("started %v, want only card 87", got)
	}
}

// One card can wait once per rule, so the drop names each card with its rule,
// and two rules on one card do not read as a duplicate.
func TestShutdownNamesEachWaitingRuleOfACard(t *testing.T) {
	h := newHarnessWith(t, defaultRules+reviewRule, rules.Defaults{})
	h.worker.started = make(chan workerSpec, 1)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(movedPayload(87, "next", "review", "agent")))
	h.router.onData([]byte(movedPayload(87, "review", "next", "agent")))

	h.router.shutdown()

	line := h.only(t, "queue_dropped")
	if got := strings.Join(dropped(t, line), " "); num(t, line, "count") != 2 || got != "87/review 87/plan" {
		t.Fatalf("queue_dropped = %v", line)
	}

	close(h.worker.block)
	h.router.wg.Wait()
	if got := h.worker.recorded(); len(got) != 1 {
		t.Fatalf("a dropped event still ran: %d workers", len(got))
	}
}

// A shut queue starts nothing. Without that, a worker that finishes after the
// shutdown admits the next card and starts an agent nobody watches.
func TestAnEventAfterShutdownIsDropped(t *testing.T) {
	h := newHarness(t)

	h.router.shutdown()
	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()

	line := h.only(t, "queue_dropped")
	if got := strings.Join(dropped(t, line), " "); num(t, line, "count") != 1 || got != "87/plan" {
		t.Fatalf("queue_dropped = %v", line)
	}
	if got := h.worker.recorded(); len(got) != 0 {
		t.Fatalf("a shut queue still ran %d workers", len(got))
	}
	if got := h.events(t, "worker_started"); len(got) != 0 {
		t.Fatalf("a shut queue still started %v", got)
	}
}

// Ctrl-C cancels the context before the stream unwinds, so a worker can finish
// before shutdown runs. The cancelled context has to stop the queue by itself,
// or that worker admits a card no process can run.
func TestACancelledContextStopsTheQueue(t *testing.T) {
	h := newHarness(t)
	h.router.maxWorkers = 1
	ctx, cancel := context.WithCancel(context.Background())
	h.router.ctx = ctx
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(88)))

	cancel()
	close(h.worker.block)
	h.router.wg.Wait()

	if got := h.worker.recorded(); len(got) != 1 {
		t.Fatalf("a cancelled bridge still ran %d workers", len(got))
	}
	line := h.only(t, "queue_dropped")
	if got := strings.Join(dropped(t, line), " "); num(t, line, "count") != 1 || got != "88/plan" {
		t.Fatalf("queue_dropped = %v", line)
	}
}

// A shutdown with nothing waiting says nothing.
func TestShutdownWithAnEmptyQueueLogsNothing(t *testing.T) {
	h := newHarness(t)

	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()
	h.router.shutdown()

	if got := h.events(t, "queue_dropped"); len(got) != 0 {
		t.Fatalf("queue_dropped = %v", got)
	}
}

func TestANonZeroExitIsReported(t *testing.T) {
	h := newHarness(t)
	h.worker.result = workerResult{exitCode: 2, output: "claude: permission denied"}

	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()

	finished := h.only(t, "worker_finished")
	if num(t, finished, "exit") != 2 {
		t.Fatalf("worker_finished = %v", finished)
	}
	if str(t, finished, "output") != "claude: permission denied" {
		t.Fatalf("the failure report dropped the output: %v", finished)
	}
	if str(t, finished, "level") != "ERROR" {
		t.Fatalf("a failed worker logged at %q", finished["level"])
	}
}

// A worker that never ran reports no exit code, so the fault itself is all the
// operator gets. It is a different event from a process that ran and failed.
func TestAWorkerThatNeverRanIsReported(t *testing.T) {
	h := newHarness(t)
	h.worker.result = workerResult{err: errors.New("boom")}

	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()

	failed := h.only(t, "worker_failed")
	if str(t, failed, "error") != "boom" || str(t, failed, "rule") != "plan" {
		t.Fatalf("worker_failed = %v", failed)
	}
	if got := h.events(t, "worker_finished"); len(got) != 0 {
		t.Fatalf("a worker that never ran also reported finishing: %v", got)
	}
}

// A move into a column no rule names starts nothing. That also closes the
// feedback loop: the worker's own move to in-progress matches no rule.
func TestCardMovedElsewhereIsIgnored(t *testing.T) {
	for _, to := range []string{"backlog", "in-progress", "done"} {
		h := newHarness(t)

		h.router.onData([]byte(movedPayload(87, "next", to, "agent")))
		h.router.wg.Wait()

		if calls := h.worker.recorded(); len(calls) != 0 {
			t.Fatalf("to=%q acted on: %+v", to, calls)
		}
		if h.log.String() != "" {
			t.Fatalf("to=%q logged %q", to, h.log.String())
		}
	}
}

// Dragging a card to a new rank inside the next column submits a move with next
// on both sides. This is the real payload the producer writes for that drag.
func TestAReorderInsideNextStartsNoWorker(t *testing.T) {
	h := newHarness(t)

	h.router.onData([]byte(`{"type":"board.card_moved","subject":{"type":"card","id":"01a0928e-9ea9-7358-aef5-4e1a629da79a"},"projectId":"` + testProject + `","cardNumber":1,"fromStatus":"next","toStatus":"next","actor":"human"}`))
	h.router.wg.Wait()

	if calls := h.worker.recorded(); len(calls) != 0 {
		t.Fatalf("a reorder inside next started a worker: %+v", calls)
	}
}

// TestUnknownTypeIsDroppedQuietly keeps an older binary usable against a newer
// server, which publishes types this build has never heard of. A type no rule
// names is one of them.
func TestUnknownTypeIsDroppedQuietly(t *testing.T) {
	for _, payload := range []string{
		`{"type":"board.card_created","projectId":"` + testProject + `","cardNumber":87}`,
		`{"type":"site_review.submitted"}`,
	} {
		h := newHarness(t)

		h.router.onData([]byte(payload))
		h.router.wg.Wait()

		if h.log.String() != "" {
			t.Fatalf("%s was reported: %q", payload, h.log.String())
		}
		if calls := h.worker.recorded(); len(calls) != 0 {
			t.Fatalf("%s acted on: %+v", payload, calls)
		}
	}
}

func TestMalformedEventIsReported(t *testing.T) {
	h := newHarness(t)

	h.router.onData([]byte(`not json`))

	if got := str(t, h.only(t, "event_malformed"), "error"); !strings.Contains(got, "parse event") {
		t.Fatalf("event_malformed error = %q", got)
	}
}

func TestIncompleteCardEventIsReportedAndDropped(t *testing.T) {
	for _, payload := range []string{
		`{"type":"board.card_moved","subject":{"type":"card","id":"` + testCard + `"},"cardNumber":87,"fromStatus":"backlog","toStatus":"next","actor":"human"}`,
		movedPayload(87, "backlog", "", "human"),
		movedPayload(87, "backlog", "next", ""),
	} {
		h := newHarness(t)

		h.router.onData([]byte(payload))
		h.router.wg.Wait()

		h.only(t, "event_malformed")
		if calls := h.worker.recorded(); len(calls) != 0 {
			t.Fatalf("acted on an incomplete card event %s: %+v", payload, calls)
		}
	}
}

// The stream reports its own faults through the handler, so a retry is visible.
func TestAStreamErrorIsReported(t *testing.T) {
	h := newHarness(t)
	h.router.projects, h.router.topic = []string{"loupe", "other"}, "https://loupe.test/users/u/events"

	h.router.handler().OnConnect()
	h.router.handler().OnError(errors.New("hub returned HTTP 401"))

	connected := h.only(t, "connected")
	if str(t, connected, "topic") != "https://loupe.test/users/u/events" || fmt.Sprint(connected["projects"]) != "[loupe other]" {
		t.Fatalf("connected = %v", connected)
	}
	if got := str(t, h.only(t, "stream_error"), "error"); got != "hub returned HTTP 401" {
		t.Fatalf("stream_error = %q", got)
	}
}

// lockProbe records, for each line whose event it names, that it saw the line
// and whether router mu was free as the line was written.
type lockProbe struct {
	router *router
	events []string
	mu     sync.Mutex
	seen   map[string]int
	free   []string
}

func (p *lockProbe) Write(b []byte) (int, error) {
	for _, name := range p.events {
		if !bytes.Contains(b, []byte(`"event":"`+name+`"`)) {
			continue
		}
		free := p.router.mu.TryLock()
		if free {
			p.router.mu.Unlock()
		}
		p.mu.Lock()
		p.seen[name]++
		if free {
			p.free = append(p.free, name)
		}
		p.mu.Unlock()
	}

	return len(b), nil
}

// A line enqueue writes after mu is released can follow the worker_started line
// that a finishing worker writes for the same event. Holding mu rules that out.
func TestEnqueueLinesAreWrittenUnderTheLock(t *testing.T) {
	h := newHarnessWith(t, chainRules, rules.Defaults{})
	events := []string{"worker_queued", "worker_coalesced", "chain_capped"}
	probe := &lockProbe{router: h.router, events: events, seen: map[string]int{}}
	h.router.log = newBridgeLogger(probe)
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(movedPayload(87, "backlog", "next", "agent")))
	<-h.worker.started
	h.router.onData([]byte(movedPayload(87, "backlog", "next", "agent")))
	h.router.onData([]byte(movedPayload(87, "backlog", "next", "agent")))
	close(h.worker.block)
	h.router.wg.Wait()
	h.worker.started, h.worker.block = nil, nil
	h.send(movedPayload(87, "backlog", "next", "agent"))

	probe.mu.Lock()
	defer probe.mu.Unlock()
	for _, name := range events {
		if probe.seen[name] == 0 {
			t.Fatalf("the probe saw no %s line: %v", name, probe.seen)
		}
	}
	if len(probe.free) != 0 {
		t.Fatalf("written with mu free: %v", probe.free)
	}
}

// The key is the subject id itself, so one card has one key in every map.
func TestTheKeyIsTheSubjectID(t *testing.T) {
	if got := keyFor(event.Event{Subject: event.Subject{ID: testCard}}); got != testCard {
		t.Fatalf("keyFor = %q, want %q", got, testCard)
	}
}

// A dropped event with no card number names its subject.
func TestShutdownNamesTheSubjectOfAGenericEvent(t *testing.T) {
	h := newHarnessWith(t, defaultRules+`
  - name: created
    on: board.card_created
    project: loupe
    prompt: Created in {project}.
`, rules.Defaults{})

	h.router.shutdown()
	h.router.onData([]byte(`{"type":"board.card_created","subject":{"type":"card","id":"` + testCard + `"},"projectId":"` + testProject + `","actor":"human"}`))

	raw, _ := h.only(t, "queue_dropped")["dropped"].([]any)
	if len(raw) != 1 {
		t.Fatalf("dropped = %v", raw)
	}
	entry, _ := raw[0].(map[string]any)
	if entry["subject"] != testCard || entry["rule"] != "created" || entry["card"] != nil {
		t.Fatalf("dropped entry = %v", entry)
	}
}

// Two projects number their cards from 1 independently, so card 87 of one
// project must not share a key with card 87 of another.
func TestTheKeySeparatesCardsWithOneNumber(t *testing.T) {
	a := event.Event{Type: event.CardMovedType, Subject: event.Subject{ID: cardUUID(1)}, ProjectID: testProject, CardNumber: 87}
	b := event.Event{Type: event.CardMovedType, Subject: event.Subject{ID: cardUUID(2)}, ProjectID: "0192f3a1-4b2c-7d3e-8f10-ffffffffffff", CardNumber: 87}

	if keyFor(a) == keyFor(b) {
		t.Fatalf("card 87 in two projects collided on %q", keyFor(a))
	}
}

// Every event type keys one card the same way, so a person's event of any type
// resets the chain a card move built, and one card never runs two workers.
func TestTheKeyIsTheSameForEveryEventType(t *testing.T) {
	moved := event.Event{Type: event.CardMovedType, Subject: event.Subject{ID: testCard}, ProjectID: testProject, CardNumber: 87}
	created := event.Event{Type: "board.card_created", Subject: event.Subject{ID: testCard}, ProjectID: testProject}

	if keyFor(moved) != keyFor(created) {
		t.Fatalf("one card has two keys: %q and %q", keyFor(moved), keyFor(created))
	}
}

// A person's event of a type the parser knows no fields of still resets a
// capped chain on its card.
func TestAPersonsGenericEventResetsTheChain(t *testing.T) {
	h := newHarnessWith(t, chainRules+`
  - name: created
    on: board.card_created
    project: loupe
    prompt: Created in {project}.
`, rules.Defaults{})

	for range 3 {
		h.send(movedPayload(87, "backlog", "next", "agent"))
	}
	if h.runs() != 2 || len(h.events(t, "chain_capped")) != 1 {
		t.Fatalf("runs = %d, want the cap to hold after two", h.runs())
	}

	h.send(`{"type":"board.card_created","subject":{"type":"card","id":"` + testCard + `"},"projectId":"` + testProject + `","actor":"human"}`)
	h.send(movedPayload(87, "backlog", "next", "agent"))
	if h.runs() != 4 {
		t.Fatalf("a person's generic event did not reset the chain: %d runs", h.runs())
	}
}

// verdictRules adds a rule on the review verdict to chainRules.
const verdictRules = chainRules + `
  - name: verdict
    on: document.review_submitted
    project: loupe
    prompt: A review landed in {project}.
`

// testDocument is the subject of every review verdict event.
const testDocument = "01a0a1b2-5555-7c3d-8e4f-5a6b7c8d9e0f"

// verdictPayload is a review verdict on the stage card with the given number.
// Card 0 sends the three card fields as null, as the server does.
func verdictPayload(number int, verdict string) string {
	card, cardNumber, column := "null", "null", "null"
	if number > 0 {
		card, cardNumber, column = fmt.Sprintf("%q", cardUUID(number)), fmt.Sprint(number), `"tech-design"`
	}

	return fmt.Sprintf(`{"type":"document.review_submitted","subject":{"type":"document","id":%q},"projectId":%q,"verdict":%q,"cardIds":[],"actor":"human","cardId":%s,"cardNumber":%s,"column":%s}`,
		testDocument, testProject, verdict, card, cardNumber, column)
}

// A verdict keys on its stage card, so it waits behind a worker of that card.
func TestAVerdictWaitsBehindAWorkerOfItsCard(t *testing.T) {
	h := newHarnessWith(t, verdictRules, rules.Defaults{})
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(verdictPayload(87, "approved")))

	h.router.mu.Lock()
	active, waiting := h.router.active, len(h.router.queue)
	h.router.mu.Unlock()
	if active != 1 || waiting != 1 {
		t.Fatalf("active = %d, waiting = %d; want the verdict to wait", active, waiting)
	}
	queued := h.events(t, "worker_queued")
	if len(queued) != 2 || num(t, queued[1], "card") != 87 || str(t, queued[1], "document") != testDocument || str(t, queued[1], "verdict") != "approved" {
		t.Fatalf("worker_queued = %v", queued)
	}

	close(h.worker.block)
	h.router.wg.Wait()
	if got := startedCards(t, h); len(got) != 2 || got[1] != 87 {
		t.Fatalf("started %v, want the verdict to run on card 87 after the move", got)
	}
	if h.worker.peak() != 1 {
		t.Fatalf("peak concurrency for one card = %d, want 1", h.worker.peak())
	}
}

func TestTheVerdictKey(t *testing.T) {
	h := newHarnessWith(t, verdictRules, rules.Defaults{})
	for name, tc := range map[string]struct {
		number int
		key    string
	}{
		"the stage card": {87, cardUUID(87)},
		"no card":        {0, testDocument},
	} {
		t.Run(name, func(t *testing.T) {
			e, err := event.Parse([]byte(verdictPayload(tc.number, "approved")), h.router.rules().ExtraTypes())
			if err != nil {
				t.Fatal(err)
			}
			if _, key := h.router.resolve(e); key != tc.key {
				t.Fatalf("key = %q, want %q", key, tc.key)
			}
		})
	}
}

// Two verdicts on a busy card become one follow-up run of the rule.
func TestASecondVerdictCoalescesWithAWaitingOne(t *testing.T) {
	h := newHarnessWith(t, verdictRules, rules.Defaults{})
	h.worker.started = make(chan workerSpec, 3)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(verdictPayload(87, "changes-requested")))
	h.router.onData([]byte(verdictPayload(87, "approved")))

	coalesced := h.only(t, "worker_coalesced")
	if num(t, coalesced, "card") != 87 || str(t, coalesced, "rule") != "verdict" || str(t, coalesced, "verdict") != "approved" {
		t.Fatalf("worker_coalesced = %v", coalesced)
	}

	close(h.worker.block)
	h.router.wg.Wait()
	if h.runs() != 2 {
		t.Fatalf("ran %d workers, want the move and one verdict run", h.runs())
	}
}

// A verdict is a person's act on the stage card, so it resets that card's chain.
func TestAPersonsVerdictResetsTheCardsChain(t *testing.T) {
	h := newHarnessWith(t, verdictRules, rules.Defaults{})

	for range 3 {
		h.send(movedPayload(87, "backlog", "next", "agent"))
	}
	if h.runs() != 2 || len(h.events(t, "chain_capped")) != 1 {
		t.Fatalf("runs = %d, want the cap to hold after two", h.runs())
	}

	h.send(verdictPayload(87, "approved"))
	h.send(movedPayload(87, "backlog", "next", "agent"))
	if h.runs() != 4 {
		t.Fatalf("runs = %d, want the verdict run and a card run after the reset", h.runs())
	}
}

// The run of a verdict reports against its stage card, not its document.
func TestAVerdictRunReportsItsCard(t *testing.T) {
	h := newHarnessWith(t, verdictRules, rules.Defaults{})
	sent := h.reports(t)

	h.send(verdictPayload(87, "approved"))

	got := <-sent
	if got.run.CardID != cardUUID(87) || got.run.CardNumber != 87 || got.run.RuleName != "verdict" {
		t.Fatalf("run = %+v", got.run)
	}
}
