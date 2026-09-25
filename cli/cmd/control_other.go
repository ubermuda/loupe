package cmd

import (
	"errors"
	"os"
	"syscall"
)

// tryLockFile takes the exclusive lock of f, or fails at once when another
// open file holds it. flock locks belong to the open file, so two opens in one
// process also exclude each other.
func tryLockFile(f *os.File) error {
	for {
		err := syscall.Flock(int(f.Fd()), syscall.LOCK_EX|syscall.LOCK_NB)
		if !errors.Is(err, syscall.EINTR) {
			return err
		}
	}
}

// lockHeld reports whether tryLockFile failed because another file holds the lock.
func lockHeld(err error) bool {
	return errors.Is(err, syscall.EWOULDBLOCK)
}

// connRefused reports whether nothing listens on the socket that err dialed.
func connRefused(err error) bool {
	return errors.Is(err, syscall.ECONNREFUSED)
}
