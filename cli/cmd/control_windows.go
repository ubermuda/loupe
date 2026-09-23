//go:build windows

package cmd

import (
	"errors"
	"syscall"
)

// wsaeconnrefused is the Winsock errno of a refused connection. A dial to a
// Unix socket on Windows returns it, and syscall.ECONNREFUSED does not match it.
const wsaeconnrefused = syscall.Errno(10061)

// connRefused reports whether nothing listens on the socket that err dialed.
func connRefused(err error) bool {
	return errors.Is(err, syscall.ECONNREFUSED) || errors.Is(err, wsaeconnrefused)
}
