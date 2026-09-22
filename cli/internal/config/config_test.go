package config

import (
	"errors"
	"os"
	"testing"
	"time"

	"github.com/zalando/go-keyring"
)

func TestSaveLoadRoundTrip(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)

	expires := time.Now().Add(time.Hour).UTC().Truncate(time.Second)
	want := oauthLogin("access-1", "refresh-1", expires)
	if err := Save(want); err != nil {
		t.Fatalf("Save: %v", err)
	}

	got, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if got.BaseURL != want.BaseURL || got.OAuth == nil {
		t.Fatalf("round-trip gives %+v, want the saved login", got)
	}
	if got.OAuth.AccessToken != "access-1" || got.OAuth.RefreshToken != "refresh-1" || !got.OAuth.ExpiresAt.Equal(expires) {
		t.Fatalf("round-trip gives %+v, want the saved tokens", got.OAuth)
	}
}

func TestLoadNotLoggedIn(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)

	if _, err := Load(); !errors.Is(err, ErrNotLoggedIn) {
		t.Fatalf("want ErrNotLoggedIn, got %v", err)
	}
}

// TestLoadRefusesAFileFromAnOlderVersion covers the upgrade. Loupe accepts no
// static token any more, so a file that holds one holds no usable credential
// and the operator must run `loupe login`.
func TestLoadRefusesAFileFromAnOlderVersion(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)
	seedConfigFile(t, `{"baseUrl":"https://example.test","token":"sk-legacy"}`)

	if _, err := Load(); !errors.Is(err, ErrNotLoggedIn) {
		t.Fatalf("want ErrNotLoggedIn, got %v", err)
	}
}

// TestSaveWorksWithoutAKeychain covers the headless host, a container or a
// Linux box with no D-Bus session. Save clears the keychain entry an older
// version wrote, and that machine has no keychain to clear.
func TestSaveWorksWithoutAKeychain(t *testing.T) {
	keyring.MockInitWithError(errors.New("no keychain here"))
	t.Cleanup(keyring.MockInit)
	useTempConfigHome(t)

	if err := Save(oauthLogin("access-1", "refresh-1", time.Now().Add(time.Hour))); err != nil {
		t.Fatalf("Save: %v", err)
	}

	got, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if got.OAuth == nil || got.OAuth.RefreshToken != "refresh-1" {
		t.Fatalf("Load gives %+v, want the saved login", got.OAuth)
	}
}

// TestSaveTightensPermissionsOnAnExistingFile pins the fix for os.WriteFile's
// mode applying only at creation: a config that already existed keeps whatever
// permissions it had, and it holds a refresh token.
func TestSaveTightensPermissionsOnAnExistingFile(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)

	if err := Save(oauthLogin("access-1", "refresh-1", time.Now().Add(time.Hour))); err != nil {
		t.Fatalf("Save: %v", err)
	}

	path := storedConfigPath(t)
	if err := os.Chmod(path, 0o644); err != nil {
		t.Fatalf("chmod: %v", err)
	}
	if err := Save(oauthLogin("access-2", "refresh-2", time.Now().Add(time.Hour))); err != nil {
		t.Fatalf("second Save: %v", err)
	}

	info, err := os.Stat(path)
	if err != nil {
		t.Fatalf("stat: %v", err)
	}
	if got := info.Mode().Perm(); got != 0o600 {
		t.Fatalf("config permissions are %04o, want 0600", got)
	}
}
