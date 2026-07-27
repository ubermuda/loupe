// Package inject turns a site-review event into the prompt text injected into a
// Claude Code session.
package inject

import (
	"encoding/json"
	"fmt"
)

// Event mirrors the Mercure update payload the server publishes on submit.
//
// The payload is deliberately just a type marker. The server used to include a
// review id, comment count, site name and affected URLs; the last two were
// reviewer-controlled and were removed as a prompt-injection vector, and the
// first two went away with the review entity itself. What replaced them is the
// SSE event id (a monotonic sequence), which the transport tracks for
// Last-Event-ID rather than the payload carrying it.
type Event struct {
	Type string `json:"type"`
}

// Parse decodes a Mercure data payload into an Event.
func Parse(data []byte) (Event, error) {
	var e Event
	if err := json.Unmarshal(data, &e); err != nil {
		return e, fmt.Errorf("parse event: %w", err)
	}

	return e, nil
}

// Directive renders the prompt text injected into the agent's tmux session.
//
// It interpolates nothing. Any value carried on the wire is either
// reviewer-controlled (a prompt-injection vector in an auto-submitted prompt)
// or redundant: the agent resolves its own project from the MCP token bound to
// the session, and get_site_review returns whatever is pending at the moment it
// asks. A duplicate nudge is therefore harmless — the second pull finds nothing
// still pending and the agent no-ops.
func Directive() string {
	return "A site review was just submitted. Fetch the pending comments with the get_site_review MCP tool, address them, and mark each one with address_site_review_comments."
}
