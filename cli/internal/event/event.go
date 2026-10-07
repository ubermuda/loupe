// Package event parses the Mercure payloads the Loupe server publishes.
package event

import (
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"regexp"
	"slices"
	"strings"
	"unicode"
	"unicode/utf8"

	"github.com/ubermuda/loupe/cli/internal/api"
)

// Event mirrors the Mercure update payloads the server publishes. Each field
// is a server identifier, or a forge value that Parse checks against a strict
// shape. Whoever can write to the board controls card titles and bodies, so
// they are never carried here and never reach a prompt.
type Event struct {
	Type       string  `json:"type"`
	Subject    Subject `json:"subject"`
	ProjectID  string  `json:"projectId"`
	CardNumber int     `json:"cardNumber"`
	Actor      string  `json:"actor"`
	// FromSlug and ToSlug are the old and new slug of a renamed project.
	FromSlug string `json:"fromSlug"`
	ToSlug   string `json:"toSlug"`
	// SessionID and CardID belong to the event that a run of a work request
	// reports with, and that a handover carries. Parse reads neither. CardID
	// is empty when the work is about a subject that is no card.
	SessionID string `json:"sessionId,omitempty"`
	CardID    string `json:"cardId,omitempty"`
}

// Subject names the aggregate an event is about. The id is what an MCP tool
// takes. card_get also takes the card number, but only inside one project.
type Subject struct {
	Type string `json:"type"`
	ID   string `json:"id"`
}

// The server publishes these when a person pauses the agents on a card, or
// lets them run again. No rule acts on them.
const (
	CardHeldType     = "board.card_held"
	CardReleasedType = "board.card_released"
)

// ProjectRenamedType is published when a project changes its slug. The
// bridge maps a project by slug, so the work of the old slug stops.
const ProjectRenamedType = "project.renamed"

// CommandType is published when the server asks a bridge to act on a run. No rule acts on it, so Parse drops it and ParseCommand reads it.
const CommandType = "bridge.command"

// WorkRequestType is published when the server offers a work request, and
// again each time its state changes. No rule acts on it, so Parse drops it and
// ParseWorkRequest reads it.
const WorkRequestType = "bridge.work_request"

// KindPattern is the shape of a work request kind, and of a capability.
var KindPattern = regexp.MustCompile(`^[a-z][a-z0-9-]{0,39}$`)

// ruleIDPattern is the shape of the id of the server rule that opened a work
// request.
var ruleIDPattern = regexp.MustCompile(`^[a-z0-9][a-z0-9._-]{0,99}$`)

// The shapes of the context values of a work request, as the server checks
// them. A bridge fills prompts and commands with these values.
var (
	pullRequestURLPattern = regexp.MustCompile(`^https://[A-Za-z0-9._~:/?#\[\]@!$&()*+,;=%-]+$`)
	headSHAPattern        = regexp.MustCompile(`^[0-9a-f]{7,64}$`)
	reasonPattern         = regexp.MustCompile(`^[a-z][a-z0-9-]{0,63}$`)
)

// maxPullRequestURL is the longest pull request URL the server sends.
const maxPullRequestURL = 2000

