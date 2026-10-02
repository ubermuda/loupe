package cmd

import (
	"context"
	"errors"
	"strings"

	"github.com/ubermuda/loupe/cli/internal/api"
)

// syncHolds replaces the held cards with the list of the server, which is the
// truth. It ends each hold the list leaves out and starts the runs that wait.
// A failed read keeps the holds until the next connect. It reports whether
// the list replaced the holds.
func (r *router) syncHolds() bool {
	if r.readHolds == nil {
		return false
	}
	ctx, cancel := context.WithTimeout(r.workerContext(), catchUpTimeout)
	holds, err := r.readHolds(ctx)
	cancel()
	if errors.Is(err, api.ErrNoCardHolds) {
		r.mu.Lock()
		first := !r.noHoldList
		r.noHoldList = true
		r.mu.Unlock()
		if first {
			r.log.Info("card_holds_unsupported", "message", "the server has no held list, so the bridge keeps the holds of the events")
		}

		return false
	}
	if err != nil {
		r.log.Warn("card_holds_unreadable", "error", err.Error())

		return false
	}

	next := make(map[string]bool, len(holds))
	for _, h := range holds {
		if h.CardID != "" {
			next[strings.ToLower(h.CardID)] = true
		}
	}
	var released []string
	r.mu.Lock()
	for id := range r.cardHolds {
		if !next[id] {
			released = append(released, id)
		}
	}
	r.cardHolds = next
	r.mu.Unlock()
	for _, id := range released {
		r.log.Info("card_hold_released", "card_id", id)
	}
	if len(released) > 0 {
		r.dispatch()
	}

	return true
}
