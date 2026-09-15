package api

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

const (
	checkProject = "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7"
	checkAsk     = "01a0a1b2-0000-7c3d-8e4f-5a6b7c8d9e0f"
)

func TestCheckAskReadsTheState(t *testing.T) {
	var method, path, auth string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		method, path, auth = r.Method, r.URL.EscapedPath(), r.Header.Get("Authorization")
		fmt.Fprintf(w, `{"askId":%q,"closed":true,"allRead":true}`, checkAsk)
	}))
	t.Cleanup(server.Close)

	got, err := New(server.URL, "secret", server.Client()).CheckAsk(context.Background(), checkProject, checkAsk)
	if err != nil {
		t.Fatal(err)
	}
	if method != http.MethodGet || path != "/api/projects/"+checkProject+"/inbox/asks/"+checkAsk || auth != "Bearer secret" {
		t.Fatalf("method = %q, path = %q, auth = %q", method, path, auth)
	}
	if !got.Closed || !got.AllRead {
		t.Fatalf("state = %+v", got)
	}
}

// Every answer that does not state both booleans for this ask is a failure, so
// the caller never skips a resume on a guess.
func TestCheckAskNamesEachFailure(t *testing.T) {
	for name, tc := range map[string]struct {
		status int
		body   string
		text   string
	}{
		"ask not found":     {http.StatusNotFound, `{"error":"ask_not_found"}`, `HTTP 404): {"error":"ask_not_found"}`},
		"project not found": {http.StatusNotFound, `{"error":"project_not_found"}`, `HTTP 404): {"error":"project_not_found"}`},
		"inbox off":         {http.StatusNotFound, ``, "HTTP 404"},
		"rate limited":      {http.StatusTooManyRequests, ``, "HTTP 429"},
		"server error":      {http.StatusInternalServerError, `boom`, "HTTP 500"},
		"not json":          {http.StatusOK, `<html>`, "decode"},
		"no allRead":        {http.StatusOK, `{"askId":"` + checkAsk + `","closed":true}`, "closed and allRead"},
		"no closed":         {http.StatusOK, `{"askId":"` + checkAsk + `","allRead":true}`, "closed and allRead"},
		"a null allRead":    {http.StatusOK, `{"askId":"` + checkAsk + `","closed":true,"allRead":null}`, "closed and allRead"},
		"another ask":       {http.StatusOK, `{"askId":"01a0a1b2-0000-7c3d-8e4f-000000000000","closed":true,"allRead":true}`, "another ask"},
	} {
		t.Run(name, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(tc.status)
				fmt.Fprint(w, tc.body)
			}))
			t.Cleanup(server.Close)

			_, err := New(server.URL, "t", server.Client()).CheckAsk(context.Background(), checkProject, checkAsk)
			if err == nil || !strings.Contains(err.Error(), tc.text) {
				t.Fatalf("err = %v, want it to contain %q", err, tc.text)
			}
		})
	}
}
