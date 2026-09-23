package mcpproxy

import (
	"bytes"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"

	"github.com/ubermuda/loupe/cli/internal/api"
)

// ProjectHeader names the project a request acts on. The server refuses a
// project the credential does not cover, and falls back to the single project
// of a credential that covers one when the header is absent.
const ProjectHeader = "X-Loupe-Project"

// Credentials adds the bearer token and the project header to every request,
// and refreshes the token once when the server rejects it.
//
// It asks the token source per request rather than caching a token, because the
// source refreshes under a file lock that another loupe process may hold.
type Credentials struct {
	// Tokens gives the bearer token. It is required.
	Tokens api.TokenSource
	// Project gives the project header, read once per request. A nil function
	// or an empty value sends no header, which leaves the project to the server.
	Project func() string
	// Base sends the request. A nil value uses http.DefaultTransport.
	Base http.RoundTripper
}

// RoundTrip implements http.RoundTripper.
func (c *Credentials) RoundTrip(req *http.Request) (*http.Response, error) {
	token, err := c.Tokens.Token(req.Context())
	if err != nil {
		return nil, err
	}

	rewind, err := bodyRewinder(req)
	if err != nil {
		return nil, err
	}

	project := ""
	if c.Project != nil {
		project = c.Project()
	}

	resp, err := c.send(req, token, project, rewind)
	if err != nil || resp.StatusCode != http.StatusUnauthorized {
		resp, err = markSessionGone(req, resp, err)

		return explain(project, resp, err)
	}

	fresh, err := c.Tokens.Refresh(req.Context(), token)
	if errors.Is(err, api.ErrNoRefresh) {
		return explain(project, resp, nil)
	}
	resp.Body.Close()
	if err != nil {
		return nil, err
	}

	resp, err = c.send(req, fresh, project, rewind)
	resp, err = markSessionGone(req, resp, err)

	return explain(project, resp, err)
}

// sessionHeader names the MCP session a request belongs to.
const sessionHeader = "Mcp-Session-Id"

// markSessionGone drops the body of a 404 that answers a request carrying a
// session id, because a 404 on that request is how the MCP transport says the
// session has ended.
//
// The Go SDK decodes an error body first and reports it as a rejection of that
// one call, which hides the ended session behind an error the caller cannot
// classify. Loupe sends such a body, so without this the shim sees a failed
// call rather than a session to reopen. Dropping the body leaves the status
// code, which is the signal the SDK reads next.
func markSessionGone(req *http.Request, resp *http.Response, err error) (*http.Response, error) {
	if err != nil || resp == nil || resp.StatusCode != http.StatusNotFound {
		return resp, err
	}
	if req.Header.Get(sessionHeader) == "" {
		return resp, err
	}

	resp.Body.Close()
	resp.Body = http.NoBody
	resp.ContentLength = 0

	return resp, nil
}

// send sends one copy of req with the given token and project. The original is
// left alone, so the caller can send it again.
func (c *Credentials) send(req *http.Request, token, project string, rewind func() (io.ReadCloser, error)) (*http.Response, error) {
	attempt := req.Clone(req.Context())
	if rewind != nil {
		body, err := rewind()
		if err != nil {
			return nil, err
		}
		attempt.Body = body
	}
	attempt.Header.Set("Authorization", "Bearer "+token)
	if project != "" {
		attempt.Header.Set(ProjectHeader, project)
	}

	base := c.Base
	if base == nil {
		base = http.DefaultTransport
	}

	return base.RoundTrip(attempt)
}

// explain turns a refusal into a message that says what to do about it. The
// MCP layer otherwise reports the status code alone, which tells a person
// nothing about a scope or a project. It names the project the request sent.
func explain(project string, resp *http.Response, err error) (*http.Response, error) {
	if err != nil || resp == nil {
		return resp, err
	}
	if resp.StatusCode != http.StatusUnauthorized && resp.StatusCode != http.StatusForbidden {
		return resp, nil
	}

	detail, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
	resp.Body.Close()

	advice := "run `loupe login` again"
	if resp.StatusCode == http.StatusForbidden {
		advice = "the login must cover this project and carry the mcp scope"
		if project != "" {
			advice = fmt.Sprintf("the login does not cover the project %s that %s names, or it carries no mcp scope", project, ".loupe.yaml")
		}
	}

	return nil, fmt.Errorf("Loupe refused the credentials (HTTP %d): %s. %s", resp.StatusCode, summarise(detail), advice)
}

// summarise turns a refusal body into one short line.
func summarise(body []byte) string {
	line := strings.TrimSpace(string(body))
	if line == "" {
		return "the server sent no reason"
	}
	if i := strings.IndexAny(line, "\r\n"); i >= 0 {
		line = line[:i]
	}

	return line
}

// bodyRewinder gives a fresh copy of req's body for each attempt, or nil for a
// request that carries none. It buffers the body only when the request has no
// GetBody of its own.
func bodyRewinder(req *http.Request) (func() (io.ReadCloser, error), error) {
	if req.Body == nil || req.Body == http.NoBody {
		return nil, nil
	}
	if req.GetBody != nil {
		return req.GetBody, nil
	}

	buf, err := io.ReadAll(req.Body)
	req.Body.Close()
	if err != nil {
		return nil, fmt.Errorf("read request body: %w", err)
	}

	return func() (io.ReadCloser, error) {
		return io.NopCloser(bytes.NewReader(buf)), nil
	}, nil
}
