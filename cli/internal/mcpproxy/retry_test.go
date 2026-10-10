package mcpproxy

import (
	"context"
	"errors"
	"io"
	"net"
	"net/http"
	"strings"
	"testing"
	"time"
)

// script answers each request with the next status of its list, and records the
// body of each request.
type script struct {
	statuses []int
	headers  map[string]string
	bodies   []string
	err      error
}

func (s *script) RoundTrip(req *http.Request) (*http.Response, error) {
	if req.Body != nil {
		b, _ := io.ReadAll(req.Body)
		s.bodies = append(s.bodies, string(b))
	}
	if s.err != nil {
		return nil, s.err
	}
	status := s.statuses[min(len(s.bodies)-1, len(s.statuses)-1)]
	resp := &http.Response{StatusCode: status, Header: http.Header{}, Body: http.NoBody}
	for k, v := range s.headers {
		resp.Header.Set(k, v)
	}

	return resp, nil
}

// clock records each wait and fires at once.
type clock struct{ waits []time.Duration }

func (c *clock) after(d time.Duration) <-chan time.Time {
	c.waits = append(c.waits, d)
	ch := make(chan time.Time, 1)
	ch <- time.Now()

	return ch
}

func newPost(t *testing.T) *http.Request {
	t.Helper()
	req, err := http.NewRequest(http.MethodPost, "http://loupe.test/mcp", strings.NewReader(`{"id":1}`))
	if err != nil {
		t.Fatal(err)
	}

	return req
}

func TestRetryingSendsTheSameBodyAgainAndWaitsForRetryAfter(t *testing.T) {
	base := &script{statuses: []int{429, 200}, headers: map[string]string{"Retry-After": "7"}}
	c := &clock{}
	r := &Retrying{Base: base, After: c.after}

	resp, err := r.RoundTrip(newPost(t))
	if err != nil || resp.StatusCode != 200 {
		t.Fatalf("got %v %v, want 200", resp, err)
	}
	if len(base.bodies) != 2 || base.bodies[0] != `{"id":1}` || base.bodies[1] != `{"id":1}` {
		t.Fatalf("bodies: got %q, want the same body twice", base.bodies)
	}
	if len(c.waits) != 1 || c.waits[0] != 7*time.Second {
		t.Fatalf("waits: got %v, want [7s]", c.waits)
	}
}

func TestRetryingBacksOffWithNoRetryAfterAndStopsAtTheBudget(t *testing.T) {
	base := &script{statuses: []int{503}}
	c := &clock{}
	r := &Retrying{Base: base, Backoff: []time.Duration{time.Second, 2 * time.Second}, Budget: 7 * time.Second, After: c.after}

	resp, err := r.RoundTrip(newPost(t))
	if err != nil || resp.StatusCode != 503 {
		t.Fatalf("got %v %v, want the last 503", resp, err)
	}
	want := []time.Duration{time.Second, 2 * time.Second, 2 * time.Second, 2 * time.Second}
	if len(c.waits) != len(want) {
		t.Fatalf("waits: got %v, want %v", c.waits, want)
	}
	for i := range want {
		if c.waits[i] != want[i] {
			t.Fatalf("waits: got %v, want %v", c.waits, want)
		}
	}
}

func TestRetryingCapsRetryAfterAtTheBudget(t *testing.T) {
	base := &script{statuses: []int{429}, headers: map[string]string{"Retry-After": "3600"}}
	c := &clock{}
	r := &Retrying{Base: base, Budget: 10 * time.Second, After: c.after}

	if _, err := r.RoundTrip(newPost(t)); err != nil {
		t.Fatal(err)
	}
	if len(c.waits) != 1 || c.waits[0] != 10*time.Second {
		t.Fatalf("waits: got %v, want [10s]", c.waits)
	}
}

func TestRetryingSendsAgainOnlyForTheStatusesThatRanNoTool(t *testing.T) {
	for status, wantCalls := range map[int]int{429: 2, 502: 2, 503: 2, 500: 1, 504: 1, 404: 1, 200: 1} {
		base := &script{statuses: []int{status, 200}}
		r := &Retrying{Base: base, After: (&clock{}).after}

		if _, err := r.RoundTrip(newPost(t)); err != nil {
			t.Fatal(err)
		}
		if len(base.bodies) != wantCalls {
			t.Fatalf("status %d: got %d calls, want %d", status, len(base.bodies), wantCalls)
		}
	}
}

func TestRetryingSendsAgainWhenNoConnectionOpens(t *testing.T) {
	base := &script{err: &net.OpError{Op: "dial", Err: errors.New("refused")}}
	c := &clock{}
	r := &Retrying{Base: base, Budget: 2 * time.Second, Backoff: []time.Duration{time.Second}, After: c.after}

	if _, err := r.RoundTrip(newPost(t)); err == nil {
		t.Fatal("want the dial error once the budget is spent")
	}
	if len(base.bodies) != 3 {
		t.Fatalf("calls: got %d, want 3", len(base.bodies))
	}
}

func TestRetryingStopsWhenTheRequestIsCancelled(t *testing.T) {
	base := &script{statuses: []int{429}}
	req := newPost(t)
	ctx, cancel := context.WithCancel(req.Context())
	cancel()
	r := &Retrying{Base: base, After: func(time.Duration) <-chan time.Time { return nil }}

	if _, err := r.RoundTrip(req.WithContext(ctx)); !errors.Is(err, ctx.Err()) {
		t.Fatalf("got %v, want the context error", err)
	}
}
