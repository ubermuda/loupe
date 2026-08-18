package config

import (
	"errors"
	"os"
	"path/filepath"
	"testing"
)

func TestSaveLoadRoundTrip(t *testing.T) {
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
	t.Setenv("XDG_CONFIG_HOME", t.TempDir())

	if _, err := Load(); !errors.Is(err, ErrNotLoggedIn) {
		t.Fatalf("want ErrNotLoggedIn, got %v", err)
	}
}

// TestSaveTightensPermissionsOnAnExistingFile pins the fix for os.WriteFile's
// mode applying only at creation: a config that already existed keeps whatever
// permissions it had, and it holds an API token.
func TestSaveTightensPermissionsOnAnExistingFile(t *testing.T) {
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
