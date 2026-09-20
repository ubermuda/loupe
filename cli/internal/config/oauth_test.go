package config

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"os/exec"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/zalando/go-keyring"
)

const oauthBase = "https://example.test"

func oauthLogin(access, refresh string, expiresAt time.Time) Config {
	return Config{BaseURL: oauthBase, OAuth: &OAuthTokens{AccessToken: access, RefreshToken: refresh, ExpiresAt: expiresAt}}
}

func TestSaveKeepsAnOAuthLoginInTheFileAt0600(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)
	if err := keyring.Set(keyringService, oauthBase, "old-static"); err != nil {
		t.Fatalf("seed keychain: %v", err)
	}

	expires := time.Now().Add(time.Hour).UTC().Truncate(time.Second)
	if err := Save(oauthLogin("access-1", "refresh-1", expires)); err != nil {
		t.Fatalf("Save: %v", err)
	}

	got, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if got.OAuth == nil || got.OAuth.AccessToken != "access-1" || got.OAuth.RefreshToken != "refresh-1" || !got.OAuth.ExpiresAt.Equal(expires) {
		t.Fatalf("Load gives %+v, want the OAuth login", got.OAuth)
	}
	if got.Token != "" {
		t.Fatalf("Load gives static token %q beside the OAuth login", got.Token)
	}
	if _, err := keyring.Get(keyringService, oauthBase); !errors.Is(err, keyring.ErrNotFound) {
		t.Fatalf("keychain still holds the static token (err %v): a device login must replace it", err)
	}

	info, err := os.Stat(storedConfigPath(t))
	if err != nil {
		t.Fatalf("stat config: %v", err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Fatalf("config mode = %v, want 0600", info.Mode().Perm())
	}
}

func TestSaveOfAStaticTokenEndsAnOAuthLogin(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)

	if err := Save(oauthLogin("access-1", "refresh-1", time.Now().Add(time.Hour))); err != nil {
		t.Fatalf("Save OAuth: %v", err)
	}
	if err := Save(Config{BaseURL: oauthBase, Token: "sk-static"}); err != nil {
		t.Fatalf("Save static: %v", err)
	}

	got, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if got.OAuth != nil || got.Token != "sk-static" {
		t.Fatalf("Load gives %+v, want the static token alone", got)
	}
	b, err := os.ReadFile(storedConfigPath(t))
	if err != nil {
		t.Fatalf("read config: %v", err)
	}
	if strings.Contains(string(b), "refresh-1") {
		t.Fatalf("config still holds the old refresh token: %s", b)
	}
}

func TestRotateAdoptsATokenAnotherProcessStored(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)
	if err := Save(oauthLogin("access-2", "refresh-2", time.Now().Add(time.Hour))); err != nil {
		t.Fatalf("Save: %v", err)
	}

	got, err := RotateOAuth(oauthBase, "access-1", time.Now().Add(time.Minute), func(string) (OAuthTokens, error) {
		t.Fatal("refresh called, but another process had already rotated the tokens")

		return OAuthTokens{}, nil
	})
	if err != nil {
		t.Fatalf("RotateOAuth: %v", err)
	}
	if got.AccessToken != "access-2" {
		t.Fatalf("RotateOAuth gives %q, want the stored access-2", got.AccessToken)
	}
}

func TestRotateRefreshesAStaleTokenAndKeepsTheBridgeID(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)
	login := oauthLogin("access-1", "refresh-1", time.Now().Add(time.Hour))
	login.BridgeID = "0192f3a1-4b2c-4d3e-8f10-a2b3c4d5e6f7"
	if err := Save(login); err != nil {
		t.Fatalf("Save: %v", err)
	}

	var sent string
	got, err := RotateOAuth(oauthBase, "access-1", time.Now().Add(time.Minute), func(refresh string) (OAuthTokens, error) {
		sent = refresh

		return OAuthTokens{AccessToken: "access-2", RefreshToken: "refresh-2", ExpiresAt: time.Now().Add(time.Hour)}, nil
	})
	if err != nil {
		t.Fatalf("RotateOAuth: %v", err)
	}
	if sent != "refresh-1" || got.AccessToken != "access-2" {
		t.Fatalf("sent %q, got %q", sent, got.AccessToken)
	}

	stored, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if stored.OAuth.RefreshToken != "refresh-2" || stored.BridgeID != login.BridgeID {
		t.Fatalf("stored %+v, bridge id %q", stored.OAuth, stored.BridgeID)
	}
}

func TestRotateRefreshesAnExpiredStoredToken(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)
	if err := Save(oauthLogin("access-2", "refresh-2", time.Now().Add(-time.Second))); err != nil {
		t.Fatalf("Save: %v", err)
	}

	calls := 0
	if _, err := RotateOAuth(oauthBase, "access-1", time.Now().Add(time.Minute), func(refresh string) (OAuthTokens, error) {
		calls++
		if refresh != "refresh-2" {
			t.Fatalf("refresh sent %q, want the stored refresh-2", refresh)
		}

		return OAuthTokens{AccessToken: "access-3", RefreshToken: "refresh-3", ExpiresAt: time.Now().Add(time.Hour)}, nil
	}); err != nil {
		t.Fatalf("RotateOAuth: %v", err)
	}
	if calls != 1 {
		t.Fatalf("refresh calls = %d, want 1", calls)
	}
}

