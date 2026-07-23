// Package api talks to the Loupe HTTP API over the user's API token.
package api

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
)

// Site is one entry of GET /api/site-review/sites.
type Site struct {
	ID   string `json:"id"`
	Name string `json:"name"`
}

// StreamCredentials is the response of GET /api/site-review/stream: everything
// needed to subscribe to one site's review event stream.
type StreamCredentials struct {
	HubURL string `json:"hubUrl"`
	Topic  string `json:"topic"`
	JWT    string `json:"jwt"`
	Site   struct {
		ID   string `json:"id"`
		Name string `json:"name"`
	} `json:"site"`
}

// Client is a Loupe API client bound to one base URL and token.
type Client struct {
	baseURL string
	token   string
	http    *http.Client
}

// New builds a Client. A nil http.Client falls back to http.DefaultClient.
func New(baseURL, token string, hc *http.Client) *Client {
	if hc == nil {
		hc = http.DefaultClient
	}

	return &Client{baseURL: strings.TrimRight(baseURL, "/"), token: token, http: hc}
}

// StreamCredentials fetches subscribe credentials for one of the caller's sites,
// by id or name.
func (c *Client) StreamCredentials(ctx context.Context, site string) (StreamCredentials, error) {
	var creds StreamCredentials
	req, err := http.NewRequestWithContext(ctx, http.MethodGet,
		c.baseURL+"/api/site-review/stream?site="+url.QueryEscape(site), nil)
	if err != nil {
		return creds, err
	}
	req.Header.Set("Authorization", "Bearer "+c.token)
	req.Header.Set("Accept", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return creds, fmt.Errorf("request stream credentials: %w", err)
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusOK:
	case http.StatusUnauthorized, http.StatusForbidden:
		return creds, fmt.Errorf("credentials rejected (HTTP %d): the API token must have the site-review scope", resp.StatusCode)
	default:
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return creds, fmt.Errorf("stream credentials request failed (HTTP %d): %s", resp.StatusCode, strings.TrimSpace(string(body)))
	}

	if err := json.NewDecoder(resp.Body).Decode(&creds); err != nil {
		return creds, fmt.Errorf("decode stream credentials: %w", err)
	}

	return creds, nil
}

// Sites lists the authenticated user's sites (for the bridge's --site picker).
func (c *Client) Sites(ctx context.Context) ([]Site, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.baseURL+"/api/site-review/sites", nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+c.token)
	req.Header.Set("Accept", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return nil, fmt.Errorf("request sites: %w", err)
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusOK:
	case http.StatusUnauthorized, http.StatusForbidden:
		return nil, fmt.Errorf("sites request rejected (HTTP %d): the API token must have the site-review scope", resp.StatusCode)
	default:
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return nil, fmt.Errorf("sites request failed (HTTP %d): %s", resp.StatusCode, strings.TrimSpace(string(body)))
	}

	var payload struct {
		Sites []Site `json:"sites"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&payload); err != nil {
		return nil, fmt.Errorf("decode sites: %w", err)
	}

	return payload.Sites, nil
}
