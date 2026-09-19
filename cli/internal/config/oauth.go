package config

import (
	"errors"
	"os"
	"time"
)

// RotateOAuth gives a usable access token for the device login of baseURL.
// stale is the access token the caller holds and can no longer use.
//
// Refresh tokens rotate, and the server refuses a used one. Several processes
// can share this file, so the read, the refresh and the write all happen under
// the config lock, after a fresh read. When the file already holds another
// access token that is valid past validUntil, another process has refreshed,
// and this call returns that token with no refresh. Otherwise refresh gets the
// stored refresh token, and its answer replaces the stored login.
func RotateOAuth(baseURL, stale string, validUntil time.Time, refresh func(refreshToken string) (OAuthTokens, error)) (OAuthTokens, error) {
	var out OAuthTokens
	d, err := Dir()
	if err != nil {
		return out, err
	}
	if _, err := os.Stat(d); errors.Is(err, os.ErrNotExist) {
		return out, ErrNotLoggedIn
	}

	err = withConfigLock(d, func() error {
		c, err := readStoredConfig(d)
		if errors.Is(err, os.ErrNotExist) {
			return ErrNotLoggedIn
		}
		if err != nil {
			return err
		}
		if c.BaseURL != baseURL || c.OAuth == nil || c.OAuth.RefreshToken == "" {
			return ErrNotLoggedIn
		}

		if c.OAuth.AccessToken != stale && c.OAuth.ExpiresAt.After(validUntil) {
			out = *c.OAuth

			return nil
		}

		fresh, err := refresh(c.OAuth.RefreshToken)
		if err != nil {
			return err
		}
		c.OAuth = &fresh
		if err := writeConfig(d, c); err != nil {
			return err
		}
		out = fresh

		return nil
	})

	return out, err
}
