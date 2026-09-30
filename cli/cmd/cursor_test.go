package cmd

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"sync"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
)

// replayer answers each replay call with the next page, and records the after
// of each call.
type replayer struct {
	mu     sync.Mutex
	pages  []api.Replay
	err    error
	afters []int64
}

func (f *replayer) replay(_ context.Context, after int64) (api.Replay, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.afters = append(f.afters, after)
	if f.err != nil {
		return api.Replay{}, f.err
	}
	if len(f.pages) == 0 {
		return api.Replay{}, nil
	}
	page := f.pages[0]
	f.pages = f.pages[1:]

	return page, nil
}

func (f *replayer) called() []int64 {
	f.mu.Lock()
	defer f.mu.Unlock()

	return slices.Clone(f.afters)
}

// row is a replayed card move of the card numbered id, with id as its
// sequence, to the column to.
func row(id int, to string) api.ReplayEvent {
	return api.ReplayEvent{ID: strconv.Itoa(id), Type: "board.card_moved", Data: movedPayload(id, "backlog", to, "human")}
}

// cardReader answers each card read with column, or with err, and records the
// card of each read.
type cardReader struct {
	mu     sync.Mutex
	column string
	err    error
	reads  []string
}

func (c *cardReader) read(_ context.Context, _, cardID string) (api.CardRead, error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.reads = append(c.reads, cardID)

	return api.CardRead{Column: c.column}, c.err
}

func (c *cardReader) count() int {
	c.mu.Lock()
	defer c.mu.Unlock()

	return len(c.reads)
}

// withCursor gives the harness a cursor file and the cursor and floor.
func withCursor(t *testing.T, h *harness, cursor, floor int64) string {
	t.Helper()
	h.router.cursorFile = filepath.Join(t.TempDir(), "cursor.json")
	h.router.cursor, h.router.floor, h.router.hasCursor = cursor, floor, true

	return h.router.cursorFile
}

func readCursorFile(t *testing.T, path string) cursorState {
	t.Helper()
	st, ok, err := readCursor(path)
	if err != nil || !ok {
		t.Fatalf("read cursor: ok = %v, err = %v", ok, err)
	}

	return st
}

func TestTheCursorFileRoundTrips(t *testing.T) {
	path := filepath.Join(t.TempDir(), "cursor.json")
	want := cursorState{Cursor: 42, Floor: 7, RecentIDs: []string{"41", "42"}}
	if err := writeCursor(path, want); err != nil {
		t.Fatal(err)
	}
	got := readCursorFile(t, path)
	if got.Cursor != 42 || got.Floor != 7 || !slices.Equal(got.RecentIDs, want.RecentIDs) {
		t.Fatalf("cursor = %+v", got)
	}
	if info, err := os.Stat(path); err != nil || info.Mode().Perm() != 0o600 {
		t.Fatalf("mode = %v, err = %v", info.Mode(), err)
	}
}

func TestAMissingCursorFileReadsAsNoCursor(t *testing.T) {
	_, ok, err := readCursor(filepath.Join(t.TempDir(), "cursor.json"))
	if ok || err != nil {
		t.Fatalf("ok = %v, err = %v", ok, err)
	}
}

// A bridge with no cursor starts from the head, which becomes the floor too.
func TestWithNoCursorTheBridgeStartsFromTheHead(t *testing.T) {
	h := newHarness(t)
	h.router.cursorFile = filepath.Join(t.TempDir(), "cursor.json")

	h.router.loadCursor(new(int64(30)))

	if st := readCursorFile(t, h.router.cursorFile); st.Cursor != 30 || st.Floor != 30 {
		t.Fatalf("cursor = %+v", st)
	}
	if h.router.cursor != 30 || h.router.floor != 30 || !h.router.hasCursor {
		t.Fatalf("router cursor = %d, floor = %d", h.router.cursor, h.router.floor)
	}
}

