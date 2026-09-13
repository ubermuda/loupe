// Package config persists the bridge's credentials. The Loupe base URL lives in
// a JSON file under the user's config dir; the API token goes to the OS
// keychain, falling back to that same file (mode 0600) wherever no keychain is
// reachable — a headless container or a Linux box with no D-Bus session.
//
// A token an older version left in the file migrates to the keychain on the
// next read, so an installation that never logs in again still stops keeping
// the secret on disk.
package config

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"

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
	// BridgeID names this bridge in its rule health reports. The server keeps
	// a report until the same id replaces it, so the id must survive a restart
	// and a new login.
	BridgeID string `json:"bridgeId,omitempty"`
}

// uuidPattern is the uuid shape the server's route accepts for a bridge id.
var uuidPattern = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[13-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`)

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

	cleared := c
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
	if stored.BridgeID == "" {
		if onDisk, err := readFile(d); err == nil {
			stored.BridgeID = onDisk.BridgeID
		}
	}

	return writeConfig(d, stored)
}

// BridgeID returns the bridge id stored in config.json. It generates one and
// writes it on the first call, or when the stored value is not a uuid. It
// rewrites the file as it is on disk, so a token the keychain holds stays out.
func BridgeID() (string, error) {
	d, err := Dir()
	if err != nil {
		return "", err
	}
	c, err := readFile(d)
	if errors.Is(err, os.ErrNotExist) {
		return "", ErrNotLoggedIn
	}
	if err != nil {
		return "", err
	}
	if uuidPattern.MatchString(c.BridgeID) {
		return c.BridgeID, nil
	}

	id, err := newUUID()
	if err != nil {
		return "", err
	}
	c.BridgeID = id
	if err := writeConfig(d, c); err != nil {
		return "", err
	}

	return id, nil
}

// newUUID returns a random version 4 uuid.
func newUUID() (string, error) {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		return "", fmt.Errorf("generate bridge id: %w", err)
	}
	b[6] = b[6]&0x0f | 0x40
	b[8] = b[8]&0x3f | 0x80
	h := hex.EncodeToString(b[:])

	return h[0:8] + "-" + h[8:12] + "-" + h[12:16] + "-" + h[16:20] + "-" + h[20:], nil
}

func readFile(d string) (Config, error) {
	var c Config
	b, err := os.ReadFile(filepath.Join(d, "config.json"))
	if err != nil {
		return c, err
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
