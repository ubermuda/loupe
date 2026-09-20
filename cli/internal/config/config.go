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
//
// A device login keeps its access token, refresh token and expiry in the file,
// never in the keychain. Refresh tokens rotate, so every refresh rewrites all
// three, and one rename under the config lock keeps them consistent.
package config

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/zalando/go-keyring"
)

// keyringService names the keychain entry; the account is the Loupe base URL,
// so credentials for two instances do not overwrite each other.
const keyringService = "loupe-cli"

// configFileName is the JSON file inside Dir().
const configFileName = "config.json"

// ErrNotLoggedIn is returned by Load when no usable credentials are stored.
var ErrNotLoggedIn = errors.New("not logged in: run `loupe login` first")

// Config is the persisted credential set. Token is a static API token, and is
// empty on disk whenever the keychain accepted it. OAuth is a device login, and
// replaces Token when set. BridgeID names this machine's bridge to the server,
// and EnsureBridgeID rather than Load is what guarantees a value.
type Config struct {
	BaseURL  string       `json:"baseUrl"`
	Token    string       `json:"token,omitempty"`
	OAuth    *OAuthTokens `json:"oauth,omitempty"`
	BridgeID string       `json:"bridgeId,omitempty"`
}

// OAuthTokens is what the device flow and each refresh give.
type OAuthTokens struct {
	AccessToken  string    `json:"accessToken"`
	RefreshToken string    `json:"refreshToken"`
	ExpiresAt    time.Time `json:"expiresAt"`
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
		if errors.Is(err, os.ErrNotExist) {
			return c, ErrNotLoggedIn
		}

		return c, err
	}
	if c.BaseURL == "" {
		return c, ErrNotLoggedIn
	}
	if c.OAuth != nil {
		if c.OAuth.RefreshToken == "" {
			return c, ErrNotLoggedIn
		}

		return c, nil
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

	// Re-read under the lock, so a bridge id another writer stored in the
	// meantime survives. A file that now holds other credentials belongs to a
	// later write, so clearing it here would throw the newer one away. A failed
	// rewrite leaves the token in both places, and the next command tries again.
	_ = withConfigLock(d, func() error {
		cleared, err := readStoredConfig(d)
		if err != nil || cleared.BaseURL != c.BaseURL || cleared.Token != c.Token {
			return nil
		}
		cleared.Token = ""

		return writeConfig(d, cleared)
	})
}

// Save writes credentials, creating the config dir if needed. A static token
// goes to the OS keychain when one is reachable, and into the config file
// otherwise. A device login goes to the file. Load reads it before any static
// token, and the keychain entry of an earlier static token is removed where a
// keychain is reachable.
func Save(c Config) error {
	d, err := Dir()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(d, 0o700); err != nil {
		return fmt.Errorf("create config dir: %w", err)
	}

	stored := c
	if c.OAuth != nil {
		stored.Token = ""
		_ = keyring.Delete(keyringService, c.BaseURL)
	} else if err := keyring.Set(keyringService, c.BaseURL, c.Token); err == nil {
		stored.Token = ""
	}

	return withConfigLock(d, func() error {
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
	})
}

// readStoredConfig reads config.json. A missing file gives an error that
// matches os.ErrNotExist, so a caller can tell it apart from a blank file. A
// blank file reads as an empty Config, so a hand-emptied file continues.
func readStoredConfig(d string) (Config, error) {
	var c Config

	b, err := os.ReadFile(filepath.Join(d, configFileName))
	if err != nil {
		return c, fmt.Errorf("read config: %w", err)
	}
	if len(bytes.TrimSpace(b)) == 0 {
		return c, nil
	}
	if err := json.Unmarshal(b, &c); err != nil {
		// An id that is a number or an object reads as absent, and the heal
		// replaces it. json reports the first type error alone, so the second
		// pass drops the id and decodes again. A credential field of the wrong
		// type therefore still fails.
		var typeErr *json.UnmarshalTypeError
		if !errors.As(err, &typeErr) || typeErr.Field != "bridgeId" {
			return c, fmt.Errorf("parse config: %w", err)
		}

		var fields map[string]json.RawMessage
		if err := json.Unmarshal(b, &fields); err != nil {
			return Config{}, fmt.Errorf("parse config: %w", err)
		}
		// json matches a field name without regard to case, so the key in the
		// file can read bridgeID.
		for k := range fields {
			if strings.EqualFold(k, "bridgeId") {
				delete(fields, k)
			}
		}
		rest, err := json.Marshal(fields)
		if err != nil {
			return Config{}, fmt.Errorf("parse config: %w", err)
		}

		c = Config{}
		if err := json.Unmarshal(rest, &c); err != nil {
			return Config{}, fmt.Errorf("parse config: %w", err)
		}
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
