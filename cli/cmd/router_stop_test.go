package cmd

import (
	"context"
	"fmt"
	"slices"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
)

// stopper stands in for the signaller and the timer of the stop ladder. The
// test fires each wait with tick, and gone makes the probe find no group.
type stopper struct {
	mu      sync.Mutex
	sent    []stopSignal
	waits   []time.Duration
	gone    bool
	signals chan stopSignal
	tick    chan time.Time
	// on runs after each signal the ladder sends, outside mu.
	on func(stopSignal)
}

func (h *harness) stopper() *stopper {
	s := &stopper{signals: make(chan stopSignal, 8), tick: make(chan time.Time)}
	h.router.signal, h.router.stopAfter = s.signal, s.after

	return s
}

func (s *stopper) signal(_ int, sig stopSignal) error {
	s.mu.Lock()
	s.sent = append(s.sent, sig)
	gone := s.gone
	s.mu.Unlock()
	if sig == stopProbe {
		if gone {
			return errGroupGone
		}

		return nil
	}
	if s.on != nil {
		s.on(sig)
	}
	s.signals <- sig

	return nil
}

func (s *stopper) after(d time.Duration) <-chan time.Time {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.waits = append(s.waits, d)

	return s.tick
}

func (s *stopper) recorded() ([]stopSignal, []time.Duration) {
	s.mu.Lock()
	defer s.mu.Unlock()

	return slices.Clone(s.sent), slices.Clone(s.waits)
}

// next waits for the next signal the ladder sends.
func (s *stopper) next(t *testing.T) stopSignal {
	t.Helper()
	select {
	case sig := <-s.signals:
		return sig
	case <-time.After(5 * time.Second):
		t.Fatal("the ladder sent no signal")

		return stopProbe
	}
}

// stopOf is a stop command for the run.
func stopOf(runID string) api.Command {
	c := testCommand(testCommandID, api.CommandStopRun, testBridgeID, soon())
	c.RunKey = runID

	return c
}

// stop sends the stop of the run and checks it is done.
func (h *harness) stop(t *testing.T, runID string) {
	t.Helper()
	if state, reason := h.router.stopRun(stopOf(runID)); state != api.CommandDone {
		t.Fatalf("stop = %s %q, want done", state, reason)
	}
}

// firstRun is the id of the first run the router reported.
func firstRun(t *testing.T, rec *stateRecorder) string {
	t.Helper()
	var id string
	eventually(t, "a run report", func() bool {
		sent := rec.states()
		if len(sent) > 0 {
			id = sent[0].runID
		}

		return id != ""
	})

	return id
}

// blocked makes the worker hold until the test closes the returned channel.
func (h *harness) blocked() chan struct{} {
	h.worker.started, h.worker.block = make(chan workerSpec, 4), make(chan struct{})

	return h.worker.block
}

// A person's stop of a live worker reports stopping, then walks the ladder:
// SIGINT, SIGTERM after the first wait, and SIGKILL after the second. The end
// of the worker reports stopped, with no resume, and frees its slot and card.
func TestAStopWalksTheLadderAndReportsStopped(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	s := h.stopper()
	block := h.blocked()
	h.worker.result = unfinishedRun
	h.router.applyFlags(api.Events{Flags: map[string]any{api.StopSigtermFlag: float64(300), api.StopSigkillFlag: float64(200)}})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	runID := firstRun(t, rec)
	h.stop(t, runID)

	if sig := s.next(t); sig != stopInt {
		t.Fatalf("first signal = %s", sig)
	}
	s.tick <- time.Time{}
	if sig := s.next(t); sig != stopTerm {
		t.Fatalf("second signal = %s", sig)
	}
	s.tick <- time.Time{}
	if sig := s.next(t); sig != stopKill {
		t.Fatalf("third signal = %s", sig)
	}
	close(block)
	h.router.wg.Wait()

	sent, waits := s.recorded()
	if !slices.Equal(sent, []stopSignal{stopInt, stopTerm, stopKill}) || !slices.Equal(waits, []time.Duration{300 * time.Millisecond, 200 * time.Millisecond}) {
		t.Fatalf("signals = %v, waits = %v", sent, waits)
	}
	wantStates(t, rec.states(), api.RunQueued, api.RunRunning, api.RunStopping, api.RunStopped)
	stopped := outcomeOf(t, rec.states(), runID)
	if stopped.ExitCode != nil || stopped.HasResult != nil || stopped.ResultStatus != "" || stopped.EndedAt.IsZero() || stopped.Output != unfinishedRun.output {
		t.Fatalf("stopped = %+v", stopped)
	}
	if h.runs() != 1 || h.used() != 0 || h.cardHeld(87) {
		t.Fatalf("runs = %d, used = %d, card key held = %v", h.runs(), h.used(), h.cardHeld(87))
	}
	h.only(t, "worker_stopping")
	h.only(t, "worker_stopped")
	if len(h.events(t, "worker_resuming")) != 0 {
		t.Fatal("the bridge resumed a stopped run")
	}
}

