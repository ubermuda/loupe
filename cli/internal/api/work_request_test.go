package api

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

const (
	workBridgeID  = "0192f3a1-7777-4d3e-8f10-a2b3c4d5e6f7"
	workRequestID = "0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90"
	workToken     = "5b0a4c8e-2f61-4d3a-9c7e-8a1b2c3d4e5f"
)

func workRequestJSON(state string) string {
	return fmt.Sprintf(`{"type":"bridge.work_request","projectId":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7",`+
		`"subject":{"type":"work-request","id":%[1]q},"workRequestId":%[1]q,"kind":"implement","capability":null,`+
		`"state":%[2]q,"subjectType":"card","subjectId":"0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7","cardNumber":42,"ruleId":"impl.rule",`+
		`"createdAt":"2026-10-01T12:30:00+00:00"}`, workRequestID, state)
}

func workServer(t *testing.T, status int, body string, seen func(*http.Request, string)) *Client {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		if seen != nil {
			seen(r, string(raw))
		}
		w.WriteHeader(status)
		_, _ = io.WriteString(w, body)
	}))
	t.Cleanup(server.Close)

	return New(server.URL, "secret", server.Client())
}

func TestClaimWorkRequestPostsAndReadsTheClaim(t *testing.T) {
	var method, path, auth, body string
	answer := fmt.Sprintf(`{"workRequestId":%q,"claimToken":%q,"leaseUntil":"2026-10-01T12:32:00+00:00","workRequest":%s}`,
		strings.ToUpper(workRequestID), workToken, workRequestJSON("claimed"))
	client := workServer(t, http.StatusOK, answer, func(r *http.Request, raw string) {
		method, path, auth, body = r.Method, r.URL.EscapedPath(), r.Header.Get("Authorization"), raw
	})

	claim, err := client.ClaimWorkRequest(context.Background(), workBridgeID, workRequestID)
	if err != nil {
		t.Fatal(err)
	}
	if method != http.MethodPost || path != "/api/bridges/"+workBridgeID+"/work-requests/"+workRequestID+"/claim" || auth != "Bearer secret" || body != "" {
		t.Fatalf("method = %q, path = %q, auth = %q, body = %q", method, path, auth, body)
	}
	if claim.ClaimToken != workToken || !claim.LeaseUntil.Equal(time.Date(2026, 10, 1, 12, 32, 0, 0, time.UTC)) {
		t.Fatalf("claim = %+v", claim)
	}
	w := claim.WorkRequest
	if w.WorkRequestID != workRequestID || w.Kind != "implement" || w.Capability != "" || w.State != WorkRequestClaimed ||
		w.SubjectType != SubjectCard || w.SubjectID != "0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7" || w.CardNumber != 42 || w.RuleID != "impl.rule" || !w.CreatedAt.Equal(time.Date(2026, 10, 1, 12, 30, 0, 0, time.UTC)) {
		t.Fatalf("work request = %+v", w)
	}
}

// A claim answer far past the 512 bytes of an error read still decodes whole.
func TestClaimWorkRequestReadsALongAnswer(t *testing.T) {
	answer := fmt.Sprintf(`{"workRequestId":%q,"claimToken":%q,"leaseUntil":"2026-10-01T12:32:00+00:00","padding":%q,"workRequest":%s}`,
		workRequestID, workToken, strings.Repeat("x", 4096), workRequestJSON("claimed"))
	claim, err := workServer(t, http.StatusOK, answer, nil).ClaimWorkRequest(context.Background(), workBridgeID, workRequestID)
	if err != nil || claim.WorkRequest.Kind != "implement" {
		t.Fatalf("claim = %+v, err = %v", claim, err)
	}
}

func TestClaimWorkRequestRefusesAnAnswerItCannotUse(t *testing.T) {
	for name, answer := range map[string]string{
		"another request": fmt.Sprintf(`{"workRequestId":"0199a0e2-0000-0000-0000-000000000000","claimToken":%q,"leaseUntil":"2026-10-01T12:32:00+00:00"}`, workToken),
		"no token":        fmt.Sprintf(`{"workRequestId":%q,"claimToken":"","leaseUntil":"2026-10-01T12:32:00+00:00"}`, workRequestID),
		"no lease":        fmt.Sprintf(`{"workRequestId":%q,"claimToken":%q}`, workRequestID, workToken),
		"not JSON":        `<html>`,
	} {
		t.Run(name, func(t *testing.T) {
			if _, err := workServer(t, http.StatusOK, answer, nil).ClaimWorkRequest(context.Background(), workBridgeID, workRequestID); err == nil {
				t.Fatal("expected an error")
			}
		})
	}
}

