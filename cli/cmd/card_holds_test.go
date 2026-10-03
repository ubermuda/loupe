package cmd

import (
	"context"
	"errors"
	"slices"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
)

// holdLists answers each read of the held cards with its list, or its error.
type holdLists struct {
	holds []api.CardHold
	err   error
	reads int
}

func (l *holdLists) read(context.Context) ([]api.CardHold, error) {
	l.reads++

	return l.holds, l.err
}

func (h *harness) holdCard(number int) {
	h.router.mu.Lock()
	h.router.holdCardLocked(cardUUID(number))
	h.router.mu.Unlock()
}

// withHoldList marks the held list as read, so the bridge follows the holds
// of the server alone.
func (h *harness) withHoldList() {
	h.router.mu.Lock()
	h.router.holdList = true
	h.router.mu.Unlock()
}

func (h *harness) cardHoldOf(number int) bool {
	h.router.mu.Lock()
	defer h.router.mu.Unlock()

	return h.router.cardHolds[cardUUID(number)]
}

// A list that leaves out a held card ends its hold.
func TestTheHeldListReleasesACardItLeavesOut(t *testing.T) {
	h := newHarness(t)
	h.withHoldList()
	h.holdCard(87)
	h.holdCard(88)
	h.router.readHolds = (&holdLists{holds: []api.CardHold{{ProjectID: testProject, CardID: strings.ToUpper(cardUUID(88))}}}).read

	h.router.handler().OnConnect()
	h.router.wg.Wait()

	if h.cardHoldOf(87) || !h.cardHoldOf(88) {
		t.Fatalf("held 87 = %v, held 88 = %v", h.cardHoldOf(87), h.cardHoldOf(88))
	}
	if released := h.only(t, "card_hold_released"); str(t, released, "card_id") != cardUUID(87) {
		t.Fatalf("card_hold_released = %v", released)
	}
}

// A list that names a card holds it, so a later move starts nothing.
func TestTheHeldListHoldsACard(t *testing.T) {
	h := newHarness(t)
	h.router.readHolds = (&holdLists{holds: []api.CardHold{{ProjectID: testProject, CardID: cardUUID(87)}}}).read

	h.router.handler().OnConnect()
	h.send(cardMoved(87))

	if h.runs() != 0 {
		t.Fatalf("runs = %d on a held card", h.runs())
	}
}

// An older server has no list. The holds stay, and the bridge says so once.
func TestAServerWithNoHeldListKeepsTheHolds(t *testing.T) {
	h := newHarness(t)
	h.holdCard(87)
	lists := &holdLists{err: api.ErrNoCardHolds}
	h.router.readHolds = lists.read

	h.router.handler().OnConnect()
	h.router.handler().OnConnect()

	if lists.reads != 2 || !h.cardHoldOf(87) {
		t.Fatalf("reads = %d, held = %v", lists.reads, h.cardHoldOf(87))
	}
	if line := h.only(t, "card_holds_unsupported"); str(t, line, "level") != "INFO" {
		t.Fatalf("card_holds_unsupported = %v", line)
	}
}

// A failed read keeps the holds and logs the error.
func TestAFailedHeldListKeepsTheHolds(t *testing.T) {
	h := newHarness(t)
	h.holdCard(87)
	h.router.readHolds = (&holdLists{err: errors.New("HTTP 500")}).read

	h.router.handler().OnConnect()

	if !h.cardHoldOf(87) {
		t.Fatal("a failed read ended the hold")
	}
	line := h.only(t, "card_holds_unreadable")
	if str(t, line, "level") != "WARN" || str(t, line, "error") != "HTTP 500" {
		t.Fatalf("card_holds_unreadable = %v", line)
	}
}

