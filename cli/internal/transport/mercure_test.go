package transport

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

// TestSubscribeResumesFromLastEventID proves the bridge does not silently lose
// notifications published while it was disconnected: the hub replays from
// Last-Event-ID, so the id of the last event seen must be sent on reconnect.
func TestSubscribeResumesFromLastEventID(t *testing.T) {
	var (
		mu       sync.Mutex
		lastSeen []string // Last-Event-ID header per connection, in order
		received []string
	)

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		lastSeen = append(lastSeen, r.Header.Get("Last-Event-ID"))
		attempt := len(lastSeen)
		mu.Unlock()

		w.Header().Set("Content-Type", "text/event-stream")
		w.WriteHeader(http.StatusOK)
		// Only the first connection carries an event; then it closes, forcing a
		// reconnect that must announce where we got to.
		if attempt == 1 {
			_, _ = w.Write([]byte("id: 42\ndata: {\"type\":\"site_review.submitted\"}\n\n"))
			if f, ok := w.(http.Flusher); ok {
				f.Flush()
			}
		}
	}))
	defer srv.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	done := make(chan struct{})
	go func() {
		defer close(done)
		_ = Subscribe(ctx, srv.Client(), srv.URL, "https://example.test/topic",
			func(context.Context) (string, error) { return "jwt", nil },
			Handler{
				OnData: func(data []byte) {
					mu.Lock()
					received = append(received, string(data))
					mu.Unlock()
				},
			},
		)
	}()

	// Wait for a reconnect to happen (backoff is a second), then stop.
	deadline := time.After(4 * time.Second)
	for {
		mu.Lock()
		attempts := len(lastSeen)
		mu.Unlock()
		if attempts >= 2 {
			break
		}
		select {
		case <-deadline:
			t.Fatalf("hub saw %d connection(s); expected a reconnect", attempts)
		case <-time.After(20 * time.Millisecond):
		}
	}
	cancel()
	<-done

	mu.Lock()
	defer mu.Unlock()

	if len(received) != 1 || received[0] != `{"type":"site_review.submitted"}` {
		t.Fatalf("unexpected events delivered: %q", received)
	}
	if lastSeen[0] != "" {
		t.Fatalf("first connection should not send Last-Event-ID, got %q", lastSeen[0])
	}
	if lastSeen[1] != "42" {
		t.Fatalf("reconnect should resume from the last event id; got %q", lastSeen[1])
	}
}

// TestSubscribeDoesNotCommitIDOfUndeliveredEvent proves the resume point only
// moves once an event has actually been handed over. The hub sends an id and a
// data line, then drops the connection before the blank line that ends the
// event — so nothing was delivered, and announcing that id on reconnect would
// ask the hub to replay from past it, losing the event permanently.
func TestSubscribeDoesNotCommitIDOfUndeliveredEvent(t *testing.T) {
	var (
		mu       sync.Mutex
		lastSeen []string
		received []string
	)

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		lastSeen = append(lastSeen, r.Header.Get("Last-Event-ID"))
		attempt := len(lastSeen)
		mu.Unlock()

		w.Header().Set("Content-Type", "text/event-stream")
		w.WriteHeader(http.StatusOK)
		if attempt == 1 {
			// No trailing blank line: the event is never dispatched.
			_, _ = w.Write([]byte("id: 42\ndata: {\"type\":\"site_review.submitted\"}\n"))
			if f, ok := w.(http.Flusher); ok {
				f.Flush()
			}
		}
	}))
	defer srv.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	done := make(chan struct{})
	go func() {
		defer close(done)
		_ = Subscribe(ctx, srv.Client(), srv.URL, "https://example.test/topic",
			func(context.Context) (string, error) { return "jwt", nil },
			Handler{
				OnData: func(data []byte) {
					mu.Lock()
					received = append(received, string(data))
					mu.Unlock()
				},
			},
		)
	}()

	deadline := time.After(4 * time.Second)
	for {
		mu.Lock()
		attempts := len(lastSeen)
		mu.Unlock()
		if attempts >= 2 {
			break
		}
		select {
		case <-deadline:
			t.Fatalf("hub saw %d connection(s); expected a reconnect", attempts)
		case <-time.After(20 * time.Millisecond):
		}
	}
	cancel()
	<-done

	mu.Lock()
	defer mu.Unlock()

	if len(received) != 0 {
		t.Fatalf("no event was terminated, so none should have been delivered; got %q", received)
	}
	if lastSeen[1] != "" {
		t.Fatalf("reconnect must not skip an undelivered event; got Last-Event-ID %q", lastSeen[1])
	}
}

