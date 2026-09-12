// Package event parses the Mercure payloads the Loupe server publishes.
package event

import (
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"strings"
)

// Event mirrors the Mercure update payloads the server publishes.
//
// Every field is a server-generated identifier. Card titles and bodies are
// controlled by whoever can write to the board, so they are never carried here
// and never reach a prompt.
type Event struct {
	Type       string  `json:"type"`
	Subject    Subject `json:"subject"`
	ProjectID  string  `json:"projectId"`
	CardNumber int     `json:"cardNumber"`
	FromStatus string  `json:"fromStatus"`
	ToStatus   string  `json:"toStatus"`
}

// Subject names the aggregate an event is about. The id is what an MCP tool
// takes; the card number identifies the card to a human and not to card_get.
type Subject struct {
	Type string `json:"type"`
	ID   string `json:"id"`
}

const (
	// CardMovedType is published when a board card changes column.
	CardMovedType = "board.card_moved"
	// StatusNext is the card status the bridge starts a worker for.
	StatusNext = "next"
)

// ErrUnknownType marks an event this build does not handle. A newer server
// publishes types an older binary has never heard of, so the caller drops these
// without reporting them.
var ErrUnknownType = errors.New("unknown event type")

// uuidPattern is the shape of a Loupe identifier. projectId and the subject id
// are the payload values that reach a prompt as free text, so their shape is
// checked here rather than left to the hub's publisher rules.
var uuidPattern = regexp.MustCompile(`(?i)^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)

// Parse decodes a Mercure data payload into an Event.
//
// The type is checked rather than assumed: without it any well-formed JSON —
// `{}` included — would reach a worker as a directive.
func Parse(data []byte) (Event, error) {
	var e Event
	if err := json.Unmarshal(data, &e); err != nil {
		return e, fmt.Errorf("parse event: %w", err)
	}

	if e.Type != CardMovedType {
		return e, fmt.Errorf("%w %q", ErrUnknownType, e.Type)
	}
	if e.ProjectID == "" {
		return e, fmt.Errorf("card event has no projectId")
	}
	if !uuidPattern.MatchString(e.ProjectID) {
		return e, fmt.Errorf("card event has a projectId that is not a uuid")
	}
	if e.CardNumber <= 0 {
		return e, fmt.Errorf("card event has an invalid cardNumber %d", e.CardNumber)
	}
	// card_get takes this id and rejects the project-scoped number, so a
	// directive without it instructs the worker to do something it cannot.
	if !uuidPattern.MatchString(e.Subject.ID) {
		return e, fmt.Errorf("card event has a subject id that is not a uuid")
	}
	// The pattern above is case-insensitive, so fold the ids here rather than
	// leaving every later comparison to remember that. The guard against a
	// second worker for one card compares keys built from the project id, and
	// two casings would build two keys.
	e.ProjectID = strings.ToLower(e.ProjectID)
	e.Subject.ID = strings.ToLower(e.Subject.ID)

	return e, nil
}
