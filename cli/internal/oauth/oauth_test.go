package oauth

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/zalando/go-keyring"
)

// fakeClock moves only when the flow sleeps, so a test of a ten-minute poll
// runs at once and can read every wait the flow asked for.
type fakeClock struct {
	mu     sync.Mutex
	now    time.Time
	sleeps []time.Duration
}

func newFakeClock() *fakeClock { return &fakeClock{now: time.Date(2026, 9, 19, 12, 0, 0, 0, time.UTC)} }

func (c *fakeClock) Now() time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()

	return c.now
}

func (c *fakeClock) Sleep(_ context.Context, d time.Duration) error {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.sleeps = append(c.sleeps, d)
	c.now = c.now.Add(d)

	return nil
}

func (c *fakeClock) flow(server *httptest.Server) Flow {
	return Flow{BaseURL: server.URL, HTTP: server.Client(), Sleep: c.Sleep, Now: c.Now}
}

// deviceServer answers the device authorization request, then answers each
// token poll with the next entry of polls.
func deviceServer(t *testing.T, polls ...string) (*httptest.Server, *[]string) {
	t.Helper()
	var mu sync.Mutex
	var seen []string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if err := r.ParseForm(); err != nil {
			t.Errorf("parse form: %v", err)
		}
		w.Header().Set("Content-Type", "application/json")
		switch r.URL.Path {
		case "/oauth/device-authorization":
			if r.PostForm.Get("client_id") != ClientID || r.PostForm.Get("scope") != Scope {
				t.Errorf("device authorization form = %v", r.PostForm)
			}
			fmt.Fprint(w, `{"device_code":"dev-1","user_code":"BCDFGHJK","verification_uri":"https://loupe.test/oauth/device","verification_uri_complete":"https://loupe.test/oauth/device?user_code=BCDFGHJK","expires_in":600,"interval":5}`)
		case "/oauth/token":
			mu.Lock()
			defer mu.Unlock()
			if r.PostForm.Get("grant_type") != DeviceGrant || r.PostForm.Get("device_code") != "dev-1" || r.PostForm.Get("client_id") != ClientID {
				t.Errorf("token form = %v", r.PostForm)
			}
			answer := "pending"
			if len(seen) < len(polls) {
				answer = polls[len(seen)]
			}
			seen = append(seen, answer)
			switch answer {
			case "tokens":
				fmt.Fprint(w, `{"token_type":"Bearer","access_token":"eyJ.access","refresh_token":"refresh-1","expires_in":3600}`)
			case "pending":
				w.WriteHeader(http.StatusBadRequest)
				fmt.Fprint(w, `{"error":"authorization_pending"}`)
			default:
				w.WriteHeader(http.StatusBadRequest)
				fmt.Fprintf(w, `{"error":%q}`, answer)
			}
		default:
			t.Errorf("unexpected path %s", r.URL.Path)
		}
	}))
	t.Cleanup(server.Close)

	return server, &seen
}

func TestStartReadsTheDeviceAuthorization(t *testing.T) {
	server, _ := deviceServer(t)

	got, err := newFakeClock().flow(server).Start(context.Background())
	if err != nil {
		t.Fatalf("Start: %v", err)
	}
	if got.DeviceCode != "dev-1" || got.UserCode != "BCDFGHJK" || got.VerificationURIComplete != "https://loupe.test/oauth/device?user_code=BCDFGHJK" || got.Interval != 5 || got.ExpiresIn != 600 {
		t.Fatalf("Start gives %+v", got)
	}
}

func TestStartReportsAServerThatRefuses(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusBadRequest)
		fmt.Fprint(w, `{"error":"unauthorized_client","error_description":"The authenticated client is not authorized to use this authorization grant type."}`)
	}))
	t.Cleanup(server.Close)

	_, err := newFakeClock().flow(server).Start(context.Background())
	if err == nil || !strings.Contains(err.Error(), "unauthorized_client") {
		t.Fatalf("Start error = %v, want the server's error code", err)
	}
}

func TestPollWaitsTheIntervalAndSlowsDownWhenAsked(t *testing.T) {
	server, seen := deviceServer(t, "pending", "slow_down", "pending", "tokens")
	clock := newFakeClock()
	flow := clock.flow(server)
	device, err := flow.Start(context.Background())
	if err != nil {
		t.Fatalf("Start: %v", err)
	}

	tokens, err := flow.Poll(context.Background(), device)
	if err != nil {
		t.Fatalf("Poll: %v", err)
	}
	if tokens.AccessToken != "eyJ.access" || tokens.RefreshToken != "refresh-1" {
		t.Fatalf("Poll gives %+v", tokens)
	}
	if want := clock.Now().Add(time.Hour); !tokens.ExpiresAt.Equal(want) {
		t.Fatalf("ExpiresAt = %v, want %v", tokens.ExpiresAt, want)
	}
	want := []time.Duration{5 * time.Second, 5 * time.Second, 10 * time.Second, 10 * time.Second}
	if fmt.Sprint(clock.sleeps) != fmt.Sprint(want) {
		t.Fatalf("waits = %v, want %v", clock.sleeps, want)
	}
	if len(*seen) != 4 {
		t.Fatalf("polls = %v", *seen)
	}
}

func TestPollStopsOnADenial(t *testing.T) {
	server, _ := deviceServer(t, "pending", "access_denied")
	flow := newFakeClock().flow(server)
	device, _ := flow.Start(context.Background())

	if _, err := flow.Poll(context.Background(), device); !errors.Is(err, ErrAccessDenied) {
		t.Fatalf("Poll error = %v, want ErrAccessDenied", err)
	}
}

