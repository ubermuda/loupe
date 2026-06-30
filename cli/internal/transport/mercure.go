// Package transport subscribes to a Mercure hub over Server-Sent Events and
// delivers each event's data payload to a handler. The connection is outbound,
// so it works from behind NAT with no inbound path. Dropped connections are
// retried with capped backoff (best-effort delivery — no replay of events
// missed while disconnected).
package transport

import (
	"bufio"
	"context"
	"fmt"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// Handler receives stream lifecycle and data callbacks. Any may be nil.
type Handler struct {
	OnConnect func()
	OnData    func([]byte)
	OnError   func(error)
}

const maxBackoff = 30 * time.Second

// Subscribe connects to hubURL for topic with the given subscriber JWT and runs
// until ctx is cancelled. It only returns a non-nil error for unrecoverable
// setup problems; transient stream failures are reported via Handler.OnError
// and retried.
func Subscribe(ctx context.Context, hc *http.Client, hubURL, topic, jwt string, h Handler) error {
	endpoint, err := url.Parse(hubURL)
	if err != nil {
		return fmt.Errorf("parse hub url: %w", err)
	}
	q := endpoint.Query()
	q.Set("topic", topic)
	endpoint.RawQuery = q.Encode()
	target := endpoint.String()

	backoff := time.Second
	for {
		if ctx.Err() != nil {
			return ctx.Err()
		}

		connected := false
		err := stream(ctx, hc, target, jwt, Handler{
			OnData: h.OnData,
			OnConnect: func() {
				connected = true
				if h.OnConnect != nil {
					h.OnConnect()
				}
			},
		})
		if ctx.Err() != nil {
			return ctx.Err()
		}
		if err != nil && h.OnError != nil {
			h.OnError(err)
		}
		if connected {
			backoff = time.Second // a real connection resets backoff
		}

		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(backoff):
		}
		if backoff < maxBackoff {
			backoff *= 2
		}
	}
}

func stream(ctx context.Context, hc *http.Client, target, jwt string, h Handler) error {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, target, nil)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+jwt)
	req.Header.Set("Accept", "text/event-stream")

	resp, err := hc.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("hub returned HTTP %d", resp.StatusCode)
	}
	if h.OnConnect != nil {
		h.OnConnect()
	}

	sc := bufio.NewScanner(resp.Body)
	sc.Buffer(make([]byte, 0, 64*1024), 1024*1024)

	var data strings.Builder
	for sc.Scan() {
		line := sc.Text()
		switch {
		case line == "": // event boundary
			if data.Len() > 0 {
				if h.OnData != nil {
					h.OnData([]byte(data.String()))
				}
				data.Reset()
			}
		case strings.HasPrefix(line, "data:"):
			if data.Len() > 0 {
				data.WriteByte('\n')
			}
			data.WriteString(strings.TrimPrefix(strings.TrimPrefix(line, "data:"), " "))
		}
	}

	return sc.Err()
}
