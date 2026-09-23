package mcpproxy

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/modelcontextprotocol/go-sdk/mcp"
	"github.com/ubermuda/loupe/cli/internal/api"
)

// agent is the local end of the pipe: what an MCP client would write and read.
type agent struct {
	writes io.WriteCloser
	reads  *lineReader
}

// newAgent builds the transport the proxy treats as its stdio, and the handles
// a test drives it through.
func newAgent() (*mcp.IOTransport, *agent) {
	toProxy, fromTest := io.Pipe()
	fromProxy, toTest := io.Pipe()

	return &mcp.IOTransport{Reader: toProxy, Writer: toTest}, &agent{writes: fromTest, reads: newLineReader(fromProxy)}
}

// lineReader reads newline-delimited JSON with a deadline, so a test fails
// rather than hangs when nothing arrives.
type lineReader struct {
	lines chan string
	body  io.Closer
}

func newLineReader(r io.ReadCloser) *lineReader {
	lr := &lineReader{lines: make(chan string, 8), body: r}
	go func() {
		defer close(lr.lines)
		dec := json.NewDecoder(r)
		for {
			var raw json.RawMessage
			if err := dec.Decode(&raw); err != nil {
				return
			}
			lr.lines <- string(raw)
		}
	}()

	return lr
}

func (lr *lineReader) next(t *testing.T) string {
	t.Helper()
	select {
	case line, ok := <-lr.lines:
		if !ok {
			t.Fatal("the proxy closed its output before sending a message")
		}

		return line
	case <-time.After(5 * time.Second):
		t.Fatal("no message arrived from the proxy within 5 seconds")

		return ""
	}
}

// loupe is a stand-in for the MCP endpoint. It answers every call with a result
// that carries the request id, so a test can match a reply to its request.
func loupe(t *testing.T, seen func(*http.Request)) *httptest.Server {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if seen != nil {
			seen(r)
		}
		body, _ := io.ReadAll(r.Body)
		var call struct {
			ID     json.RawMessage `json:"id"`
			Method string          `json:"method"`
		}
		_ = json.Unmarshal(body, &call)
		if len(call.ID) == 0 {
			w.WriteHeader(http.StatusAccepted)

			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Mcp-Session-Id", "11111111-1111-1111-1111-111111111111")
		_, _ = w.Write([]byte(`{"jsonrpc":"2.0","id":` + string(call.ID) + `,"result":{"echoed":"` + call.Method + `"}}`))
	}))
	t.Cleanup(server.Close)

	return server
}

// remote builds the HTTP end of the pipe against server.
func remote(server *httptest.Server, project string) Dial {
	hc := *server.Client()
	hc.Transport = &Credentials{Tokens: api.StaticToken("t0ken"), Project: fixed(project), Base: hc.Transport}

	return dial(server.URL, &hc)
}

// dial opens one session against endpoint, the way the mcp command does.
func dial(endpoint string, hc *http.Client) Dial {
	return func(ctx context.Context) (mcp.Connection, error) {
		return (&mcp.StreamableClientTransport{Endpoint: endpoint, HTTPClient: hc, DisableStandaloneSSE: true}).Connect(ctx)
	}
}

// run starts Run in the background and gives back its error once it returns.
func run(t *testing.T, ctx context.Context, local mcp.Transport, d Dial) <-chan error {
	t.Helper()
	errs := make(chan error, 1)
	notes := &bytes.Buffer{}
	go func() { errs <- Run(ctx, local, d, notes) }()

	return errs
}

func TestTheProxyCarriesACallToLoupeAndItsReplyBack(t *testing.T) {
	server := loupe(t, nil)
	local, client := newAgent()

	errs := run(t, context.Background(), local, remote(server, ""))

	if _, err := client.writes.Write([]byte(`{"jsonrpc":"2.0","id":7,"method":"tools/list"}` + "\n")); err != nil {
		t.Fatalf("write to the proxy: %v", err)
	}

	reply := client.reads.next(t)
	if !strings.Contains(reply, `"id":7`) || !strings.Contains(reply, "tools/list") {
		t.Fatalf("reply: got %s, want the id and method echoed back", reply)
	}

	client.writes.Close()
	if err := <-errs; err != nil {
		t.Fatalf("Run after the agent closed its input: %v", err)
	}
}