// TestSubscribeClearsResumePointOnEmptyID pins the SSE rule that an empty `id:`
// resets the resume point: announcing the previous id after one would ask the
// hub to replay events already handled.
func TestSubscribeClearsResumePointOnEmptyID(t *testing.T) {
	var (
		mu       sync.Mutex
		lastSeen []string
		received []string
	)

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		lastSeen = append(lastSeen, r.Header.Get("Last-Event-ID"))
		attempt := len(lastSeen)
		mu.Unlock()

		w.Header().Set("Content-Type", "text/event-stream")
		w.WriteHeader(http.StatusOK)
		if attempt == 1 {
			_, _ = w.Write([]byte("id: 42\ndata: {\"type\":\"site_review.submitted\"}\n\n"))
			_, _ = w.Write([]byte("id:\ndata: {\"type\":\"site_review.submitted\"}\n\n"))
			if f, ok := w.(http.Flusher); ok {
				f.Flush()
			}
		}
	}))
	defer srv.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	done := make(chan struct{})
	go func() {
		defer close(done)
		_ = Subscribe(ctx, srv.Client(), srv.URL, "https://example.test/topic",
			func(context.Context) (string, error) { return "jwt", nil },
			Handler{
				OnData: func(data []byte) {
					mu.Lock()
					received = append(received, string(data))
					mu.Unlock()
				},
			},
		)
	}()

	deadline := time.After(4 * time.Second)
	for {
		mu.Lock()
		attempts := len(lastSeen)
		mu.Unlock()
		if attempts >= 2 {
			break
		}
		select {
		case <-deadline:
			t.Fatalf("hub saw %d connection(s); expected a reconnect", attempts)
		case <-time.After(20 * time.Millisecond):
		}
	}
	cancel()
	<-done

	mu.Lock()
	defer mu.Unlock()

	if len(received) != 2 {
		t.Fatalf("both events should have been delivered; got %q", received)
	}
	if lastSeen[1] != "" {
		t.Fatalf("an empty id must clear the resume point; got Last-Event-ID %q", lastSeen[1])
	}
}

// TestStreamHandlesCRLFLineEndings pins the behaviour the SSE spec allows and
// bufio.ScanLines already provides, so a future change to the scanner's split
// function cannot break CRLF hubs unnoticed.
func TestStreamHandlesCRLFLineEndings(t *testing.T) {
	var (
		mu       sync.Mutex
		received []string
	)

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/event-stream")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte("id: 7\r\ndata: {\"type\":\"site_review.submitted\"}\r\n\r\n"))
		if f, ok := w.(http.Flusher); ok {
			f.Flush()
		}
	}))
	defer srv.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	done := make(chan struct{})
	go func() {
		defer close(done)
		_ = Subscribe(ctx, srv.Client(), srv.URL, "https://example.test/topic",
			func(context.Context) (string, error) { return "jwt", nil },
			Handler{
				OnData: func(data []byte) {
					mu.Lock()
					received = append(received, string(data))
					mu.Unlock()
				},
			},
		)
	}()

	deadline := time.After(4 * time.Second)
	for {
		mu.Lock()
		got := len(received)
		mu.Unlock()
		if got > 0 {
			break
		}
		select {
		case <-deadline:
			t.Fatal("no event delivered from a CRLF-terminated stream")
		case <-time.After(20 * time.Millisecond):
		}
	}
	cancel()
	<-done

	mu.Lock()
	defer mu.Unlock()
	if received[0] != `{"type":"site_review.submitted"}` {
		t.Fatalf("unexpected payload from CRLF stream: %q", received[0])
	}
}

// TestSubscribeMintsAFreshTokenAfterA401 covers the regression that shipped
// once already: subscriber JWTs are short-lived, so a hub that rejects an
// expired one must be retried with a newly minted token, not the rejected one.
func TestSubscribeMintsAFreshTokenAfterA401(t *testing.T) {
	var (
		mu       sync.Mutex
		auth     []string // Authorization header per connection, in order
		received []string
		errs     []string
		minted   int
	)

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		auth = append(auth, r.Header.Get("Authorization"))
		attempt := len(auth)
		mu.Unlock()

		// The first token is treated as expired; the reconnect is served.
		if attempt == 1 {
			w.WriteHeader(http.StatusUnauthorized)

			return
		}

		w.Header().Set("Content-Type", "text/event-stream")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte("id: 9\ndata: {\"type\":\"site_review.submitted\"}\n\n"))
		if f, ok := w.(http.Flusher); ok {
			f.Flush()
		}
	}))
	defer srv.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()

	done := make(chan struct{})
	go func() {
		defer close(done)
		_ = Subscribe(ctx, srv.Client(), srv.URL, "https://example.test/topic",
			func(context.Context) (string, error) {
				mu.Lock()
				defer mu.Unlock()
				minted++

				return fmt.Sprintf("jwt-%d", minted), nil
			},
			Handler{
				OnData: func(data []byte) {
					mu.Lock()
					received = append(received, string(data))
					mu.Unlock()
				},
				OnError: func(err error) {
					mu.Lock()
					errs = append(errs, err.Error())
					mu.Unlock()
				},
			},
		)
	}()

	deadline := time.After(8 * time.Second)
	for {
		mu.Lock()
		got := len(received)
		mu.Unlock()
		if got > 0 {
			break
		}
		select {
		case <-deadline:
			mu.Lock()
			seen := append([]string(nil), auth...)
			mu.Unlock()
			t.Fatalf("no event delivered after a 401; connections were %q", seen)
		case <-time.After(20 * time.Millisecond):
		}
	}
	cancel()
	<-done

	mu.Lock()
	defer mu.Unlock()

	if len(auth) < 2 {
		t.Fatalf("hub saw %d connection(s); expected a retry after the 401", len(auth))
	}
	if auth[0] != "Bearer jwt-1" {
		t.Fatalf("first connection sent %q, want the first minted token", auth[0])
	}
	if auth[1] != "Bearer jwt-2" {
		t.Fatalf("retry sent %q; a rejected token must not be replayed", auth[1])
	}
	if len(errs) == 0 || !strings.Contains(errs[0], "401") {
		t.Fatalf("the 401 should have been reported to OnError; got %q", errs)
	}
	if received[0] != `{"type":"site_review.submitted"}` {
		t.Fatalf("unexpected payload after the retry: %q", received[0])
	}
}
