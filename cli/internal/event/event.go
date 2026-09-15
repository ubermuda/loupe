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
	Actor      string  `json:"actor"`
	// FromSlug and ToSlug are the old and new slug of a renamed column or
	// project. Slug is the slug of a deleted column.
	FromSlug string `json:"fromSlug"`
	ToSlug   string `json:"toSlug"`
	Slug     string `json:"slug"`
	// SessionID, BridgeID and CardID belong to inbox.ask_closed. A null card or
	// bridge decodes as "".
	SessionID string `json:"sessionId"`
	BridgeID  string `json:"bridgeId"`
	CardID    string `json:"cardId"`
}

// Subject names the aggregate an event is about. The id is what an MCP tool
// takes; the card number identifies the card to a human and not to card_get.
type Subject struct {
	Type string `json:"type"`
	ID   string `json:"id"`
}

// CardMovedType is published when a board card changes column. It is the one
// type whose fields this build knows.
const CardMovedType = "board.card_moved"

// The types that change a slug a rule can name. The bridge marks those rules
// dead, so it parses these types whether or not a rule names them.
const (
	ColumnRenamedType  = "board.column_renamed"
	ColumnDeletedType  = "board.column_deleted"
	ProjectRenamedType = "project.renamed"
)

// AskClosedType is published when an inbox ask closes. The bridge parses it
// only when a rule names it.
const AskClosedType = "inbox.ask_closed"

// The actors the server names. Reviewer is someone using the site-review
// widget, whom the app cannot authenticate.
const (
	ActorHuman    = "human"
	ActorAgent    = "agent"
	ActorReviewer = "reviewer"
)

// ErrUnknownType marks an event this build does not handle. A newer server
// publishes types an older binary has never heard of, so the caller drops these
// without reporting them.
var ErrUnknownType = errors.New("unknown event type")

// uuidPattern is the shape of a Loupe identifier. projectId and the subject id
// are the payload values that reach a prompt as free text, so their shape is
// checked here rather than left to the hub's publisher rules.
var uuidPattern = regexp.MustCompile(`(?i)^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)

// IsID reports whether s has the shape of a Loupe identifier.
func IsID(s string) bool {
	return uuidPattern.MatchString(s)
}

// SlugPattern is the shape of a project or column slug. A column slug reaches a
// prompt through {from} and {to}.
var SlugPattern = regexp.MustCompile(`^[a-z0-9]+(-[a-z0-9]+)*$`)

// ForAnotherBridge reports whether data is an inbox.ask_closed event that does
// not name bridgeID as a string, whatever its case. The caller drops such an
// event before Parse, so a malformed field of another bridge's event logs
// nothing. Data that is not a JSON object is left to Parse.
func ForAnotherBridge(data []byte, bridgeID string) bool {
	var head struct {
		Type     string          `json:"type"`
		BridgeID json.RawMessage `json:"bridgeId"`
	}
	if json.Unmarshal(data, &head) != nil || head.Type != AskClosedType {
		return false
	}
	var id string
	if json.Unmarshal(head.BridgeID, &id) != nil {
		return true
	}

	return id == "" || !strings.EqualFold(id, bridgeID)
}

// Parse decodes a Mercure data payload into an Event.
//
// board.card_moved and the three slug-changing types are always parsed, with
// their own fields checked. Any other type is parsed only when
// extraTypes names it, and then only the fields every event carries are
// checked. The type is checked rather than assumed: without it any well-formed
// JSON, `{}` included, would reach a worker.
func Parse(data []byte, extraTypes map[string]bool) (Event, error) {
	var e Event
	if err := json.Unmarshal(data, &e); err != nil {
		return e, fmt.Errorf("parse event: %w", err)
	}

	switch {
	case e.Type == CardMovedType:
		if err := checkCardMoved(e); err != nil {
			return e, err
		}
	case e.Type == ColumnRenamedType, e.Type == ProjectRenamedType:
		if err := checkSlugs(e, "fromSlug", e.FromSlug, "toSlug", e.ToSlug); err != nil {
			return e, err
		}
	case e.Type == ColumnDeletedType:
		if err := checkSlugs(e, "slug", e.Slug); err != nil {
			return e, err
		}
	case e.Type == AskClosedType && extraTypes[e.Type]:
		if err := checkAskClosed(e); err != nil {
			return e, err
		}
	case e.Type != "" && extraTypes[e.Type]:
		if err := checkCommon(e); err != nil {
			return e, err
		}
	default:
		return e, fmt.Errorf("%w %q", ErrUnknownType, e.Type)
	}

	// The pattern above is case-insensitive, so fold the ids here rather than
	// leaving every later comparison to remember that. Worker keys and the
	// project map compare these ids, and two casings would not match.
	e.ProjectID = strings.ToLower(e.ProjectID)
	e.Subject.ID = strings.ToLower(e.Subject.ID)
	e.SessionID = strings.ToLower(e.SessionID)
	e.BridgeID = strings.ToLower(e.BridgeID)
	e.CardID = strings.ToLower(e.CardID)

	return e, nil
}

func checkCommon(e Event) error {
	if e.ProjectID == "" {
		return fmt.Errorf("%s event has no projectId", e.Type)
	}
	if !uuidPattern.MatchString(e.ProjectID) {
		return fmt.Errorf("%s event has a projectId that is not a uuid", e.Type)
	}
	// card_get takes this id and rejects the project-scoped number, so a
	// prompt without it instructs the worker to do something it cannot.
	if !uuidPattern.MatchString(e.Subject.ID) {
		return fmt.Errorf("%s event has a subject id that is not a uuid", e.Type)
	}
	switch e.Actor {
	case ActorHuman, ActorAgent, ActorReviewer:
	default:
		return fmt.Errorf("%s event has an unknown actor %q", e.Type, e.Actor)
	}

	return nil
}

// checkSlugs checks the common fields, then each named slug, given as pairs of
// a key and its value.
func checkSlugs(e Event, pairs ...string) error {
	if err := checkCommon(e); err != nil {
		return err
	}
	for i := 0; i+1 < len(pairs); i += 2 {
		if !SlugPattern.MatchString(pairs[i+1]) {
			return fmt.Errorf("%s event has a %s that is not a slug", e.Type, pairs[i])
		}
	}

	return nil
}

// checkAskClosed checks the ids a resume puts into claude's argv and into its
// report. The router compares the bridge id with its own, so any other drops.
func checkAskClosed(e Event) error {
	if err := checkCommon(e); err != nil {
		return err
	}
	if !uuidPattern.MatchString(e.SessionID) {
		return fmt.Errorf("%s event has a sessionId that is not a uuid", e.Type)
	}
	if e.CardID != "" && !uuidPattern.MatchString(e.CardID) {
		return fmt.Errorf("%s event has a cardId that is not a uuid", e.Type)
	}
	if e.CardNumber < 0 || (e.CardID == "") != (e.CardNumber == 0) {
		return fmt.Errorf("%s event names half a card: cardId %q, cardNumber %d", e.Type, e.CardID, e.CardNumber)
	}

	return nil
}

func checkCardMoved(e Event) error {
	if err := checkCommon(e); err != nil {
		return err
	}
	if e.CardNumber <= 0 {
		return fmt.Errorf("card event has an invalid cardNumber %d", e.CardNumber)
	}
	if !SlugPattern.MatchString(e.FromStatus) {
		return fmt.Errorf("card event has a fromStatus that is not a slug")
	}
	if !SlugPattern.MatchString(e.ToStatus) {
		return fmt.Errorf("card event has a toStatus that is not a slug")
	}

	return nil
}