func TestTheProxyNeverNamesAnMcpMethodSoANewToolNeedsNoRelease(t *testing.T) {
	server := loupe(t, nil)
	local, client := newAgent()

	errs := run(t, context.Background(), local, remote(server, ""))

	// A method this CLI has never heard of must travel unchanged.
	if _, err := client.writes.Write([]byte(`{"jsonrpc":"2.0","id":1,"method":"loupe/invented_tomorrow"}` + "\n")); err != nil {
		t.Fatalf("write to the proxy: %v", err)
	}
	if reply := client.reads.next(t); !strings.Contains(reply, "loupe/invented_tomorrow") {
		t.Fatalf("reply: got %s, want the unknown method echoed back", reply)
	}

	client.writes.Close()
	<-errs
}

func TestTheProxySendsTheCredentialsAndTheProjectHeader(t *testing.T) {
	var mu sync.Mutex
	var auth, project string
	server := loupe(t, func(r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		auth, project = r.Header.Get("Authorization"), r.Header.Get(ProjectHeader)
	})
	local, client := newAgent()

	errs := run(t, context.Background(), local, remote(server, "01a0c0d9-905c-7922-a586-ccc8ce043704"))

	if _, err := client.writes.Write([]byte(`{"jsonrpc":"2.0","id":1,"method":"initialize"}` + "\n")); err != nil {
		t.Fatalf("write to the proxy: %v", err)
	}
	client.reads.next(t)

	mu.Lock()
	gotAuth, gotProject := auth, project
	mu.Unlock()
	if gotAuth != "Bearer t0ken" {
		t.Fatalf("Authorization: got %q, want %q", gotAuth, "Bearer t0ken")
	}
	if gotProject != "01a0c0d9-905c-7922-a586-ccc8ce043704" {
		t.Fatalf("%s: got %q, want the project from the file", ProjectHeader, gotProject)
	}

	client.writes.Close()
	<-errs
}

func TestTheProxySendsNoProjectHeaderWhenNoFileNamesOne(t *testing.T) {
	var mu sync.Mutex
	present := true
	server := loupe(t, func(r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		_, present = r.Header[http.CanonicalHeaderKey(ProjectHeader)]
	})
	local, client := newAgent()

	errs := run(t, context.Background(), local, remote(server, ""))

	if _, err := client.writes.Write([]byte(`{"jsonrpc":"2.0","id":1,"method":"initialize"}` + "\n")); err != nil {
		t.Fatalf("write to the proxy: %v", err)
	}
	client.reads.next(t)

	mu.Lock()
	sent := present
	mu.Unlock()
	if sent {
		t.Fatalf("%s must be absent so the server picks the single project of the grant", ProjectHeader)
	}

	client.writes.Close()
	<-errs
}

func TestTheProxyKeepsServingAfterLoupeForgetsTheSession(t *testing.T) {
	var mu sync.Mutex
	var issued int
	expired := map[string]bool{}
	var replays int

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		var call struct {
			ID     json.RawMessage `json:"id"`
			Method string          `json:"method"`
		}
		_ = json.Unmarshal(body, &call)

		session := r.Header.Get("Mcp-Session-Id")

		mu.Lock()
		gone := session != "" && expired[session]
		if call.Method == "initialize" {
			issued++
			if issued > 1 {
				replays++
			}
			session = fmt.Sprintf("%08d-0000-0000-0000-000000000000", issued)
		}
		mu.Unlock()

		if gone {
			w.WriteHeader(http.StatusNotFound)

			return
		}

		// The first tools/list expires the session it arrived on, which is
		// what an idle hour or a deploy does to Loupe's file session store.
		if call.Method == "tools/list" {
			mu.Lock()
			first := len(expired) == 0
			if first {
				expired[session] = true
			}
			mu.Unlock()
			if first {
				w.WriteHeader(http.StatusNotFound)

				return
			}
		}

		if len(call.ID) == 0 {
			w.Header().Set("Mcp-Session-Id", session)
			w.WriteHeader(http.StatusAccepted)

			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Mcp-Session-Id", session)
		_, _ = w.Write([]byte(`{"jsonrpc":"2.0","id":` + string(call.ID) + `,"result":{"echoed":"` + call.Method + `"}}`))
	}))
	t.Cleanup(server.Close)

	local, client := newAgent()
	errs := run(t, context.Background(), local, remote(server, ""))

	for _, message := range []string{
		`{"jsonrpc":"2.0","id":1,"method":"initialize"}`,
		`{"jsonrpc":"2.0","method":"notifications/initialized"}`,
	} {
		if _, err := client.writes.Write([]byte(message + "\n")); err != nil {
			t.Fatalf("write to the proxy: %v", err)
		}
	}
	if reply := client.reads.next(t); !strings.Contains(reply, `"id":1`) {
		t.Fatalf("handshake reply: got %s, want an answer for id 1", reply)
	}

	// This call finds the session gone. The agent must still get its answer.
	if _, err := client.writes.Write([]byte(`{"jsonrpc":"2.0","id":2,"method":"tools/list"}` + "\n")); err != nil {
		t.Fatalf("write to the proxy: %v", err)
	}
	if reply := client.reads.next(t); !strings.Contains(reply, `"id":2`) {
		t.Fatalf("reply after the session expired: got %s, want an answer for id 2", reply)
	}

	client.writes.Close()
	if err := <-errs; err != nil {
		t.Fatalf("Run after the agent closed its input: %v", err)
	}

	mu.Lock()
	defer mu.Unlock()
	if replays != 1 {
		t.Fatalf("replayed handshakes: got %d, want exactly 1", replays)
	}
}

