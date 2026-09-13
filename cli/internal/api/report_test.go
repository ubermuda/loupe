package api

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

const bridgeID = "0192f3a1-7777-4d3e-8f10-a2b3c4d5e6f7"

func TestReportRulesSendsTheContractBody(t *testing.T) {
	var method, path, auth, accept, contentType, body string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		method, path, body = r.Method, r.URL.EscapedPath(), string(raw)
		auth, accept, contentType = r.Header.Get("Authorization"), r.Header.Get("Accept"), r.Header.Get("Content-Type")
		w.WriteHeader(http.StatusNoContent)
	}))
	t.Cleanup(server.Close)

	reason := ReasonColumnRenamed
	err := New(server.URL, "secret", server.Client()).ReportRules(context.Background(), "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7", bridgeID, []RuleHealth{
		{Name: "plan", On: "board.card_moved", Columns: []string{"ready"}, State: RuleDead, Reason: &reason},
		{Name: "review", On: "board.card_moved", Columns: []string{"review"}, State: RuleLive},
	})
	if err != nil {
		t.Fatal(err)
	}
	if method != http.MethodPut || path != "/api/projects/0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7/bridges/"+bridgeID+"/rules" {
		t.Fatalf("%s %s", method, path)
	}
	if auth != "Bearer secret" || accept != "application/json" || contentType != "application/json" {
		t.Fatalf("auth = %q, accept = %q, content type = %q", auth, accept, contentType)
	}
	want := `{"rules":[` +
		`{"name":"plan","on":"board.card_moved","columns":["ready"],"state":"dead","reason":"column_renamed"},` +
		`{"name":"review","on":"board.card_moved","columns":["review"],"state":"live","reason":null}]}`
	if body != want {
		t.Fatalf("body =\n%s\nwant\n%s", body, want)
	}
}

// A nil list still sends the empty list the contract requires, never null.
func TestReportRulesSendsAnEmptyList(t *testing.T) {
	var body string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		body = string(raw)
		w.WriteHeader(http.StatusNoContent)
	}))
	t.Cleanup(server.Close)

	if err := New(server.URL, "t", server.Client()).ReportRules(context.Background(), "loupe", bridgeID, nil); err != nil {
		t.Fatal(err)
	}
	if body != `{"rules":[]}` {
		t.Fatalf("body = %s", body)
	}
}

func TestReportRulesNamesEachFailure(t *testing.T) {
	for _, tc := range []struct {
		status   int
		body     string
		is       error
		rejected bool
	}{
		{http.StatusNotFound, `{"error":"board_disabled"}`, ErrBoardDisabled, false},
		{http.StatusNotFound, `{"error":"project_not_found"}`, ErrProjectNotFound, true},
		{http.StatusNotFound, ``, ErrEndpointMissing, true},
		{http.StatusUnprocessableEntity, `{"violations":[{"propertyPath":"rules[0].reason"}]}`, nil, true},
		{http.StatusForbidden, `{"error":"insufficient_scope"}`, nil, true},
		{http.StatusTooManyRequests, ``, nil, false},
		{http.StatusInternalServerError, ``, nil, false},
	} {
		server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			w.WriteHeader(tc.status)
			fmt.Fprint(w, tc.body)
		}))
		err := New(server.URL, "t", server.Client()).ReportRules(context.Background(), "loupe", bridgeID, nil)
		server.Close()

		if err == nil {
			t.Fatalf("HTTP %d: no error", tc.status)
		}
		if tc.is != nil && !errors.Is(err, tc.is) {
			t.Fatalf("HTTP %d: err = %v, want %v", tc.status, err, tc.is)
		}
		if errors.Is(err, ErrReportRejected) != tc.rejected {
			t.Fatalf("HTTP %d: err = %v, rejected should be %v", tc.status, err, tc.rejected)
		}
		if tc.status == http.StatusUnprocessableEntity && !strings.Contains(err.Error(), "rules[0].reason") {
			t.Fatalf("HTTP 422: err = %v, want the violation in it", err)
		}
	}
}
