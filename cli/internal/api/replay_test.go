package api

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestReplayReadsAPage(t *testing.T) {
	var method, path, query, auth string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		method, path, query, auth = r.Method, r.URL.EscapedPath(), r.URL.RawQuery, r.Header.Get("Authorization")
		fmt.Fprint(w, `{"events":[{"id":"4811","type":"board.card_moved","data":"{\"projectId\":\"p\"}"},{"id":"4812","type":"inbox.ask_closed","data":"{}"}],"hasMore":true}`)
	}))
	t.Cleanup(server.Close)

	page, err := New(server.URL, "secret", server.Client()).Replay(context.Background(), 4810)
	if err != nil {
		t.Fatal(err)
	}
	if method != http.MethodGet || path != "/api/events/replay" || query != "after=4810" || auth != "Bearer secret" {
		t.Fatalf("method = %q, path = %q, query = %q, auth = %q", method, path, query, auth)
	}
	if !page.HasMore || len(page.Events) != 2 {
		t.Fatalf("page = %+v", page)
	}
	if got := page.Events[0]; got.ID != "4811" || got.Type != "board.card_moved" || got.Data != `{"projectId":"p"}` {
		t.Fatalf("first event = %+v", got)
	}
}

func TestReplayNamesEachFailure(t *testing.T) {
	for name, tc := range map[string]struct {
		status int
		body   string
		text   string
	}{
		"forbidden":    {http.StatusForbidden, ``, "agent scope"},
		"unauthorized": {http.StatusUnauthorized, ``, "agent scope"},
		"old server":   {http.StatusNotFound, ``, "no GET /api/events/replay"},
		"rate limited": {http.StatusTooManyRequests, ``, "rate limit"},
		"server error": {http.StatusInternalServerError, `boom`, "HTTP 500): boom"},
		"not json":     {http.StatusOK, `<html>`, "decode"},
	} {
		t.Run(name, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(tc.status)
				fmt.Fprint(w, tc.body)
			}))
			t.Cleanup(server.Close)

			_, err := New(server.URL, "t", server.Client()).Replay(context.Background(), 0)
			if err == nil || !strings.Contains(err.Error(), tc.text) {
				t.Fatalf("err = %v, want it to contain %q", err, tc.text)
			}
		})
	}
}

func TestEventsReadsTheHead(t *testing.T) {
	for name, tc := range map[string]struct {
		field string
		want  *int64
	}{
		"head":    {`,"head":4812`, new(int64(4812))},
		"zero":    {`,"head":0`, new(int64(0))},
		"no head": {``, nil},
	} {
		t.Run(name, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				fmt.Fprint(w, `{"hubUrl":"h","jwt":"j","topic":"t","projects":[]`+tc.field+`}`)
			}))
			t.Cleanup(server.Close)

			got, err := New(server.URL, "t", server.Client()).Events(context.Background())
			if err != nil {
				t.Fatal(err)
			}
			if (got.Head == nil) != (tc.want == nil) || (got.Head != nil && *got.Head != *tc.want) {
				t.Fatalf("head = %v, want %v", got.Head, tc.want)
			}
		})
	}
}
