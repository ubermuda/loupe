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
	req.Header.Set("Authorization", "Bearer "+c.token)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.http.Do(req)
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

		return fmt.Errorf("%w: %w", ErrReportRejected, err)
	case http.StatusUnauthorized, http.StatusForbidden, http.StatusUnprocessableEntity:
		detail, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return fmt.Errorf("%w (HTTP %d): %s", ErrReportRejected, resp.StatusCode, strings.TrimSpace(string(detail)))
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
