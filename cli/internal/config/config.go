// Package config persists the bridge's credentials. The Loupe base URL lives in
// a JSON file under the user's config dir; the API token goes to the OS
// keychain, falling back to that same file (mode 0600) wherever no keychain is
// reachable — a headless container or a Linux box with no D-Bus session.
package config

import (
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

// ErrNotLoggedIn is returned by Load when no usable credentials are stored.
var ErrNotLoggedIn = errors.New("not logged in: run `loupe login` first")

// Config is the persisted credential set. Token is empty on disk whenever the
// keychain accepted it.
type Config struct {
	BaseURL string `json:"baseUrl"`
	Token   string `json:"token,omitempty"`
}

func dir() (string, error) {
	d, err := os.UserConfigDir()
	if err != nil {
		return "", fmt.Errorf("locate config dir: %w", err)
	}

	return filepath.Join(d, "loupe"), nil
}

// Load reads stored credentials, returning ErrNotLoggedIn if none are present.
func Load() (Config, error) {
	var c Config
	d, err := dir()
	if err != nil {
		return c, err
	}
	b, err := os.ReadFile(filepath.Join(d, "config.json"))
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return c, ErrNotLoggedIn
		}

		return c, fmt.Errorf("read config: %w", err)
	}
	if err := json.Unmarshal(b, &c); err != nil {
		return c, fmt.Errorf("parse config: %w", err)
	}
	if c.BaseURL == "" {
		return c, ErrNotLoggedIn
	}
	// An empty token on disk means Save handed it to the keychain. A keychain
	// that has since become unreachable is indistinguishable from one that
	// never held the token, and both mean the same thing to the caller.
	if c.Token == "" {
		if token, err := keyring.Get(keyringService, c.BaseURL); err == nil {
			c.Token = token
		}
	}
	if c.Token == "" {
		return c, ErrNotLoggedIn
	}

	return c, nil
}

// Save writes credentials, creating the config dir if needed. The token goes to
// the OS keychain when one is reachable, and into the config file otherwise.
func Save(c Config) error {
	d, err := dir()
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

	b, err := json.MarshalIndent(stored, "", "  ")
	if err != nil {
		return err
	}
	path := filepath.Join(d, "config.json")
	if err := os.WriteFile(path, b, 0o600); err != nil {
		return fmt.Errorf("write config: %w", err)
	}
	// WriteFile's mode applies only when it creates the file, so a config that
	// already existed keeps whatever permissions it had — including
	// world-readable ones holding an API token.
	if err := os.Chmod(path, 0o600); err != nil {
		return fmt.Errorf("secure config: %w", err)
	}

	return nil
}
