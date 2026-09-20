package mcpproxy

import (
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
)

// rotating hands out stale until a refresh, then fresh.
type rotating struct {
	mu        sync.Mutex
	current   string
	refreshed int
}

func (r *rotating) Token(context.Context) (string, error) { return r.current, nil }

func (r *rotating) Refresh(context.Context, string) (string, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.refreshed++
	r.current = "fresh"

	return r.current, nil
}

// post sends one request through creds and gives back what the server saw.
func post(t *testing.T, creds *Credentials, handler http.HandlerFunc) (*http.Response, error) {
	t.Helper()
	server := httptest.NewServer(handler)
	t.Cleanup(server.Close)
	creds.Base = server.Client().Transport

	req, err := http.NewRequestWithContext(context.Background(), http.MethodPost, server.URL, strings.NewReader(`{"method":"tools/list"}`))
	if err != nil {
		t.Fatalf("build request: %v", err)
	}

	return creds.RoundTrip(req)
}

func TestARejectedTokenIsRefreshedOnceAndTheBodyIsSentAgain(t *testing.T) {
	var mu sync.Mutex
	var bodies []string
	tokens := &rotating{current: "stale"}

	resp, err := post(t, &Credentials{Tokens: tokens}, func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		mu.Lock()
		bodies = append(bodies, r.Header.Get("Authorization")+" "+string(body))
		mu.Unlock()
		if r.Header.Get("Authorization") != "Bearer fresh" {
			w.WriteHeader(http.StatusUnauthorized)

			return
		}
		w.WriteHeader(http.StatusOK)
	})
	if err != nil {
		t.Fatalf("RoundTrip: %v", err)
	}
	resp.Body.Close()

	mu.Lock()
	defer mu.Unlock()
	if len(bodies) != 2 {
		t.Fatalf("requests: got %d, want 2", len(bodies))
	}
	// The retry must carry the body again. An empty retry is the bug this
	// guards: the first send consumed the reader.
	for _, seen := range bodies {
		if !strings.Contains(seen, `{"method":"tools/list"}`) {
			t.Fatalf("request without its body: %s", seen)
		}
	}
	if tokens.refreshed != 1 {
		t.Fatalf("refreshes: got %d, want exactly 1", tokens.refreshed)
	}
}

func TestATokenThatCannotRefreshGivesAMessageThatSaysToLogInAgain(t *testing.T) {
	_, err := post(t, &Credentials{Tokens: api.StaticToken("static")}, func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
	})
	if err == nil {
		t.Fatal("RoundTrip against a 401: got no error")
	}
	if !strings.Contains(err.Error(), "loupe login") {
		t.Fatalf("message must say how to recover, got %v", err)
	}
}

func TestARefusedProjectSaysWhichProjectWasAskedFor(t *testing.T) {
	_, err := post(t, &Credentials{Tokens: api.StaticToken("static"), ProjectID: "01a0c0d9-905c-7922-a586-ccc8ce043704"}, func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusForbidden)
		_, _ = w.Write([]byte("this login does not cover that project"))
	})
	if err == nil {
		t.Fatal("RoundTrip against a 403: got no error")
	}
	if !strings.Contains(err.Error(), "01a0c0d9-905c-7922-a586-ccc8ce043704") {
		t.Fatalf("message must name the project, got %v", err)
	}
	if !strings.Contains(err.Error(), ".loupe.yaml") {
		t.Fatalf("message must name the file that chose it, got %v", err)
	}
	if !strings.Contains(err.Error(), "this login does not cover that project") {
		t.Fatalf("message must carry the server's reason, got %v", err)
	}
}

func TestASuccessPassesStraightThrough(t *testing.T) {
	resp, err := post(t, &Credentials{Tokens: api.StaticToken("static")}, func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"ok":true}`))
	})
	if err != nil {
		t.Fatalf("RoundTrip: %v", err)
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)
	if string(body) != `{"ok":true}` {
		t.Fatalf("body: got %s, want the server's own answer", body)
	}
}

func TestASessionGoneFourOhFourLosesItsBodySoTheTransportSeesTheEndedSession(t *testing.T) {
	// Loupe answers a request on an ended session with 404 and a JSON-RPC
	// error body. The transport decodes such a body first and reports the call
	// as rejected, which hides the ended session, so the body must go.
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusNotFound)
		_, _ = w.Write([]byte(`{"jsonrpc":"2.0","id":"","error":{"code":-32600,"message":"Session not found or has expired."}}`))
	}))
	t.Cleanup(server.Close)

	creds := &Credentials{Tokens: api.StaticToken("static"), Base: server.Client().Transport}
	req, err := http.NewRequestWithContext(context.Background(), http.MethodPost, server.URL, strings.NewReader("{}"))
	if err != nil {
		t.Fatalf("build request: %v", err)
	}
	req.Header.Set(sessionHeader, "11111111-1111-1111-1111-111111111111")

	resp, err := creds.RoundTrip(req)
	if err != nil {
		t.Fatalf("RoundTrip: %v", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusNotFound {
		t.Fatalf("status: got %d, want 404 kept", resp.StatusCode)
	}
	body, _ := io.ReadAll(resp.Body)
	if len(body) != 0 {
		t.Fatalf("body: got %s, want it dropped", body)
	}
}

func TestAFourOhFourOnARequestWithNoSessionKeepsItsBody(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusNotFound)
		_, _ = w.Write([]byte("no such endpoint"))
	}))
	t.Cleanup(server.Close)

	creds := &Credentials{Tokens: api.StaticToken("static"), Base: server.Client().Transport}
	req, err := http.NewRequestWithContext(context.Background(), http.MethodPost, server.URL, strings.NewReader("{}"))
	if err != nil {
		t.Fatalf("build request: %v", err)
	}

	resp, err := creds.RoundTrip(req)
	if err != nil {
		t.Fatalf("RoundTrip: %v", err)
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)
	if string(body) != "no such endpoint" {
		t.Fatalf("body: got %q, want the server's own answer kept", body)
	}
}
