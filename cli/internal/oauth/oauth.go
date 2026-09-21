// Package oauth signs the CLI in to Loupe with the device authorization grant
// (RFC 8628), and keeps the resulting access token fresh.
//
// No function here puts a token in an error or a log line. An error carries the
// server's error code and description, which name no token.
package oauth

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"

	"github.com/ubermuda/loupe/cli/internal/config"
)

// ClientID is the public client every Loupe instance registers for the CLI.
const ClientID = "loupe-cli"

// Scope is what the CLI asks for. `agent` reaches the bridge endpoints, `mcp`
// reaches the MCP endpoint that `loupe mcp` forwards to, and `projects` covers
// every project the person owns, so a new project needs no new login.
const Scope = "agent mcp projects"

// DeviceGrant is the grant type of a device token poll.
const DeviceGrant = "urn:ietf:params:oauth:grant-type:device_code"

// expirySkew refreshes a token this long before it expires, so a request does
// not leave with a token that dies on the way.
const expirySkew = time.Minute

// slowDownStep is what RFC 8628 adds to the interval at each slow_down.
const slowDownStep = 5 * time.Second

// maxBody caps a token endpoint answer.
const maxBody = 64 << 10

var (
	// ErrAccessDenied means the person chose Deny on the verification page.
	ErrAccessDenied = errors.New("the sign-in was denied in the browser")
	// ErrExpired means the code expired before anyone approved it.
	ErrExpired = errors.New("the code expired before it was approved: run `loupe login` again")
	// ErrLoginExpired means the refresh token no longer works: it expired, it
	// was revoked on the Connected apps page, or the account lost access.
	ErrLoginExpired = errors.New("your Loupe login has expired or was revoked: run `loupe login` again")
)

// DisplayUserCode shows eight letters as two groups of four, as the
// verification page does. The page reads a code without its dash.
func DisplayUserCode(code string) string {
	if len(code) != 8 {
		return code
	}

	return code[:4] + "-" + code[4:]
}

// Device is the answer of the device authorization endpoint.
type Device struct {
	DeviceCode              string `json:"device_code"`
	UserCode                string `json:"user_code"`
	VerificationURI         string `json:"verification_uri"`
	VerificationURIComplete string `json:"verification_uri_complete"`
	ExpiresIn               int    `json:"expires_in"`
	Interval                int    `json:"interval"`
}

// Flow talks to the authorization server of one Loupe instance. Sleep and Now
// exist so a test can run a ten-minute poll at once.
type Flow struct {
	BaseURL string
	HTTP    *http.Client
	Sleep   func(context.Context, time.Duration) error
	Now     func() time.Time
}

// NewFlow gives a Flow on the real clock.
func NewFlow(baseURL string, hc *http.Client) Flow {
	return Flow{BaseURL: strings.TrimRight(baseURL, "/"), HTTP: hc, Sleep: sleep, Now: time.Now}
}

func sleep(ctx context.Context, d time.Duration) error {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}

// Start asks for a device code and the user code a person types.
func (f Flow) Start(ctx context.Context) (Device, error) {
	var d Device
	status, body, err := f.post(ctx, "/oauth/device-authorization", url.Values{"client_id": {ClientID}, "scope": {Scope}})
	if err != nil {
		return d, fmt.Errorf("start the device sign-in: %w", err)
	}
	if status != http.StatusOK {
		return d, fmt.Errorf("start the device sign-in: %w", serverError(status, body))
	}
	if err := json.Unmarshal(body, &d); err != nil {
		return d, fmt.Errorf("start the device sign-in: decode the answer: %w", err)
	}
	if d.DeviceCode == "" || d.UserCode == "" || d.VerificationURI == "" {
		return d, errors.New("start the device sign-in: the answer has no device code, user code or verification URI")
	}

	return d, nil
}

// Poll asks the token endpoint for the tokens of d until a person approves or
// denies the code, or it expires. It waits the interval the server set before
// each poll, and five seconds more after each slow_down.
func (f Flow) Poll(ctx context.Context, d Device) (config.OAuthTokens, error) {
	interval := time.Duration(d.Interval) * time.Second
	if interval <= 0 {
		interval = 5 * time.Second
	}
	deadline := f.Now().Add(time.Duration(d.ExpiresIn) * time.Second)

	for {
		if f.Now().Add(interval).After(deadline) {
			return config.OAuthTokens{}, ErrExpired
		}
		if err := f.Sleep(ctx, interval); err != nil {
			return config.OAuthTokens{}, err
		}

		status, body, err := f.post(ctx, "/oauth/token", url.Values{"grant_type": {DeviceGrant}, "client_id": {ClientID}, "device_code": {d.DeviceCode}})
		if err != nil {
			return config.OAuthTokens{}, fmt.Errorf("poll for the tokens: %w", err)
		}
		if status == http.StatusOK {
			return f.tokens(body)
		}

		switch errorCode(body) {
		case "authorization_pending":
		case "slow_down":
			interval += slowDownStep
		case "access_denied":
			return config.OAuthTokens{}, ErrAccessDenied
		case "expired_token":
			return config.OAuthTokens{}, ErrExpired
		default:
			return config.OAuthTokens{}, fmt.Errorf("poll for the tokens: %w", serverError(status, body))
		}
	}
}

