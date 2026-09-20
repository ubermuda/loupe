package mcpproxy

import (
	"context"
	"errors"
	"fmt"
	"io"
	"sync"
	"time"

	"github.com/modelcontextprotocol/go-sdk/jsonrpc"
	"github.com/modelcontextprotocol/go-sdk/mcp"
)

// Loupe keeps an MCP session in a file store that expires after an hour and
// lives in one web container's cache directory. A session therefore ends on an
// idle agent, on a deploy, and on a request that lands on another container.
// The agent cannot recover from that by itself: its tools disappear for the
// rest of its run. So the shim opens a new session and keeps serving.
//
// These are the only two method names anywhere in the shim. Everything else
// travels unread, which is what lets a new Loupe tool arrive with no release of
// this CLI.
const (
	methodInitialize  = "initialize"
	methodInitialized = "notifications/initialized"
)

// handshakeTimeout bounds the replayed handshake. A new session that does not
// answer must fail rather than hold the agent for ever.
const handshakeTimeout = 30 * time.Second

// renewing is an mcp.Connection that opens a new session when the server
// forgets the one it holds, and replays the handshake onto it.
type renewing struct {
	// dial opens a new connection to the same endpoint.
	dial func(context.Context) (mcp.Connection, error)
	// notes receives one line per renewal. Nil writes nothing.
	notes io.Writer

	mu sync.Mutex
	// current is the connection both directions use until one of them renews it.
	current mcp.Connection
	// initialize is the agent's own handshake, kept so a new session can be
	// brought to the same point. Nil until the agent sends one.
	initialize *jsonrpc.Request
	// initialized is the notification that follows it, if the agent sent one.
	initialized *jsonrpc.Request
	closed      bool
}

// newRenewing wraps an already-connected mcp.Connection. Each renewal is
// reported on notes, which an agent shows as this server's log.
func newRenewing(conn mcp.Connection, dial func(context.Context) (mcp.Connection, error), notes io.Writer) *renewing {
	return &renewing{dial: dial, current: conn, notes: notes}
}

// Read reads the next message, opening a new session first when the server has
// forgotten the current one.
func (r *renewing) Read(ctx context.Context) (jsonrpc.Message, error) {
	for {
		conn := r.conn()
		msg, err := conn.Read(ctx)
		if !errors.Is(err, mcp.ErrSessionMissing) {
			return msg, err
		}
		if _, rerr := r.renew(ctx, conn); rerr != nil {
			return nil, rerr
		}
	}
}

// Write sends msg, and sends it again on a new session when the server has
// forgotten the current one. It retries once: a fresh session that is also
// refused is a real failure.
func (r *renewing) Write(ctx context.Context, msg jsonrpc.Message) error {
	r.record(msg)

	conn := r.conn()
	err := conn.Write(ctx, msg)
	if !errors.Is(err, mcp.ErrSessionMissing) {
		return err
	}

	fresh, rerr := r.renew(ctx, conn)
	if rerr != nil {
		return rerr
	}

	return fresh.Write(ctx, msg)
}

// Close closes the connection in use.
func (r *renewing) Close() error {
	r.mu.Lock()
	r.closed = true
	conn := r.current
	r.mu.Unlock()

	return conn.Close()
}

// SessionID reports the session the server gave the connection in use.
func (r *renewing) SessionID() string { return r.conn().SessionID() }

// conn gives the connection in use.
func (r *renewing) conn() mcp.Connection {
	r.mu.Lock()
	defer r.mu.Unlock()

	return r.current
}

// record keeps the handshake a new session has to replay. Only the first
// initialize is kept, because that is the one the agent's session is built on.
func (r *renewing) record(msg jsonrpc.Message) {
	req, ok := msg.(*jsonrpc.Request)
	if !ok {
		return
	}

	r.mu.Lock()
	defer r.mu.Unlock()
	switch {
	case req.Method == methodInitialize && r.initialize == nil:
		r.initialize = req
	case req.Method == methodInitialized:
		r.initialized = req
	}
}

// renew replaces stale with a new session and brings it to the same point.
//
// Both directions may notice the lost session at once, so a caller passes the
// connection it found broken. A caller whose connection is no longer the
// current one gets the replacement the other direction already made.
func (r *renewing) renew(ctx context.Context, stale mcp.Connection) (mcp.Connection, error) {
	r.mu.Lock()
	defer r.mu.Unlock()

	if r.current != stale {
		return r.current, nil
	}
	if r.closed {
		return nil, mcp.ErrSessionMissing
	}
	if r.initialize == nil {
		// Nothing to replay, so a new session would be no better than this one.
		return nil, fmt.Errorf("Loupe forgot the session before the agent finished its handshake: %w", mcp.ErrSessionMissing)
	}

	fresh, err := r.dial(ctx)
	if err != nil {
		return nil, fmt.Errorf("open a new Loupe session: %w", err)
	}

	if err := r.handshake(ctx, fresh); err != nil {
		fresh.Close()

		return nil, err
	}

	stale.Close()
	r.current = fresh
	if r.notes != nil {
		fmt.Fprintf(r.notes, "loupe mcp: Loupe ended session %s, opened %s and carried on\n", stale.SessionID(), fresh.SessionID())
	}

	return fresh, nil
}

// handshake replays the agent's handshake onto conn and drops the answer. The
// agent already has an answer for that request id, so forwarding a second one
// would break its bookkeeping.
func (r *renewing) handshake(ctx context.Context, conn mcp.Connection) error {
	ctx, cancel := context.WithTimeout(ctx, handshakeTimeout)
	defer cancel()

	if err := conn.Write(ctx, r.initialize); err != nil {
		return fmt.Errorf("replay the handshake on the new Loupe session: %w", err)
	}
	if _, err := conn.Read(ctx); err != nil {
		return fmt.Errorf("read the new Loupe session's handshake answer: %w", err)
	}
	if r.initialized == nil {
		return nil
	}
	if err := conn.Write(ctx, r.initialized); err != nil {
		return fmt.Errorf("replay the handshake on the new Loupe session: %w", err)
	}

	return nil
}