func TestTheProxyForwardsNoSecondAnswerForTheReplayedHandshake(t *testing.T) {
	var mu sync.Mutex
	var issued int
	expired := map[string]bool{}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		var call struct {
			ID     json.RawMessage `json:"id"`
			Method string          `json:"method"`
		}
		_ = json.Unmarshal(body, &call)

		session := r.Header.Get("Mcp-Session-Id")
		mu.Lock()
		gone := session != "" && expired[session]
		if call.Method == "initialize" {
			issued++
			session = fmt.Sprintf("%08d-0000-0000-0000-000000000000", issued)
		}
		if call.Method == "tools/list" && len(expired) == 0 {
			expired[session] = true
			gone = true
		}
		mu.Unlock()

		if gone {
			w.WriteHeader(http.StatusNotFound)

			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Mcp-Session-Id", session)
		if len(call.ID) == 0 {
			w.WriteHeader(http.StatusAccepted)

			return
		}
		_, _ = w.Write([]byte(`{"jsonrpc":"2.0","id":` + string(call.ID) + `,"result":{"echoed":"` + call.Method + `"}}`))
	}))
	t.Cleanup(server.Close)

	local, client := newAgent()
	errs := run(t, context.Background(), local, remote(server, ""))

	if _, err := client.writes.Write([]byte(`{"jsonrpc":"2.0","id":1,"method":"initialize"}` + "\n")); err != nil {
		t.Fatalf("write to the proxy: %v", err)
	}
	client.reads.next(t)
	if _, err := client.writes.Write([]byte(`{"jsonrpc":"2.0","id":2,"method":"tools/list"}` + "\n")); err != nil {
		t.Fatalf("write to the proxy: %v", err)
	}

	// Exactly one more message, the answer for id 2. A forwarded answer for
	// the replayed initialize would arrive here instead and break the agent's
	// bookkeeping.
	if reply := client.reads.next(t); !strings.Contains(reply, `"id":2`) {
		t.Fatalf("next message: got %s, want the answer for id 2 and nothing before it", reply)
	}

	client.writes.Close()
	<-errs
}

func TestTheProxyReportsAnUnreachableLoupe(t *testing.T) {
	server := loupe(t, nil)
	endpoint := server.URL
	server.Close()

	local, client := newAgent()
	hc := &http.Client{Transport: &Credentials{Tokens: api.StaticToken("t0ken")}}
	errs := run(t, context.Background(), local, dial(endpoint, hc))

	if _, err := client.writes.Write([]byte(`{"jsonrpc":"2.0","id":1,"method":"initialize"}` + "\n")); err != nil {
		t.Fatalf("write to the proxy: %v", err)
	}

	select {
	case err := <-errs:
		if err == nil {
			t.Fatal("Run against a closed server: got no error")
		}
	case <-time.After(5 * time.Second):
		t.Fatal("Run did not report an unreachable server within 5 seconds")
	}
	client.writes.Close()
}