func TestClaimWorkRequestNamesEachFailure(t *testing.T) {
	for name, tc := range map[string]struct {
		status      int
		body        string
		want        error
		refusal     bool
		rateLimited bool
		code        string
	}{
		"already claimed":    {http.StatusConflict, `{"error":"already_claimed"}`, ErrWorkRequestAlreadyClaimed, false, false, ""},
		"not found":          {http.StatusNotFound, `{"error":"work_request_not_found"}`, ErrWorkRequestNotFound, false, false, ""},
		"old server":         {http.StatusNotFound, ``, ErrWorkRequestsUnsupported, false, false, ""},
		"unknown bridge":     {http.StatusUnprocessableEntity, `{"error":"unknown_bridge"}`, nil, true, false, "unknown_bridge"},
		"capability missing": {http.StatusUnprocessableEntity, `{"error":"capability_missing"}`, nil, true, false, "capability_missing"},
		"rate limit":         {http.StatusTooManyRequests, ``, nil, true, true, ""},
		"server error":       {http.StatusInternalServerError, `boom`, nil, false, false, ""},
	} {
		t.Run(name, func(t *testing.T) {
			_, err := workServer(t, tc.status, tc.body, nil).ClaimWorkRequest(context.Background(), workBridgeID, workRequestID)
			checkWorkError(t, err, tc.want, tc.refusal, tc.rateLimited, tc.code)
		})
	}
}

func checkWorkError(t *testing.T, err, want error, refusal, rateLimited bool, code string) {
	t.Helper()
	if err == nil {
		t.Fatal("expected an error")
	}
	if want != nil && !errors.Is(err, want) {
		t.Fatalf("err = %v, want %v", err, want)
	}
	var r *WorkRequestRefusal
	if errors.As(err, &r) != refusal {
		t.Fatalf("err = %v, refusal = %v", err, !refusal)
	}
	if refusal && (r.RateLimited() != rateLimited || r.Code != code) {
		t.Fatalf("refusal = %+v, rate limited = %v", r, r.RateLimited())
	}
}

func TestSettleWorkRequestPutsTheResult(t *testing.T) {
	var method, path, contentType, body string
	client := workServer(t, http.StatusOK, fmt.Sprintf(`{"workRequestId":%q,"state":"refused"}`, workRequestID), func(r *http.Request, raw string) {
		method, path, contentType, body = r.Method, r.URL.EscapedPath(), r.Header.Get("Content-Type"), raw
	})

	stored, err := client.SettleWorkRequest(context.Background(), workBridgeID, workRequestID, workToken, WorkRequestRefused, " worktree-dirty ")
	if err != nil || stored != WorkRequestRefused {
		t.Fatalf("stored = %q, err = %v", stored, err)
	}
	if method != http.MethodPut || path != "/api/bridges/"+workBridgeID+"/work-requests/"+workRequestID+"/result" || contentType != "application/json" {
		t.Fatalf("method = %q, path = %q, content type = %q", method, path, contentType)
	}
	if body != `{"claimToken":"`+workToken+`","state":"refused","reason":"worktree-dirty"}` {
		t.Fatalf("body = %s", body)
	}
}

func TestSettleWorkRequestSendsNoEmptyReason(t *testing.T) {
	var body string
	client := workServer(t, http.StatusOK, fmt.Sprintf(`{"workRequestId":%q,"state":"done"}`, workRequestID), func(_ *http.Request, raw string) {
		body = raw
	})

	if _, err := client.SettleWorkRequest(context.Background(), workBridgeID, workRequestID, workToken, WorkRequestDone, "  "); err != nil {
		t.Fatal(err)
	}
	if body != `{"claimToken":"`+workToken+`","state":"done"}` {
		t.Fatalf("body = %s", body)
	}
}

func TestSettleWorkRequestNamesEachFailure(t *testing.T) {
	for name, tc := range map[string]struct {
		status      int
		body        string
		want        error
		refusal     bool
		rateLimited bool
		code        string
	}{
		"claim lost":    {http.StatusConflict, `{"error":"claim_lost"}`, ErrClaimLost, false, false, ""},
		"not found":     {http.StatusNotFound, `{"error":"work_request_not_found"}`, ErrWorkRequestNotFound, false, false, ""},
		"old server":    {http.StatusNotFound, ``, ErrWorkRequestsUnsupported, false, false, ""},
		"invalid state": {http.StatusUnprocessableEntity, `{"error":"invalid_state"}`, nil, true, false, "invalid_state"},
		"invalid token": {http.StatusUnprocessableEntity, `{"error":"invalid_claim_token"}`, nil, true, false, "invalid_claim_token"},
		"rate limit":    {http.StatusTooManyRequests, ``, nil, true, true, ""},
		"other 409":     {http.StatusConflict, `{"error":"something_else"}`, nil, false, false, ""},
	} {
		t.Run(name, func(t *testing.T) {
			_, err := workServer(t, tc.status, tc.body, nil).SettleWorkRequest(context.Background(), workBridgeID, workRequestID, workToken, WorkRequestDone, "")
			checkWorkError(t, err, tc.want, tc.refusal, tc.rateLimited, tc.code)
			if name == "other 409" && errors.Is(err, ErrClaimLost) {
				t.Fatalf("err = %v, want no claim_lost", err)
			}
		})
	}
}