func TestRotateRefusesWhenTheFileHoldsAnotherLogin(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)
	if err := Save(Config{BaseURL: oauthBase, Token: "sk-static"}); err != nil {
		t.Fatalf("Save: %v", err)
	}

	_, err := RotateOAuth(oauthBase, "access-1", time.Now(), func(string) (OAuthTokens, error) {
		t.Fatal("refresh called for a file that holds no OAuth login")

		return OAuthTokens{}, nil
	})
	if !errors.Is(err, ErrNotLoggedIn) {
		t.Fatalf("RotateOAuth error = %v, want ErrNotLoggedIn", err)
	}
}

func TestRotateKeepsTheFileWhenTheRefreshFails(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)
	if err := Save(oauthLogin("access-1", "refresh-1", time.Now().Add(time.Hour))); err != nil {
		t.Fatalf("Save: %v", err)
	}

	boom := errors.New("network down")
	if _, err := RotateOAuth(oauthBase, "access-1", time.Now().Add(time.Minute), func(string) (OAuthTokens, error) { return OAuthTokens{}, boom }); !errors.Is(err, boom) {
		t.Fatalf("RotateOAuth error = %v, want %v", err, boom)
	}

	stored, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if stored.OAuth.RefreshToken != "refresh-1" {
		t.Fatalf("stored refresh token = %q, want refresh-1 kept", stored.OAuth.RefreshToken)
	}
}

// rotatingServer is a token endpoint that rotates its refresh token on every
// use and refuses a used one, as Loupe does. The pause widens the window in
// which two unlocked refreshers would both send the same refresh token.
type rotatingServer struct {
	mu        sync.Mutex
	current   string
	rotations atomic.Int32
}

func (s *rotatingServer) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	_ = r.ParseForm()
	time.Sleep(50 * time.Millisecond)
	s.mu.Lock()
	defer s.mu.Unlock()
	if r.PostForm.Get("refresh_token") != s.current {
		w.WriteHeader(http.StatusBadRequest)
		fmt.Fprint(w, `{"error":"invalid_grant"}`)

		return
	}
	n := s.rotations.Add(1)
	s.current = fmt.Sprintf("refresh-%d", n+1)
	fmt.Fprintf(w, `{"access_token":"access-%d","refresh_token":%q,"expires_in":3600}`, n+1, s.current)
}

// TestRotateHelperProcess is not a test. TestRotateIsSafeAcrossProcesses runs
// the test binary again with this test selected, so each refresher is a real
// process with its own file descriptors and no shared mutex.
func TestRotateHelperProcess(t *testing.T) {
	if os.Getenv("LOUPE_ROTATE_HELPER") != "1" {
		t.Skip("run by TestRotateIsSafeAcrossProcesses")
	}
	endpoint := os.Getenv("LOUPE_ROTATE_ENDPOINT")
	_, err := RotateOAuth(oauthBase, "access-1", time.Now().Add(time.Minute), func(refresh string) (OAuthTokens, error) {
		resp, err := http.PostForm(endpoint, url.Values{"refresh_token": {refresh}})
		if err != nil {
			return OAuthTokens{}, err
		}
		defer resp.Body.Close()
		if resp.StatusCode != http.StatusOK {
			return OAuthTokens{}, fmt.Errorf("refresh refused with HTTP %d", resp.StatusCode)
		}
		var body struct {
			AccessToken  string `json:"access_token"`
			RefreshToken string `json:"refresh_token"`
			ExpiresIn    int    `json:"expires_in"`
		}
		if err := json.NewDecoder(resp.Body).Decode(&body); err != nil {
			return OAuthTokens{}, err
		}

		return OAuthTokens{AccessToken: body.AccessToken, RefreshToken: body.RefreshToken, ExpiresAt: time.Now().Add(time.Duration(body.ExpiresIn) * time.Second)}, nil
	})
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
	os.Exit(0)
}

// TestRotateIsSafeAcrossProcesses starts several processes that all hold the
// same stale access token and all try to refresh it at once. Refresh tokens
// rotate, so a second refresh with the first token would get invalid_grant and
// that process would lose its login. With the file lock, one process refreshes
// and every other one adopts what it wrote.
func TestRotateIsSafeAcrossProcesses(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)
	server := &rotatingServer{current: "refresh-1"}
	endpoint := httptest.NewServer(server)
	t.Cleanup(endpoint.Close)
	if err := Save(oauthLogin("access-1", "refresh-1", time.Now().Add(time.Hour))); err != nil {
		t.Fatalf("Save: %v", err)
	}

	const processes = 6
	errs := make(chan error, processes)
	start := make(chan struct{})
	for range processes {
		cmd := exec.Command(os.Args[0], "-test.run=^TestRotateHelperProcess$")
		cmd.Env = append(os.Environ(), "LOUPE_ROTATE_HELPER=1", "LOUPE_ROTATE_ENDPOINT="+endpoint.URL)
		go func() {
			<-start
			out, err := cmd.CombinedOutput()
			if err != nil {
				err = fmt.Errorf("%w: %s", err, out)
			}
			errs <- err
		}()
	}
	close(start)
	for range processes {
		if err := <-errs; err != nil {
			t.Errorf("a refresher lost its login: %v", err)
		}
	}

	if got := server.rotations.Load(); got != 1 {
		t.Fatalf("the server rotated %d times, want 1", got)
	}
	stored, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if stored.OAuth.RefreshToken != "refresh-2" || stored.OAuth.AccessToken != "access-2" {
		t.Fatalf("stored %+v, want the one rotation", stored.OAuth)
	}
}
