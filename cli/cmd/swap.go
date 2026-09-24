package cmd

import (
	"bytes"
	"crypto/sha256"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"slices"
	"syscall"

	"github.com/ubermuda/loupe/cli/internal/update"
)

// swapBinary copies staged over target through a rename in the directory of
// target, under update.lock in dir, so two bridges never write it at once. A
// target that already holds the staged bytes stays untouched. It reports
// whether it wrote target.
func swapBinary(dir, staged, target string) (bool, error) {
	lock, err := os.OpenFile(filepath.Join(dir, "update.lock"), os.O_CREATE|os.O_RDWR, 0o600)
	if err != nil {
		return false, fmt.Errorf("open the update lock: %w", err)
	}
	defer lock.Close()
	for {
		err = syscall.Flock(int(lock.Fd()), syscall.LOCK_EX)
		if !errors.Is(err, syscall.EINTR) {
			break
		}
	}
	if err != nil {
		return false, fmt.Errorf("take the update lock: %w", err)
	}

	target, err = filepath.EvalSymlinks(target)
	if err != nil {
		return false, err
	}
	want, err := digest(staged)
	if err != nil {
		return false, err
	}
	if have, err := digest(target); err == nil && bytes.Equal(have, want) {
		return false, nil
	}

	src, err := os.Open(staged)
	if err != nil {
		return false, err
	}
	defer src.Close()
	tmp, err := os.CreateTemp(filepath.Dir(target), ".loupe-install-*")
	if err != nil {
		return false, err
	}
	defer os.Remove(tmp.Name())
	if _, err := io.Copy(tmp, src); err != nil {
		tmp.Close()

		return false, err
	}
	if err := tmp.Chmod(0o755); err != nil {
		tmp.Close()

		return false, err
	}
	if err := tmp.Sync(); err != nil {
		tmp.Close()

		return false, err
	}
	if err := tmp.Close(); err != nil {
		return false, err
	}

	return true, os.Rename(tmp.Name(), target)
}

// digest is the size and the sha256 of the file at path.
func digest(path string) ([]byte, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	h := sha256.New()
	n, err := io.Copy(h, f)
	if err != nil {
		return nil, err
	}

	return fmt.Appendf(h.Sum(nil), "%d", n), nil
}

// pruneVersions removes each staged version but keep from dir, and from
// update.json.
func pruneVersions(dir string, keep ...string) error {
	root := filepath.Join(dir, "versions")
	entries, err := os.ReadDir(root)
	if errors.Is(err, fs.ErrNotExist) {
		return nil
	}
	if err != nil {
		return err
	}
	var errs []error
	for _, e := range entries {
		if !slices.Contains(keep, e.Name()) {
			errs = append(errs, os.RemoveAll(filepath.Join(root, e.Name())))
		}
	}
	st, err := update.LoadState(dir)
	if err != nil {
		return errors.Join(append(errs, err)...)
	}
	for v := range st.Staged {
		if !slices.Contains(keep, v) {
			delete(st.Staged, v)
		}
	}

	return errors.Join(append(errs, st.Save(dir))...)
}
