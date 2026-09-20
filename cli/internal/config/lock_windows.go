//go:build windows

package config

import (
	"os"

	"golang.org/x/sys/windows"
)

// lockFile blocks until this open file holds an exclusive lock on its first
// byte. Every caller locks the same byte, so they exclude each other.
func lockFile(f *os.File) error {
	return windows.LockFileEx(windows.Handle(f.Fd()), windows.LOCKFILE_EXCLUSIVE_LOCK, 0, 1, 0, new(windows.Overlapped))
}

func unlockFile(f *os.File) error {
	return windows.UnlockFileEx(windows.Handle(f.Fd()), 0, 1, 0, new(windows.Overlapped))
}
