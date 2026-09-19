//go:build unix

package config

import (
	"errors"
	"os"
	"syscall"
)

// lockFile blocks until this open file holds the exclusive lock. flock locks
// belong to the open file, so two opens in one process also exclude each other.
func lockFile(f *os.File) error {
	for {
		err := syscall.Flock(int(f.Fd()), syscall.LOCK_EX)
		if !errors.Is(err, syscall.EINTR) {
			return err
		}
	}
}

func unlockFile(f *os.File) error {
	return syscall.Flock(int(f.Fd()), syscall.LOCK_UN)
}
