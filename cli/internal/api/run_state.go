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
	RunQueued     = "queued"
	RunReplaced   = "replaced"
	RunSkipped    = "skipped"
	RunRunning    = "running"
	RunDropped    = "dropped"
	RunSucceeded  = "succeeded"
	RunNoResult   = "no-result"
	RunFailed     = "failed"
	RunNotStarted = "not-started"
	RunUnfinished = "unfinished"
	RunBlocked    = "blocked"
	// RunWaitingOnForge is a run that ended with its work waiting on the
	// forge, such as checks on a pushed pull request.
	RunWaitingOnForge = "waiting-on-forge"
	// RunStopping and RunStopped follow a person's stop. RunStopped closes the
	// run and is no outcome, so it carries no exit code and no result.
	RunStopping = "stopping"
	RunStopped  = "stopped"
	// RunPreparing is a run whose rule's before command runs. The agent has
	// not started.
	RunPreparing = "preparing"
)

// RunKindCommand is the kind of the run of a command rule. It runs no agent,
// so its running report names no session.
const RunKindCommand = "command"

// The reasons of a dropped run.
const (
	DropShutdown = "shutdown"
	DropRuleDead = "rule_dead"
	DropReload   = "reload"
)

// IsOutcome reports whether state is how a worker ended.
func IsOutcome(state string) bool {
	switch state {
	case RunSucceeded, RunNoResult, RunFailed, RunNotStarted, RunUnfinished, RunBlocked, RunWaitingOnForge:
		return true
	}

	return false
}

// RunStateReport is one state of a run, as PUT
// /api/projects/{handle}/worker-runs/{runId} takes it. Every report carries the
// subject and the work request, so the first one the server reads can create
// the run. A run of a rules: entry names no work request. A field that the state
// does not use stays zero and is not sent.
type RunStateReport struct {
	BridgeID string    `json:"bridgeId"`
	State    string    `json:"state"`
	At       time.Time `json:"at"`
	// SubjectType and SubjectID come from the work request of the run, and
	// name the card for the run of a rules: entry.
	SubjectType string `json:"subjectType"`
	SubjectID   string `json:"subjectId"`
	// CardNumber is the label of a card subject, and 0 for any other subject.
	CardNumber int `json:"cardNumber,omitempty"`
	// WorkRequestID, WorkKind and RuleID come from the work request of the run.
	WorkRequestID string `json:"workRequestId,omitempty"`
	WorkKind      string `json:"workKind,omitempty"`
	RuleID        string `json:"ruleId,omitempty"`
	// Rule names the run in the bridge log. The run state endpoint does not
	// take it.
	Rule string `json:"-"`
	// Kind is RunKindCommand for the run of a command rule, and empty for a
	// worker run.
	Kind string `json:"kind,omitempty"`

	SessionID string    `json:"sessionId,omitzero"`
	StartedAt time.Time `json:"startedAt,omitzero"`

	EndedAt  time.Time `json:"endedAt,omitzero"`
	ExitCode *int      `json:"exitCode,omitzero"`
	// HasResult says whether the output held a result line, and pairs with ExitCode.
	HasResult     *bool   `json:"hasResult,omitzero"`
	FailureReason *string `json:"failureReason,omitzero"`
	Output        string  `json:"output,omitzero"`

	ReplacedBy string `json:"replacedBy,omitzero"`
	Reason     string `json:"reason,omitzero"`
	// WorkerPool is the pool of a worker run: the pool it started in, or the
	// pool its rule names while it waits. An interactive run has none.
	WorkerPool string `json:"workerPool,omitzero"`

	// ResultStatus, ResultReason and ResultFields come from the worker's
	// structured result. ResumeSkipped says why the bridge did not resume a run
	// that did not finish.
	ResultStatus  string         `json:"resultStatus,omitempty"`
	ResultReason  string         `json:"resultReason,omitempty"`
	ResultFields  map[string]any `json:"resultFields,omitempty"`
	ResumeSkipped string         `json:"resumeSkipped,omitempty"`
	// Continues is the id of the run a resume continues.
	Continues string `json:"continues,omitempty"`
	// Usage goes on an outcome alone. A nil usage is unknown.
	Usage *Usage `json:"usage,omitempty"`
	// PeakContextTokens goes on an outcome and on a stopped report. A nil
	// value is unknown.
	PeakContextTokens *int64 `json:"peakContextTokens,omitempty"`
	// The experiment fields go on running and on the outcome of a run whose
	// rule joins an experiment.
	Experiment     string `json:"experiment,omitempty"`
	Variant        string `json:"variant,omitempty"`
	RequestedModel string `json:"requestedModel,omitempty"`
	SwitchedFrom   string `json:"switchedFrom,omitempty"`
	// Harness, Account and Model go on running and on the outcome of an agent
	// run. An empty one is not sent, and the server keeps what it holds.
	Harness string `json:"harness,omitempty"`
	Account string `json:"account,omitempty"`
	Model   string `json:"model,omitempty"`
}

// MarshalJSON sends every field of an outcome, so an empty output, a null
// result flag and the null half of the exit code and failure reason pair still
// reach the server.
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

// ReportRunState records one state of a run against one of the caller's
// projects. It answers whether the state is new for the run: the server answers
// 201 for a new state and 200 for a state the run already holds.
func (c *Client) ReportRunState(ctx context.Context, handle, runID string, report RunStateReport) (bool, error) {
	report.Output = clip(report.Output, maxRunOutput)
	report.Model = sendableModel(report.Model)
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
// That report is refused for good.
func namesTheProject(detail []byte) bool {
	return errors.Is(notFound(bytes.NewReader(detail)), ErrProjectNotFound)
}
