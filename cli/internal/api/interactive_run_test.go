package api

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

const testSessionID = "0199a0e2-5e5e-7f66-9b33-405162738495"

func launchReport(state string) InteractiveLaunchReport {
	return InteractiveLaunchReport{
		BridgeID:   "0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90",
		CardID:     "0199a0e2-b1f3-7a44-9c11-2d3e4f506172",
		CardNumber: 42,
		RuleName:   "design",
		State:      state,
		At:         time.Date(2026, 9, 25, 10, 0, 0, 0, time.UTC),
	}
}

// putLaunch sends report to a server that answers status, and returns the path
// and the body the server read.
func putLaunch(t *testing.T, report InteractiveLaunchReport, status int) (string, map[string]any, bool, error) {
	t.Helper()

	var gotPath, gotMethod string
	var gotBody map[string]any
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath, gotMethod = r.URL.EscapedPath(), r.Method
		_ = json.NewDecoder(r.Body).Decode(&gotBody)
		w.WriteHeader(status)
	}))
	t.Cleanup(server.Close)

	created, err := New(server.URL, "t", server.Client()).
		ReportInteractiveLaunch(context.Background(), "loupe", testSessionID, report)
	if gotMethod != http.MethodPut {
		t.Fatalf("method = %s, want PUT", gotMethod)
	}

	return gotPath, gotBody, created, err
}

func TestReportInteractiveLaunchPutsTheLaunchOnTheSession(t *testing.T) {
	path, body, created, err := putLaunch(t, launchReport(RunRunning), http.StatusCreated)
	if err != nil || !created {
		t.Fatalf("created = %v, err = %v", created, err)
	}
	if path != "/api/projects/loupe/interactive-runs/"+testSessionID {
		t.Fatalf("path = %s", path)
	}
	if got := keys(body); got != "at,bridgeId,cardId,cardNumber,ruleName,state" {
		t.Fatalf("keys = %s", got)
	}
	if body["state"] != "running" || body["cardNumber"] != float64(42) || body["ruleName"] != "design" || body["at"] != "2026-09-25T10:00:00Z" {
		t.Fatalf("body = %v", body)
	}
}

func TestReportInteractiveLaunchSendsTheReasonOfAFailedLaunch(t *testing.T) {
	report := launchReport(RunNotStarted)
	report.FailureReason = "exit code 3: " + strings.Repeat("é", maxFailureReason)
	report.RuleName = "  " + strings.Repeat("r", maxRuleName+5)

	_, body, _, err := putLaunch(t, report, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if body["state"] != "not-started" {
		t.Fatalf("state = %v", body["state"])
	}
	reason, _ := body["failureReason"].(string)
	if len([]rune(reason)) != maxFailureReason || !strings.HasPrefix(reason, "exit code 3: é") {
		t.Fatalf("failureReason = %q", reason)
	}
	if body["ruleName"] != strings.Repeat("r", maxRuleName) {
		t.Fatalf("ruleName = %v", body["ruleName"])
	}
}

// A 404 with no error code is a server older than the endpoint. A 404 that
// names the project is a project the caller lost.
func TestReportInteractiveLaunchReadsEachAnswer(t *testing.T) {
	for _, tc := range []struct {
		status      int
		body        string
		created     bool
		ok          bool
		refused     bool
		unsupported bool
	}{
		{status: http.StatusCreated, created: true, ok: true},
		{status: http.StatusOK, ok: true},
		{status: http.StatusNotFound, unsupported: true},
		{status: http.StatusNotFound, body: `<html>Not Found</html>`, unsupported: true},
		{status: http.StatusNotFound, body: `{"error":"project_not_found"}`, refused: true},
		{status: http.StatusUnprocessableEntity, refused: true},
		{status: http.StatusTooManyRequests},
		{status: http.StatusInternalServerError},
	} {
		server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			w.WriteHeader(tc.status)
			fmt.Fprint(w, tc.body)
		}))

		created, err := New(server.URL, "t", server.Client()).
			ReportInteractiveLaunch(context.Background(), "loupe", testSessionID, launchReport(RunRunning))
		server.Close()

		if created != tc.created || (err == nil) != tc.ok {
			t.Fatalf("HTTP %d %s: created = %v, err = %v", tc.status, tc.body, created, err)
		}
		if errors.Is(err, ErrReportRefused) != tc.refused || errors.Is(err, ErrInteractiveRunsUnsupported) != tc.unsupported {
			t.Fatalf("HTTP %d %s: err = %v", tc.status, tc.body, err)
		}
	}
}
