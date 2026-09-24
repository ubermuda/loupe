// Package api talks to the Loupe HTTP API over the user's API token.
package api

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math"
	"net/http"
	"net/url"
	"strings"
	"time"
	"unicode/utf8"
)

// Site is one entry of GET /api/projects. A project with no slug yet sends a
// null slug, which decodes as "".
type Site struct {
	ID   string `json:"id"`
	Slug string `json:"slug"`
	Name string `json:"name"`
}

// EventsProject is one project of GET /api/events. Its events arrive on the
// caller's topic. A project with no slug yet sends a null slug.
type EventsProject struct {
	ID   string `json:"id"`
	Slug string `json:"slug"`
	Name string `json:"name"`
}

// InboxFlag is the flag that switches the inbox on.
const InboxFlag = "inbox.enabled"

// Events is the response of GET /api/events: the hub, the caller's own topic,
// a subscriber JWT for that topic, the projects whose events arrive on it, and
// the feature flags the server shares with a bridge.
type Events struct {
	HubURL   string          `json:"hubUrl"`
	JWT      string          `json:"jwt"`
	Topic    string          `json:"topic"`
	Projects []EventsProject `json:"projects"`
	// Flags holds values of several types. A server older than the map sends
	// none, and every flag then reads as off.
	Flags map[string]any `json:"flags"`
}

// HeartbeatIntervalFlag is the flag that holds the seconds between two
// heartbeats.
const HeartbeatIntervalFlag = "bridge.heartbeat_interval_seconds"

// maxSeconds keeps a number of seconds inside what a time.Duration holds.
const maxSeconds = math.MaxInt64 / int64(time.Second)

// Enabled reports whether the server sent the flag name as the boolean true.
func (e Events) Enabled(name string) bool {
	on, ok := e.Flags[name].(bool)

	return ok && on
}

// Seconds reads the flag name as a whole number of seconds above zero. It
// reports false for a missing flag and for any other value.
func (e Events) Seconds(name string) (int, bool) {
	n, ok := e.Flags[name].(float64)
	if !ok || n < 1 || n > float64(maxSeconds) || n != math.Trunc(n) {
		return 0, false
	}

	return int(n), true
}

// maxBody caps a success body the client decodes. A columns or sites list is
// far smaller, and a misrouted proxy must not make the bridge read without end.
const maxBody = 1 << 20

// decodeBody decodes a success body of at most maxBody bytes into v.
func decodeBody(body io.Reader, v any) error {
	data, err := io.ReadAll(io.LimitReader(body, maxBody+1))
	if err != nil {
		return err
	}
	if len(data) > maxBody {
		return fmt.Errorf("the response body is larger than %d bytes", maxBody)
	}

	return json.Unmarshal(data, v)
}

// TokenSource gives the bearer token for each request.
type TokenSource interface {
	// Token gives a token to send now.
	Token(ctx context.Context) (string, error)
	// Refresh gives a new token after the server rejected the token rejected.
	// A source that cannot refresh returns ErrNoRefresh.
	Refresh(ctx context.Context, rejected string) (string, error)
}

// ErrNoRefresh is the answer of a source with nothing to refresh.
var ErrNoRefresh = errors.New("this token cannot be refreshed")

// StaticToken is an API token that never changes.
type StaticToken string

// Token gives the token itself.
func (t StaticToken) Token(context.Context) (string, error) { return string(t), nil }

// Refresh always returns ErrNoRefresh.
func (t StaticToken) Refresh(context.Context, string) (string, error) { return "", ErrNoRefresh }

// Client is a Loupe API client bound to one base URL and token source.
type Client struct {
	baseURL string
	tokens  TokenSource
	http    *http.Client
}

// New builds a Client for a static API token. A nil http.Client falls back to
// http.DefaultClient.
func New(baseURL, token string, hc *http.Client) *Client {
	return NewWithSource(baseURL, StaticToken(token), hc)
}

// NewWithSource builds a Client whose token can change, such as a device login
// that refreshes.
func NewWithSource(baseURL string, tokens TokenSource, hc *http.Client) *Client {
	if hc == nil {
		hc = http.DefaultClient
	}

	return &Client{baseURL: strings.TrimRight(baseURL, "/"), tokens: tokens, http: hc}
}