// A saved cursor wins over the head, and its recent ids count as handled.
func TestASavedCursorWinsOverTheHead(t *testing.T) {
	h := newHarness(t)
	h.router.cursorFile = filepath.Join(t.TempDir(), "cursor.json")
	if err := writeCursor(h.router.cursorFile, cursorState{Cursor: 12, Floor: 5, RecentIDs: []string{"12"}}); err != nil {
		t.Fatal(err)
	}

	h.router.loadCursor(new(int64(30)))
	h.router.onEvent("12", []byte(cardMoved(12)))
	h.router.wg.Wait()

	if h.router.cursor != 12 || h.router.floor != 5 {
		t.Fatalf("router cursor = %d, floor = %d", h.router.cursor, h.router.floor)
	}
	h.only(t, "event_duplicate")
}

// A corrupt file is logged and read as no cursor.
func TestACorruptCursorFileReadsAsNoCursor(t *testing.T) {
	h := newHarness(t)
	h.router.cursorFile = filepath.Join(t.TempDir(), "cursor.json")
	if err := os.WriteFile(h.router.cursorFile, []byte("{"), 0o600); err != nil {
		t.Fatal(err)
	}

	h.router.loadCursor(new(int64(30)))

	h.only(t, "cursor_unreadable")
	if st := readCursorFile(t, h.router.cursorFile); st.Cursor != 30 || st.Floor != 30 {
		t.Fatalf("cursor = %+v", st)
	}
}

// An older server sends no head. With no saved cursor, the bridge keeps no
// cursor, so it never replays from 0.
func TestWithNoCursorAndNoHeadTheBridgeKeepsNoCursor(t *testing.T) {
	h := newHarness(t)
	h.router.cursorFile = filepath.Join(t.TempDir(), "cursor.json")
	rep := &replayer{}
	h.router.replay = rep.replay

	h.router.loadCursor(nil)
	h.router.onEvent("40", []byte(cardMoved(40)))
	h.router.catchUp()
	h.router.wg.Wait()

	if _, err := os.Stat(h.router.cursorFile); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("the cursor file exists: %v", err)
	}
	if got := rep.called(); len(got) != 0 {
		t.Fatalf("replay ran with %v", got)
	}
}

// A live event moves the cursor up and saves it with the recent ids. An event
// below the cursor still runs and leaves the cursor where it was.
func TestALiveEventMovesTheCursor(t *testing.T) {
	h := newHarness(t)
	path := withCursor(t, h, 10, 10)

	h.router.onEvent("12", []byte(cardMoved(12)))
	h.router.onEvent("11", []byte(cardMoved(11)))
	h.router.onEvent("not-a-number", []byte(cardMoved(13)))
	h.router.wg.Wait()

	if got := startedCards(t, h); !slices.Equal(got, []int{12, 11, 13}) {
		t.Fatalf("started = %v", got)
	}
	st := readCursorFile(t, path)
	if st.Cursor != 12 || st.Floor != 10 || !slices.Equal(st.RecentIDs, []string{"12", "11", "not-a-number"}) {
		t.Fatalf("cursor = %+v", st)
	}
}

// A cursor file that cannot be written is logged once per streak, and the
// events still run.
func TestACursorThatCannotBeSavedIsLoggedOnce(t *testing.T) {
	h := newHarness(t)
	withCursor(t, h, 10, 10)
	h.router.cursorFile = filepath.Join(t.TempDir(), "missing", "cursor.json")

	h.router.onEvent("11", []byte(cardMoved(11)))
	h.router.onEvent("12", []byte(cardMoved(12)))
	h.router.wg.Wait()

	if got := startedCards(t, h); !slices.Equal(got, []int{11, 12}) {
		t.Fatalf("started = %v", got)
	}
	h.only(t, "cursor_save_failed")
}

// The catch-up reads pages until the last one, each after the highest id of
// the one before. It skips a row at or below the floor and a row it handled
// already, and runs the rest in order.
func TestTheCatchUpReadsEveryPage(t *testing.T) {
	h := newHarness(t)
	path := withCursor(t, h, 10, 8)
	h.router.onEvent("9", []byte(cardMoved(9)))
	h.router.wg.Wait()
	rep := &replayer{pages: []api.Replay{
		{Events: []api.ReplayEvent{row(7, "next"), row(9, "next"), row(11, "next"), row(12, "next")}, HasMore: true},
		{Events: []api.ReplayEvent{row(13, "next")}},
	}}
	h.router.replay = rep.replay

	h.router.handler().OnConnect()
	h.router.wg.Wait()

	if got := rep.called(); !slices.Equal(got, []int64{10, 12}) {
		t.Fatalf("replay afters = %v", got)
	}
	if got := startedCards(t, h); !slices.Equal(got, []int{9, 11, 12, 13}) {
		t.Fatalf("started = %v", got)
	}
	h.only(t, "event_duplicate")
	done := h.only(t, "catch_up_done")
	if num(t, done, "after") != 10 || num(t, done, "events") != 5 || num(t, done, "cursor") != 13 {
		t.Fatalf("catch_up_done = %v", done)
	}
	if st := readCursorFile(t, path); st.Cursor != 13 || st.Floor != 8 {
		t.Fatalf("cursor = %+v", st)
	}
}

