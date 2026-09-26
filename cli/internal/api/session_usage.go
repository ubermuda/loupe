package api

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
)

// SessionUsageResult counts the worker runs of the session, and the runs whose
// usage changed.
type SessionUsageResult struct {
	Runs    int `json:"runs"`
	Updated int `json:"updated"`
}

// UsageRefused is a session usage report the server did not take. Code is the
// error the server names, such as process_count_mismatch, and "" when it names
// none.
type UsageRefused struct {
	Status int
	Code   string
}

func (e *UsageRefused) Error() string {
	if e.Code != "" {
		return e.Code
	}

	return fmt.Sprintf("HTTP %d", e.Status)
}

// ReportSessionUsage sends the usage of each worker process of a session, in
// the order the processes started.
func (c *Client) ReportSessionUsage(ctx context.Context, handle, sessionID string, processes []Usage) (SessionUsageResult, error) {
	body, err := json.Marshal(struct {
		Processes []Usage `json:"processes"`
	}{processes})
	if err != nil {
		return SessionUsageResult{}, fmt.Errorf("encode the session usage: %w", err)
	}

	status, detail, err := c.putReport(ctx,
		"/api/projects/"+url.PathEscape(handle)+"/worker-runs/sessions/"+url.PathEscape(sessionID)+"/usage", body)
	if err != nil {
		return SessionUsageResult{}, fmt.Errorf("report the session usage: %w", err)
	}

	if status != http.StatusOK {
		var answer struct {
			Error string `json:"error"`
		}
		_ = json.Unmarshal(detail, &answer)

		return SessionUsageResult{}, &UsageRefused{Status: status, Code: answer.Error}
	}
	var result SessionUsageResult
	if err := json.Unmarshal(detail, &result); err != nil {
		return SessionUsageResult{}, fmt.Errorf("read the session usage answer: %w", err)
	}

	return result, nil
}