func TestPollStopsWhenTheServerSaysTheCodeExpired(t *testing.T) {
	server, _ := deviceServer(t, "expired_token")
	flow := newFakeClock().flow(server)
	device, _ := flow.Start(context.Background())

	if _, err := flow.Poll(context.Background(), device); !errors.Is(err, ErrExpired) {
		t.Fatalf("Poll error = %v, want ErrExpired", err)
	}
}

func TestPollStopsAtTheDeadlineOfTheCode(t *testing.T) {
	server, seen := deviceServer(t)
	clock := newFakeClock()
	flow := clock.flow(server)
	device, _ := flow.Start(context.Background())
	device.ExpiresIn = 12

	if _, err := flow.Poll(context.Background(), device); !errors.Is(err, ErrExpired) {
		t.Fatalf("Poll error = %v, want ErrExpired", err)
	}
	if len(*seen) != 2 {
		t.Fatalf("polls = %d, want 2 inside 12 seconds at a 5-second interval", len(*seen))
	}
}

// refreshServer answers refresh_token grants with refresh, and counts them.
func refreshServer(t *testing.T, answer string) (*httptest.Server, *int) {
	t.Helper()
	calls := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = r.ParseForm()
		if r.URL.Path != "/oauth/token" || r.PostForm.Get("grant_type") != "refresh_token" || r.PostForm.Get("client_id") != ClientID {
			t.Errorf("refresh request %s %v", r.URL.Path, r.PostForm)
		}
		calls++
		w.Header().Set("Content-Type", "application/json")
		if answer != "" {
			w.WriteHeader(http.StatusBadRequest)
			fmt.Fprintf(w, `{"error":%q,"error_description":"The refresh token is invalid."}`, answer)

			return
		}
		fmt.Fprintf(w, `{"access_token":"access-%d","refresh_token":"refresh-%d","expires_in":3600}`, calls+1, calls+1)
	}))
	t.Cleanup(server.Close)

	return server, &calls
}

func storeLogin(t *testing.T, baseURL string, expiresAt time.Time) config.OAuthTokens {
	t.Helper()
	keyring.MockInit()
	dir := t.TempDir()
	t.Setenv("XDG_CONFIG_HOME", dir)
	t.Setenv("HOME", dir)
	tokens := config.OAuthTokens{AccessToken: "access-1", RefreshToken: "refresh-1", ExpiresAt: expiresAt}
	if err := config.Save(config.Config{BaseURL: baseURL, OAuth: &tokens}); err != nil {
		t.Fatalf("Save: %v", err)
	}

	return tokens
}

func TestSourceRefreshesBeforeTheTokenExpires(t *testing.T) {
	server, calls := refreshServer(t, "")
	source := NewSource(server.URL, storeLogin(t, server.URL, time.Now().Add(30*time.Second)), server.Client())

	got, err := source.Token(context.Background())
	if err != nil {
		t.Fatalf("Token: %v", err)
	}
	if got != "access-2" || *calls != 1 {
		t.Fatalf("Token = %q after %d refreshes, want access-2 after 1", got, *calls)
	}
	if again, _ := source.Token(context.Background()); again != "access-2" || *calls != 1 {
		t.Fatalf("second Token = %q after %d refreshes, want no new refresh", again, *calls)
	}
	stored, err := config.Load()
	if err != nil || stored.OAuth.RefreshToken != "refresh-2" {
		t.Fatalf("stored %+v, %v", stored.OAuth, err)
	}
}

func TestSourceKeepsAValidToken(t *testing.T) {
	server, calls := refreshServer(t, "")
	source := NewSource(server.URL, storeLogin(t, server.URL, time.Now().Add(time.Hour)), server.Client())

	if got, _ := source.Token(context.Background()); got != "access-1" || *calls != 0 {
		t.Fatalf("Token = %q after %d refreshes", got, *calls)
	}
}

func TestSourceRefreshesARejectedTokenOnce(t *testing.T) {
	server, calls := refreshServer(t, "")
	source := NewSource(server.URL, storeLogin(t, server.URL, time.Now().Add(time.Hour)), server.Client())

	got, err := source.Refresh(context.Background(), "access-1")
	if err != nil || got != "access-2" {
		t.Fatalf("Refresh = %q, %v", got, err)
	}
	// A second caller that was rejected with the same old token gets the new
	// one without a second refresh, which would spend a refresh token twice.
	if again, err := source.Refresh(context.Background(), "access-1"); err != nil || again != "access-2" || *calls != 1 {
		t.Fatalf("second Refresh = %q, %v after %d refreshes", again, err, *calls)
	}
}

func TestSourceAsksForANewLoginOnInvalidGrant(t *testing.T) {
	server, _ := refreshServer(t, "invalid_grant")
	source := NewSource(server.URL, storeLogin(t, server.URL, time.Now().Add(time.Hour)), server.Client())

	_, err := source.Refresh(context.Background(), "access-1")
	if !errors.Is(err, ErrLoginExpired) {
		t.Fatalf("Refresh error = %v, want ErrLoginExpired", err)
	}
	if !strings.Contains(err.Error(), "loupe login") {
		t.Fatalf("error %q does not tell the user to run loupe login", err)
	}
}

func TestSourceNeverPutsATokenInAnError(t *testing.T) {
	server, _ := refreshServer(t, "server_error")
	source := NewSource(server.URL, storeLogin(t, server.URL, time.Now().Add(time.Hour)), server.Client())

	_, err := source.Refresh(context.Background(), "access-1")
	if err == nil {
		t.Fatal("Refresh succeeded against a failing server")
	}
	for _, secret := range []string{"access-1", "refresh-1"} {
		if strings.Contains(err.Error(), secret) {
			t.Fatalf("error %q carries a token", err)
		}
	}
}
