// Package mcpproxy pipes MCP messages between a local agent and a Loupe
// instance.
//
// The pipe copies JSON-RPC messages and reads no method name except the two the
// handshake replay needs, so a tool Loupe adds reaches every agent with no new
// release of this CLI. Nothing here builds an MCP client or an MCP server.
package mcpproxy

import (
	"context"
	"errors"
	"fmt"
	"io"

	"github.com/modelcontextprotocol/go-sdk/mcp"
)

// Dial opens one connection to a Loupe instance. Run calls it again whenever
// the server forgets the session, so it must build a fresh connection each
// time rather than hand back the same one.
type Dial func(context.Context) (mcp.Connection, error)

// Run copies messages between the local agent and Loupe until one end finishes.
//
// It runs one goroutine per direction, because each connection allows one
// reader at a time and a message may travel either way at any moment. The
// first end of input or error stops the other direction, so Run returns once,
// with the reason the pipe stopped.
// notes receives one line whenever the shim reopens a session. It is the
// agent's view of this server's log, so it must never be stdout.
func Run(ctx context.Context, local mcp.Transport, dial Dial, notes io.Writer) error {
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()

	localConn, err := local.Connect(ctx)
	if err != nil {
		return fmt.Errorf("connect to the agent: %w", err)
	}
	defer localConn.Close()

	first, err := dial(ctx)
	if err != nil {
		return fmt.Errorf("connect to Loupe: %w", err)
	}
	remoteConn := newRenewing(first, dial, notes)
	defer remoteConn.Close()

	// Buffered for both directions, so the goroutine that loses the race still
	// finishes rather than blocking on a send nobody reads.
	done := make(chan error, 2)
	go func() { done <- copyMessages(ctx, localConn, remoteConn) }()
	go func() { done <- copyMessages(ctx, remoteConn, localConn) }()

	err = <-done
	cancel()
	// Closing both unblocks the reader of the other direction, which the
	// Connection contract allows concurrently with Read.
	localConn.Close()
	remoteConn.Close()
	<-done

	return err
}

// copyMessages reads from src and writes to dst until either one ends.
//
// An end of input is how an agent says it is finished, so it is not an error.
// A cancelled context is the other direction stopping first, and it is not an
// error either: the direction that stopped first reports the reason.
func copyMessages(ctx context.Context, src, dst mcp.Connection) error {
	for {
		msg, err := src.Read(ctx)
		if err != nil {
			if isEnd(ctx, err) {
				return nil
			}

			return fmt.Errorf("read: %w", err)
		}
		if err := dst.Write(ctx, msg); err != nil {
			if isEnd(ctx, err) {
				return nil
			}

			return fmt.Errorf("write: %w", err)
		}
	}
}

// isEnd reports whether err means the pipe closed rather than failed.
func isEnd(ctx context.Context, err error) bool {
	return errors.Is(err, io.EOF) || errors.Is(err, context.Canceled) || ctx.Err() != nil
}
