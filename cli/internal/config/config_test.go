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
