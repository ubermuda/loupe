package cmd

import (
	"context"
	"maps"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// poolRules gives review a quick pool of 1, and plan the 3 default slots.
const poolRules = `
maxWorkers: 4
workerPools:
  quick:
    size: 1
projects:
  loupe:
    dir: {dir}
work:
  plan:
    prompt: Card {cardNumber}.
  review:
    workerPool: quick
    prompt: Review {cardNumber}.
`

// quickRules puts plan in a quick pool of 1, and leaves 1 default slot.
const quickRules = `
maxWorkers: 2
workerPools:
  quick:
    size: 1
projects:
  loupe:
    dir: {dir}
work:
  plan:
    workerPool: quick
    prompt: Card {cardNumber}.
`

// inUse copies the slots the router holds in each pool.
func (h *harness) inUse() map[string]int {
	h.router.mu.Lock()
	defer h.router.mu.Unlock()

	return maps.Clone(h.router.inUse)
}

func wantInUse(t *testing.T, h *harness, want map[string]int) {
	t.Helper()
	if got := h.inUse(); !maps.Equal(got, want) {
		t.Fatalf("slots in use = %v, want %v", got, want)
	}
}

func reviewMoved(number int) string {
	return movedPayload(number, "backlog", "review", "human")
}

// lineOf is the one line of the event name that names the card.
func lineOf(t *testing.T, h *harness, name string, card int) map[string]any {
	t.Helper()
	var out []map[string]any
	for _, line := range h.events(t, name) {
		if num(t, line, "card") == card {
			out = append(out, line)
		}
	}
	if len(out) != 1 {
		t.Fatalf("%d %s lines for card %d: %v", len(out), name, card, out)
	}

	return out[0]
}

// A quick event starts at once while the default pool is full.
func TestAQuickEventStartsWhileTheDefaultPoolIsFull(t *testing.T) {
	h := newHarnessWith(t, poolRules, rules.Defaults{})
	h.worker.started = make(chan workerSpec, 4)
	h.worker.block = make(chan struct{})
	for _, card := range []int{87, 88, 89} {
		h.router.onData([]byte(cardMoved(card)))
		<-h.worker.started
	}

	h.router.onData([]byte(reviewMoved(90)))

	wantInUse(t, h, map[string]int{rules.DefaultPool: 3, "quick": 1})
	if got := h.queued(); len(got) != 0 {
		t.Fatalf("queue = %v", got)
	}
	close(h.worker.block)
	h.router.wg.Wait()
	if pool := str(t, lineOf(t, h, "worker_started", 90), "worker_pool"); pool != "quick" {
		t.Fatalf("worker_pool = %q", pool)
	}
	wantInUse(t, h, map[string]int{})
}

// A full pool holds its events back and lets the events of another pool pass.
func TestAFullPoolQueuesItsEventAndNotTheOthers(t *testing.T) {
	h := newHarnessWith(t, poolRules, rules.Defaults{})
	rec := h.states()
	h.worker.started = make(chan workerSpec, 3)
	h.worker.block = make(chan struct{})
	h.router.onData([]byte(reviewMoved(90)))
	<-h.worker.started

	h.router.onData([]byte(reviewMoved(91)))
	h.router.onData([]byte(cardMoved(87)))

	wantInUse(t, h, map[string]int{rules.DefaultPool: 1, "quick": 1})
	if got := h.queued(); !slices.Equal(got, []string{"0091/work:review"}) {
		t.Fatalf("queue = %v", got)
	}
	quick := lineOf(t, h, "worker_queued", 91)
	if str(t, quick, "worker_pool") != "quick" || num(t, quick, "pool_depth") != 1 || num(t, quick, "queue_depth") != 1 {
		t.Fatalf("worker_queued = %v", quick)
	}
	plan := lineOf(t, h, "worker_queued", 87)
	if str(t, plan, "worker_pool") != rules.DefaultPool || num(t, plan, "pool_depth") != 1 || num(t, plan, "queue_depth") != 2 {
		t.Fatalf("worker_queued = %v", plan)
	}
	for _, s := range rec.states() {
		want := rules.DefaultPool
		if s.report.CardNumber >= 90 {
			want = "quick"
		}
		if s.report.WorkerPool != want {
			t.Fatalf("the %s report of card %d names pool %q, want %q", s.report.State, s.report.CardNumber, s.report.WorkerPool, want)
		}
	}

	close(h.worker.block)
	h.router.wg.Wait()
	// Each start waits for its claim, so 87 and 91 can log in either order.
	if got := startedCards(t, h); len(got) != 3 || got[0] != 90 || !slices.Contains(got, 87) || !slices.Contains(got, 91) {
		t.Fatalf("started %v", got)
	}
	wantInUse(t, h, map[string]int{})
}

// A reload that shrinks a pool stops no worker, and the pool takes no new run
// until its runs fit in the new size.
func TestAReloadThatShrinksAPoolStopsNoWorker(t *testing.T) {
	h := newHarness(t)
	h.worker.started = make(chan workerSpec, 4)
	h.worker.block = make(chan struct{})
	for _, card := range []int{87, 88, 89} {
		h.router.onData([]byte(cardMoved(card)))
		<-h.worker.started
	}

	res := h.reload(t, "workerPools:\n  quick:\n    size: 1\n"+defaultRules)
	if !res.OK {
		t.Fatalf("result = %+v", res)
	}
	applied := h.only(t, "reload_applied")
	if got := applied["pools"]; !slices.Equal(anyStrings(t, got), []string{"default: 3 -> 2", "quick: added 1"}) {
		t.Fatalf("reload_applied = %v", applied)
	}
	if _, ok := applied["max_workers"]; ok {
		t.Fatalf("reload_applied = %v names an unchanged budget", applied)
	}
	wantInUse(t, h, map[string]int{rules.DefaultPool: 3})

	h.router.onData([]byte(cardMoved(90)))
	h.worker.block <- struct{}{}
	eventually(t, "one run to end", func() bool { return h.used() == 2 })
	if got := h.queued(); !slices.Equal(got, []string{"0090/work:plan"}) {
		t.Fatalf("queue = %v", got)
	}
	h.worker.block <- struct{}{}
	<-h.worker.started

	close(h.worker.block)
	h.router.wg.Wait()
	if got := startedCards(t, h); len(got) != 4 || got[3] != 90 {
		t.Fatalf("started %v", got)
	}
	if n := len(h.events(t, "worker_finished")); n != 4 {
		t.Fatalf("%d workers finished, want 4", n)
	}
}

// A reload that raises the budget keeps the queue and starts it.
func TestAReloadThatRaisesTheBudgetStartsTheQueue(t *testing.T) {
	h := newHarness(t)
	h.worker.started = make(chan workerSpec, 5)
	h.worker.block = make(chan struct{})
	for _, card := range []int{87, 88, 89} {
		h.router.onData([]byte(cardMoved(card)))
		<-h.worker.started
	}
	h.router.onData([]byte(cardMoved(90)))
	h.router.onData([]byte(cardMoved(91)))

	if res := h.reload(t, withMaxWorkers(defaultRules, 5)); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	<-h.worker.started
	<-h.worker.started

	if got := h.queued(); len(got) != 0 {
		t.Fatalf("queue = %v", got)
	}
	wantInUse(t, h, map[string]int{rules.DefaultPool: 5})
	applied := h.only(t, "reload_applied")
	if num(t, applied, "max_workers") != 5 || !slices.Equal(anyStrings(t, applied["pools"]), []string{"default: 3 -> 5"}) {
		t.Fatalf("reload_applied = %v", applied)
	}
	if len(h.events(t, "queue_dropped")) != 0 {
		t.Fatal("the reload dropped a queued event")
	}
	close(h.worker.block)
	h.router.wg.Wait()
}

// An interactive match takes no slot, so it launches while every pool is full.
func TestAnInteractiveMatchLaunchesWhileEveryPoolIsFull(t *testing.T) {
	h, rec := launchHarnessWith(t, withMaxWorkers(launchRules, 1), `[sh, -c, 'exit 0', sh, '{script}']`)
	h.worker.started = make(chan workerSpec, 1)
	h.worker.block = make(chan struct{})
	h.router.onData([]byte(reviewMoved(87)))
	<-h.worker.started

	h.router.onData([]byte(cardMoved(88)))
	eventually(t, "the launch", func() bool { return len(rec.launches()) == 1 })

	if _, ok := h.only(t, "session_launching")["worker_pool"]; ok {
		t.Fatal("a launch names a worker pool")
	}
	wantInUse(t, h, map[string]int{rules.DefaultPool: 1})
	close(h.worker.block)
	h.router.wg.Wait()
}

// A run keeps the slot of the pool it started in when a reload moves its rule,
// and its end frees that pool.
func TestARunThatAReloadMovesFreesThePoolItStartedIn(t *testing.T) {
	body := quickRules + `
  review:
    prompt: Review {cardNumber}.
`
	h := newHarnessWith(t, body, rules.Defaults{})
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})
	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started

	moved := `
maxWorkers: 2
workerPools:
  quick:
    size: 1
projects:
  loupe:
    dir: {dir}
work:
  plan:
    prompt: Card {cardNumber}.
  review:
    workerPool: quick
    prompt: Review {cardNumber}.
`
	if res := h.reload(t, moved); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	h.router.onData([]byte(reviewMoved(88)))

	if got := h.queued(); !slices.Equal(got, []string{"0088/work:review"}) {
		t.Fatalf("queue = %v", got)
	}
	wantInUse(t, h, map[string]int{"quick": 1})
	h.worker.block <- struct{}{}
	<-h.worker.started
	wantInUse(t, h, map[string]int{"quick": 1})

	close(h.worker.block)
	h.router.wg.Wait()
	wantInUse(t, h, map[string]int{})
}

