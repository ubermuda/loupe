// Package config persists the bridge's credentials (Better Plans base URL and
// API token) to a single JSON file under the user's config dir, mode 0600.
//
// Storing the token in the OS keychain is a future enhancement (see
// docs/NEXT_STEPS.md).
package config

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
)

// ErrNotLoggedIn is returned by Load when no usable credentials are stored.
var ErrNotLoggedIn = errors.New("not logged in: run `betterplans login` first")

// Config is the persisted credential set.
type Config struct {
	BaseURL string `json:"baseUrl"`
	Token   string `json:"token"`
}

func dir() (string, error) {
	d, err := os.UserConfigDir()
	if err != nil {
		return "", fmt.Errorf("locate config dir: %w", err)
	}

	return filepath.Join(d, "betterplans"), nil
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
	if c.BaseURL == "" || c.Token == "" {
		return c, ErrNotLoggedIn
	}

	return c, nil
}

// Save writes credentials, creating the config dir if needed.
func Save(c Config) error {
	d, err := dir()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(d, 0o700); err != nil {
		return fmt.Errorf("create config dir: %w", err)
	}
	b, err := json.MarshalIndent(c, "", "  ")
	if err != nil {
		return err
	}
	if err := os.WriteFile(filepath.Join(d, "config.json"), b, 0o600); err != nil {
		return fmt.Errorf("write config: %w", err)
	}

	return nil
}
