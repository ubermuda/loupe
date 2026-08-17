package transport

import (
	"context"
	"net/http"
	"net/http/httptest"
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
