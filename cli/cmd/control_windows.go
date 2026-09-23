//go:build windows

package cmd

import (
	"errors"
	"os"
	"syscall"

	"golang.org/x/sys/windows"
)

// tryLockFile takes an exclusive lock on the first byte of f, or fails at once
// when another handle holds it.
func tryLockFile(f *os.File) error {
	return windows.LockFileEx(windows.Handle(f.Fd()), windows.LOCKFILE_EXCLUSIVE_LOCK|windows.LOCKFILE_FAIL_IMMEDIATELY, 0, 1, 0, new(windows.Overlapped))
}

// lockHeld reports whether tryLockFile failed because another handle holds the lock.
func lockHeld(err error) bool {
	return errors.Is(err, windows.ERROR_LOCK_VIOLATION)
}

// wsaeconnrefused is the Winsock errno of a refused connection. A dial to a
// Unix socket on Windows returns it, and syscall.ECONNREFUSED does not match it.
const wsaeconnrefused = syscall.Errno(10061)

// connRefused reports whether nothing listens on the socket that err dialed.
func connRefused(err error) bool {
	return errors.Is(err, syscall.ECONNREFUSED) || errors.Is(err, wsaeconnrefused)
}
