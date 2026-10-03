// Package event parses the Mercure payloads the Loupe server publishes.
package event

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/url"
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
	FromStatus string  `json:"fromStatus"`
	ToStatus   string  `json:"toStatus"`
	Actor      string  `json:"actor"`
	// FromSlug and ToSlug are the old and new slug of a renamed column or
	// project. Slug is the slug of a deleted column, and MovedCardIDs the
	// cards that its delete moved to another column.
	FromSlug     string   `json:"fromSlug"`
	ToSlug       string   `json:"toSlug"`
	Slug         string   `json:"slug"`
	MovedCardIDs []string `json:"movedCardIds"`
	// SessionID and BridgeID belong to inbox.ask_closed and
	// pull_request.fix_requested. CardID belongs to those, to
	// document.review_submitted and to every pull_request type. Verdict belongs
	// to both review types, and Column to document.review_submitted. A null
	// value decodes as "" or 0.
	SessionID string `json:"sessionId"`
	BridgeID  string `json:"bridgeId"`
	CardID    string `json:"cardId"`
	Verdict   string `json:"verdict"`
	Column    string `json:"column"`
	// Card belongs to board.card_moved and document.review_submitted. An older
	// server, or a review with no stage card, sends none.
	Card CardState `json:"card"`
	// Every pull_request type can carry the fields below up to HeadSHA.
	// Conclusion and FailedChecks belong to pull_request.checks_concluded, and
	// Reason to pull_request.fix_requested.
	Forge             string   `json:"forge"`
	Repository        string   `json:"repository"`
	PullRequestNumber int      `json:"pullRequestNumber"`
	PullRequestURL    string   `json:"pullRequestUrl"`
	HeadSHA           string   `json:"headSha"`
	Conclusion        string   `json:"conclusion"`
	FailedChecks      []string `json:"failedChecks"`
	Reason            string   `json:"reason"`
}

// CardState is what the server says about the card an event names.
// InteractiveRun is true while a person runs an interactive session on it, and
// Held is true while the agents on it are paused. Held is nil when the event
// has no held key, as from an older server, so a bridge never reads it as the
// end of a hold.
type CardState struct {
	InteractiveRun bool  `json:"interactiveRun"`
	Held           *bool `json:"held"`
}

// Subject names the aggregate an event is about. The id is what an MCP tool
// takes. card_get also takes the card number, but only inside one project.
type Subject struct {
	Type string `json:"type"`
	ID   string `json:"id"`
}

// CardMovedType is published when a board card changes column. It is the one
// type whose fields this build knows.
const CardMovedType = "board.card_moved"

// The server publishes these when a person pauses the agents on a card, or
// lets them run again. No rule acts on them.
const (
	CardHeldType     = "board.card_held"
	CardReleasedType = "board.card_released"
)

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

// CommandType is published when the server asks a bridge to stop or resume a
// run. No rule acts on it, so Parse drops it and ParseCommand reads it.
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

// ReviewSubmittedType is published when a person approves a document or asks
// for changes. The bridge parses it only when a rule names it.
const ReviewSubmittedType = "document.review_submitted"

// The verdicts a document.review_submitted event carries.
const (
	VerdictApproved         = "approved"
	VerdictChangesRequested = "changes-requested"
)

// PullRequestPrefix starts the type of every event about a card's pull request.
// The bridge parses such a type only when a rule names it.
const PullRequestPrefix = "pull_request."

// The pull request types whose own fields a rule can read.
const (
	ChecksConcludedType            = "pull_request.checks_concluded"
	PullRequestReviewSubmittedType = "pull_request.review_submitted"
	FixRequestedType               = "pull_request.fix_requested"
)

// IsPullRequest reports whether t is a pull request type.
func IsPullRequest(t string) bool {
	return strings.HasPrefix(t, PullRequestPrefix)
}

// The conclusions a pull_request.checks_concluded event carries.
const (
	ConclusionPassed = "passed"
	ConclusionFailed = "failed"
)

// The reasons a pull_request.fix_requested event carries.
const (
	ReasonChecksFailed     = "checks-failed"
	ReasonConflict         = "conflict"
	ReasonChangesRequested = "changes-requested"
)

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