func TestHeartbeatSendsTheWorkClaims(t *testing.T) {
	var body string
	client := workServer(t, http.StatusNoContent, ``, func(_ *http.Request, raw string) { body = raw })

	hb := Heartbeat{CLIVersion: "v", WorkClaims: []WorkClaim{{ID: workRequestID, ClaimToken: workToken}}}
	if _, err := client.Heartbeat(context.Background(), workBridgeID, hb); err != nil {
		t.Fatal(err)
	}
	if body != `{"projects":[],"cliVersion":"v","workClaims":[{"id":"`+workRequestID+`","claimToken":"`+workToken+`"}]}` {
		t.Fatalf("body = %s", body)
	}
}

// The server refuses a heartbeat with more than 200 claims, and a refused
// heartbeat renews no lease at all.
func TestHeartbeatClipsTheWorkClaims(t *testing.T) {
	var body string
	client := workServer(t, http.StatusNoContent, ``, func(_ *http.Request, raw string) { body = raw })

	claims := make([]WorkClaim, MaxWorkClaims+5)
	for i := range claims {
		claims[i] = WorkClaim{ID: workRequestID, ClaimToken: workToken}
	}
	if _, err := client.Heartbeat(context.Background(), workBridgeID, Heartbeat{CLIVersion: "v", WorkClaims: claims}); err != nil {
		t.Fatal(err)
	}
	if n := strings.Count(body, `"claimToken"`); n != MaxWorkClaims {
		t.Fatalf("sent %d claims, want %d", n, MaxWorkClaims)
	}
}

// A bad offer or a bad id drops alone, and the rest of the reply stands.
func TestHeartbeatReadsTheWorkRequestsAndTheLostClaims(t *testing.T) {
	reply := fmt.Sprintf(`{"cliRange":"^1.0","workRequests":[%s,{"cardNumber":"x"},7],"lostClaims":[%q,7,null]}`,
		workRequestJSON("open"), strings.ToUpper(workRequestID))
	got, err := workServer(t, http.StatusOK, reply, nil).Heartbeat(context.Background(), workBridgeID, Heartbeat{CLIVersion: "v"})
	if err != nil {
		t.Fatal(err)
	}
	if got.CLIRange != "^1.0" || len(got.WorkRequests) != 1 || got.WorkRequests[0].State != WorkRequestOpen || got.WorkRequests[0].Kind != "implement" {
		t.Fatalf("reply = %+v", got)
	}
	if len(got.LostClaims) != 1 || got.LostClaims[0] != workRequestID {
		t.Fatalf("lost claims = %v", got.LostClaims)
	}
}

// A newer server sends the context of the request. An older one sends no
// context key, and a value it does not know is null; both decode as empty.
func TestAWorkRequestDecodesItsContext(t *testing.T) {
	full := `{"pullRequestNumber":42,"pullRequestUrl":"https://github.com/acme/widgets/pull/42",` +
		`"headSha":"abc1234","reason":"checks-failed","documentId":"01a10beb-ba65-736b-8626-a6e3fa59dfc5"}`
	for name, tc := range map[string]struct {
		body string
		want WorkRequestContext
	}{
		"full": {strings.TrimSuffix(workRequestJSON(WorkRequestOpen), "}") + `,"context":` + full + `}`, WorkRequestContext{
			PullRequestNumber: 42, PullRequestURL: "https://github.com/acme/widgets/pull/42", HeadSHA: "abc1234",
			Reason: "checks-failed", DocumentID: "01a10beb-ba65-736b-8626-a6e3fa59dfc5",
		}},
		"null values": {strings.TrimSuffix(workRequestJSON(WorkRequestOpen), "}") +
			`,"context":{"pullRequestNumber":null,"pullRequestUrl":null,"headSha":null,"reason":null,"documentId":null}}`, WorkRequestContext{}},
		"null context": {strings.TrimSuffix(workRequestJSON(WorkRequestOpen), "}") + `,"context":null}`, WorkRequestContext{}},
		"older server": {workRequestJSON(WorkRequestOpen), WorkRequestContext{}},
	} {
		t.Run(name, func(t *testing.T) {
			var w WorkRequest
			if err := json.Unmarshal([]byte(tc.body), &w); err != nil {
				t.Fatal(err)
			}
			if w.Context != tc.want || w.WorkRequestID != workRequestID {
				t.Fatalf("work request = %+v, want context %+v", w, tc.want)
			}
		})
	}
}
