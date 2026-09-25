package update

import (
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"slices"
)

const stateFile = "update.json"

// State is what the updater keeps between two runs: the versions it must not
// install, and the binary it staged for each version.
type State struct {
	Skips  []string          `json:"skip,omitempty"`
	Staged map[string]string `json:"staged,omitempty"`
}

// LoadState reads update.json in dir. A missing file is an empty state.
func LoadState(dir string) (*State, error) {
	s := &State{Staged: map[string]string{}}
	data, err := os.ReadFile(filepath.Join(dir, stateFile))
	if errors.Is(err, fs.ErrNotExist) {
		return s, nil
	}
	if err != nil {
		return nil, err
	}
	if err := json.Unmarshal(data, s); err != nil {
		return nil, fmt.Errorf("read %s: %w", stateFile, err)
	}
	if s.Staged == nil {
		s.Staged = map[string]string{}
	}

	return s, nil
}

// Save writes update.json in dir through a rename, so a crash leaves the old
// file or the new one.
func (s *State) Save(dir string) error {
	data, err := json.Marshal(s)
	if err != nil {
		return err
	}

	return WriteFile(filepath.Join(dir, stateFile), data, 0o600)
}

// Skip adds version, as X.Y.Z, to the versions the updater must not install.
func (s *State) Skip(version string) {
	if !s.Skipped(version) {
		s.Skips = append(s.Skips, version)
	}
}

func (s *State) Skipped(version string) bool {
	return slices.Contains(s.Skips, version)
}

// SkipSet is the skip list in the shape Pick reads.
func (s *State) SkipSet() map[string]bool {
	set := map[string]bool{}
	for _, v := range s.Skips {
		set[v] = true
	}

	return set
}

// StagePath is where the binary of version waits for its handover.
func StagePath(dir, version string) string {
	return filepath.Join(dir, "versions", version, "loupe")
}

// WriteFile writes data to path through a temporary file in the same
// directory and a rename, and creates the directory.
func WriteFile(path string, data []byte, mode os.FileMode) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(path), "."+filepath.Base(path)+"-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()

		return err
	}
	if err := tmp.Chmod(mode); err != nil {
		tmp.Close()

		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}

	return os.Rename(tmp.Name(), path)
}