// A worker that exits after SIGINT, with its group gone, ends the ladder, so
// no SIGTERM follows.
func TestAWorkerThatExitsOnSIGINTEndsTheLadder(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	s := h.stopper()
	block := h.blocked()
	s.gone = true
	s.on = func(sig stopSignal) {
		if sig == stopInt {
			close(block)
		}
	}

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.stop(t, firstRun(t, rec))
	h.router.wg.Wait()

	if sent, _ := s.recorded(); !slices.Equal(sent, []stopSignal{stopInt, stopProbe}) {
		t.Fatalf("signals = %v, want SIGINT and a probe", sent)
	}
	wantStates(t, rec.states(), api.RunQueued, api.RunRunning, api.RunStopping, api.RunStopped)
}

// With no held list read, the bridge treats the server as an older one. A
// stop holds the card, so a later offer starts nothing until the hold ends.
func TestWithNoHeldListAStopHoldsTheCard(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	stopRunning(t, h, rec)

	h.send(cardMoved(87))
	if h.runs() != 1 {
		t.Fatalf("runs = %d on a held card", h.runs())
	}
	h.only(t, "card_held")

	h.router.releaseHold(cardUUID(87))
	h.send(cardMoved(87))
	if h.runs() != 2 {
		t.Fatalf("runs = %d after the release", h.runs())
	}
	h.only(t, "card_hold_released")
}

// stopRunning starts a worker on card 87 and stops it, as a person does.
func stopRunning(t *testing.T, h *harness, rec *stateRecorder) {
	t.Helper()
	s := h.stopper()
	s.gone = true
	h.worker.started, h.worker.block = make(chan workerSpec, 1), make(chan struct{})
	s.on = func(sig stopSignal) {
		if sig == stopInt {
			close(h.worker.block)
		}
	}
	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.stop(t, firstRun(t, rec))
	h.router.wg.Wait()
	h.worker.started, h.worker.block = nil, nil
}

// A run that waits behind the stopped worker of its card starts when that
// worker ends, because the stop holds no card.
func TestARunQueuedBehindAStoppedWorkerStartsWhenItEnds(t *testing.T) {
	h := newHarness(t)
	h.withHoldList()
	rec := h.states()
	s := h.stopper()
	block := h.blocked()
	s.gone = true

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	first := firstRun(t, rec)
	h.router.onData([]byte(cardMoved(87)))
	h.stop(t, first)
	s.next(t)
	close(block)
	h.router.wg.Wait()
	if h.runs() != 2 {
		t.Fatalf("runs = %d after the stopped worker ended", h.runs())
	}
}