// A second release of one slot gives no slot back.
func TestADoubleReleaseAddsNoSlot(t *testing.T) {
	r := &router{}
	r.takeLocked("quick")
	r.releaseLocked("quick")
	r.releaseLocked("quick")

	if len(r.inUse) != 0 {
		t.Fatalf("slots in use = %v, want none", r.inUse)
	}
}

// A run in a pool that a reload removes counts against the budget until it
// ends.
func TestARunInARemovedPoolCountsUntilItEnds(t *testing.T) {
	h := newHarnessWith(t, quickRules, rules.Defaults{})
	h.worker.started = make(chan workerSpec, 3)
	h.worker.block = make(chan struct{})
	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started

	if res := h.reload(t, withMaxWorkers(defaultRules, 2)); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	if got := anyStrings(t, h.only(t, "reload_applied")["pools"]); !slices.Equal(got, []string{"default: 1 -> 2", "quick: removed"}) {
		t.Fatalf("pools = %v", got)
	}
	h.router.onData([]byte(cardMoved(88)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(89)))

	wantInUse(t, h, map[string]int{rules.DefaultPool: 1, "quick": 1})
	if got := h.queued(); !slices.Equal(got, []string{"0089/work:plan"}) {
		t.Fatalf("queue = %v", got)
	}
	h.worker.block <- struct{}{}
	<-h.worker.started

	close(h.worker.block)
	h.router.wg.Wait()
	wantInUse(t, h, map[string]int{})
}

