package api

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// InteractiveLaunchReport is how one launch of an interactive session went, as
// PUT /api/projects/{handle}/interactive-runs/{sessionId} takes it. State is
// RunRunning or RunNotStarted, and only RunNotStarted carries a FailureReason.
type InteractiveLaunchReport struct {
	BridgeID      string    `json:"bridgeId"`
	CardID        string    `json:"cardId"`
	CardNumber    int       `json:"cardNumber"`
	RuleName      string    `json:"ruleName"`
	State         string    `json:"state"`
	At            time.Time `json:"at"`
	FailureReason string    `json:"failureReason,omitzero"`
}

// ErrInteractiveRunsUnsupported marks a 404 with no error code, which is the
// answer of a server older than the interactive run endpoint, or with agent
// push switched off.
var ErrInteractiveRunsUnsupported = errors.New("the server has no interactive run endpoint, or agent push is switched off")

// ReportInteractiveLaunch records one launch against one of the caller's
// projects. It answers whether the server wrote a new row: 201 for a new one
// and 200 for one it already holds.
func (c *Client) ReportInteractiveLaunch(ctx context.Context, handle, sessionID string, report InteractiveLaunchReport) (bool, error) {
	report.RuleName = clip(strings.TrimSpace(report.RuleName), maxRuleName)
	report.FailureReason = clip(report.FailureReason, maxFailureReason)

	body, err := json.Marshal(report)
	if err != nil {
		return false, fmt.Errorf("%w: encode the interactive launch: %w", ErrReportRefused, err)
	}

	status, detail, err := c.putReport(ctx,
		"/api/projects/"+url.PathEscape(handle)+"/interactive-runs/"+url.PathEscape(sessionID), body)
	if err != nil {
		return false, fmt.Errorf("report the interactive launch: %w", err)
	}

	switch {
	case status == http.StatusCreated:
		return true, nil
	case status == http.StatusOK:
		return false, nil
	case status == http.StatusNotFound && !namesTheProject(detail):
		return false, ErrInteractiveRunsUnsupported
	}

	return false, reportFailure("interactive launch report", status, detail)
}