// A page that says it has more but does not move past the cursor ends the
// catch-up, so a server fault cannot loop it.
func TestTheCatchUpStopsWhenAPageDoesNotMove(t *testing.T) {
	h := newHarness(t)
	withCursor(t, h, 10, 10)
	rep := &replayer{pages: []api.Replay{
		{Events: []api.ReplayEvent{row(9, "next")}, HasMore: true},
		{Events: []api.ReplayEvent{row(11, "next")}},
	}}
	h.router.replay = rep.replay

	h.router.catchUp()
	h.router.wg.Wait()

	if got := rep.called(); !slices.Equal(got, []int64{10}) {
		t.Fatalf("replay afters = %v", got)
	}
	h.only(t, "catch_up_done")
}

// A failed catch-up is logged, and the stream goes on live.
func TestAFailedCatchUpLeavesTheStreamLive(t *testing.T) {
	h := newHarness(t)
	withCursor(t, h, 10, 10)
	h.router.replay = (&replayer{err: errors.New("HTTP 429")}).replay

	h.router.handler().OnConnect()
	h.router.onEvent("11", []byte(cardMoved(11)))
	h.router.wg.Wait()

	failed := h.only(t, "catch_up_failed")
	if num(t, failed, "after") != 10 || str(t, failed, "error") != "HTTP 429" {
		t.Fatalf("catch_up_failed = %v", failed)
	}
	if got := startedCards(t, h); !slices.Equal(got, []int{11}) {
		t.Fatalf("started = %v", got)
	}
	if len(h.events(t, "catch_up_done")) != 0 {
		t.Fatal("a failed catch-up logged catch_up_done")
	}
}

// The hub can send again, after the catch-up, every event the catch-up read.
// That can be more than the recent ids hold, and none of them runs twice.
func TestTheHubCannotRunAnEventTheCatchUpRead(t *testing.T) {
	h := newHarness(t)
	withCursor(t, h, 0, 0)
	page := api.Replay{Events: []api.ReplayEvent{row(1, "next")}}
	for id := 2; id <= recentLimit+1; id++ {
		page.Events = append(page.Events, row(id, "done"))
	}
	h.router.replay = (&replayer{pages: []api.Replay{page}}).replay

	h.router.catchUp()
	h.router.onEvent("1", []byte(cardMoved(1)))
	h.router.wg.Wait()

	if got := startedCards(t, h); !slices.Equal(got, []int{1}) {
		t.Fatalf("started = %v", got)
	}
	h.only(t, "event_duplicate")
}

// The hub can drop a connection before the stream reads a line, and then
// replay from the same point after the next catch-up. An event an earlier
// catch-up read still does not run twice.
func TestTheHubCannotRunAnEventAnEarlierCatchUpRead(t *testing.T) {
	h := newHarness(t)
	withCursor(t, h, 0, 0)
	page := api.Replay{Events: []api.ReplayEvent{row(1, "next")}}
	for id := 2; id <= recentLimit+1; id++ {
		page.Events = append(page.Events, row(id, "done"))
	}
	h.router.replay = (&replayer{pages: []api.Replay{page}}).replay

	h.router.catchUp()
	h.router.catchUp()
	h.router.onEvent("1", []byte(cardMoved(1)))
	h.router.wg.Wait()

	if got := startedCards(t, h); !slices.Equal(got, []int{1}) {
		t.Fatalf("started = %v", got)
	}
	h.only(t, "event_duplicate")
}