// Refresh spends refreshToken for a new access token and a new refresh token.
func (f Flow) Refresh(ctx context.Context, refreshToken string) (config.OAuthTokens, error) {
	status, body, err := f.post(ctx, "/oauth/token", url.Values{"grant_type": {"refresh_token"}, "client_id": {ClientID}, "refresh_token": {refreshToken}})
	if err != nil {
		return config.OAuthTokens{}, fmt.Errorf("refresh the Loupe login: %w", err)
	}
	if status == http.StatusOK {
		return f.tokens(body)
	}
	if errorCode(body) == "invalid_grant" {
		return config.OAuthTokens{}, ErrLoginExpired
	}

	return config.OAuthTokens{}, fmt.Errorf("refresh the Loupe login: %w", serverError(status, body))
}

func (f Flow) tokens(body []byte) (config.OAuthTokens, error) {
	var answer struct {
		AccessToken  string `json:"access_token"`
		RefreshToken string `json:"refresh_token"`
		ExpiresIn    int    `json:"expires_in"`
	}
	if err := json.Unmarshal(body, &answer); err != nil {
		return config.OAuthTokens{}, fmt.Errorf("decode the tokens: %w", err)
	}
	if answer.AccessToken == "" || answer.RefreshToken == "" || answer.ExpiresIn <= 0 {
		return config.OAuthTokens{}, errors.New("the token answer has no access token, refresh token or lifetime")
	}

	return config.OAuthTokens{
		AccessToken:  answer.AccessToken,
		RefreshToken: answer.RefreshToken,
		ExpiresAt:    f.Now().Add(time.Duration(answer.ExpiresIn) * time.Second),
	}, nil
}

func (f Flow) post(ctx context.Context, path string, form url.Values) (int, []byte, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, f.BaseURL+path, strings.NewReader(form.Encode()))
	if err != nil {
		return 0, nil, err
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.Header.Set("Accept", "application/json")

	resp, err := f.HTTP.Do(req)
	if err != nil {
		return 0, nil, err
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(io.LimitReader(resp.Body, maxBody))
	if err != nil {
		return 0, nil, err
	}

	return resp.StatusCode, body, nil
}

func errorCode(body []byte) string {
	var answer struct {
		Error string `json:"error"`
	}
	_ = json.Unmarshal(body, &answer)

	return answer.Error
}

// serverError names the OAuth error code and description, and nothing else of
// the body.
func serverError(status int, body []byte) error {
	var answer struct {
		Error       string `json:"error"`
		Description string `json:"error_description"`
	}
	if err := json.Unmarshal(body, &answer); err != nil || answer.Error == "" {
		return fmt.Errorf("HTTP %d", status)
	}
	if answer.Description == "" {
		return fmt.Errorf("HTTP %d: %s", status, answer.Error)
	}

	return fmt.Errorf("HTTP %d: %s: %s", status, answer.Error, answer.Description)
}

// Source is the api.TokenSource of a device login. Each refresh goes through
// config.RotateOAuth, so it is safe beside other Sources in this process and
// in other processes that share the config file.
type Source struct {
	baseURL string
	flow    Flow

	mu     sync.Mutex
	tokens config.OAuthTokens
}

// NewSource gives a Source that starts from tokens.
func NewSource(baseURL string, tokens config.OAuthTokens, hc *http.Client) *Source {
	if hc == nil {
		hc = http.DefaultClient
	}

	return &Source{baseURL: baseURL, flow: NewFlow(baseURL, hc), tokens: tokens}
}

// Token gives the current access token, refreshed first when it expires soon.
func (s *Source) Token(ctx context.Context) (string, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.tokens.ExpiresAt.After(s.flow.Now().Add(expirySkew)) {
		return s.tokens.AccessToken, nil
	}

	return s.rotate(ctx, s.tokens.AccessToken)
}

// Refresh gives a new access token after the server rejected rejected.
func (s *Source) Refresh(ctx context.Context, rejected string) (string, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.tokens.AccessToken != rejected && s.tokens.ExpiresAt.After(s.flow.Now().Add(expirySkew)) {
		return s.tokens.AccessToken, nil
	}

	return s.rotate(ctx, rejected)
}

func (s *Source) rotate(ctx context.Context, stale string) (string, error) {
	tokens, err := config.RotateOAuth(s.baseURL, stale, s.flow.Now().Add(expirySkew), func(refreshToken string) (config.OAuthTokens, error) {
		return s.flow.Refresh(ctx, refreshToken)
	})
	if errors.Is(err, config.ErrNotLoggedIn) {
		return "", ErrLoginExpired
	}
	if err != nil {
		return "", err
	}
	s.tokens = tokens

	return tokens.AccessToken, nil
}