// do sends req with the current token. On a 401 it asks the source for a new
// token and sends req once more, so a token that expired or rotated in another
// process costs one extra request, never a failed call.
func (c *Client) do(req *http.Request) (*http.Response, error) {
	token, err := c.tokens.Token(req.Context())
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+token)
	resp, err := c.http.Do(req)
	if err != nil || resp.StatusCode != http.StatusUnauthorized {
		return resp, err
	}

	fresh, err := c.tokens.Refresh(req.Context(), token)
	if errors.Is(err, ErrNoRefresh) {
		return resp, nil
	}
	resp.Body.Close()
	if err != nil {
		return nil, err
	}

	retry := req.Clone(req.Context())
	if req.GetBody != nil {
		if retry.Body, err = req.GetBody(); err != nil {
			return nil, err
		}
	}
	retry.Header.Set("Authorization", "Bearer "+fresh)

	return c.http.Do(retry)
}

// Events fetches the hub, the caller's topic, a subscriber JWT for it, and the
// projects the caller owns.
func (c *Client) Events(ctx context.Context) (Events, error) {
	var out Events
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.baseURL+"/api/events", nil)
	if err != nil {
		return out, err
	}
	req.Header.Set("Accept", "application/json")

	resp, err := c.do(req)
	if err != nil {
		return out, fmt.Errorf("request events: %w", err)
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusOK:
	case http.StatusUnauthorized, http.StatusForbidden:
		return out, fmt.Errorf("credentials rejected (HTTP %d): the API token must have the agent scope", resp.StatusCode)
	case http.StatusNotFound:
		return out, errors.New("the server has no GET /api/events endpoint: push is switched off on this Loupe instance, or the server is older than this bridge")
	default:
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return out, fmt.Errorf("events request failed (HTTP %d): %s", resp.StatusCode, strings.TrimSpace(string(body)))
	}

	if err := decodeBody(resp.Body, &out); err != nil {
		return out, fmt.Errorf("decode events: %w", err)
	}
	if out.Topic == "" {
		return out, errors.New("GET /api/events returned no topic: the server is older than this bridge")
	}

	return out, nil
}

// Column is one column of a project's board.
type Column struct {
	Slug     string `json:"slug"`
	Label    string `json:"label"`
	Terminal bool   `json:"terminal"`
	Default  bool   `json:"default"`
}

// ProjectColumns is the response of GET /api/projects/{handle}/board/columns.
// A server that predates project slugs sends a null slug, which decodes as "".
type ProjectColumns struct {
	Project struct {
		ID   string `json:"id"`
		Slug string `json:"slug"`
	} `json:"project"`
	Columns []Column `json:"columns"`
}

// ErrProjectNotFound is returned when the server knows no project of the
// caller by that handle.
var ErrProjectNotFound = errors.New("project not found")

// ErrProjectAmbiguous is returned when the handle names more than one of the
// caller's projects.
var ErrProjectAmbiguous = errors.New("project handle is ambiguous")

// ErrBoardDisabled is returned when the instance has the board switched off.
var ErrBoardDisabled = errors.New("the board is disabled on this instance")

// ErrEndpointMissing is returned for a 404 that carries no error code, which
// is the answer of a server that predates the endpoint.
var ErrEndpointMissing = errors.New("the server has no columns endpoint")

// notFound reads which 404 the server meant from its JSON error code.
func notFound(body io.Reader) error {
	var payload struct {
		Error string `json:"error"`
	}
	_ = json.NewDecoder(io.LimitReader(body, 4096)).Decode(&payload)

	switch payload.Error {
	case "project_not_found":
		return ErrProjectNotFound
	case "board_disabled":
		return ErrBoardDisabled
	default:
		return ErrEndpointMissing
	}
}

// Columns fetches the board columns of one of the caller's projects, by id or
// slug.
func (c *Client) Columns(ctx context.Context, handle string) (ProjectColumns, error) {
	var out ProjectColumns
	req, err := http.NewRequestWithContext(ctx, http.MethodGet,
		c.baseURL+"/api/projects/"+url.PathEscape(handle)+"/board/columns", nil)
	if err != nil {
		return out, err
	}
	req.Header.Set("Accept", "application/json")

	resp, err := c.do(req)
	if err != nil {
		return out, fmt.Errorf("request columns of %s: %w", handle, err)
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusOK:
	case http.StatusNotFound:
		return out, fmt.Errorf("%w: %s", notFound(resp.Body), handle)
	case http.StatusConflict:
		return out, fmt.Errorf("%w: %s", ErrProjectAmbiguous, handle)
	case http.StatusUnauthorized, http.StatusForbidden:
		return out, fmt.Errorf("columns request rejected (HTTP %d): the API token must have the agent scope", resp.StatusCode)
	default:
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return out, fmt.Errorf("columns request for %s failed (HTTP %d): %s", handle, resp.StatusCode, strings.TrimSpace(string(body)))
	}

	if err := decodeBody(resp.Body, &out); err != nil {
		return out, fmt.Errorf("decode columns of %s: %w", handle, err)
	}

	return out, nil
}