// A stop that comes while the worker spawns starts the ladder once the process
// exists.
func TestAStopDuringTheSpawnStartsTheLadderAtStart(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	s := h.stopper()
	s.gone = true
	spawn, block := make(chan struct{}), make(chan struct{})
	s.on = func(stopSignal) { close(block) }
	h.router.worker.run = func(_ context.Context, _ workerSpec, onStart func(workerProc)) workerResult {
		<-spawn
		onStart(workerProc{})
		<-block

		return unfinishedRun
	}

	h.router.onData([]byte(cardMoved(87)))
	h.stop(t, firstRun(t, rec))
	close(spawn)
	h.router.wg.Wait()

	if sent, _ := s.recorded(); len(sent) == 0 || sent[0] != stopInt {
		t.Fatalf("signals = %v", sent)
	}
	wantStates(t, rec.states(), api.RunQueued, api.RunRunning, api.RunStopping, api.RunStopped)
}

// A run the bridge never held, and a run already closed, are refused.
func TestAStopOfARunTheBridgeDoesNotHoldIsRefused(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.send(cardMoved(87))
	closed := firstRun(t, rec)

	for _, runID := range []string{closed, "0199a0e2-0000-7c5e-9f2a-000000000099"} {
		if state, reason := h.router.stopRun(stopOf(runID)); state != api.CommandRefused || reason != noOpenRun {
			t.Fatalf("stop of %s = %s %q", runID, state, reason)
		}
	}
	h.send(cardMoved(87))
	if h.runs() != 2 {
		t.Fatalf("runs = %d, a refused stop held the card", h.runs())
	}
}

// A hold of the server skips the offers of its card, and an offer after the
// release runs as usual.
func TestTheHoldOfTheServerSkipsAndReleasesACard(t *testing.T) {
	h := newHarness(t)

	h.send(holdPayload("board.card_held", 87))
	h.send(cardMoved(87))
	h.send(cardMoved(88))
	if got := startedCards(t, h); !slices.Equal(got, []int{88}) {
		t.Fatalf("started = %v", got)
	}
	h.only(t, "card_held")

	h.send(holdPayload("board.card_released", 87))
	h.send(cardMoved(87))
	if got := startedCards(t, h); !slices.Equal(got, []int{88, 87}) {
		t.Fatalf("started = %v after the release", got)
	}
	h.only(t, "card_hold_released")
}

// holdPayload is a board.card_held or board.card_released event of the card.
func holdPayload(typ string, number int) string {
	return fmt.Sprintf(`{"type":%q,"subject":{"type":"card","id":%q},"projectId":%q,"cardNumber":%d,"actor":"human"}`,
		typ, cardUUID(number), testProject, number)
}

// A card_held event holds the card, so a move of the card starts nothing.
func TestAHeldEventHoldsTheCard(t *testing.T) {
	h := newHarness(t)

	h.send(holdPayload("board.card_held", 87))
	h.send(cardMoved(87))

	if h.runs() != 0 {
		t.Fatalf("runs = %d on a held card", h.runs())
	}
	h.only(t, "card_held")
}

// A card_released event ends the hold.
func TestAReleasedEventEndsTheHold(t *testing.T) {
	h := newHarness(t)
	h.withHoldList()

	h.send(holdPayload("board.card_held", 87))
	if !h.cardHoldOf(87) {
		t.Fatal("the card is not held")
	}

	h.send(holdPayload("board.card_released", 87))
	if h.cardHoldOf(87) {
		t.Fatal("the card is still held after the release")
	}
	h.only(t, "card_hold_released")
}

// A hold event with a bad field is malformed, and holds nothing.
func TestAMalformedHoldEventIsLogged(t *testing.T) {
	h := newHarness(t)

	h.send(`{"type":"board.card_held","subject":{"type":"card","id":"card-uuid"},"projectId":"` + testProject + `","actor":"human"}`)
	h.send(cardMoved(87))

	h.only(t, "event_malformed")
	if h.runs() != 1 {
		t.Fatalf("runs = %d after a malformed hold", h.runs())
	}
}

