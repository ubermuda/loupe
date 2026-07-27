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