// The states and reasons of a rule health report.
const (
	RuleLive = "live"
	RuleDead = "dead"

	ReasonColumnRenamed  = "column_renamed"
	ReasonColumnDeleted  = "column_deleted"
	ReasonProjectRenamed = "project_renamed"
	ReasonProjectGone    = "project_gone"
)

// RuleHealth is one rule of a health report. It has no prompt field, so no
// prompt text can reach the server.
type RuleHealth struct {
	Name    string   `json:"name"`
	On      string   `json:"on"`
	Columns []string `json:"columns"`
	State   string   `json:"state"`
	Reason  *string  `json:"reason"`
}

// ErrReportRejected marks a report the server refused for a reason a retry of
// the same body cannot fix, such as a 422 or an unknown project.
var ErrReportRejected = errors.New("the server rejected the rule report")

// Violation is one field a 422 names, such as rules[0].name.
type Violation struct {
	PropertyPath string `json:"propertyPath"`
	Title        string `json:"title"`
}

// RejectedReport is a report the server refused for good. It matches
// ErrReportRejected, and the 404 cause, such as ErrProjectNotFound.
type RejectedReport struct {
	Status     int
	Violations []Violation
	cause      error
}

func (e *RejectedReport) Error() string {
	msg := fmt.Sprintf("the server rejected the rule report (HTTP %d)", e.Status)
	if e.cause != nil {
		msg += ": " + e.cause.Error()
	}
	for _, v := range e.Violations {
		msg += fmt.Sprintf("; %s: %s", v.PropertyPath, v.Title)
	}

	return msg
}

func (e *RejectedReport) Unwrap() []error {
	if e.cause == nil {
		return []error{ErrReportRejected}
	}

	return []error{ErrReportRejected, e.cause}
}

// ReportRules replaces this bridge's rule health report for one project.
func (c *Client) ReportRules(ctx context.Context, handle, bridgeID string, rules []RuleHealth) error {
	if rules == nil {
		rules = []RuleHealth{}
	}
	body, err := json.Marshal(struct {
		Rules []RuleHealth `json:"rules"`
	}{rules})
	if err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPut,
		c.baseURL+"/api/projects/"+url.PathEscape(handle)+"/bridges/"+url.PathEscape(bridgeID)+"/rules", bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.do(req)
	if err != nil {
		return fmt.Errorf("report rules of %s: %w", handle, err)
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusNoContent, http.StatusOK:
		return nil
	case http.StatusNotFound:
		err := notFound(resp.Body)
		if errors.Is(err, ErrBoardDisabled) {
			return err
		}

		return &RejectedReport{Status: resp.StatusCode, cause: err}
	case http.StatusUnprocessableEntity:
		var problem struct {
			Violations []Violation `json:"violations"`
		}
		_ = json.NewDecoder(io.LimitReader(resp.Body, maxBody)).Decode(&problem)

		return &RejectedReport{Status: resp.StatusCode, Violations: problem.Violations}
	case http.StatusUnauthorized, http.StatusForbidden:
		return &RejectedReport{Status: resp.StatusCode}
	default:
		detail, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return fmt.Errorf("rule report for %s failed (HTTP %d): %s", handle, resp.StatusCode, strings.TrimSpace(string(detail)))
	}
}

// Sites lists the authenticated user's sites. Login calls it to check a token.
func (c *Client) Sites(ctx context.Context) ([]Site, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.baseURL+"/api/projects", nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Accept", "application/json")

	resp, err := c.do(req)
	if err != nil {
		return nil, fmt.Errorf("request sites: %w", err)
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusOK:
	case http.StatusUnauthorized, http.StatusForbidden:
		return nil, fmt.Errorf("sites request rejected (HTTP %d): the API token must have the agent scope", resp.StatusCode)
	default:
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return nil, fmt.Errorf("sites request failed (HTTP %d): %s", resp.StatusCode, strings.TrimSpace(string(body)))
	}

	var payload struct {
		Sites []Site `json:"sites"`
	}
	if err := decodeBody(resp.Body, &payload); err != nil {
		return nil, fmt.Errorf("decode sites: %w", err)
	}

	return payload.Sites, nil
}

