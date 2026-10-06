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
	"time"

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
	// results answer the calls in turn, before result answers the rest.
	results  []workerResult
	sessions int
}

// testSession is the first session id a fakeWorker hands out.
const testSession = "0199a0e2-0000-4000-8000-000000000001"

// sessionUUID is the nth session id a fakeWorker hands out, counting from 1.
func sessionUUID(n int) string {
	return fmt.Sprintf("0199a0e2-0000-4000-8000-%012d", n)
}

func (f *fakeWorker) ops() workerOps {
	return workerOps{sessionID: f.nextSession, run: func(_ context.Context, spec workerSpec, onStart func(workerProc)) workerResult {
		res := f.enter(spec)
		// A result with an error stands for a process that never started.
		if onStart != nil && res.err == nil {
			onStart(workerProc{})
		}

		if f.started != nil {
			f.started <- spec
		}
		if f.block != nil {
			<-f.block
		}
		f.leave()

		return res
	}}
}

func (f *fakeWorker) nextSession() string {
	f.mu.Lock()
	defer f.mu.Unlock()

	f.sessions++

	return sessionUUID(f.sessions)
}

func (f *fakeWorker) enter(spec workerSpec) workerResult {
	f.mu.Lock()
	defer f.mu.Unlock()

	f.calls = append(f.calls, spec)
	f.inFlight++
	if f.inFlight > f.maxSeen {
		f.maxSeen = f.inFlight
	}
	if len(f.results) > 0 {
		res := f.results[0]
		f.results = f.results[1:]

		return res
	}

	return f.result
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

// defaultRules runs a worker for each plan work request.
const defaultRules = `
projects:
  loupe:
    dir: {dir}
work:
  plan:
    prompt: Card {cardNumber} ({cardId}) entered next.
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
	// work is the fake server that claims and settles the work requests the
	// test offers.
	work *fakeWork
}

func newHarness(t *testing.T) *harness {
	t.Helper()

	return newHarnessWith(t, defaultRules, rules.Defaults{})
}

func newHarnessWith(t *testing.T, body string, defaults rules.Defaults) *harness {
	t.Helper()
	set, dir := loadRules(t, body, defaults)
	w := &fakeWorker{result: workerResult{hasResult: true}}
	h := &harness{worker: w, log: &syncBuffer{}, dir: dir}
	h.work = &fakeWork{requests: map[string]api.WorkRequest{}, errs: map[string]error{}}
	h.router = withRules(&router{
		log:      newBridgeLogger(h.log),
		worker:   w.ops(),
		bridgeID: testBridgeID,
		workAPI:  h.work,
		startDir: func(string) (string, error) { return "", nil },
	}, set)

	return h
}

// withMaxWorkers sets the worker budget of a rule file body to n.
func withMaxWorkers(body string, n int) string {
	return fmt.Sprintf("maxWorkers: %d\n%s", n, body)
}

// used is the number of worker slots the router holds.
func (h *harness) used() int {
	h.router.mu.Lock()
	defer h.router.mu.Unlock()

	return h.router.usedLocked()
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

// offerPayload is a new open work request of the kind for the card, as the
// server offers it. Each call names a new request.
func offerPayload(number int, kind string) string {
	return offerPayloadIn(testProject, number, kind)
}

// offerPayloadIn is offerPayload for a card of another project.
func offerPayloadIn(project string, number int, kind string) string {
	n := int(offerSeq.Add(1))
	w := api.WorkRequest{
		Type: event.WorkRequestType, ProjectID: project, Subject: api.WorkRequestSubject{Type: "work-request", ID: offerID(n)},
		WorkRequestID: offerID(n), Kind: kind, State: api.WorkRequestOpen, SubjectType: api.SubjectCard, SubjectID: cardUUID(number), CardNumber: number,
		RuleID: kind + "-rule", CreatedAt: time.Date(2026, 10, 2, 8, 0, 0, 0, time.UTC),
	}
	offered.Store(w.WorkRequestID, w)

	return workPayload(w)
}

// offerID is the id of the nth work request a card helper builds. Its range
// stays clear of workID.
func offerID(n int) string {
	return fmt.Sprintf("0199c000-0000-7000-8000-%012d", n)
}

// cardMoved offers a plan work request for the card.
func cardMoved(number int) string {
	return offerPayload(number, "plan")
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
	if !strings.Contains(calls[0].schema, `"required":["status","summary"]`) {
		t.Fatalf("schema = %q", calls[0].schema)
	}
	want := "Card 87 (" + testCard + ") entered next.\n\n" + directive.Footer
	if calls[0].prompt != want {
		t.Fatalf("prompt = %q, want %q", calls[0].prompt, want)
	}

	started := h.only(t, "worker_started")
	if num(t, started, "card") != 87 || str(t, started, "project") != testProject || str(t, started, "rule") != "work:plan" {
		t.Fatalf("worker_started = %v", started)
	}
	finished := h.only(t, "worker_finished")
	if num(t, finished, "exit") != 0 || str(t, finished, "rule") != "work:plan" {
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
  review:
    permissionMode: plan
    model: opus
    prompt: Review {cardId} in {project}, for {kind}.
`, rules.Defaults{PermissionMode: "acceptEdits"})

	h.router.onData([]byte(movedPayload(87, "in-progress", "review", "agent")))
	h.router.wg.Wait()

	calls := h.worker.recorded()
	if len(calls) != 1 {
		t.Fatalf("expected one worker, got %+v", calls)
	}
	if !v4UUID.MatchString(calls[0].runID) {
		t.Fatalf("run id %q is not a uuid", calls[0].runID)
	}
	schema := `{"properties":{"reason":{"type":"string"},"status":{"enum":["finished","blocked","unfinished","waiting"],"type":"string"},"summary":{"type":"string"}},"required":["status","summary"],"type":"object"}`
	want := workerSpec{
		dir: h.dir, permissionMode: "plan", model: "opus", schema: schema, sessionID: testSession,
		prompt: "Review " + testCard + " in loupe, for review.\n\n" + directive.Footer,
		runID:  calls[0].runID, rule: "work:review", key: testCard,
	}
	if calls[0] != want {
		t.Fatalf("worker = %+v, want %+v", calls[0], want)
	}
	if got := str(t, h.only(t, "worker_started"), "rule"); got != "work:review" {
		t.Fatalf("rule = %q", got)
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
	h.worker.result = workerResult{output: "moved card 87 to in-progress", hasResult: true}

	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()

	finished := h.only(t, "worker_finished")
	if got := str(t, finished, "output"); got != "moved card 87 to in-progress" {
		t.Fatalf("output = %q", got)
	}
	if str(t, finished, "level") != "INFO" {
		t.Fatalf("a successful worker logged at %q", finished["level"])
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
	active, waiting := h.router.usedLocked(), len(h.router.queue)
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

const reviewRule = `
  review:
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
	if strings.Join(order, " ") != "work:plan work:review work:plan" {
		t.Fatalf("card 87 ran %v, want plan review plan", order)
	}
}

// send delivers one payload and waits for any worker it started.
func (h *harness) send(payload string) {
	h.router.onData([]byte(payload))
	h.router.wg.Wait()
}

func (h *harness) runs() int {
	return len(h.worker.recorded())
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

// TestTheBoundLimitsConcurrentWorkers checks the maxWorkers budget. peak
// is monotonic, so the check after wg.Wait reads the highest concurrency the
// run ever reached.
func TestTheBoundLimitsConcurrentWorkers(t *testing.T) {
	h := newHarnessWith(t, withMaxWorkers(defaultRules, 2), rules.Defaults{})
	h.worker.started = make(chan workerSpec, 4)
	h.worker.block = make(chan struct{})

	for _, card := range []int{87, 88, 89, 90} {
		h.router.onData([]byte(cardMoved(card)))
	}

	// onData dispatches on this goroutine and nothing finishes while block is
	// held, so the counts here are settled rather than sampled.
	h.router.mu.Lock()
	active, queued := h.router.usedLocked(), len(h.router.queue)
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
	h := newHarnessWith(t, withMaxWorkers(defaultRules, 1), rules.Defaults{})
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
	h := newHarnessWith(t, withMaxWorkers(defaultRules, 1), rules.Defaults{})
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
	h := newHarnessWith(t, withMaxWorkers(defaultRules, 1), rules.Defaults{})
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
	if got := strings.Join(dropped(t, line), " "); got != "88/work:plan 89/work:plan" {
		t.Fatalf("dropped = %s, want 88/work:plan 89/work:plan", got)
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
	if got := strings.Join(dropped(t, line), " "); num(t, line, "count") != 2 || got != "87/work:review 87/work:plan" {
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
	if got := strings.Join(dropped(t, line), " "); num(t, line, "count") != 1 || got != "87/work:plan" {
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
	h := newHarnessWith(t, withMaxWorkers(defaultRules, 1), rules.Defaults{})
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
	if got := strings.Join(dropped(t, line), " "); num(t, line, "count") != 1 || got != "88/work:plan" {
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
	h := newHarnessWith(t, defaultRules, rules.Defaults{})
	h.worker.result = workerResult{exitCode: 2, output: "claude: permission denied", hasResult: true}

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

// claude -p can end a worker mid-task and still exit 0. A run with no result
// line is a failure, whatever its exit code.
func TestARunWithNoResultLineIsAnError(t *testing.T) {
	for _, exit := range []int{0, 1} {
		h := newHarnessWith(t, defaultRules, rules.Defaults{})
		h.worker.result = workerResult{exitCode: exit, output: "waiting on a task"}

		h.router.onData([]byte(cardMoved(87)))
		h.router.wg.Wait()

		line := h.only(t, "worker_no_result")
		if str(t, line, "level") != "ERROR" || num(t, line, "exit") != exit || num(t, line, "card") != 87 {
			t.Fatalf("worker_no_result = %v", line)
		}
		if str(t, line, "rule") != "work:plan" || str(t, line, "output") != "waiting on a task" || line["duration_ms"] == nil {
			t.Fatalf("worker_no_result = %v", line)
		}
		if got := h.events(t, "worker_finished"); len(got) != 0 {
			t.Fatalf("a run with no result also logged worker_finished: %v", got)
		}
	}
}

// The bridge killed a worker it shut down, so the missing result line is the
// bridge's doing and the line says the worker finished.
func TestAWorkerTheBridgeKilledLogsFinished(t *testing.T) {
	h := newHarness(t)
	ctx, cancel := context.WithCancel(context.Background())
	h.router.ctx = ctx
	h.worker.started = make(chan workerSpec, 1)
	h.worker.block = make(chan struct{})
	h.worker.result = workerResult{exitCode: -1, output: "working", killed: true}

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	cancel()
	close(h.worker.block)
	h.router.wg.Wait()

	finished := h.only(t, "worker_finished")
	if str(t, finished, "level") != "ERROR" || num(t, finished, "exit") != -1 {
		t.Fatalf("worker_finished = %v", finished)
	}
	if got := h.events(t, "worker_no_result"); len(got) != 0 {
		t.Fatalf("a killed worker logged worker_no_result: %v", got)
	}
}

// A worker that exits on its own while the bridge shuts down is judged by its
// own result, not by the cancelled context.
func TestAWorkerThatFinishedDuringShutdownKeepsItsOutcome(t *testing.T) {
	h := newHarness(t)
	ctx, cancel := context.WithCancel(context.Background())
	h.router.ctx = ctx
	h.worker.started = make(chan workerSpec, 1)
	h.worker.block = make(chan struct{})
	h.worker.result = workerResult{output: "STAGE RESULT: ready", hasResult: true}

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	cancel()
	close(h.worker.block)
	h.router.wg.Wait()

	finished := h.only(t, "worker_finished")
	if str(t, finished, "level") != "INFO" || num(t, finished, "exit") != 0 {
		t.Fatalf("worker_finished = %v", finished)
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
	if str(t, failed, "error") != "boom" || str(t, failed, "rule") != "work:plan" {
		t.Fatalf("worker_failed = %v", failed)
	}
	if got := append(h.events(t, "worker_finished"), h.events(t, "worker_no_result")...); len(got) != 0 {
		t.Fatalf("a worker that never ran also reported finishing: %v", got)
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

// A hold event the bridge cannot read is logged and acts on nothing.
func TestIncompleteCardEventIsReportedAndDropped(t *testing.T) {
	for _, payload := range []string{
		`{"type":"board.card_held","subject":{"type":"card","id":"` + testCard + `"},"cardNumber":87,"actor":"human"}`,
		`{"type":"board.card_held","subject":{"type":"card","id":"card-87"},"projectId":"` + testProject + `","actor":"human"}`,
		`{"type":"board.card_released","subject":{"type":"card","id":"` + testCard + `"},"projectId":"` + testProject + `"}`,
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
	h := newHarnessWith(t, defaultRules, rules.Defaults{})
	events := []string{"worker_queued"}
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
