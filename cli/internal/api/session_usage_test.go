package api

import (
	"context"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
)

const usageSessionID = "0199a0e2-d3e4-7f66-9b33-405162738400"

func putSessionUsage(t *testing.T, status int, answer string) (string, string, SessionUsageResult, error) {
	t.Helper()
	var gotPath, gotBody string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPut {
			t.Errorf("method = %s, want PUT", r.Method)
		}
		gotPath = r.URL.EscapedPath()
		body, _ := io.ReadAll(r.Body)
		gotBody = string(body)
		w.WriteHeader(status)
		_, _ = io.WriteString(w, answer)
	}))
	t.Cleanup(server.Close)

	cost := 0.5
	processes := []Usage{
		{Source: UsageReported, Models: map[string]ModelUsage{"claude-opus-5-5": {InputTokens: 1, CostUSD: &cost}}},
		{Source: UsageEstimated},
	}
	result, err := New(server.URL, "t", server.Client()).
		ReportSessionUsage(context.Background(), "loupe", usageSessionID, processes)

	return gotPath, gotBody, result, err
}

func TestReportSessionUsageSendsEachProcess(t *testing.T) {
	path, body, result, err := putSessionUsage(t, http.StatusOK, `{"runs":2,"updated":1}`)
	if err != nil {
		t.Fatal(err)
	}
	if path != "/api/projects/loupe/worker-runs/sessions/"+usageSessionID+"/usage" {
		t.Fatalf("path = %s", path)
	}
	want := `{"processes":[{"source":"reported","models":{"claude-opus-5-5":{"inputTokens":1,"outputTokens":0,"cacheReadTokens":0,"cacheWriteTokens":0,"costUsd":0.5}}},{"source":"estimated","models":{}}]}`
	if body != want {
		t.Fatalf("body = %s\nwant %s", body, want)
	}
	if result != (SessionUsageResult{Runs: 2, Updated: 1}) {
		t.Fatalf("result = %+v", result)
	}
}

// The usage of one run names the run, and holds that run alone.
func TestReportRunUsageNamesTheRun(t *testing.T) {
	var gotPath, gotBody string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.EscapedPath()
		body, _ := io.ReadAll(r.Body)
		gotBody = string(body)
		_, _ = io.WriteString(w, `{"runs":1,"updated":1}`)
	}))
	t.Cleanup(server.Close)

	result, err := New(server.URL, "t", server.Client()).
		ReportRunUsage(context.Background(), "loupe", usageSessionID, "0199a0e2-4444-7c5e-9f2a-3b1c6d7e8f90", Usage{Source: UsageEstimated})
	if err != nil {
		t.Fatal(err)
	}
	if gotPath != "/api/projects/loupe/worker-runs/sessions/"+usageSessionID+"/usage" {
		t.Fatalf("path = %s", gotPath)
	}
	want := `{"runId":"0199a0e2-4444-7c5e-9f2a-3b1c6d7e8f90","processes":[{"source":"estimated","models":{}}]}`
	if gotBody != want {
		t.Fatalf("body = %s\nwant %s", gotBody, want)
	}
	if result != (SessionUsageResult{Runs: 1, Updated: 1}) {
		t.Fatalf("result = %+v", result)
	}
}

// The server names why it wrote nothing, and the caller prints that name.
func TestReportSessionUsageNamesTheRefusal(t *testing.T) {
	for status, answer := range map[int]string{
		http.StatusConflict: `{"error":"process_count_mismatch"}`,
		http.StatusNotFound: `{"error":"project_not_found"}`,
	} {
		_, _, _, err := putSessionUsage(t, status, answer)
		var refused *UsageRefused
		if !errors.As(err, &refused) || refused.Status != status {
			t.Fatalf("status %d: err = %v", status, err)
		}
	}
	_, _, _, err := putSessionUsage(t, http.StatusConflict, `{"error":"ambiguous_start_order"}`)
	var refused *UsageRefused
	if !errors.As(err, &refused) || refused.Code != "ambiguous_start_order" {
		t.Fatalf("err = %v", err)
	}
	_, _, _, err = putSessionUsage(t, http.StatusNotFound, ``)
	if !errors.As(err, &refused) || refused.Code != "" || err.Error() != "HTTP 404" {
		t.Fatalf("err = %v", err)
	}
}