// WorkerRun is one finished worker run, as POST
// /api/projects/{handle}/worker-runs takes it.
//
// ExitCode is nil when the process never started, and FailureReason then says
// why. The server keeps those two faults apart, and refuses a report that sends
// both or neither. HasResult says whether the output held a result line, and is
// nil with ExitCode.
type WorkerRun struct {
	BridgeID      string    `json:"bridgeId"`
	SessionID     string    `json:"sessionId"`
	CardID        string    `json:"cardId"`
	CardNumber    int       `json:"cardNumber"`
	RuleName      string    `json:"ruleName"`
	StartedAt     time.Time `json:"startedAt"`
	EndedAt       time.Time `json:"endedAt"`
	ExitCode      *int      `json:"exitCode"`
	HasResult     *bool     `json:"hasResult"`
	FailureReason *string   `json:"failureReason"`
	Output        string    `json:"output"`
}

// The server's own caps on a report. It refuses a longer value, and a refused
// report is lost, so the client cuts each one to fit.
const (
	maxRunOutput     = 4000
	maxFailureReason = 1000
	maxRuleName      = 100
)

// ErrReportRefused marks a report the server refuses again for the same reason:
// a token it will not take, a handle it does not know, or a body it reads as
// invalid. A retry cannot turn any of those into a stored row.
var ErrReportRefused = errors.New("the server refused the worker run report")

