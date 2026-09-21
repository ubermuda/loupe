package cmd

import (
	"context"
	"net/http"

	"github.com/modelcontextprotocol/go-sdk/mcp"
	"github.com/ubermuda/loupe/cli/internal/mcpproxy"
)

// dialLoupe opens one MCP session against endpoint. A new transport per call,
// because a transport is built for a single connection and the shim opens
// another whenever the server forgets the session.
func dialLoupe(endpoint string, hc *http.Client) mcpproxy.Dial {
	return func(ctx context.Context) (mcp.Connection, error) {
		return (&mcp.StreamableClientTransport{
			Endpoint:   endpoint,
			HTTPClient: hc,
			// Loupe answers a GET on the endpoint with 405, so there is no
			// standalone event stream to listen on. Asking for one costs a
			// failed request per session.
			DisableStandaloneSSE: true,
		}).Connect(ctx)
	}
}
