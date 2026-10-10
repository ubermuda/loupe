package mcpproxy

import (
	"errors"
	"net"
	"net/http"
	"strconv"
	"time"
)

// ErrCredentialsRefused marks a 401 that a refresh cannot fix, or a 403. It is
// the one refusal that ends the process, because no wait or new session makes
// the next call different.
var ErrCredentialsRefused = errors.New("Loupe refused the credentials")

// defaultBackoff doubles from one second to a cap of thirty. retryBudget, not
// this list, limits how long a call waits.
var defaultBackoff = []time.Duration{
	1 * time.Second, 2 * time.Second, 4 * time.Second, 8 * time.Second,
	16 * time.Second, 30 * time.Second, 30 * time.Second, 30 * time.Second,
}

// retryBudget is the total wait before Retrying gives the last answer back. It
// cuts the last step of defaultBackoff, whose sum is 121 seconds, by one second.
const retryBudget = 2 * time.Minute

// Retrying sends a request again when Loupe answers 429, 502 or 503, or when no
// connection to Loupe opens. It waits for the next backoff step, or for
// Retry-After when that is longer, so a Retry-After of zero still uses up the
// budget.
//
// Those are the answers that arrive before a tool ran: the rate limiter and
// the load balancer both refuse a request before the application sees it. A
// 500 or 504 can come after the tool ran, so a retry could repeat a write, and
// those go back to the caller at once.
type Retrying struct {
	// Base sends the request. A nil value uses http.DefaultTransport.
	Base http.RoundTripper
	// Backoff lists the waits for answers that carry no Retry-After. The last
	// step repeats. A nil slice uses defaultBackoff.
	Backoff []time.Duration
	// Budget caps the total wait. Zero uses two minutes.
	Budget time.Duration
	// After returns a channel that fires after d. A nil function uses time.After.
	After func(time.Duration) <-chan time.Time
}

// RoundTrip implements http.RoundTripper. When the budget runs out it returns
// the last answer, so the caller reports the status that Loupe kept sending.
func (r *Retrying) RoundTrip(req *http.Request) (*http.Response, error) {
	rewind, err := bodyRewinder(req)
	if err != nil {
		return nil, err
	}

	base := r.Base
	if base == nil {
		base = http.DefaultTransport
	}
	steps := r.Backoff
	if len(steps) == 0 {
		steps = defaultBackoff
	}
	remaining := r.Budget
	if remaining == 0 {
		remaining = retryBudget
	}
	after := r.After
	if after == nil {
		after = time.After
	}

	for attempt := 0; ; attempt++ {
		sent := req
		if rewind != nil {
			body, err := rewind()
			if err != nil {
				return nil, err
			}
			sent = req.Clone(req.Context())
			sent.Body = body
		}

		resp, err := base.RoundTrip(sent)
		if !retryable(resp, err) || remaining <= 0 {
			return resp, err
		}

		wait := steps[min(attempt, len(steps)-1)]
		if resp != nil {
			if asked, ok := retryAfter(resp); ok {
				wait = max(wait, asked)
			}
			resp.Body.Close()
		}
		wait = min(wait, remaining)
		remaining -= wait

		select {
		case <-after(wait):
		case <-req.Context().Done():
			return nil, req.Context().Err()
		}
	}
}

// retryable reports whether an answer or an error means Loupe did no work.
func retryable(resp *http.Response, err error) bool {
	if err != nil {
		var op *net.OpError

		return errors.As(err, &op) && op.Op == "dial"
	}

	switch resp.StatusCode {
	case http.StatusTooManyRequests, http.StatusBadGateway, http.StatusServiceUnavailable:
		return true
	}

	return false
}

// retryAfter reads the Retry-After header, either as seconds or as a date.
func retryAfter(resp *http.Response) (time.Duration, bool) {
	value := resp.Header.Get("Retry-After")
	if value == "" {
		return 0, false
	}
	if seconds, err := strconv.Atoi(value); err == nil && seconds >= 0 {
		return time.Duration(seconds) * time.Second, true
	}
	if date, err := http.ParseTime(value); err == nil {
		return max(time.Until(date), 0), true
	}

	return 0, false
}