// A replayed card move whose card left the column since does not run.
func TestAReplayedMoveOfACardThatMovedOnIsStale(t *testing.T) {
	h := newHarness(t)
	withCursor(t, h, 10, 10)
	cards := &cardReader{column: "done"}
	h.router.readCard = cards.read
	h.router.replay = (&replayer{pages: []api.Replay{{Events: []api.ReplayEvent{row(11, "next")}}}}).replay

	h.router.catchUp()
	h.router.wg.Wait()

	if got := startedCards(t, h); len(got) != 0 {
		t.Fatalf("started = %v", got)
	}
	stale := h.only(t, "event_stale")
	if num(t, stale, "card") != 11 || str(t, stale, "project") != testProject || str(t, stale, "rule") != "plan" ||
		str(t, stale, "column") != "done" || str(t, stale, "to") != "next" {
		t.Fatalf("event_stale = %v", stale)
	}
	if h.router.cursor != 11 {
		t.Fatalf("cursor = %d", h.router.cursor)
	}
}

// A replayed move runs when its card is still in the column, and when the
// card read fails. A live move reads no card.
func TestAReplayedMoveRunsUnlessTheCardMovedOn(t *testing.T) {
	for name, tc := range map[string]struct {
		cards *cardReader
		reads int
	}{
		"current card": {&cardReader{column: "next"}, 1},
		"read failure": {&cardReader{err: errors.New("HTTP 500")}, 1},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarness(t)
			withCursor(t, h, 10, 10)
			h.router.readCard = tc.cards.read
			h.router.replay = (&replayer{pages: []api.Replay{{Events: []api.ReplayEvent{row(11, "next")}}}}).replay

			h.router.catchUp()
			h.router.wg.Wait()

			if got := startedCards(t, h); !slices.Equal(got, []int{11}) {
				t.Fatalf("started = %v", got)
			}
			if tc.cards.count() != tc.reads {
				t.Fatalf("reads = %d", tc.cards.count())
			}
		})
	}

	h := newHarness(t)
	cards := &cardReader{column: "done"}
	h.router.readCard = cards.read
	h.router.onEvent("11", []byte(cardMoved(11)))
	h.router.wg.Wait()
	if got := startedCards(t, h); !slices.Equal(got, []int{11}) || cards.count() != 0 {
		t.Fatalf("started = %v, reads = %d", got, cards.count())
	}
}

// A replayed event that a freeze holds back keeps its replay mark, so the
// resume still checks its card.
func TestAHeldReplayedEventKeepsItsStaleCheck(t *testing.T) {
	h := newHarness(t)
	withCursor(t, h, 10, 10)
	cards := &cardReader{column: "done"}
	h.router.readCard = cards.read
	h.router.replay = (&replayer{pages: []api.Replay{{Events: []api.ReplayEvent{row(11, "next")}}}}).replay

	h.router.freeze()
	h.router.catchUp()
	h.router.mu.Lock()
	held := slices.Clone(h.router.heldEvents)
	h.router.mu.Unlock()
	if len(held) != 1 || !held[0].replayed {
		t.Fatalf("held = %+v", held)
	}

	h.router.resume()
	h.router.wg.Wait()
	if got := startedCards(t, h); len(got) != 0 {
		t.Fatalf("started = %v", got)
	}
	h.only(t, "event_stale")
}

// Ctrl-C during a catch-up stops it before the next row, so the cursor does not
// pass the rows that the next start must still run.
func TestACancelledCatchUpRoutesNoMoreRows(t *testing.T) {
	h := newHarness(t)
	path := withCursor(t, h, 10, 10)
	ctx, cancel := context.WithCancel(context.Background())
	h.router.ctx = ctx
	cards := &cardReader{column: "next"}
	h.router.readCard = func(ctx context.Context, handle, cardID string) (api.CardRead, error) {
		cancel()

		return cards.read(ctx, handle, cardID)
	}
	h.router.replay = (&replayer{pages: []api.Replay{{Events: []api.ReplayEvent{row(11, "next"), row(12, "next")}}}}).replay

	h.router.catchUp()
	h.router.wg.Wait()

	if cards.count() != 1 {
		t.Fatalf("reads = %d", cards.count())
	}
	if st := readCursorFile(t, path); st.Cursor != 11 {
		t.Fatalf("cursor = %+v", st)
	}
}
