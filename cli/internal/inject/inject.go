// Package inject turns a site-review event into the prompt text injected into a
// Claude Code session.
package inject

import (
	"encoding/json"
	"fmt"
	"strings"
)

// Event mirrors the Mercure update payload the server publishes on submit.
type Event struct {
	Type         string   `json:"type"`
	BatchID      string   `json:"batchId"`
	CommentCount int      `json:"commentCount"`
	URLs         []string `json:"urls"`
	CreatedAt    string   `json:"createdAt"`
}

// Parse decodes a Mercure data payload into an Event.
func Parse(data []byte) (Event, error) {
	var e Event
	if err := json.Unmarshal(data, &e); err != nil {
		return e, fmt.Errorf("parse event: %w", err)
	}

	return e, nil
}

// Mode selects how much context to inject.
type Mode int

const (
	// SelfContained injects a full prompt from the event — works on a vanilla
	// `claude` with no Better Plans MCP configured.
	SelfContained Mode = iota
	// IDOnly injects just the batch id and asks Claude to load details via the
	// get_site_review MCP tool.
	IDOnly
)

// Directive renders the prompt text for an event under the given mode.
func Directive(e Event, m Mode) string {
	if m == IDOnly {
		return fmt.Sprintf(
			"A new site review (batch %s) was just submitted. Load it with the get_site_review MCP tool and address the comments.",
			e.BatchID,
		)
	}

	var b strings.Builder
	fmt.Fprintf(&b, "A new site review was just submitted (batch %s, %d comment(s)).", e.BatchID, e.CommentCount)
	if len(e.URLs) > 0 {
		fmt.Fprintf(&b, " Affected pages: %s.", strings.Join(e.URLs, ", "))
	}
	b.WriteString(" Review the feedback and address it.")

	return b.String()
}
