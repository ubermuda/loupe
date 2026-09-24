package cmd

import (
	"context"
	"errors"
	"log/slog"
	"math/rand/v2"
	"net/http"
	"os"
	"path/filepath"
	"runtime"
	"sync"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/update"
)

const (
	updateInterval = time.Hour
	updateJitter   = 10 * time.Minute
	// updateTimeout bounds one GitHub request, a download included.
	updateTimeout = 10 * time.Minute
)

// Update states, as the heartbeat reports them.
const (
	updateCurrent    = "current"
	updateUpdating   = "updating"
	updateBlocked    = "blocked"
	updateOff        = "off"
	updateDev        = "dev"
	updateRolledBack = "rolled-back"
)

// stagedOutcome is how a handover that returned ended. A handover that
// succeeds never returns, because the process runs the new binary.
type stagedOutcome int

const (
	// stagedDeferred leaves the version for the next check.
	stagedDeferred stagedOutcome = iota
	// stagedRejected puts the version on the skip list.
	stagedRejected
)

// stagedHook receives a verified binary, staged at path. It runs on the check
// goroutine, so no other check starts until it returns, and ctx ends when the
// bridge stops.
type stagedHook func(ctx context.Context, c update.Candidate, path string) stagedOutcome

// updater checks GitHub for a CLI release inside the range the server
// supports, and stages it for a handover. It checks at start, when the range
// changes, and once an hour plus a random delay, one check at a time.
type updater struct {
	version    string
	goos       string
	goarch     string
	dir        string
	apiBase    string
	hc         *http.Client
	log        *slog.Logger
	autoUpdate func() bool
	onStaged   stagedHook
	executable func() (string, error)
	after      func(time.Duration) <-chan time.Time
	jitter     func() time.Duration

	mu       sync.Mutex
	cliRange string
	current  api.HeartbeatUpdate
	// rolledBack names the version this run rolled back from. The state keeps
	// saying so, where it would otherwise say current.
	rolledBack string
	kick     chan struct{}
	cancel   context.CancelFunc
	wg       sync.WaitGroup

	// logged holds each once-only line already written. Only the check
	// goroutine reads or writes it.
	logged map[string]bool
}

// newUpdater builds an updater for the running version, which is empty for a
// development build. dir is the config directory.
func newUpdater(log *slog.Logger, version, dir string, autoUpdate func() bool, onStaged stagedHook) *updater {
	return &updater{
		version:    version,
		goos:       runtime.GOOS,
		goarch:     runtime.GOARCH,
		dir:        dir,
		apiBase:    update.GitHubAPI,
		hc:         &http.Client{Timeout: updateTimeout},
		log:        log,
		autoUpdate: autoUpdate,
		onStaged:   onStaged,
		executable: os.Executable,
		after:      time.After,
		jitter:     func() time.Duration { return rand.N(updateJitter) },
		kick:       make(chan struct{}, 1),
		logged:     map[string]bool{},
	}
}

func (u *updater) start(ctx context.Context) {
	ctx, cancel := context.WithCancel(ctx)
	u.mu.Lock()
	u.cancel = cancel
	u.mu.Unlock()
	u.wg.Add(1)
	go u.loop(ctx)
}

// stop ends the loop, and a check in flight, and waits for them.
func (u *updater) stop() {
	u.mu.Lock()
	cancel := u.cancel
	u.mu.Unlock()
	if cancel != nil {
		cancel()
	}
	u.wg.Wait()
}

// setRange applies the range of a heartbeat answer. It never blocks, because
// the heartbeat lane calls it.
func (u *updater) setRange(r string) {
	u.mu.Lock()
	if r == u.cliRange {
		u.mu.Unlock()

		return
	}
	u.cliRange = r
	u.mu.Unlock()

	select {
	case u.kick <- struct{}{}:
	default:
	}
}

// state is the update state for the next heartbeat. It is empty until the
// first check has one.
func (u *updater) state() api.HeartbeatUpdate {
	u.mu.Lock()
	defer u.mu.Unlock()

	return u.current
}

func (u *updater) setState(state, version string) {
	u.mu.Lock()
	defer u.mu.Unlock()
	if state == updateCurrent && u.rolledBack != "" {
		state, version = updateRolledBack, u.rolledBack
	}
	u.current = api.HeartbeatUpdate{State: state, Version: version}
}

// markRolledBack reports version as rolled back for the rest of this run.
func (u *updater) markRolledBack(version string) {
	u.mu.Lock()
	u.rolledBack = version
	u.current = api.HeartbeatUpdate{State: updateRolledBack, Version: version}
	u.mu.Unlock()
}

// skipVersion puts version on the skip list in update.json of dir.
func skipVersion(dir, version string) error {
	st, err := update.LoadState(dir)
	if err != nil {
		return err
	}
	st.Skip(version)

	return st.Save(dir)
}

