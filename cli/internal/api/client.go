// Package api talks to the Loupe HTTP API over the user's API token.
package api

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
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

// Events is the response of GET /api/events: the hub, the caller's own topic,
// a subscriber JWT for that topic, and the projects whose events arrive on it.
type Events struct {
	HubURL   string          `json:"hubUrl"`
	JWT      string          `json:"jwt"`
	Topic    string          `json:"topic"`
	Projects []EventsProject `json:"projects"`
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

// Client is a Loupe API client bound to one base URL and token.
type Client struct {
	baseURL string
	token   string
	http    *http.Client
}

// New builds a Client. A nil http.Client falls back to http.DefaultClient.
func New(baseURL, token string, hc *http.Client) *Client {
	if hc == nil {
		hc = http.DefaultClient
	}

	return &Client{baseURL: strings.TrimRight(baseURL, "/"), token: token, http: hc}
}

// Events fetches the hub, the caller's topic, a subscriber JWT for it, and the
// projects the caller owns.
func (c *Client) Events(ctx context.Context) (Events, error) {
	var out Events
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.baseURL+"/api/events", nil)
	if err != nil {
		return out, err
	}
	req.Header.Set("Authorization", "Bearer "+c.token)
	req.Header.Set("Accept", "application/json")

	resp, err := c.http.Do(req)
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
	req.Header.Set("Authorization", "Bearer "+c.token)
	req.Header.Set("Accept", "application/json")

	resp, err := c.http.Do(req)
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

// WorkerRun is one finished worker run, as POST
// /api/projects/{handle}/worker-runs takes it.
//
// ExitCode is nil when the process never started, and FailureReason then says
// why. The server keeps those two faults apart, and refuses a report that sends
// both or neither.
type WorkerRun struct {
	BridgeID      string    `json:"bridgeId"`
	CardID        string    `json:"cardId"`
	CardNumber    int       `json:"cardNumber"`
	RuleName      string    `json:"ruleName"`
	StartedAt     time.Time `json:"startedAt"`
	EndedAt       time.Time `json:"endedAt"`
	ExitCode      *int      `json:"exitCode"`
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
	run.RuleName = clip(run.RuleName, maxRuleName)
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
	req.Header.Set("Authorization", "Bearer "+c.token)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.http.Do(req)
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

// clip cuts s to at most limit characters. The server counts characters, so a
// byte count would cut a value that holds a multi-byte character too short.
func clip(s string, limit int) string {
	if utf8.RuneCountInString(s) <= limit {
		return s
	}

	return string([]rune(s)[:limit])
}

// Sites lists the authenticated user's sites. Login calls it to check a token.
func (c *Client) Sites(ctx context.Context) ([]Site, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.baseURL+"/api/projects", nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+c.token)
	req.Header.Set("Accept", "application/json")

	resp, err := c.http.Do(req)
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