// The stop waits come from the flags, and a value below 100 ms or a missing
// one reads as the default.
func TestTheStopWaitsComeFromTheFlags(t *testing.T) {
	for name, tc := range map[string]struct {
		flags map[string]any
		want  stopWaits
	}{
		"missing":     {nil, stopWaits{defaultStopTerm, defaultStopKill}},
		"below floor": {map[string]any{api.StopSigtermFlag: float64(99), api.StopSigkillFlag: float64(0)}, stopWaits{defaultStopTerm, defaultStopKill}},
		"at floor":    {map[string]any{api.StopSigtermFlag: float64(100), api.StopSigkillFlag: float64(100)}, stopWaits{100 * time.Millisecond, 100 * time.Millisecond}},
		"set":         {map[string]any{api.StopSigtermFlag: float64(20000), api.StopSigkillFlag: float64(5000)}, stopWaits{20 * time.Second, 5 * time.Second}},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarness(t)
			h.router.applyFlags(api.Events{Flags: tc.flags})
			h.router.mu.Lock()
			defer h.router.mu.Unlock()
			if h.router.stopWaits != tc.want {
				t.Fatalf("waits = %+v, want %+v", h.router.stopWaits, tc.want)
			}
		})
	}
}

// A stop reaches the handler through the intake, and the answer is done.
func TestAStopCommandIsAnsweredDone(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	acks := h.withAcks()
	s := h.stopper()
	s.gone = true
	h.worker.started, h.worker.block = make(chan workerSpec, 1), make(chan struct{})
	s.on = func(sig stopSignal) {
		if sig == stopInt {
			close(h.worker.block)
		}
	}
	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started

	h.reply(api.HeartbeatReply{Commands: []api.Command{stopOf(firstRun(t, rec))}})
	h.router.wg.Wait()

	if got := acks.recorded(); !slices.Equal(got, []string{testBridgeID + " " + testCommandID + " done "}) {
		t.Fatalf("acks = %v", got)
	}
}

// A freeze refuses a stop that raced it, and the held cards reach the next
// image.
func TestAHandoverCarriesTheHoldsAndRefusesAStop(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.send(holdPayload("board.card_held", 87))
	block := h.blocked()
	defer close(block)
	h.router.onData([]byte(cardMoved(88)))
	<-h.worker.started
	runID := firstRun(t, rec)

	h.router.pause()
	st := h.router.freeze()
	if state, reason := h.router.stopRun(stopOf(runID)); state != api.CommandRefused || reason != handingOver {
		t.Fatalf("stop while frozen = %s %q", state, reason)
	}
	if !slices.Equal(st.Holds, []string{cardUUID(87)}) {
		t.Fatalf("holds = %v", st.Holds)
	}

	next := newHarness(t)
	next.router.worker.adopt = func(context.Context, string) workerResult { return finishedRun }
	next.router.adopt(roundTrip(t, st))
	next.send(cardMoved(87))
	if got := startedCards(t, next); len(got) != 0 {
		t.Fatalf("started = %v, want nothing on the held card", got)
	}
	next.only(t, "card_held")
}

// A stop can mark a run just before end() reports its outcome. That outcome
// drops the mark, so the drain of a handover does not wait for it.
func TestAnOutcomeDropsTheStopMark(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.send(cardMoved(87))
	p := pending{runID: firstRun(t, rec)}
	p.event.Subject.ID, p.event.CardNumber = cardUUID(87), 87

	h.router.mu.Lock()
	h.router.held[p.runID] = api.InventoryRun{RunID: p.runID, State: api.RunRunning}
	h.router.stops = map[string]bool{p.runID: true}
	h.router.emitLocked(p, api.RunStateReport{State: api.RunSucceeded})
	h.router.mu.Unlock()

	if _, _, _, stops, _ := h.router.inFlight(); stops != 0 {
		t.Fatalf("stops = %d after the outcome", stops)
	}
}

// A stored state that differs from the answer is logged.
func TestAnAckThatFindsAnotherStateIsLogged(t *testing.T) {
	h := newHarness(t)
	acks := h.withAcks()
	acks.stored = "expired"

	h.reply(api.HeartbeatReply{Commands: []api.Command{testCommand(testCommandID, api.CommandResumeRun, testBridgeID, soon())}})

	line := h.only(t, "command_ack_state")
	if str(t, line, "state") != api.CommandRefused || str(t, line, "stored") != "expired" {
		t.Fatalf("command_ack_state = %v", line)
	}
}
