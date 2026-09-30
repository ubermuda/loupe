package cmd

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"slices"
	"strconv"
	"time"

	"github.com/ubermuda/loupe/cli/internal/event"
)

// catchUpTimeout bounds the read of one replay page.
const catchUpTimeout = 10 * time.Second

// cursorState is the cursor file. Cursor is the highest outbox sequence the
// bridge handled, and Floor the head it started from with no cursor. A
// catch-up never runs an event at or below Floor, which happened before the
// first start. RecentIDs are the ids handled last.
type cursorState struct {
	Cursor    int64    `json:"cursor"`
	Floor     int64    `json:"floor"`
	RecentIDs []string `json:"recentIds"`
}

// cursorPath names the cursor file of the bridge that reads rulesPath. It
// shares the key of the lock, because the lock holder writes it.
func cursorPath(rulesPath string) (string, error) {
	key, err := lockKey(rulesPath)
	if err != nil {
		return "", err
	}

	return bridgeFile("cursor-", key, ".json")
}

// readCursor reads a cursor file. A missing file reports false and no error.
func readCursor(path string) (cursorState, bool, error) {
	var st cursorState
	b, err := os.ReadFile(path)
	if errors.Is(err, fs.ErrNotExist) {
		return st, false, nil
	}
	if err != nil {
		return st, false, fmt.Errorf("read cursor: %w", err)
	}
	if err := json.Unmarshal(b, &st); err != nil {
		return cursorState{}, false, fmt.Errorf("parse cursor %s: %w", path, err)
	}

	return st, true, nil
}

// writeCursor writes the cursor through a temporary file and a rename, so a
// reader never sees half of it.
func writeCursor(path string, st cursorState) error {
	b, err := json.Marshal(st)
	if err != nil {
		return err
	}
	if err := writeAtomic(path, ".cursor-*", b); err != nil {
		return fmt.Errorf("write cursor: %w", err)
	}

	return nil
}

// loadCursor reads the cursor file before the first connect. With no file, the
// bridge starts from head, which is also its floor. With no head either, as
// from an older server, it keeps no cursor and catches up nothing. A file it
// cannot read counts as no file.
func (r *router) loadCursor(head *int64) {
	if r.cursorFile == "" {
		return
	}
	st, ok, err := readCursor(r.cursorFile)
	if err != nil {
		r.log.Warn("cursor_unreadable", "file", r.cursorFile, "error", err.Error())
	}
	r.mu.Lock()
	switch {
	case ok:
		r.cursor, r.floor, r.hasCursor = st.Cursor, st.Floor, true
		for _, id := range st.RecentIDs {
			r.rememberLocked(id)
		}
	case head != nil:
		r.cursor, r.floor, r.hasCursor = *head, *head, true
	}
	st = cursorState{Cursor: r.cursor, Floor: r.floor, RecentIDs: slices.Clone(r.recent)}
	r.mu.Unlock()
	if !ok && head != nil {
		r.saveCursor(st)
	}
}

// seedResumePoint starts the stream at the cursor when no handover gave a
// resume point, so the hub sends what it holds after the cursor too.
func (r *router) seedResumePoint() {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.lastEventID == "" && r.hasCursor {
		r.lastEventID = strconv.FormatInt(r.cursor, 10)
	}
}

// advanceCursor moves the cursor up to the id of an event the router handled,
// and saves it with the recent ids. An id that is no sequence moves nothing.
func (r *router) advanceCursor(id string) {
	if id == "" {
		return
	}
	r.mu.Lock()
	if !r.hasCursor {
		r.mu.Unlock()

		return
	}
	if n, err := strconv.ParseInt(id, 10, 64); err == nil {
		r.cursor = max(r.cursor, n)
	}
	st := cursorState{Cursor: r.cursor, Floor: r.floor, RecentIDs: slices.Clone(r.recent)}
	r.mu.Unlock()
	r.saveCursor(st)
}

// saveCursor writes the cursor file, and logs the first failure of a streak.
// Routing goes on either way.
func (r *router) saveCursor(st cursorState) {
	if r.cursorFile == "" {
		return
	}
	err := writeCursor(r.cursorFile, st)
	r.mu.Lock()
	first := err != nil && !r.cursorFailing
	r.cursorFailing = err != nil
	r.mu.Unlock()
	if first {
		r.log.Warn("cursor_save_failed", "file", r.cursorFile, "error", err.Error())
	}
}

// catchUp reads the outbox events after the cursor, page by page, and routes
// each one above the floor as a replayed event. It stops at the last page, or
// at a page that does not move past the one before. A failure ends it, and the
// stream then goes on live.
func (r *router) catchUp() {
	r.mu.Lock()
	start, floor, ok := r.cursor, r.floor, r.hasCursor && r.replay != nil
	if ok {
		r.caughtUp = map[string]bool{}
	}
	r.mu.Unlock()
	if !ok {
		return
	}

	after, received := start, 0
	for {
		ctx, cancel := context.WithTimeout(r.workerContext(), catchUpTimeout)
		page, err := r.replay(ctx, after)
		cancel()
		if err != nil {
			r.log.Warn("catch_up_failed", "after", after, "error", err.Error())

			return
		}
		received += len(page.Events)
		next := after
		for _, e := range page.Events {
			if id, err := strconv.ParseInt(e.ID, 10, 64); err == nil {
				next = max(next, id)
				if id <= floor {
					continue
				}
			}
			r.takeEvent(e.ID, []byte(e.Data), true)
		}
		if !page.HasMore || next <= after {
			break
		}
		after = next
	}

	r.mu.Lock()
	cursor := r.cursor
	r.mu.Unlock()
	r.log.Info("catch_up_done", "after", start, "events", received, "cursor", cursor)
}

// stale reports whether a replayed card move is out of date: its card has left
// the column the move names. A failed card read runs the event.
func (r *router) stale(p pending) bool {
	e := p.event
	cardID, _ := cardOf(e)
	if e.Type != event.CardMovedType || r.readCard == nil || cardID == "" {
		return false
	}
	timeout := r.checkTimeout
	if timeout <= 0 {
		timeout = askCheckTimeout
	}
	ctx, cancel := context.WithTimeout(r.workerContext(), timeout)
	card, err := r.readCard(ctx, e.ProjectID, cardID)
	cancel()
	if err != nil {
		r.log.Warn("card_read_failed", append(about(e, p.rule),
			"error", err.Error(),
			"message", "the bridge could not read the card, so it runs the replayed event",
		)...)

		return false
	}
	if card.Column == e.ToStatus {
		return false
	}
	r.log.Info("event_stale", append(about(e, p.rule), "column", card.Column, "to", e.ToStatus)...)

	return true
}
