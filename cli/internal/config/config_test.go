package config

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/zalando/go-keyring"
)

// configPath mirrors what Save writes, so a test can inspect the file itself.
func configPath(t *testing.T, root string) string {
	t.Helper()

	return filepath.Join(root, "loupe", "config.json")
}

// writeLegacyConfig lays down the file an older CLI wrote: the token in the
// JSON, no keychain entry anywhere.
func writeLegacyConfig(t *testing.T, root string, c Config) {
	t.Helper()

	if err := os.MkdirAll(filepath.Join(root, "loupe"), 0o700); err != nil {
		t.Fatalf("create config dir: %v", err)
	}
	b, err := json.Marshal(c)
	if err != nil {
		t.Fatalf("marshal config: %v", err)
	}
	if err := os.WriteFile(configPath(t, root), b, 0o600); err != nil {
		t.Fatalf("write legacy config: %v", err)
	}
}

func TestSaveLoadRoundTrip(t *testing.T) {
	keyring.MockInit()
	t.Setenv("XDG_CONFIG_HOME", t.TempDir())

	want := Config{BaseURL: "https://example.test", Token: "sk-tok"}
	if err := Save(want); err != nil {
		t.Fatalf("Save: %v", err)
	}

	got, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if got != want {
		t.Fatalf("round-trip mismatch: got %+v want %+v", got, want)
	}
}

func TestLoadNotLoggedIn(t *testing.T) {
	keyring.MockInit()
	t.Setenv("XDG_CONFIG_HOME", t.TempDir())

	if _, err := Load(); !errors.Is(err, ErrNotLoggedIn) {
		t.Fatalf("want ErrNotLoggedIn, got %v", err)
	}
}

// TestSaveKeepsTheTokenOutOfTheFileWhenTheKeychainAcceptsIt is the point of
// keychain storage: a config file that still carried the token would leave it
// readable by anything running as the user.
func TestSaveKeepsTheTokenOutOfTheFileWhenTheKeychainAcceptsIt(t *testing.T) {
	keyring.MockInit()
	root := t.TempDir()
	t.Setenv("XDG_CONFIG_HOME", root)

	if err := Save(Config{BaseURL: "https://example.test", Token: "sk-secret"}); err != nil {
		t.Fatalf("Save: %v", err)
	}

	b, err := os.ReadFile(configPath(t, root))
	if err != nil {
		t.Fatalf("read config: %v", err)
	}
	if strings.Contains(string(b), "sk-secret") {
		t.Fatalf("token was written to disk: %s", b)
	}

	stored, err := keyring.Get(keyringService, "https://example.test")
	if err != nil {
		t.Fatalf("keychain Get: %v", err)
	}
	if stored != "sk-secret" {
		t.Fatalf("keychain holds %q, want the saved token", stored)
	}

	got, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if got.Token != "sk-secret" {
		t.Fatalf("Load returned token %q, want it read back from the keychain", got.Token)
	}
}

// TestSaveFallsBackToTheFileWithoutAKeychain covers the headless case — a
// container or a Linux box with no D-Bus session — where refusing to store the
// token at all would make the bridge unusable.
func TestSaveFallsBackToTheFileWithoutAKeychain(t *testing.T) {
	keyring.MockInitWithError(errors.New("no keychain here"))
	t.Cleanup(keyring.MockInit)

	root := t.TempDir()
	t.Setenv("XDG_CONFIG_HOME", root)

	want := Config{BaseURL: "https://example.test", Token: "sk-fallback"}
	if err := Save(want); err != nil {
		t.Fatalf("Save: %v", err)
	}

	b, err := os.ReadFile(configPath(t, root))
	if err != nil {
		t.Fatalf("read config: %v", err)
	}
	var onDisk Config
	if err := json.Unmarshal(b, &onDisk); err != nil {
		t.Fatalf("parse config: %v", err)
	}
	if onDisk.Token != "sk-fallback" {
		t.Fatalf("config file holds token %q, want the fallback copy", onDisk.Token)
	}

	got, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if got != want {
		t.Fatalf("round-trip mismatch: got %+v want %+v", got, want)
	}
}