func (u *updater) loop(ctx context.Context) {
	defer u.wg.Done()
	if u.version == "" {
		u.log.Info("update_skipped", "reason", "development build")
		u.setState(updateDev, "")

		return
	}

	u.check(ctx)
	for {
		select {
		case <-ctx.Done():
			return
		case <-u.kick:
		case <-u.after(updateInterval + u.jitter()):
		}
		u.check(ctx)
	}
}

// once reports whether key is new, and remembers it.
func (u *updater) once(key string) bool {
	if u.logged[key] {
		return false
	}
	u.logged[key] = true

	return true
}

// check runs one update check.
func (u *updater) check(ctx context.Context) {
	u.mu.Lock()
	cliRange := u.cliRange
	u.mu.Unlock()
	if u.version == "" || cliRange == "" {
		return
	}
	u.log.Info("update_check", "from", u.version, "range", cliRange)

	st, err := update.LoadState(u.dir)
	if err != nil {
		u.log.Warn("update_state_unreadable", "error", err.Error())
		st = &update.State{Staged: map[string]string{}}
	}
	releases, err := update.FetchReleases(ctx, u.hc, u.apiBase)
	if err != nil {
		u.failed(ctx, err, "")

		return
	}
	c, found := update.Pick(releases, cliRange, u.goos, u.goarch, st.SkipSet())
	if !found || !update.ShouldInstall(u.version, c.Version, cliRange) {
		if update.Satisfies(u.version, cliRange) {
			u.setState(updateCurrent, "")
		} else if u.once("unavailable|" + cliRange + "|" + u.version) {
			u.log.Warn("update_unavailable", "from", u.version, "range", cliRange,
				"message", "The running version is outside the range this Loupe supports, and no release inside it can be installed.")
		}

		return
	}
	to := c.Version.String()
	if !u.autoUpdate() {
		if u.once("available|" + to) {
			u.log.Info("update_available", "from", u.version, "to", to)
		}
		u.setState(updateOff, to)

		return
	}
	if err := u.writable(); err != nil {
		if u.once("blocked|" + to) {
			u.log.Warn("update_blocked", "from", u.version, "to", to, "error", err.Error())
		}
		u.setState(updateBlocked, to)

		return
	}

	path, ok := u.stage(ctx, st, c)
	if !ok {
		return
	}
	prev := u.state()
	u.setState(updateUpdating, to)
	if u.onStaged(ctx, c, path) != stagedRejected {
		u.mu.Lock()
		u.current = prev
		u.mu.Unlock()

		return
	}
	if err := skipVersion(u.dir, to); err != nil {
		u.log.Warn("update_skip_failed", "version", to, "error", err.Error())
	}
	u.markRolledBack(to)
}

// stage downloads, verifies and writes the binary of c, unless an earlier
// check already staged it.
func (u *updater) stage(ctx context.Context, st *update.State, c update.Candidate) (string, bool) {
	to := c.Version.String()
	path := update.StagePath(u.dir, to)
	if st.Staged[to] == path {
		if _, err := os.Stat(path); err == nil {
			return path, true
		}
	}

	u.log.Info("update_download", "from", u.version, "to", to, "url", c.Archive.URL)
	archive, err := update.Download(ctx, u.hc, c.Archive.URL)
	if err != nil {
		u.failed(ctx, err, to)

		return "", false
	}
	checksums, err := update.Download(ctx, u.hc, c.Checksums.URL)
	if err != nil {
		u.failed(ctx, err, to)

		return "", false
	}
	if err := update.Verify(archive, checksums, c.Archive.Name); err != nil {
		u.log.Warn("update_rejected", "from", u.version, "to", to, "reason", err.Error())

		return "", false
	}
	u.log.Info("update_verified", "from", u.version, "to", to)
	binary, err := update.ExtractBinary(archive, "loupe")
	if err != nil {
		u.log.Warn("update_rejected", "from", u.version, "to", to, "reason", err.Error())

		return "", false
	}
	if err := update.WriteFile(path, binary, 0o755); err != nil {
		u.log.Warn("update_stage_failed", "from", u.version, "to", to, "error", err.Error())

		return "", false
	}
	st.Staged[to] = path
	if err := st.Save(u.dir); err != nil {
		u.log.Warn("update_stage_failed", "from", u.version, "to", to, "error", err.Error())
	}

	return path, true
}

// failed logs a check that did not reach GitHub. A shutdown is not a failure.
func (u *updater) failed(ctx context.Context, err error, to string) {
	if ctx.Err() != nil {
		return
	}
	attrs := []any{"from", u.version, "error", err.Error()}
	if to != "" {
		attrs = append(attrs, "to", to)
	}
	u.log.Warn("update_check_failed", attrs...)
}

// writable checks that the directory of the running binary takes a new file,
// which the handover needs to replace the binary.
func (u *updater) writable() error {
	exe, err := u.executable()
	if err != nil {
		return err
	}
	exe, err = filepath.EvalSymlinks(exe)
	if err != nil {
		return err
	}
	f, err := os.CreateTemp(filepath.Dir(exe), ".loupe-update-*")
	if err != nil {
		return err
	}

	return errors.Join(f.Close(), os.Remove(f.Name()))
}