// ReportWorkerRun records one finished worker run against one of the caller's
// projects. It answers whether the server wrote a new row.
//
// The server answers 201 for a new report and 200 for one it already holds, so
// a retry of a report that landed counts as a success. A caller that has sent
// this report once reads a false as a row it did not write.
func (c *Client) ReportWorkerRun(ctx context.Context, handle string, run WorkerRun) (bool, error) {
	// Trimmed before the cut, because the server trims first and then measures.
	// A name of 100 spaces and a word would otherwise cut to spaces alone, which
	// the server reads as blank and refuses for good.
	run.RuleName = clip(strings.TrimSpace(run.RuleName), maxRuleName)
	run.Output = clip(run.Output, maxRunOutput)
	if run.FailureReason != nil {
		reason := clip(*run.FailureReason, maxFailureReason)
		run.FailureReason = &reason
	}

	body, err := json.Marshal(run)
	if err != nil {
		return false, fmt.Errorf("%w: encode the worker run: %w", ErrReportRefused, err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost,
		c.baseURL+"/api/projects/"+url.PathEscape(handle)+"/worker-runs", bytes.NewReader(body))
	if err != nil {
		return false, err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.do(req)
	if err != nil {
		return false, fmt.Errorf("report the worker run: %w", err)
	}
	defer resp.Body.Close()

	detail, _ := io.ReadAll(io.LimitReader(resp.Body, 512))

	switch {
	case resp.StatusCode == http.StatusCreated:
		return true, nil
	case resp.StatusCode == http.StatusOK:
		return false, nil
	// A rate limit and a request timeout clear on their own, so they are the two
	// 4xx answers worth another try. Every other 4xx reads the same body again.
	case resp.StatusCode == http.StatusTooManyRequests, resp.StatusCode == http.StatusRequestTimeout:
		return false, fmt.Errorf("worker run report not taken yet (HTTP %d)", resp.StatusCode)
	case resp.StatusCode >= 400 && resp.StatusCode < 500:
		return false, fmt.Errorf("%w (HTTP %d): %s", ErrReportRefused, resp.StatusCode, strings.TrimSpace(string(detail)))
	default:
		return false, fmt.Errorf("worker run report failed (HTTP %d): %s", resp.StatusCode, strings.TrimSpace(string(detail)))
	}
}

// Heartbeat is the body of PUT /api/bridges/{bridgeId}/heartbeat: the ids of
// the projects the bridge follows, the build it runs, and its hooks. The server
// keeps its stored hooks when the key is absent and clears them on [], so a nil
// list sends no key and an empty one sends [].
type Heartbeat struct {
	Projects   []string     `json:"projects"`
	CLIVersion string       `json:"cliVersion"`
	Hooks      []HookReport `json:"hooks,omitzero"`
}

// HookReport is how the last run of one hook package on one event went.
// LastRunAt is RFC 3339, and empty for a hook that has not run.
type HookReport struct {
	Package   string `json:"package"`
	Ref       string `json:"ref"`
	Event     string `json:"event"`
	LastRunAt string `json:"lastRunAt,omitempty"`
	Outcome   string `json:"outcome"`
	Error     string `json:"error,omitempty"`
}

// The server's caps, which it measures trimmed.
const (
	maxCLIVersion  = 100
	maxHookPackage = 300
	maxHookRef     = 100
	maxHookError   = 500
	maxHookRows    = 100
)

// ErrHeartbeatMissing marks a 404, which is the answer of a server that
// predates the heartbeat or has agent push switched off. The route sends no
// error code, so the two read the same.
var ErrHeartbeatMissing = errors.New("the server has no heartbeat endpoint, or agent push is switched off")

// Heartbeat tells the server that this bridge runs.
func (c *Client) Heartbeat(ctx context.Context, bridgeID string, hb Heartbeat) error {
	if hb.Projects == nil {
		hb.Projects = []string{}
	}
	hb.CLIVersion = clip(strings.TrimSpace(hb.CLIVersion), maxCLIVersion)
	if hb.Hooks != nil {
		rows := make([]HookReport, 0, min(len(hb.Hooks), maxHookRows))
		for _, row := range hb.Hooks[:min(len(hb.Hooks), maxHookRows)] {
			row.Package = clip(strings.TrimSpace(row.Package), maxHookPackage)
			row.Ref = clip(strings.TrimSpace(row.Ref), maxHookRef)
			row.Error = clip(strings.TrimSpace(row.Error), maxHookError)
			rows = append(rows, row)
		}
		hb.Hooks = rows
	}
	body, err := json.Marshal(hb)
	if err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPut,
		c.baseURL+"/api/bridges/"+url.PathEscape(bridgeID)+"/heartbeat", bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.do(req)
	if err != nil {
		return fmt.Errorf("send the heartbeat: %w", err)
	}
	defer resp.Body.Close()

	detail, _ := io.ReadAll(io.LimitReader(resp.Body, 512))

	switch {
	case resp.StatusCode == http.StatusNoContent, resp.StatusCode == http.StatusOK:
		return nil
	case resp.StatusCode == http.StatusNotFound:
		return ErrHeartbeatMissing
	default:
		return fmt.Errorf("heartbeat failed (HTTP %d): %s", resp.StatusCode, strings.TrimSpace(string(detail)))
	}
}

// AskState is the answer of GET /api/projects/{handle}/inbox/asks/{askId}.
type AskState struct {
	AskID   string `json:"askId"`
	Closed  bool   `json:"closed"`
	AllRead bool   `json:"allRead"`
}

// CheckAsk reads whether an ask closed and whether its session read every item.
// Any answer other than a 200 that states both booleans for this ask is an
// error, and the error carries the server's body. The caller resumes on every
// error alike, so no error kind is told apart.
func (c *Client) CheckAsk(ctx context.Context, handle, askID string) (AskState, error) {
	var out AskState
	req, err := http.NewRequestWithContext(ctx, http.MethodGet,
		c.baseURL+"/api/projects/"+url.PathEscape(handle)+"/inbox/asks/"+url.PathEscape(askID), nil)
	if err != nil {
		return out, err
	}
	req.Header.Set("Accept", "application/json")

	resp, err := c.do(req)
	if err != nil {
		return out, fmt.Errorf("check ask %s: %w", askID, err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return out, fmt.Errorf("ask check failed (HTTP %d): %s", resp.StatusCode, strings.TrimSpace(string(body)))
	}

	var raw struct {
		AskID   string `json:"askId"`
		Closed  *bool  `json:"closed"`
		AllRead *bool  `json:"allRead"`
	}
	if err := decodeBody(resp.Body, &raw); err != nil {
		return out, fmt.Errorf("decode the check of ask %s: %w", askID, err)
	}
	if raw.Closed == nil || raw.AllRead == nil {
		return out, fmt.Errorf("the check of ask %s does not state closed and allRead", askID)
	}
	if !strings.EqualFold(raw.AskID, askID) {
		return out, fmt.Errorf("the check of ask %s answers for another ask, %q", askID, raw.AskID)
	}

	return AskState{AskID: raw.AskID, Closed: *raw.Closed, AllRead: *raw.AllRead}, nil
}

// clip cuts s to at most limit characters. The server counts characters, so a
// byte count would cut a value that holds a multi-byte character too short.
func clip(s string, limit int) string {
	if utf8.RuneCountInString(s) <= limit {
		return s
	}

	return string([]rune(s)[:limit])
}