// A hold event moves the cursor. The hub can send its id again after a
// release, and that copy is a duplicate, so the card stays free.
func TestAHoldEventMovesTheCursorAndRunsOnce(t *testing.T) {
	h := newHarness(t)
	path := withCursor(t, h, 10, 10)

	h.router.onEvent("11", []byte(holdPayload("board.card_held", 87)))
	if st := readCursorFile(t, path); st.Cursor != 11 || !h.cardHoldOf(87) {
		t.Fatalf("cursor = %+v, held = %v", st, h.cardHoldOf(87))
	}
	h.router.onEvent("12", []byte(holdPayload("board.card_released", 87)))
	h.router.onEvent("11", []byte(holdPayload("board.card_held", 87)))

	if st := readCursorFile(t, path); st.Cursor != 12 {
		t.Fatalf("cursor = %+v", st)
	}
	if line := h.only(t, "event_duplicate"); str(t, line, "id") != "11" {
		t.Fatalf("event_duplicate = %v", line)
	}
	if h.cardHoldOf(87) {
		t.Fatal("a duplicate held the card again")
	}
	h.send(cardMoved(87))
	if h.runs() != 1 {
		t.Fatalf("runs = %d on a free card", h.runs())
	}
}

// On connect the list comes before the catch-up, so a replayed move of a card
// the list holds starts nothing.
func TestTheHeldListComesBeforeTheReplay(t *testing.T) {
	h := newHarness(t)
	withCursor(t, h, 10, 10)
	rep := &replayer{pages: []api.Replay{{Events: []api.ReplayEvent{
		{ID: "11", Type: "board.card_moved", Data: cardMoved(87)},
	}}}}
	h.router.replay = rep.replay
	lists := &holdLists{holds: []api.CardHold{{ProjectID: testProject, CardID: cardUUID(87)}}}
	h.router.readHolds = lists.read

	h.router.handler().OnConnect()
	h.router.wg.Wait()

	if h.runs() != 0 || lists.reads != 2 {
		t.Fatalf("runs = %d, reads = %d", h.runs(), lists.reads)
	}
}

// On connect the list comes after the catch-up too, so it wins over a
// replayed hold of a card the list leaves out.
func TestTheHeldListWinsOverAReplayedHold(t *testing.T) {
	h := newHarness(t)
	withCursor(t, h, 10, 10)
	rep := &replayer{pages: []api.Replay{{Events: []api.ReplayEvent{
		{ID: "11", Type: "board.card_held", Data: holdPayload("board.card_held", 87)},
	}}}}
	h.router.replay = rep.replay
	h.router.readHolds = (&holdLists{}).read

	h.router.handler().OnConnect()
	h.router.wg.Wait()

	if got := rep.called(); !slices.Equal(got, []int64{10}) {
		t.Fatalf("replay afters = %v", got)
	}
	if h.cardHoldOf(87) {
		t.Fatal("the replayed hold outlived the list")
	}
	if released := h.only(t, "card_hold_released"); str(t, released, "card_id") != cardUUID(87) {
		t.Fatalf("card_hold_released = %v", released)
	}
	h.send(cardMoved(87))
	if h.runs() != 1 {
		t.Fatalf("runs = %d after the list freed the card", h.runs())
	}
}

// Once a read of the held list works, the bridge follows the server, so a
// stop holds no card.
func TestAfterTheHeldListAStopHoldsNothing(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.router.readHolds = (&holdLists{}).read
	h.router.handler().OnConnect()
	h.router.wg.Wait()
	h.reply(pausedReply(true))
	h.send(cardMoved(87))

	h.stop(t, firstRun(t, rec))
	h.reply(pausedReply(false))
	h.send(cardMoved(87))

	if h.cardHoldOf(87) || h.runs() != 1 {
		t.Fatalf("held = %v, runs = %d", h.cardHoldOf(87), h.runs())
	}
}

// A server that loses the list after a read holds a card on a stop again, so
// the bridge does too.
func TestAServerThatLosesTheHeldListTurnsTheStopHoldBackOn(t *testing.T) {
	h := newHarness(t)
	lists := &holdLists{}
	h.router.readHolds = lists.read
	h.router.handler().OnConnect()
	lists.err = api.ErrNoCardHolds
	h.router.handler().OnConnect()

	h.router.mu.Lock()
	on := h.router.holdList
	h.router.mu.Unlock()
	if on {
		t.Fatal("the bridge kept the held list mode after a 404")
	}
}

// A bridge with no reader keeps its holds.
func TestNoHeldListReaderKeepsTheHolds(t *testing.T) {
	h := newHarness(t)
	h.holdCard(87)

	h.router.handler().OnConnect()

	if !h.cardHoldOf(87) || len(h.events(t, "card_hold_released")) != 0 {
		t.Fatal("a bridge with no reader ended the hold")
	}
}
