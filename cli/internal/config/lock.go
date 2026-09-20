package config

import (
	"fmt"
	"os"
	"path/filepath"
	"sync"
)

// lockFileName sits beside config.json. The lock cannot be on config.json
// itself, because every write replaces that file with a rename.
const lockFileName = "config.lock"

// configMu serialises the goroutines of one process. The file lock serialises
// processes: a worker, a second bridge and `loupe login` can share one config.
var configMu sync.Mutex

// withConfigLock runs fn while this process holds both locks, so a
// read-modify-write of config.json cannot interleave with another one. The
// config directory must exist.
func withConfigLock(d string, fn func() error) error {
	configMu.Lock()
	defer configMu.Unlock()

	f, err := os.OpenFile(filepath.Join(d, lockFileName), os.O_CREATE|os.O_RDWR, 0o600)
	if err != nil {
		return fmt.Errorf("open config lock: %w", err)
	}
	defer f.Close()

	if err := lockFile(f); err != nil {
		return fmt.Errorf("lock config: %w", err)
	}
	defer unlockFile(f)

	return fn()
}