// The actors the server names. Reviewer is someone using the site-review
// widget, whom the app cannot authenticate. System is the app acting on a
// person's approval, which nobody judged as a move of its own: it neither
// spends a card's chain budget nor resets it.
const (
	ActorHuman    = "human"
	ActorAgent    = "agent"
	ActorReviewer = "reviewer"
	ActorSystem   = "system"
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

// ForAnotherBridge reports whether data is a bridge.command event that does
// not name bridgeID as a string, whatever its case. The caller drops it before
// Parse, so another bridge's malformed event logs nothing. Data that is not a
// JSON object is left to Parse.
func ForAnotherBridge(data []byte, bridgeID string) bool {
	var head struct {
		Type     string          `json:"type"`
		BridgeID json.RawMessage `json:"bridgeId"`
	}
	if json.Unmarshal(data, &head) != nil || head.Type != CommandType {
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
// The two hold types and project.renamed are parsed, with their own fields
// checked. A command and a work request have their own parsers, so Parse
// drops both as unknown, and any other type too. The type is checked rather
// than assumed: without it any well-formed JSON, `{}` included, would reach
// the router.
func Parse(data []byte) (Event, error) {
	var e Event
	if err := json.Unmarshal(data, &e); err != nil {
		return e, fmt.Errorf("parse event: %w", err)
	}

	switch e.Type {
	case CardHeldType, CardReleasedType:
		if err := checkCardHold(e); err != nil {
			return e, err
		}
	case ProjectRenamedType:
		if err := checkSlugs(e, "fromSlug", e.FromSlug, "toSlug", e.ToSlug); err != nil {
			return e, err
		}
	default:
		return e, fmt.Errorf("%w %q", ErrUnknownType, e.Type)
	}

	// The pattern above is case-insensitive, so fold the ids here rather than
	// leaving every later comparison to remember that. The project map
	// compares these ids, and two casings would not match.
	e.ProjectID = strings.ToLower(e.ProjectID)
	e.Subject.ID = strings.ToLower(e.Subject.ID)

	return e, nil
}

func checkCommon(e Event) error {
	if e.ProjectID == "" {
		return fmt.Errorf("%s event has no projectId", e.Type)
	}
	if !uuidPattern.MatchString(e.ProjectID) {
		return fmt.Errorf("%s event has a projectId that is not a uuid", e.Type)
	}
	// The worker prompt names the card by this id, so a prompt without it
	// instructs the worker to do something it cannot.
	if !uuidPattern.MatchString(e.Subject.ID) {
		return fmt.Errorf("%s event has a subject id that is not a uuid", e.Type)
	}
	switch e.Actor {
	case ActorHuman, ActorAgent, ActorReviewer, ActorSystem:
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

// ParseCommand decodes a bridge.command payload and checks it with
// CheckCommand.
func ParseCommand(data []byte) (api.Command, error) {
	var c api.Command
	if err := json.Unmarshal(data, &c); err != nil {
		return c, fmt.Errorf("parse command: %w", err)
	}

	return CheckCommand(c)
}

// checkRunWindow checks that a request for the usage of a run names the
// session, the run and a window that does not end before it starts.
func checkRunWindow(c api.Command) error {
	switch {
	case c.SessionID == "":
		return errors.New("a usage request names no sessionId")
	case c.RunID == "":
		return errors.New("a usage request names no runId")
	case c.StartedAt == nil || c.EndedAt == nil:
		return errors.New("a usage request has no startedAt or no endedAt")
	case c.EndedAt.Before(*c.StartedAt):
		return errors.New("a usage request ends before it starts")
	}

	return nil
}

// CheckCommand checks a command from either channel, and returns it with its
// ids in lower case, as Parse does for an event.
func CheckCommand(c api.Command) (api.Command, error) {
	if c.Type != CommandType {
		return c, fmt.Errorf("command has the type %q", c.Type)
	}
	for _, f := range []struct {
		name, value string
		optional    bool
	}{
		{"projectId", c.ProjectID, false},
		{"commandId", c.CommandID, false},
		{"bridgeId", c.BridgeID, false},
		{"runKey", c.RunKey, true},
		{"sessionId", c.SessionID, true},
		{"workRequestId", c.WorkRequestID, true},
		{"runId", c.RunID, true},
	} {
		if (f.value != "" || !f.optional) && !uuidPattern.MatchString(f.value) {
			return c, fmt.Errorf("command has a %s that is not a uuid", f.name)
		}
	}
	if c.Subject.Type != "bridge-command" || !strings.EqualFold(c.Subject.ID, c.CommandID) {
		return c, fmt.Errorf("command %s names another subject", c.CommandID)
	}
	switch c.Kind {
	case api.CommandStopRun, api.CommandResumeRun, api.CommandRerunCommand:
	case api.CommandCollectSessionUsage:
		if err := checkRunWindow(c); err != nil {
			return c, fmt.Errorf("command %s: %w", c.CommandID, err)
		}
	default:
		return c, fmt.Errorf("command has an unknown kind %q", c.Kind)
	}
	if err := checkSubject(c.SubjectType, c.SubjectID, c.CardNumber); err != nil {
		return c, fmt.Errorf("command %s %w", c.CommandID, err)
	}
	if c.WorkKind != "" && !KindPattern.MatchString(c.WorkKind) {
		return c, fmt.Errorf("command has an invalid workKind %q", c.WorkKind)
	}
	if c.ExpiresAt.IsZero() {
		return c, errors.New("command has no expiresAt")
	}
	// A server older than the cause sends none, and only a person asked then.
	if c.Cause == "" {
		c.Cause = api.CausePerson
	}
	if c.Cause != api.CausePerson && c.Cause != api.CauseAskClosed {
		return c, fmt.Errorf("command has an unknown cause %q", c.Cause)
	}
	if err := checkWorkContext(c.Context); err != nil {
		return c, fmt.Errorf("command %s: %w", c.CommandID, err)
	}
	if c.Model != "" && !ModelWord(c.Model) {
		return c, fmt.Errorf("command has an invalid model %q", c.Model)
	}
	if c.Effort != "" && !slices.Contains(api.Efforts, c.Effort) {
		return c, fmt.Errorf("command has an invalid effort %q", c.Effort)
	}

	c.ProjectID = strings.ToLower(c.ProjectID)
	c.CommandID = strings.ToLower(c.CommandID)
	c.Subject.ID = strings.ToLower(c.Subject.ID)
	c.BridgeID = strings.ToLower(c.BridgeID)
	c.SubjectID = strings.ToLower(c.SubjectID)
	c.RunKey = strings.ToLower(c.RunKey)
	c.SessionID = strings.ToLower(c.SessionID)
	c.WorkRequestID = strings.ToLower(c.WorkRequestID)
	c.RunID = strings.ToLower(c.RunID)
	c.Context.DocumentID = strings.ToLower(c.Context.DocumentID)

	return c, nil
}

// ParseWorkRequest decodes a bridge.work_request payload and checks it with
// CheckWorkRequest.
func ParseWorkRequest(data []byte) (api.WorkRequest, error) {
	var w api.WorkRequest
	if err := json.Unmarshal(data, &w); err != nil {
		return w, fmt.Errorf("parse work request: %w", err)
	}

	return w, CheckWorkRequest(&w)
}

// CheckWorkRequest checks a work request from any channel, and puts its ids in
// lower case, as Parse does for an event.
func CheckWorkRequest(w *api.WorkRequest) error {
	if w.Type != WorkRequestType {
		return fmt.Errorf("work request has the type %q", w.Type)
	}
	for _, f := range []struct{ name, value string }{
		{"projectId", w.ProjectID},
		{"workRequestId", w.WorkRequestID},
	} {
		if !uuidPattern.MatchString(f.value) {
			return fmt.Errorf("work request has a %s that is not a uuid", f.name)
		}
	}
	if w.Subject.Type != "work-request" || !strings.EqualFold(w.Subject.ID, w.WorkRequestID) {
		return fmt.Errorf("work request %s names another subject", w.WorkRequestID)
	}
	if !KindPattern.MatchString(w.Kind) {
		return fmt.Errorf("work request has an invalid kind %q", w.Kind)
	}
	if w.Capability != "" && !KindPattern.MatchString(w.Capability) {
		return fmt.Errorf("work request has an invalid capability %q", w.Capability)
	}
	switch w.State {
	case api.WorkRequestOpen, api.WorkRequestClaimed, api.WorkRequestDone, api.WorkRequestRefused, api.WorkRequestExpired, api.WorkRequestCancelled:
	default:
		return fmt.Errorf("work request has an unknown state %q", w.State)
	}
	if err := checkSubject(w.SubjectType, w.SubjectID, w.CardNumber); err != nil {
		return fmt.Errorf("work request %s %w", w.WorkRequestID, err)
	}
	if !ruleIDPattern.MatchString(w.RuleID) {
		return fmt.Errorf("work request has an invalid ruleId %q", w.RuleID)
	}
	if w.CreatedAt.IsZero() {
		return errors.New("work request has no createdAt")
	}
	if w.ResumeSessionID != "" && !uuidPattern.MatchString(w.ResumeSessionID) {
		return errors.New("work request has a resumeSessionId that is not a uuid")
	}
	if err := checkWorkContext(w.Context); err != nil {
		return fmt.Errorf("work request %s: %w", w.WorkRequestID, err)
	}
	if w.Model != "" && !ModelWord(w.Model) {
		return fmt.Errorf("work request has an invalid model %q", w.Model)
	}
	if w.Effort != "" && !slices.Contains(api.Efforts, w.Effort) {
		return fmt.Errorf("work request has an invalid effort %q", w.Effort)
	}

	w.ProjectID = strings.ToLower(w.ProjectID)
	w.WorkRequestID = strings.ToLower(w.WorkRequestID)
	w.Subject.ID = strings.ToLower(w.Subject.ID)
	w.SubjectID = strings.ToLower(w.SubjectID)
	w.ResumeSessionID = strings.ToLower(w.ResumeSessionID)
	w.Context.DocumentID = strings.ToLower(w.Context.DocumentID)

	return nil
}

// ModelWord reports whether s is a model claude takes from a request: 1 to 64
// characters, no whitespace or control character, and no leading hyphen,
// which claude would read as an option.
func ModelWord(s string) bool {
	if s == "" || utf8.RuneCountInString(s) > 64 || s[0] == '-' || !utf8.ValidString(s) {
		return false
	}

	return !strings.ContainsFunc(s, func(r rune) bool { return unicode.IsSpace(r) || unicode.IsControl(r) })
}

// checkSubject checks the subject of a work request or a command. The card
// number is a label that only a card subject carries.
func checkSubject(subjectType, subjectID string, cardNumber int) error {
	switch {
	case !KindPattern.MatchString(subjectType) || !uuidPattern.MatchString(subjectID):
		return errors.New("has an invalid subject")
	case subjectType == api.SubjectCard && cardNumber <= 0:
		return fmt.Errorf("has an invalid cardNumber %d", cardNumber)
	case subjectType != api.SubjectCard && cardNumber != 0:
		return fmt.Errorf("names a card number and a %s subject", subjectType)
	}

	return nil
}

// checkWorkContext checks each value the context holds. An empty value is
// one the server did not send.
func checkWorkContext(c api.WorkRequestContext) error {
	if c.PullRequestNumber < 0 || c.PullRequestNumber > math.MaxInt32 {
		return fmt.Errorf("the context has an invalid pullRequestNumber %d", c.PullRequestNumber)
	}
	// The server keeps a link as a person gave it, so a strict URL parser
	// would refuse some links it sends, such as one with a bad escape.
	if c.PullRequestURL != "" {
		authority, _, _ := strings.Cut(strings.TrimPrefix(c.PullRequestURL, "https://"), "/")
		authority, _, _ = strings.Cut(authority, "?")
		authority, _, _ = strings.Cut(authority, "#")
		host := authority[strings.LastIndex(authority, "@")+1:]
		if !strings.HasPrefix(host, "[") {
			host, _, _ = strings.Cut(host, ":")
		}
		if len(c.PullRequestURL) > maxPullRequestURL || !pullRequestURLPattern.MatchString(c.PullRequestURL) || host == "" {
			return errors.New("the context has a pullRequestUrl that is not an https URL")
		}
	}
	if c.HeadSHA != "" && !headSHAPattern.MatchString(c.HeadSHA) {
		return fmt.Errorf("the context has an invalid headSha %q", c.HeadSHA)
	}
	if c.Reason != "" && !reasonPattern.MatchString(c.Reason) {
		return fmt.Errorf("the context has an invalid reason %q", c.Reason)
	}
	if c.DocumentID != "" && !uuidPattern.MatchString(c.DocumentID) {
		return errors.New("the context has a documentId that is not a uuid")
	}

	return nil
}

func checkCardHold(e Event) error {
	if e.Subject.Type != "card" {
		return fmt.Errorf("%s event has a subject that is not a card", e.Type)
	}

	return checkCommon(e)
}
