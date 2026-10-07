package config

import (
	"errors"
	"fmt"
	"os"
)

// AgentAccount is a GitHub user that bridge workers push as. Token is its
// personal access token. Login and ID are what GitHub answered for that token.
type AgentAccount struct {
	Token string `json:"token"`
	Login string `json:"login"`
	ID    int64  `json:"id"`
}

// LoadAgentAccount returns the stored agent account, or nil when there is none.
// It needs no login.
func LoadAgentAccount() (*AgentAccount, error) {
	d, err := Dir()
	if err != nil {
		return nil, err
	}
	c, err := readStoredConfig(d)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}

	return c.AgentAccount, nil
}

// SetAgentAccount stores a, or removes the stored account when a is nil. It
// keeps every other field, and creates config.json when it does not exist.
func SetAgentAccount(a *AgentAccount) error {
	d, err := Dir()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(d, 0o700); err != nil {
		return fmt.Errorf("create config dir: %w", err)
	}

	return withConfigLock(d, func() error {
		c, err := readStoredConfig(d)
		if err != nil && !errors.Is(err, os.ErrNotExist) {
			return err
		}
		c.AgentAccount = a

		return writeConfig(d, c)
	})
}
