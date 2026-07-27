// Package inject turns a site-review event into the prompt text injected into a
// Claude Code session.
package inject

import (
	"encoding/json"
	"fmt"
)

// Event mirrors the Mercure update payload the server publishes on submit.
type Event struct {
	Type         string   `json:"type"`
	SiteID       string   `json:"siteId"`
	SiteName     string   `json:"siteName"`
	ReviewID     string   `json:"reviewId"`
	CommentCount int      `json:"commentCount"`
	URLs         []string `json:"urls"`
	SubmittedAt  string   `json:"submittedAt"`
}

// Parse decodes a Mercure data payload into an Event.
func Parse(data []byte) (Event, error) {
	var e Event
	if err := json.Unmarshal(data, &e); err != nil {
		return e, fmt.Errorf("parse event: %w", err)
	}

	return e, nil
}

// Directive renders the prompt text injected into the agent's tmux session
// for an event.
//
// It carries only opaque, server-generated identifiers (the review id and a
// comment count) — never reviewer-controlled strings such as comment URLs
// or the site name. Those fields are still part of Event, because Event
// mirrors the server's wire payload as a whole, but they must never be
// interpolated here: anyone who can post a site review through the embedded
// widget controls that text, and interpolating it into an auto-submitted
// prompt would let them inject instructions into the owner's agent session.
//
// The agent fetches the actual comment content itself via the
// get_site_review MCP tool, whose `site` parameter is optional and resolves
// from the caller's bound token — so no site identifier needs to be injected
// either.
func Directive(e Event) string {
	return fmt.Sprintf(
		"A site review (%s) was just submitted with %d comment(s). Fetch the pending comments with the get_site_review MCP tool, address them, and mark each one with address_site_review_comments.",
		e.ReviewID, e.CommentCount,
	)
}
