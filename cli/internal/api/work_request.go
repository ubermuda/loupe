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
)

// The states of a work request.
const (
	WorkRequestOpen      = "open"
	WorkRequestClaimed   = "claimed"
	WorkRequestDone      = "done"
	WorkRequestRefused   = "refused"
	WorkRequestExpired   = "expired"
	WorkRequestCancelled = "cancelled"
)

// WorkRequest is one piece of agent work on a card that the server offers to
// the bridges. It arrives as a bridge.work_request event, in each heartbeat
// reply, and in the answer to a claim. A null capability decodes as "".
type WorkRequest struct {
	Type          string             `json:"type"`
	ProjectID     string             `json:"projectId"`
	Subject       WorkRequestSubject `json:"subject"`
	WorkRequestID string             `json:"workRequestId"`
	Kind          string             `json:"kind"`
	Capability    string             `json:"capability"`
	State         string             `json:"state"`
	CardID        string             `json:"cardId"`
	CardNumber    int                `json:"cardNumber"`
	RuleID        string             `json:"ruleId"`
	CreatedAt     time.Time          `json:"createdAt"`
	// ResumeSessionID is the session of an unfinished run of the card and
	// kind, which the run of this request resumes. A null decodes as "".
	ResumeSessionID string `json:"resumeSessionId,omitempty"`
}

// WorkRequestSubject names the work request itself.
type WorkRequestSubject struct {
	Type string `json:"type"`
	ID   string `json:"id"`
}

// WorkClaim is one claim a heartbeat renews.
type WorkClaim struct {
	ID         string `json:"id"`
	ClaimToken string `json:"claimToken"`
}

// MaxWorkClaims is the server's cap on the claims of one heartbeat.
const MaxWorkClaims = 200

// Claim is the answer to a claim. WorkRequest is decoded and not checked. Run
// it through event.CheckWorkRequest before use.
type Claim struct {
	WorkRequestID string
	ClaimToken    string
	LeaseUntil    time.Time
	WorkRequest   WorkRequest
}

// ErrWorkRequestAlreadyClaimed marks a request that is not open. Another
// bridge holds it, or it settled.
var ErrWorkRequestAlreadyClaimed = errors.New("the work request is not open")

// ErrWorkRequestNotFound marks a request the server does not hold for this
// bridge.
var ErrWorkRequestNotFound = errors.New("the server holds no such work request")

// ErrWorkRequestsUnsupported marks a 404 with no error code. The server has no
// work request endpoint, or agent push is switched off.
var ErrWorkRequestsUnsupported = errors.New("the server has no work request endpoint, or agent push is switched off")

// ErrClaimLost marks a result the server refused, because this bridge no
// longer holds the claim with that token.
var ErrClaimLost = errors.New("the bridge no longer holds the claim")

// WorkRequestRefusal is a 422 or a 429 from a work request endpoint. Code is
// the error code of the body, and empty for a 429.
type WorkRequestRefusal struct {
	Status int
	Code   string
}

func (e *WorkRequestRefusal) Error() string {
	if e.Code == "" {
		return fmt.Sprintf("the server refused the work request call (HTTP %d)", e.Status)
	}

	return fmt.Sprintf("the server refused the work request call (HTTP %d): %s", e.Status, e.Code)
}

// RateLimited reports whether the refusal is a rate limit, which clears on
// its own.
func (e *WorkRequestRefusal) RateLimited() bool {
	return e.Status == http.StatusTooManyRequests
}

