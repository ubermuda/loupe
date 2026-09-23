//go:build !windows

package cmd

import (
	"errors"
	"syscall"
)

// connRefused reports whether nothing listens on the socket that err dialed.
func connRefused(err error) bool {
	return errors.Is(err, syscall.ECONNREFUSED)
}