// ForAnotherBridge reports whether data is an inbox.ask_closed or a
// bridge.command event that does not name bridgeID as a string, whatever its
// case, or a pull_request.fix_requested event that names another bridge. The
// caller drops it before Parse, so another bridge's malformed event logs
// nothing. Data that is not a JSON object is left to Parse.
func ForAnotherBridge(data []byte, bridgeID string) bool {
	var head struct {
		Type     string          `json:"type"`
		BridgeID json.RawMessage `json:"bridgeId"`
	}
	if json.Unmarshal(data, &head) != nil || (head.Type != AskClosedType && head.Type != FixRequestedType && head.Type != CommandType) {
		return false
	}
	if head.Type == FixRequestedType && len(head.BridgeID) == 0 {
		return false
	}
	var id string
	if json.Unmarshal(head.BridgeID, &id) != nil {
		return true
	}
	if id == "" {
		return head.Type != FixRequestedType
	}

	return !strings.EqualFold(id, bridgeID)
}

// Parse decodes a Mercure data payload into an Event.
//
// board.card_moved, the two hold types and the three slug-changing types are
// always parsed, with their own fields checked. Any other type is parsed only
// when extraTypes names it, and then only the fields every event carries are
// checked. The type is checked rather than assumed: without it any well-formed
// JSON, `{}` included, would reach a worker.
func Parse(data []byte, extraTypes map[string]bool) (Event, error) {
	var e Event
	if err := json.Unmarshal(data, &e); err != nil {
		return e, fmt.Errorf("parse event: %w", err)
	}

	switch {
	case e.Type == CommandType, e.Type == WorkRequestType:
		return e, fmt.Errorf("%w %q", ErrUnknownType, e.Type)
	case e.Type == CardMovedType:
		if err := checkCardMoved(e); err != nil {
			return e, err
		}
	case e.Type == CardHeldType, e.Type == CardReleasedType:
		if err := checkCardHold(e); err != nil {
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
	case e.Type == ReviewSubmittedType && extraTypes[e.Type]:
		if err := checkReviewSubmitted(e); err != nil {
			return e, err
		}
	case IsPullRequest(e.Type) && extraTypes[e.Type]:
		if err := checkPullRequest(&e); err != nil {
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

// checkAskClosed checks the ids a resume puts into claude's argv and into its
// report. The router compares the bridge id with its own, so any other drops.
func checkAskClosed(e Event) error {
	if err := checkCommon(e); err != nil {
		return err
	}
	if !uuidPattern.MatchString(e.SessionID) {
		return fmt.Errorf("%s event has a sessionId that is not a uuid", e.Type)
	}

	return checkCard(e)
}

// checkReviewSubmitted checks the verdict a rule matches, and the card and
// column that reach a prompt.
func checkReviewSubmitted(e Event) error {
	if err := checkCommon(e); err != nil {
		return err
	}
	if e.Verdict != VerdictApproved && e.Verdict != VerdictChangesRequested {
		return fmt.Errorf("%s event has an unknown verdict %q", e.Type, e.Verdict)
	}
	if err := checkCard(e); err != nil {
		return err
	}
	if e.CardID == "" && e.Column != "" {
		return fmt.Errorf("%s event names a column %q and no card", e.Type, e.Column)
	}
	if e.CardID != "" && !SlugPattern.MatchString(e.Column) {
		return fmt.Errorf("%s event has a column that is not a slug", e.Type)
	}

	return nil
}

// checkCard checks an optional card. The id and the number come together, or
// neither comes.
func checkCard(e Event) error {
	if e.CardID != "" && !uuidPattern.MatchString(e.CardID) {
		return fmt.Errorf("%s event has a cardId that is not a uuid", e.Type)
	}
	if e.CardNumber < 0 || (e.CardID == "") != (e.CardNumber == 0) {
		return fmt.Errorf("%s event names half a card: cardId %q, cardNumber %d", e.Type, e.CardID, e.CardNumber)
	}

	return nil
}

// The shapes of the pull request fields that reach a prompt.
var (
	repositoryPattern = regexp.MustCompile(`^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)+$`)
	urlPattern        = regexp.MustCompile(`^https://[A-Za-z0-9._~:/?#\[\]@!$&()*+,;=%-]+$`)
	shaPattern        = regexp.MustCompile(`^[0-9a-f]{7,64}$`)
)

// The limits on the pull request fields.
const (
	maxRepository   = 255
	maxURL          = 2000
	maxFailedChecks = 100
	maxCheckName    = 200
)

// checkPullRequest checks a pull request event, whose subject is its card. It
// fills the card id from the subject when the server sends none. Each field
// that a type does not carry must be empty.
func checkPullRequest(e *Event) error {
	if e.CardID == "" {
		e.CardID = e.Subject.ID
	}
	if err := checkCommon(*e); err != nil {
		return err
	}
	if err := checkCard(*e); err != nil {
		return err
	}
	if e.CardNumber <= 0 {
		return fmt.Errorf("%s event has an invalid cardNumber %d", e.Type, e.CardNumber)
	}
	if !strings.EqualFold(e.CardID, e.Subject.ID) {
		return fmt.Errorf("%s event names card %s and subject %s", e.Type, e.CardID, e.Subject.ID)
	}
	if e.Forge != "" && !SlugPattern.MatchString(e.Forge) {
		return fmt.Errorf("%s event has a forge that is not a slug", e.Type)
	}
	if e.Repository != "" && (len(e.Repository) > maxRepository || !repositoryPattern.MatchString(e.Repository)) {
		return fmt.Errorf("%s event has a repository that is not owner/name", e.Type)
	}
	if e.PullRequestNumber < 0 {
		return fmt.Errorf("%s event has an invalid pullRequestNumber %d", e.Type, e.PullRequestNumber)
	}
	if e.PullRequestURL != "" && !isPullRequestURL(e.PullRequestURL) {
		return fmt.Errorf("%s event has a pullRequestUrl that is not an https URL", e.Type)
	}
	if e.HeadSHA != "" && !shaPattern.MatchString(e.HeadSHA) {
		return fmt.Errorf("%s event has a headSha that is not a commit hash", e.Type)
	}
	if err := checkFailedChecks(*e); err != nil {
		return err
	}
	for _, f := range []struct {
		name, value, owner string
		allowed            []string
	}{
		{"conclusion", e.Conclusion, ChecksConcludedType, []string{ConclusionPassed, ConclusionFailed}},
		{"verdict", e.Verdict, PullRequestReviewSubmittedType, []string{VerdictApproved, VerdictChangesRequested}},
		{"reason", e.Reason, FixRequestedType, []string{ReasonChecksFailed, ReasonConflict, ReasonChangesRequested}},
	} {
		if f.value == "" {
			continue
		}
		if e.Type != f.owner {
			return fmt.Errorf("%s event has a %s, which only %s carries", e.Type, f.name, f.owner)
		}
		if !slices.Contains(f.allowed, f.value) {
			return fmt.Errorf("%s event has an unknown %s %q", e.Type, f.name, f.value)
		}
	}

	return checkFixSession(*e)
}

func isPullRequestURL(s string) bool {
	if len(s) > maxURL || !urlPattern.MatchString(s) {
		return false
	}
	u, err := url.Parse(s)

	return err == nil && u.Host != ""
}

// checkFailedChecks checks the check names, which the forge names and a
// prompt reads. A brace could read as a placeholder, and a double quote or a
// backslash could break the quotes a prompt puts round a name.
func checkFailedChecks(e Event) error {
	if len(e.FailedChecks) == 0 {
		return nil
	}
	if e.Type != ChecksConcludedType {
		return fmt.Errorf("%s event has failedChecks, which only %s carries", e.Type, ChecksConcludedType)
	}
	if len(e.FailedChecks) > maxFailedChecks {
		return fmt.Errorf("%s event names %d failed checks, and the bridge takes at most %d", e.Type, len(e.FailedChecks), maxFailedChecks)
	}
	for _, name := range e.FailedChecks {
		if n := utf8.RuneCountInString(name); n < 1 || n > maxCheckName ||
			strings.ContainsFunc(name, unicode.IsControl) || strings.ContainsAny(name, "{}\"\\") {
			return fmt.Errorf("%s event has a failed check name that is empty, too long, or holds a control character, a brace, a double quote or a backslash", e.Type)
		}
	}

	return nil
}

// checkFixSession checks the session a fix request resumes. The session and
// the bridge come together, or neither comes, and only a fix request carries
// them.
func checkFixSession(e Event) error {
	if e.Type != FixRequestedType {
		if e.SessionID != "" || e.BridgeID != "" {
			return fmt.Errorf("%s event names a session or a bridge, which only %s carries", e.Type, FixRequestedType)
		}

		return nil
	}
	if (e.SessionID == "") != (e.BridgeID == "") {
		return fmt.Errorf("%s event names half a session: sessionId %q, bridgeId %q", e.Type, e.SessionID, e.BridgeID)
	}
	if e.SessionID != "" && (!uuidPattern.MatchString(e.SessionID) || !uuidPattern.MatchString(e.BridgeID)) {
		return fmt.Errorf("%s event has a sessionId or a bridgeId that is not a uuid", e.Type)
	}

	return nil
}

// maxResumeIndex is the server's cap on the place of a run in its resume series.
const maxResumeIndex = 32767

// ParseCommand decodes a bridge.command payload and checks it with
// CheckCommand.
func ParseCommand(data []byte) (api.Command, error) {
	var c api.Command
	if err := json.Unmarshal(data, &c); err != nil {
		return c, fmt.Errorf("parse command: %w", err)
	}

	return CheckCommand(c)
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
		{"cardId", c.CardID, false},
		{"runKey", c.RunKey, true},
		{"sessionId", c.SessionID, true},
	} {
		if (f.value != "" || !f.optional) && !uuidPattern.MatchString(f.value) {
			return c, fmt.Errorf("command has a %s that is not a uuid", f.name)
		}
	}
	if c.Subject.Type != "bridge-command" || !strings.EqualFold(c.Subject.ID, c.CommandID) {
		return c, fmt.Errorf("command %s names another subject", c.CommandID)
	}
	if c.Kind != api.CommandStopRun && c.Kind != api.CommandResumeRun && c.Kind != api.CommandRerunCommand {
		return c, fmt.Errorf("command has an unknown kind %q", c.Kind)
	}
	if c.CardNumber <= 0 {
		return c, fmt.Errorf("command has an invalid cardNumber %d", c.CardNumber)
	}
	if c.RuleName == "" {
		return c, errors.New("command names no rule")
	}
	if c.CardColumn != "" && !SlugPattern.MatchString(c.CardColumn) {
		return c, errors.New("command has a cardColumn that is not a slug")
	}
	if c.ResumeIndex != nil && (*c.ResumeIndex < 0 || *c.ResumeIndex > maxResumeIndex) {
		return c, fmt.Errorf("command has an invalid resumeIndex %d", *c.ResumeIndex)
	}
	if c.ExpiresAt.IsZero() {
		return c, errors.New("command has no expiresAt")
	}

	c.ProjectID = strings.ToLower(c.ProjectID)
	c.CommandID = strings.ToLower(c.CommandID)
	c.Subject.ID = strings.ToLower(c.Subject.ID)
	c.BridgeID = strings.ToLower(c.BridgeID)
	c.CardID = strings.ToLower(c.CardID)
	c.RunKey = strings.ToLower(c.RunKey)
	c.SessionID = strings.ToLower(c.SessionID)

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
		{"cardId", w.CardID},
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
	if w.CardNumber <= 0 {
		return fmt.Errorf("work request has an invalid cardNumber %d", w.CardNumber)
	}
	if !ruleIDPattern.MatchString(w.RuleID) {
		return fmt.Errorf("work request has an invalid ruleId %q", w.RuleID)
	}
	if w.CreatedAt.IsZero() {
		return errors.New("work request has no createdAt")
	}

	w.ProjectID = strings.ToLower(w.ProjectID)
	w.WorkRequestID = strings.ToLower(w.WorkRequestID)
	w.Subject.ID = strings.ToLower(w.Subject.ID)
	w.CardID = strings.ToLower(w.CardID)

	return nil
}

func checkCardHold(e Event) error {
	if e.Subject.Type != "card" {
		return fmt.Errorf("%s event has a subject that is not a card", e.Type)
	}

	return checkCommon(e)
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