// A dropped event names its pool.
func TestAShutdownNamesThePoolOfEachDroppedEvent(t *testing.T) {
	h := newHarnessWith(t, poolRules, rules.Defaults{})
	rec := h.states()
	h.worker.started = make(chan workerSpec, 1)
	h.worker.block = make(chan struct{})
	h.router.onData([]byte(reviewMoved(90)))
	<-h.worker.started
	h.router.onData([]byte(reviewMoved(91)))

	h.router.shutdown()
	close(h.worker.block)
	h.router.wg.Wait()

	entries, ok := h.only(t, "queue_dropped")["dropped"].([]any)
	if !ok || len(entries) != 1 || entries[0].(map[string]any)["worker_pool"] != "quick" {
		t.Fatalf("dropped = %v", entries)
	}
	for _, s := range rec.states() {
		if s.report.State == api.RunDropped && s.report.WorkerPool != "quick" {
			t.Fatalf("dropped report = %+v", s.report)
		}
	}
}

// sentPools returns the pool rows the router last handed to the heartbeater.
func sentPools(hb *heartbeater) []api.WorkerPoolReport {
	hb.mu.Lock()
	defer hb.mu.Unlock()

	return hb.pools
}

// The heartbeat names every pool of the set, an empty default pool too, and a
// pool a reload removed while one of its runs still runs.
func TestTheRouterReportsEachPoolToTheHeartbeat(t *testing.T) {
	onlyQuick := "maxWorkers: 1\nworkerPools:\n  quick:\n    size: 1\n" + strings.Replace(defaultRules, "  plan:\n", "  plan:\n    workerPool: quick\n", 1)
	h := newHarnessWith(t, onlyQuick, rules.Defaults{})
	hb := newHeartbeater(context.Background(), syncQueue{}, nil, testBridgeID, api.Heartbeat{}, time.Minute, h.router.log)
	h.router.heartbeat = hb
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})
	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(88)))

	want := []api.WorkerPoolReport{{Name: rules.DefaultPool}, {Name: "quick", Size: 1, InUse: 1, Queued: 1}}
	if got := sentPools(hb); !slices.Equal(got, want) {
		t.Fatalf("pools = %+v, want %+v", got, want)
	}

	if res := h.reload(t, withMaxWorkers(defaultRules, 2)); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	<-h.worker.started
	want = []api.WorkerPoolReport{{Name: rules.DefaultPool, Size: 2, InUse: 1}, {Name: "quick", InUse: 1}}
	if got := sentPools(hb); !slices.Equal(got, want) {
		t.Fatalf("pools after the reload = %+v, want %+v", got, want)
	}

	close(h.worker.block)
	h.router.wg.Wait()
	want = []api.WorkerPoolReport{{Name: rules.DefaultPool, Size: 2}}
	if got := sentPools(hb); !slices.Equal(got, want) {
		t.Fatalf("pools at the end = %+v, want %+v", got, want)
	}
}

func anyStrings(t *testing.T, v any) []string {
	t.Helper()
	raw, ok := v.([]any)
	if !ok {
		t.Fatalf("%v is not a list", v)
	}
	out := make([]string, len(raw))
	for i, s := range raw {
		out[i], _ = s.(string)
	}

	return out
}
