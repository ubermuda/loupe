package api

import (
	"context"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
)

// rotatingSource hands out old until a refresh, then fresh.
type rotatingSource struct {
	mu        sync.Mutex
	current   string
	fresh     string
	refreshed []string
	err       error
}

func (s *rotatingSource) Token(context.Context) (string, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	return s.current, nil
}

func (s *rotatingSource) Refresh(_ context.Context, rejected string) (string, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.refreshed = append(s.refreshed, rejected)
	if s.err != nil {
		return "", s.err
	}
	s.current = s.fresh

	return s.current, nil
}

func TestAClientRefreshesARejectedTokenAndRetriesOnce(t *testing.T) {
	var bodies []string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		bodies = append(bodies, r.Header.Get("Authorization")+" "+string(body))
		if r.Header.Get("Authorization") != "Bearer fresh" {
			w.WriteHeader(http.StatusUnauthorized)

			return
		}
		w.WriteHeader(http.StatusNoContent)
	}))
	t.Cleanup(server.Close)
	source := &rotatingSource{current: "stale", fresh: "fresh"}

	_, err := NewWithSource(server.URL, source, server.Client()).Heartbeat(context.Background(), "bridge-1", Heartbeat{Projects: []string{"p"}, CLIVersion: "v"})
	if err != nil {
		t.Fatalf("Heartbeat: %v", err)
	}
	if len(bodies) != 2 || !strings.HasPrefix(bodies[1], "Bearer fresh {") || strings.TrimPrefix(bodies[0], "Bearer stale ") != strings.TrimPrefix(bodies[1], "Bearer fresh ") {
		t.Fatalf("requests = %q, want the same body sent again with the new token", bodies)
	}
	if len(source.refreshed) != 1 || source.refreshed[0] != "stale" {
		t.Fatalf("refreshed = %v, want one refresh of the rejected token", source.refreshed)
	}
}

func TestAClientRefreshesOnlyOnceWhenTheNewTokenIsRejectedToo(t *testing.T) {
	calls := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		calls++
		w.WriteHeader(http.StatusUnauthorized)
	}))
	t.Cleanup(server.Close)
	source := &rotatingSource{current: "stale", fresh: "fresh"}

	_, err := NewWithSource(server.URL, source, server.Client()).Sites(context.Background())
	if err == nil || !strings.Contains(err.Error(), "401") {
		t.Fatalf("Sites error = %v, want the 401", err)
	}
	if calls != 2 || len(source.refreshed) != 1 {
		t.Fatalf("requests = %d, refreshes = %d, want 2 and 1", calls, len(source.refreshed))
	}
}

func TestAClientReportsAFailedRefresh(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
	}))
	t.Cleanup(server.Close)
	expired := errors.New("login expired: run `loupe login` again")
	source := &rotatingSource{current: "stale", err: expired}

	_, err := NewWithSource(server.URL, source, server.Client()).Events(context.Background())
	if !errors.Is(err, expired) {
		t.Fatalf("Events error = %v, want the refresh error", err)
	}
}

func TestAStaticTokenClientDoesNotRetryA401(t *testing.T) {
	calls := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		calls++
		w.WriteHeader(http.StatusUnauthorized)
	}))
	t.Cleanup(server.Close)

	_, err := New(server.URL, "static", server.Client()).Sites(context.Background())
	if err == nil || !strings.Contains(err.Error(), "agent scope") {
		t.Fatalf("Sites error = %v, want the scope hint", err)
	}
	if calls != 1 {
		t.Fatalf("requests = %d, want 1", calls)
	}
}
