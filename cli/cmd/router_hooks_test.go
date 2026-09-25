package cmd

import (
	"context"
	"errors"
	"slices"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/hooks"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// withHookRunner gives the harness a runner whose loop never starts, so its
// inbox keeps every busy and idle the router hands it.
func (h *harness) withHookRunner() *hookRunner {
	hr := newHookRunner(nil, testBridgeID, newBridgeLogger(h.log))
	h.router.hookRunner = hr

	return hr
}

// fired is the inbox of a runner whose loop never started.
func (hr *hookRunner) fired() []string {
	hr.mu.Lock()
	defer hr.mu.Unlock()

	var events []string
	for _, job := range hr.inbox {
		events = append(events, job.event)
	}

	return events
}

func wantFired(t *testing.T, hr *hookRunner, want ...string) {
	t.Helper()
	if got := hr.fired(); !slices.Equal(got, want) {
		t.Fatalf("fired = %v, want %v", got, want)
	}
}

// The bridge turns busy at its first queued or running card, and idle once
// none is left. A second card that waits for a slot fires nothing.
func TestTheBridgeIsBusyFromTheFirstCardToTheLast(t *testing.T) {
	h := newHarness(t)
	hr := h.withHookRunner()
	h.router.maxWorkers = 1
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(88)))
	h.router.onData([]byte(cardMoved(88)))
	wantFired(t, hr, hookBusy)

	close(h.worker.block)
	h.router.wg.Wait()
	wantFired(t, hr, hookBusy, hookIdle)

	h.send(cardMoved(89))
	wantFired(t, hr, hookBusy, hookIdle, hookBusy, hookIdle)
}

// An ask check holds its card, so the bridge is busy while it runs. A skipped
// resume leaves the bridge idle.
func TestAnAskCheckKeepsTheBridgeBusy(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	hr := h.withHookRunner()
	c := &checks{state: api.AskState{AskID: testAsk, Closed: true, AllRead: true}}
	h.router.checkAsk = c.check

	h.send(h.mine(ask{card: 87}))

	if calls := h.worker.recorded(); len(calls) != 0 {
		t.Fatalf("workers = %+v", calls)
	}
	wantFired(t, hr, hookBusy, hookIdle)
}

// A capped card holds nothing, so it does not make the bridge busy.
func TestAChainCappedCardLeavesTheBridgeIdle(t *testing.T) {
	h := newHarnessWith(t, chainRules, rules.Defaults{})
	hr := h.withHookRunner()

	h.send(movedPayload(87, "backlog", "next", "human"))
	h.send(movedPayload(87, "backlog", "next", "agent"))
	h.send(movedPayload(87, "backlog", "next", "agent"))
	h.send(movedPayload(87, "backlog", "next", "agent"))

	h.only(t, "chain_capped")
	wantFired(t, hr, hookBusy, hookIdle, hookBusy, hookIdle, hookBusy, hookIdle)
}

// A kill and a reload that drop a waiting card keep the bridge busy while a
// worker runs, and it turns idle when the worker ends.
func TestADroppedQueueTurnsIdleWithItsLastWorker(t *testing.T) {
	for name, drop := range map[string]func(h *harness){
		"kill":   func(h *harness) { h.router.onData([]byte(projectRenamed())) },
		"reload": func(h *harness) { h.reload(t, strings.Replace(defaultRules, "name: plan", "name: build", 1)) },
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarness(t)
			hr := h.withHookRunner()
			h.router.maxWorkers = 1
			h.worker.started = make(chan workerSpec, 2)
			h.worker.block = make(chan struct{})

			h.router.onData([]byte(cardMoved(87)))
			<-h.worker.started
			h.router.onData([]byte(cardMoved(88)))
			drop(h)
			h.only(t, "queue_dropped")
			wantFired(t, hr, hookBusy)

			close(h.worker.block)
			h.router.wg.Wait()
			wantFired(t, hr, hookBusy, hookIdle)
		})
	}
}

// After shutdown the runner gets no idle, so stop alone ends the bridge.
func TestShutdownFiresNoIdle(t *testing.T) {
	h := newHarness(t)
	hr := h.withHookRunner()
	h.router.maxWorkers = 1
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(88)))
	h.router.shutdown()
	close(h.worker.block)
	h.router.wg.Wait()

	wantFired(t, hr, hookBusy)
}

// A reload that cannot resolve the hooks fails at the hooks stage and changes
// nothing.
func TestAReloadWhoseHooksFailChangesNothing(t *testing.T) {
	h := newHarness(t)
	hr := newHookRunner([]hooks.Hook{testHook("acme/old", h.dir, hookBusy)}, testBridgeID, newBridgeLogger(h.log))
	h.router.hookRunner = hr
	before := h.router.rules()
	src := h.source(twoRuleFile)
	src.resolveHooks = func(*rules.Set) ([]hooks.Hook, error) {
		return nil, errors.New("hook acme/new: the package is not installed")
	}

	res := h.router.reload(context.Background(), src)

	if res.OK || res.Stage != "hooks" || !slices.Equal(res.Problems, []string{"hook acme/new: the package is not installed"}) {
		t.Fatalf("reload = %+v", res)
	}
	if h.router.rules() != before {
		t.Fatal("the set changed")
	}
	if got := hr.rows(); len(got) != 1 || got[0].Package != "acme/old" {
		t.Fatalf("rows = %+v", got)
	}
}

// A reload hands the hooks it resolved from the new set to the runner.
func TestAReloadAppliesTheNewHooks(t *testing.T) {
	h := newHarness(t)
	hr := newHookRunner([]hooks.Hook{testHook("acme/old", h.dir, hookBusy)}, testBridgeID, newBridgeLogger(h.log))
	h.router.hookRunner = hr
	src := h.source(twoRuleFile)
	var resolved *rules.Set
	src.resolveHooks = func(set *rules.Set) ([]hooks.Hook, error) {
		resolved = set

		return []hooks.Hook{testHook("acme/new", h.dir, hookIdle)}, nil
	}

	if res := h.router.reload(context.Background(), src); !res.OK {
		t.Fatalf("reload = %+v", res)
	}
	if resolved != h.router.rules() {
		t.Fatal("the hooks came from another set")
	}
	if got := hr.rows(); len(got) != 1 || got[0].Package != "acme/new" || got[0].Event != hookIdle {
		t.Fatalf("rows = %+v", got)
	}
}