// TestLoadMigratesAFileTokenIntoTheKeychain covers the upgrade path. Someone
// already logged in has no reason to run `login` again, so without this the
// keychain never holds their token and it stays on disk forever.
func TestLoadMigratesAFileTokenIntoTheKeychain(t *testing.T) {
	keyring.MockInit()
	root := t.TempDir()
	t.Setenv("XDG_CONFIG_HOME", root)

	want := Config{BaseURL: "https://example.test", Token: "sk-legacy"}
	writeLegacyConfig(t, root, want)
	// Loosened first, so the permission assertion below cannot pass by accident.
	if err := os.Chmod(configPath(t, root), 0o644); err != nil {
		t.Fatalf("chmod: %v", err)
	}

	got, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if got != want {
		t.Fatalf("Load returned %+v, want %+v", got, want)
	}

	b, err := os.ReadFile(configPath(t, root))
	if err != nil {
		t.Fatalf("read config: %v", err)
	}
	if strings.Contains(string(b), "sk-legacy") {
		t.Fatalf("token was left on disk after migration: %s", b)
	}

	stored, err := keyring.Get(keyringService, want.BaseURL)
	if err != nil {
		t.Fatalf("keychain Get: %v", err)
	}
	if stored != want.Token {
		t.Fatalf("keychain holds %q, want the migrated token", stored)
	}

	info, err := os.Stat(configPath(t, root))
	if err != nil {
		t.Fatalf("stat: %v", err)
	}
	if perm := info.Mode().Perm(); perm != 0o600 {
		t.Fatalf("migration left permissions at %04o, want 0600", perm)
	}

	// The next command reads the migrated token back out of the keychain.
	again, err := Load()
	if err != nil {
		t.Fatalf("second Load: %v", err)
	}
	if again != want {
		t.Fatalf("second Load returned %+v, want %+v", again, want)
	}
}

// TestLoadKeepsTheFileTokenWithoutAKeychain is the other half: on a headless
// host the migration must be a silent no-op rather than a logout.
func TestLoadKeepsTheFileTokenWithoutAKeychain(t *testing.T) {
	keyring.MockInitWithError(errors.New("no keychain here"))
	t.Cleanup(keyring.MockInit)

	root := t.TempDir()
	t.Setenv("XDG_CONFIG_HOME", root)

	want := Config{BaseURL: "https://example.test", Token: "sk-legacy"}
	writeLegacyConfig(t, root, want)

	got, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if got != want {
		t.Fatalf("Load returned %+v, want %+v", got, want)
	}

	b, err := os.ReadFile(configPath(t, root))
	if err != nil {
		t.Fatalf("read config: %v", err)
	}
	var onDisk Config
	if err := json.Unmarshal(b, &onDisk); err != nil {
		t.Fatalf("parse config: %v", err)
	}
	if onDisk.Token != want.Token {
		t.Fatalf("config file holds token %q; without a keychain it stays authoritative", onDisk.Token)
	}
}

// TestSaveTightensPermissionsOnAnExistingFile pins the fix for os.WriteFile's
// mode applying only at creation: a config that already existed keeps whatever
// permissions it had, and without a keychain it holds an API token.
func TestSaveTightensPermissionsOnAnExistingFile(t *testing.T) {
	keyring.MockInit()
	dir := t.TempDir()
	t.Setenv("XDG_CONFIG_HOME", dir)
	t.Setenv("HOME", dir)

	if err := Save(Config{BaseURL: "https://loupe.test", Token: "first"}); err != nil {
		t.Fatalf("Save: %v", err)
	}

	path := ""
	if err := filepath.Walk(dir, func(p string, info os.FileInfo, err error) error {
		if err == nil && info != nil && !info.IsDir() && filepath.Base(p) == "config.json" {
			path = p
		}

		return nil
	}); err != nil {
		t.Fatalf("walk: %v", err)
	}
	if path == "" {
		t.Fatal("config.json was not written")
	}

	if err := os.Chmod(path, 0o644); err != nil {
		t.Fatalf("chmod: %v", err)
	}
	if err := Save(Config{BaseURL: "https://loupe.test", Token: "second"}); err != nil {
		t.Fatalf("Save: %v", err)
	}

	info, err := os.Stat(path)
	if err != nil {
		t.Fatalf("stat: %v", err)
	}
	if got := info.Mode().Perm(); got != 0o600 {
		t.Fatalf("config permissions are %04o, want 0600", got)
	}
}
