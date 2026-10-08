package api

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"time"
	"unicode/utf8"
)

// maxFullText bounds the full text of one call, in bytes.
const maxFullText = 20000

// ToolCall is one tool call of a worker run, as PUT
// /api/projects/{handle}/worker-runs/{runId}/tool-calls takes it. Every key
// goes out, and a nil pointer goes out as null.
type ToolCall struct {
	Seq          int
	Tool         string
	StartedAt    time.Time
	DurationMs   *int64
	IsError      *bool
	InSubagent   bool
	BackgroundID *string
	WaitsOn      *string
	Signatures   []string
	// FullText is the raw input of the call, and nil unless the project
	// collects it.
	FullText *string
}

// ToolTiming is the tool time and the idle time of a run. A nil value is unknown.
type ToolTiming struct {
	ToolTimeMs *int64 `json:"toolTimeMs"`
	IdleGapMs  *int64 `json:"idleGapMs"`
}

// ToolCallBatch is one batch of the calls of a run. Only the last batch of a
// run carries its timing.
type ToolCallBatch struct {
	Calls  []ToolCall  `json:"calls"`
	Timing *ToolTiming `json:"timing"`
}

// MarshalJSON writes startedAt in UTC with milliseconds, and the signatures as
// a list even when there are none.
func (c ToolCall) MarshalJSON() ([]byte, error) {
	signatures := c.Signatures
	if signatures == nil {
		signatures = []string{}
	}

	return json.Marshal(struct {
		Seq          int      `json:"seq"`
		Tool         string   `json:"tool"`
		StartedAt    string   `json:"startedAt"`
		DurationMs   *int64   `json:"durationMs"`
		IsError      *bool    `json:"isError"`
		InSubagent   bool     `json:"inSubagent"`
		BackgroundID *string  `json:"backgroundId"`
		WaitsOn      *string  `json:"waitsOn"`
		Signatures   []string `json:"signatures"`
		FullText     *string  `json:"fullText"`
	}{
		c.Seq, c.Tool, c.StartedAt.UTC().Format("2006-01-02T15:04:05.000Z"), c.DurationMs, c.IsError,
		c.InSubagent, c.BackgroundID, c.WaitsOn, signatures, c.FullText,
	})
}

// ErrToolCallsUnsupported marks a 404 with no error code, which is the answer
// of a server older than the tool call endpoint, or with agent push switched
// off. No retry changes it.
var ErrToolCallsUnsupported = errors.New("the server has no tool call endpoint, or agent push is switched off")

// ReportToolCalls records one batch of the tool calls of a run. The server
// answers 200, and any 2xx means it stored the batch. A 404 that names a
// cause, such as an unknown run, is refused like any other 4xx.
func (c *Client) ReportToolCalls(ctx context.Context, handle, runID string, batch ToolCallBatch) error {
	calls := make([]ToolCall, len(batch.Calls))
	for i, call := range batch.Calls {
		if call.FullText != nil {
			text := cutBytes(*call.FullText, maxFullText)
			call.FullText = &text
		}
		calls[i] = call
	}
	batch.Calls = calls

	body, err := json.Marshal(batch)
	if err != nil {
		return fmt.Errorf("%w: encode the tool calls: %w", ErrReportRefused, err)
	}

	status, detail, err := c.putReport(ctx,
		"/api/projects/"+url.PathEscape(handle)+"/worker-runs/"+url.PathEscape(runID)+"/tool-calls", body)
	if err != nil {
		return fmt.Errorf("report the tool calls: %w", err)
	}

	switch {
	case status >= 200 && status < 300:
		return nil
	case status == http.StatusNotFound && !namesACause(detail):
		return ErrToolCallsUnsupported
	}

	return reportFailure("tool call report", status, detail)
}

// namesACause reports whether a 404 body is JSON with an error code.
func namesACause(detail []byte) bool {
	var payload struct {
		Error string `json:"error"`
	}

	return json.NewDecoder(bytes.NewReader(detail)).Decode(&payload) == nil && payload.Error != ""
}

// cutBytes keeps at most limit bytes of s, and never splits a rune.
func cutBytes(s string, limit int) string {
	if len(s) <= limit {
		return s
	}
	for limit > 0 && !utf8.RuneStart(s[limit]) {
		limit--
	}

	return s[:limit]
}
