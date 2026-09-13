package config

import (
	"errors"
	"os"
	"strings"
	"testing"

	"github.com/zalando/go-keyring"
)

func TestTheBridgeIDPersistsAcrossLoads(t *testing.T) {
	keyring.MockInit()
	root := t.TempDir()
	t.Setenv("XDG_CONFIG_HOME", root)
	if err := Save(Config{BaseURL: "https://example.test", Token: "sk-secret"}); err != nil {
		t.Fatal(err)
	}

	first, err := BridgeID()
	if err != nil {
		t.Fatal(err)
	}
	if !uuidPattern.MatchString(first) {
		t.Fatalf("bridge id %q is not a uuid the server route accepts", first)
	}
	second, err := BridgeID()
	if err != nil {
		t.Fatal(err)
	}
	if second != first {
		t.Fatalf("bridge id changed from %q to %q", first, second)
	}
	loaded, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if loaded.BridgeID != first || loaded.Token != "sk-secret" {
		t.Fatalf("Load = %+v", loaded)
	}

	b, err := os.ReadFile(configPath(t, root))
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(b), "sk-secret") {
		t.Fatalf("writing the bridge id put the keychain token on disk: %s", b)
	}
}

// A new login rewrites config.json. The server keeps a report until the same
// bridge replaces it, so a login must not mint a second bridge.
func TestALoginKeepsTheBridgeID(t *testing.T) {
	keyring.MockInit()
	t.Setenv("XDG_CONFIG_HOME", t.TempDir())
	if err := Save(Config{BaseURL: "https://example.test", Token: "one"}); err != nil {
		t.Fatal(err)
	}
	id, err := BridgeID()
	if err != nil {
		t.Fatal(err)
	}

	if err := Save(Config{BaseURL: "https://example.test", Token: "two"}); err != nil {
		t.Fatal(err)
	}
	again, err := BridgeID()
	if err != nil {
		t.Fatal(err)
	}
	if again != id {
		t.Fatalf("bridge id changed across a login: %q, then %q", id, again)
	}
}

// The token stays in the file where no keychain is reachable, and writing the
// bridge id must keep it there.
func TestTheBridgeIDKeepsAFileToken(t *testing.T) {
	keyring.MockInitWithError(errors.New("no keychain here"))
	t.Cleanup(keyring.MockInit)
	t.Setenv("XDG_CONFIG_HOME", t.TempDir())
	writeLegacyConfig(t, os.Getenv("XDG_CONFIG_HOME"), Config{BaseURL: "https://example.test", Token: "sk-file", BridgeID: "not-a-uuid"})

	id, err := BridgeID()
	if err != nil {
		t.Fatal(err)
	}
	if !uuidPattern.MatchString(id) {
		t.Fatalf("an invalid stored id was kept: %q", id)
	}
	got, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if got.Token != "sk-file" || got.BridgeID != id {
		t.Fatalf("Load = %+v", got)
	}
}

func TestTheBridgeIDNeedsALogin(t *testing.T) {
	t.Setenv("XDG_CONFIG_HOME", t.TempDir())
	if _, err := BridgeID(); !errors.Is(err, ErrNotLoggedIn) {
		t.Fatalf("err = %v, want ErrNotLoggedIn", err)
	}
}
