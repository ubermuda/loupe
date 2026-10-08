package api

import (
	"context"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

const pinCard = "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7"

func TestResolveExperimentPinSendsTheContractBody(t *testing.T) {
	var method, path, auth, contentType, body string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		method, path, body = r.Method, r.URL.EscapedPath(), string(raw)
		auth, contentType = r.Header.Get("Authorization"), r.Header.Get("Content-Type")
		_, _ = io.WriteString(w, `{"variant":"sonnet","switchedFrom":null}`)
	}))
	t.Cleanup(server.Close)

	variant, switchedFrom, err := New(server.URL, "secret", server.Client()).
		ResolveExperimentPin(context.Background(), "my project", "impl-model", pinCard, "sonnet", []string{"opus", "sonnet"}, []int{1, 2}, []string{"cost", "merge-rate"})
	if err != nil {
		t.Fatal(err)
	}
	if variant != "sonnet" || switchedFrom != "" {
		t.Fatalf("variant = %q, switched from = %q", variant, switchedFrom)
	}
	if method != http.MethodPut || path != "/api/projects/my%20project/experiments/impl-model/pins/"+pinCard {
		t.Fatalf("%s %s", method, path)
	}
	if auth != "Bearer secret" || contentType != "application/json" {
		t.Fatalf("auth = %q, content type = %q", auth, contentType)
	}
	if want := `{"candidate":"sonnet","variants":["opus","sonnet"],"weights":[1,2],"metrics":["cost","merge-rate"]}`; body != want {
		t.Fatalf("body = %s, want %s", body, want)
	}
}

// An experiment with no declared metrics sends an empty list, which the server
// reads as the default metrics.
func TestResolveExperimentPinSendsAnEmptyMetricList(t *testing.T) {
	var body string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		body = string(raw)
		_, _ = io.WriteString(w, `{"variant":"sonnet","switchedFrom":null}`)
	}))
	t.Cleanup(server.Close)

	if _, _, err := New(server.URL, "t", server.Client()).
		ResolveExperimentPin(context.Background(), "loupe", "impl-model", pinCard, "sonnet", nil, nil, nil); err != nil {
		t.Fatal(err)
	}
	if want := `{"candidate":"sonnet","variants":[],"weights":[],"metrics":[]}`; body != want {
		t.Fatalf("body = %s, want %s", body, want)
	}
}

func TestResolveExperimentPinReadsASwitchedPin(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = io.WriteString(w, `{"variant":"sonnet","switchedFrom":"haiku"}`)
	}))
	t.Cleanup(server.Close)

	variant, switchedFrom, err := New(server.URL, "t", server.Client()).
		ResolveExperimentPin(context.Background(), "loupe", "impl-model", pinCard, "sonnet", []string{"opus", "sonnet"}, []int{1, 1}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if variant != "sonnet" || switchedFrom != "haiku" {
		t.Fatalf("variant = %q, switched from = %q", variant, switchedFrom)
	}
}

func TestResolveExperimentPinNamesEachFailure(t *testing.T) {
	for name, tc := range map[string]struct {
		status      int
		body        string
		unsupported bool
	}{
		"coded 422":         {http.StatusUnprocessableEntity, `{"error":"invalid_variants"}`, false},
		"bare 404":          {http.StatusNotFound, ``, true},
		"unknown project":   {http.StatusNotFound, `{"error":"project_not_found"}`, false},
		"rate limit":        {http.StatusTooManyRequests, ``, false},
		"server error":      {http.StatusInternalServerError, ``, false},
		"200 with no value": {http.StatusOK, `{"variant":"","switchedFrom":null}`, false},
		"200 not json":      {http.StatusOK, `<html>`, false},
	} {
		t.Run(name, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(tc.status)
				_, _ = io.WriteString(w, tc.body)
			}))
			t.Cleanup(server.Close)

			variant, _, err := New(server.URL, "t", server.Client()).
				ResolveExperimentPin(context.Background(), "loupe", "impl-model", pinCard, "sonnet", []string{"sonnet"}, []int{1}, nil)
			if err == nil {
				t.Fatalf("variant = %q, want an error", variant)
			}
			if got := errors.Is(err, ErrExperimentPinsUnsupported); got != tc.unsupported {
				t.Fatalf("err = %v, unsupported = %v, want %v", err, got, tc.unsupported)
			}
		})
	}
}

func TestResolveExperimentPinStopsAtTheDeadline(t *testing.T) {
	release := make(chan struct{})
	server := httptest.NewServer(http.HandlerFunc(func(_ http.ResponseWriter, r *http.Request) {
		select {
		case <-r.Context().Done():
		case <-release:
		}
	}))
	t.Cleanup(server.Close)
	t.Cleanup(func() { close(release) })

	ctx, cancel := context.WithTimeout(context.Background(), 50*time.Millisecond)
	defer cancel()
	_, _, err := New(server.URL, "t", server.Client()).
		ResolveExperimentPin(ctx, "loupe", "impl-model", pinCard, "sonnet", []string{"sonnet"}, []int{1}, nil)
	if !errors.Is(err, context.DeadlineExceeded) {
		t.Fatalf("err = %v, want the deadline", err)
	}
}
