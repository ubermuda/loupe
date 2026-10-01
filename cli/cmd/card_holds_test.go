package cmd

import (
	"context"
	"errors"
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

func (h *harness) cardHoldOf(number int) bool {
	h.router.mu.Lock()
	defer h.router.mu.Unlock()

	return h.router.cardHolds[cardUUID(number)]
}

// A list that leaves out a held card ends its hold, and the run that waits
// for the card starts.
func TestTheHeldListReleasesACardItLeavesOut(t *testing.T) {
	h := newHarness(t)
	h.transcripts(true)
	h.router.readCard = (&cardReads{column: "next"}).read
	h.holdCard(87)
	h.holdCard(88)
	if state, _ := h.resume(resumeOf(endedRunKey)); state != api.CommandDone {
		t.Fatalf("resume = %s", state)
	}
	h.router.readHolds = (&holdLists{holds: []api.CardHold{{ProjectID: testProject, CardID: strings.ToUpper(cardUUID(88))}}}).read

	h.router.handler().OnConnect()
	h.router.wg.Wait()

	if h.runs() != 1 {
		t.Fatalf("workers = %d after the list", h.runs())
	}
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

// A bridge with no reader keeps its holds.
func TestNoHeldListReaderKeepsTheHolds(t *testing.T) {
	h := newHarness(t)
	h.holdCard(87)

	h.router.handler().OnConnect()

	if !h.cardHoldOf(87) || len(h.events(t, "card_hold_released")) != 0 {
		t.Fatal("a bridge with no reader ended the hold")
	}
}
