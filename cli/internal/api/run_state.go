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

// The states a bridge reports for a run. The server adds timed-out and lost on
// its own.
const (
	RunQueued           = "queued"
	RunReplaced         = "replaced"
	RunResumed          = "resumed"
	RunSkipped          = "skipped"
	RunRunning          = "running"
	RunWaitingForPerson = "waiting-for-person"
	RunDropped          = "dropped"
	RunSucceeded        = "succeeded"
	RunNoResult         = "no-result"
	RunFailed           = "failed"
	RunNotStarted       = "not-started"
)

// The reasons of a dropped run.
const (
	DropShutdown = "shutdown"
	DropRuleDead = "rule_dead"
	DropReload   = "reload"
)

// IsOutcome reports whether state is how a worker ended. Only an outcome maps
// onto the old report.
func IsOutcome(state string) bool {
	return state == RunSucceeded || state == RunNoResult || state == RunFailed || state == RunNotStarted
}

// RunStateReport is one state of a run, as PUT
// /api/projects/{handle}/worker-runs/{runId} takes it. Every report carries the
// card and the rule, so the first one the server reads can create the run. A
// field that the state does not use stays zero and is not sent.
type RunStateReport struct {
	BridgeID   string    `json:"bridgeId"`
	State      string    `json:"state"`
	At         time.Time `json:"at"`
	CardID     string    `json:"cardId"`
	CardNumber int       `json:"cardNumber"`
	RuleName   string    `json:"ruleName"`

	SessionID string    `json:"sessionId,omitzero"`
	StartedAt time.Time `json:"startedAt,omitzero"`

	EndedAt  time.Time `json:"endedAt,omitzero"`
	ExitCode *int      `json:"exitCode,omitzero"`
	// HasResult says whether the output held a result line. It pairs with
	// ExitCode, as in the old report.
	HasResult     *bool   `json:"hasResult,omitzero"`
	FailureReason *string `json:"failureReason,omitzero"`
	Output        string  `json:"output,omitzero"`

	AskID      string `json:"askId,omitzero"`
	ReplacedBy string `json:"replacedBy,omitzero"`
	MaxChain   int    `json:"maxChain,omitzero"`
	Reason     string `json:"reason,omitzero"`
}

// MarshalJSON sends every field of an outcome, as the old report does, so an
// empty output, a null result flag and the null half of the exit code and
// failure reason pair still reach the server.
func (r RunStateReport) MarshalJSON() ([]byte, error) {
	type plain RunStateReport
	if !IsOutcome(r.State) {
		return json.Marshal(plain(r))
	}

	return json.Marshal(struct {
		plain
		EndedAt       time.Time `json:"endedAt"`
		ExitCode      *int      `json:"exitCode"`
		HasResult     *bool     `json:"hasResult"`
		FailureReason *string   `json:"failureReason"`
		Output        string    `json:"output"`
	}{plain(r), r.EndedAt, r.ExitCode, r.HasResult, r.FailureReason, r.Output})
}

// InventoryRun is one run the bridge holds, as PUT /api/bridges/{bridgeId}/runs
// takes it.
type InventoryRun struct {
	RunID     string `json:"runId"`
	ProjectID string `json:"projectId"`
	State     string `json:"state"`
}

// ErrRunStatesUnsupported marks a 404 with no error code, which is the answer
// of a server older than the run state endpoints, or with agent push switched
// off. The caller falls back on ReportWorkerRun.
var ErrRunStatesUnsupported = errors.New("the server has no run state endpoint, or agent push is switched off")

// ReportRunState records one state of a run against one of the caller's
// projects. It answers whether the state is new for the run: the server answers
// 201 for a new state and 200 for a state the run already holds.
func (c *Client) ReportRunState(ctx context.Context, handle, runID string, report RunStateReport) (bool, error) {
	report.RuleName = clip(strings.TrimSpace(report.RuleName), maxRuleName)
	report.Output = clip(report.Output, maxRunOutput)
	if report.FailureReason != nil {
		reason := clip(*report.FailureReason, maxFailureReason)
		report.FailureReason = &reason
	}

	body, err := json.Marshal(report)
	if err != nil {
		return false, fmt.Errorf("%w: encode the run state: %w", ErrReportRefused, err)
	}

	status, detail, err := c.putReport(ctx,
		"/api/projects/"+url.PathEscape(handle)+"/worker-runs/"+url.PathEscape(runID), body)
	if err != nil {
		return false, fmt.Errorf("report the run state: %w", err)
	}

	switch {
	case status == http.StatusCreated:
		return true, nil
	case status == http.StatusOK:
		return false, nil
	case status == http.StatusNotFound && !namesTheProject(detail):
		return false, ErrRunStatesUnsupported
	}

	return false, reportFailure("run state report", status, detail)
}

// ReportRunInventory tells the server every run the bridge still holds. The
// server marks Lost each open run of this bridge that runs does not name.
func (c *Client) ReportRunInventory(ctx context.Context, bridgeID string, runs []InventoryRun) error {
	if runs == nil {
		runs = []InventoryRun{}
	}
	body, err := json.Marshal(struct {
		Runs []InventoryRun `json:"runs"`
	}{runs})
	if err != nil {
		return fmt.Errorf("%w: encode the run inventory: %w", ErrReportRefused, err)
	}

	status, detail, err := c.putReport(ctx, "/api/bridges/"+url.PathEscape(bridgeID)+"/runs", body)
	if err != nil {
		return fmt.Errorf("report the run inventory: %w", err)
	}

	switch status {
	case http.StatusOK, http.StatusNoContent:
		return nil
	case http.StatusNotFound:
		return ErrRunStatesUnsupported
	}

	return reportFailure("run inventory", status, detail)
}

// putReport sends body to path, and returns the status and the start of the
// answer.
func (c *Client) putReport(ctx context.Context, path string, body []byte) (int, []byte, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodPut, c.baseURL+path, bytes.NewReader(body))
	if err != nil {
		return 0, nil, err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.do(req)
	if err != nil {
		return 0, nil, err
	}
	defer resp.Body.Close()

	detail, _ := io.ReadAll(io.LimitReader(resp.Body, 512))

	return resp.StatusCode, detail, nil
}

// reportFailure reads a status that is not a success. A rate limit and a
// request timeout clear on their own, so they are the two 4xx answers worth
// another try. Every other 4xx reads the same body again.
func reportFailure(what string, status int, detail []byte) error {
	text := strings.TrimSpace(string(detail))
	switch {
	case status == http.StatusTooManyRequests, status == http.StatusRequestTimeout:
		return fmt.Errorf("%s not taken yet (HTTP %d)", what, status)
	case status >= 400 && status < 500:
		return fmt.Errorf("%w (HTTP %d): %s", ErrReportRefused, status, text)
	default:
		return fmt.Errorf("%s failed (HTTP %d): %s", what, status, text)
	}
}

// namesTheProject reports whether a 404 says the caller has no such project.
// That run is refused for good, and the old endpoint would refuse it too.
func namesTheProject(detail []byte) bool {
	return errors.Is(notFound(bytes.NewReader(detail)), ErrProjectNotFound)
}
