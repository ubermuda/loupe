// Package config persists the bridge's credentials. The Loupe base URL lives in
// a JSON file under the user's config dir; the API token goes to the OS
// keychain, falling back to that same file (mode 0600) wherever no keychain is
// reachable — a headless container or a Linux box with no D-Bus session.
//
// A token an older version left in the file migrates to the keychain on the
// next read, so an installation that never logs in again still stops keeping
// the secret on disk.
//
// The bridge id sits in that same file. It names a bridge and grants nothing,
// so it is not a secret and the keychain does not hold it.
package config

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"

	"github.com/zalando/go-keyring"
)

// keyringService names the keychain entry; the account is the Loupe base URL,
// so credentials for two instances do not overwrite each other.
const keyringService = "loupe-cli"

// configFileName is the JSON file inside Dir().
const configFileName = "config.json"

// ErrNotLoggedIn is returned by Load when no usable credentials are stored.
var ErrNotLoggedIn = errors.New("not logged in: run `loupe login` first")

// Config is the persisted credential set. Token is empty on disk whenever the
// keychain accepted it. BridgeID names this machine's bridge to the server, and
// EnsureBridgeID rather than Load is what guarantees a value.
type Config struct {
	BaseURL  string `json:"baseUrl"`
	Token    string `json:"token,omitempty"`
	BridgeID string `json:"bridgeId,omitempty"`
}

// Dir is the directory that holds config.json, and rules.yaml beside it.
func Dir() (string, error) {
	d, err := os.UserConfigDir()
	if err != nil {
		return "", fmt.Errorf("locate config dir: %w", err)
	}

	return filepath.Join(d, "loupe"), nil
}

// Load reads stored credentials, returning ErrNotLoggedIn if none are present.
func Load() (Config, error) {
	var c Config
	d, err := Dir()
	if err != nil {
		return c, err
	}
	c, err = readStoredConfig(d)
	if err != nil {
		return c, err
	}
	if c.BaseURL == "" {
		return c, ErrNotLoggedIn
	}
	if c.Token == "" {
		// An empty token on disk means Save handed it to the keychain. A
		// keychain that has since become unreachable is indistinguishable from
		// one that never held it, and both mean the same to the caller.
		if token, err := keyring.Get(keyringService, c.BaseURL); err == nil {
			c.Token = token
		}
	} else {
		migrateTokenToKeyring(d, c)
	}

	if c.Token == "" {
		return c, ErrNotLoggedIn
	}

	return c, nil
}

// migrateTokenToKeyring moves a token an older version left in the config file
// into the keychain, so the secret stops living on disk without the user having
// to log in again. The file is rewritten only once the keychain confirms the
// write — losing the token from both places would log the user out. Where no
// keychain is reachable this is a silent no-op and the file stays authoritative.
func migrateTokenToKeyring(d string, c Config) {
	if err := keyring.Set(keyringService, c.BaseURL, c.Token); err != nil {
		return
	}

	configMu.Lock()
	defer configMu.Unlock()

	// Re-read under the lock, so a bridge id another goroutine stored in the
	// meantime survives.
	cleared, err := readStoredConfig(d)
	if err != nil {
		return
	}
	cleared.Token = ""
	// A failed rewrite leaves the token in both places, and the next command
	// tries again.
	_ = writeConfig(d, cleared)
}

// Save writes credentials, creating the config dir if needed. The token goes to
// the OS keychain when one is reachable, and into the config file otherwise.
func Save(c Config) error {
	d, err := Dir()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(d, 0o700); err != nil {
		return fmt.Errorf("create config dir: %w", err)
	}

	stored := c
	if err := keyring.Set(keyringService, c.BaseURL, c.Token); err == nil {
		stored.Token = ""
	}
	configMu.Lock()
	defer configMu.Unlock()

	if stored.BridgeID == "" {
		// A caller that knows nothing about the bridge id, such as `loupe
		// login`, must not change which bridge this machine is. A file this
		// cannot read holds no id to keep, and `login` is how an operator
		// repairs such a file, so the read error stops nothing.
		if previous, err := readStoredConfig(d); err == nil && isUUID(previous.BridgeID) {
			stored.BridgeID = previous.BridgeID
		}
	}

	return writeConfig(d, stored)
}

// readStoredConfig reads config.json. A file that is missing or blank reads as
// an empty Config, so a first run and a hand-emptied file both continue.
func readStoredConfig(d string) (Config, error) {
	var c Config

	b, err := os.ReadFile(filepath.Join(d, configFileName))
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return c, nil
		}

		return c, fmt.Errorf("read config: %w", err)
	}
	if len(bytes.TrimSpace(b)) == 0 {
		return c, nil
	}
	if err := json.Unmarshal(b, &c); err != nil {
		return c, fmt.Errorf("parse config: %w", err)
	}

	return c, nil
}

func writeConfig(d string, c Config) error {
	b, err := json.MarshalIndent(c, "", "  ")
	if err != nil {
		return err
	}

	// A write in place truncates first, so a full disk leaves the credentials
	// half written. os.CreateTemp opens at 0600, and the rename replaces the
	// file in one step, which also sets the mode of an existing config that
	// held world-readable permissions and an API token.
	f, err := os.CreateTemp(d, configFileName+".*")
	if err != nil {
		return fmt.Errorf("write config: %w", err)
	}
	tmp := f.Name()
	defer os.Remove(tmp)

	if _, err := f.Write(b); err != nil {
		f.Close()

		return fmt.Errorf("write config: %w", err)
	}
	if err := f.Close(); err != nil {
		return fmt.Errorf("write config: %w", err)
	}
	if err := os.Rename(tmp, filepath.Join(d, configFileName)); err != nil {
		return fmt.Errorf("write config: %w", err)
	}

	return nil
}