// ClaimWorkRequest claims an open work request for this bridge.
func (c *Client) ClaimWorkRequest(ctx context.Context, bridgeID, id string) (Claim, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.workRequestURL(bridgeID, id, "claim"), nil)
	if err != nil {
		return Claim{}, err
	}
	req.Header.Set("Accept", "application/json")

	resp, err := c.do(req)
	if err != nil {
		return Claim{}, fmt.Errorf("claim work request %s: %w", id, err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return Claim{}, fmt.Errorf("claim work request %s: %w", id, workRequestError(resp, ErrWorkRequestAlreadyClaimed, "already_claimed"))
	}

	var raw struct {
		WorkRequestID string      `json:"workRequestId"`
		ClaimToken    string      `json:"claimToken"`
		LeaseUntil    time.Time   `json:"leaseUntil"`
		WorkRequest   WorkRequest `json:"workRequest"`
	}
	if err := decodeBody(resp.Body, &raw); err != nil {
		return Claim{}, fmt.Errorf("decode the claim of work request %s: %w", id, err)
	}
	switch {
	case !strings.EqualFold(raw.WorkRequestID, id):
		return Claim{}, fmt.Errorf("the claim of work request %s answers for another request, %q", id, raw.WorkRequestID)
	case raw.ClaimToken == "":
		return Claim{}, fmt.Errorf("the claim of work request %s holds no claim token", id)
	case raw.LeaseUntil.IsZero():
		return Claim{}, fmt.Errorf("the claim of work request %s holds no leaseUntil", id)
	}

	return Claim{WorkRequestID: strings.ToLower(raw.WorkRequestID), ClaimToken: raw.ClaimToken, LeaseUntil: raw.LeaseUntil, WorkRequest: raw.WorkRequest}, nil
}

// SettleWorkRequest sends the result of a claimed request, done or refused,
// and returns the state the server stores. An empty reason sends no reason
// key.
func (c *Client) SettleWorkRequest(ctx context.Context, bridgeID, id, token, state, reason string) (string, error) {
	body, err := json.Marshal(struct {
		ClaimToken string `json:"claimToken"`
		State      string `json:"state"`
		Reason     string `json:"reason,omitempty"`
	}{token, state, strings.TrimSpace(reason)})
	if err != nil {
		return "", err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPut, c.workRequestURL(bridgeID, id, "result"), bytes.NewReader(body))
	if err != nil {
		return "", err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.do(req)
	if err != nil {
		return "", fmt.Errorf("settle work request %s: %w", id, err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("settle work request %s: %w", id, workRequestError(resp, ErrClaimLost, "claim_lost"))
	}

	var stored struct {
		State string `json:"state"`
	}
	if err := decodeBody(resp.Body, &stored); err != nil {
		return "", fmt.Errorf("decode the result of work request %s: %w", id, err)
	}

	return stored.State, nil
}

func (c *Client) workRequestURL(bridgeID, id, action string) string {
	return c.baseURL + "/api/bridges/" + url.PathEscape(bridgeID) + "/work-requests/" + url.PathEscape(id) + "/" + action
}

// workRequestError reads a failed answer. conflict is the error of a 409 that
// carries conflictCode.
func workRequestError(resp *http.Response, conflict error, conflictCode string) error {
	detail, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
	var payload struct {
		Error string `json:"error"`
	}
	_ = json.Unmarshal(detail, &payload)

	switch {
	case resp.StatusCode == http.StatusConflict && payload.Error == conflictCode:
		return conflict
	case resp.StatusCode == http.StatusNotFound && payload.Error == "work_request_not_found":
		return ErrWorkRequestNotFound
	case resp.StatusCode == http.StatusNotFound && payload.Error == "":
		return ErrWorkRequestsUnsupported
	case resp.StatusCode == http.StatusUnprocessableEntity, resp.StatusCode == http.StatusTooManyRequests:
		return &WorkRequestRefusal{Status: resp.StatusCode, Code: payload.Error}
	}

	return fmt.Errorf("HTTP %d: %s", resp.StatusCode, strings.TrimSpace(string(detail)))
}

// decodeWorkRequests decodes each offer alone and drops one it cannot decode.
func decodeWorkRequests(raw []json.RawMessage) []WorkRequest {
	var out []WorkRequest
	for _, item := range raw {
		var w WorkRequest
		if json.Unmarshal(item, &w) == nil {
			out = append(out, w)
		}
	}

	return out
}

// decodeLostClaims keeps each id that is a string, in lower case.
func decodeLostClaims(raw []json.RawMessage) []string {
	var out []string
	for _, item := range raw {
		var id string
		if json.Unmarshal(item, &id) == nil && id != "" {
			out = append(out, strings.ToLower(id))
		}
	}

	return out
}
