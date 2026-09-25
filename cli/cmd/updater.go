package cmd

import (
	"cmp"
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
	// checkWait bounds the wait of a forced check for the check in flight.
	checkWait = 2 * time.Minute
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

// The outcomes of a forced check that the heartbeat states do not name.
const (
	outcomeHandingOver = "handing-over"
	outcomeDeferred    = "deferred"
	outcomeRejected    = "rejected"
	outcomeFailed      = "failed"
)

// updateResult is the answer to a forced check. An older bridge that knows no
// update answers with Problems instead.
type updateResult struct {
	OK       bool     `json:"ok"`
	From     string   `json:"from,omitempty"`
	To       string   `json:"to,omitempty"`
	Outcome  string   `json:"outcome,omitempty"`
	Problem  string   `json:"problem,omitempty"`
	Problems []string `json:"problems,omitempty"`
}

func (u *updater) result(outcome, to, problem string) updateResult {
	return updateResult{
		OK:   outcome != outcomeFailed && outcome != outcomeRejected && outcome != updateBlocked,
		From: u.version, To: to, Outcome: outcome, Problem: problem,
	}
}

type announceKey struct{}

// withAnnounce gives the handover of a forced check a way to answer before the
// exec ends the connection.
func withAnnounce(ctx context.Context, announce func(to string)) context.Context {
	return context.WithValue(ctx, announceKey{}, announce)
}

func announceHandover(ctx context.Context, to string) {
	if announce, ok := ctx.Value(announceKey{}).(func(string)); ok && announce != nil {
		announce(to)
	}
}

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
	checkWait  time.Duration
	// token is held by the check that runs, so two checks never overlap.
	token chan struct{}

	mu       sync.Mutex
	cliRange string
	current  api.HeartbeatUpdate
	// rolledBack names the version this run rolled back from. The state keeps
	// saying so, where it would otherwise say current.
	rolledBack string
	kick       chan struct{}
	cancel     context.CancelFunc
	wg         sync.WaitGroup

	// logged holds each once-only line already written. Only the holder of
	// token reads or writes it.
	logged map[string]bool
}

// newUpdater builds an updater for the running version, which is empty for a
// development build. dir is the config directory. LOUPE_UPDATE_API replaces
// the GitHub API, for a test that serves a fake one.
func newUpdater(log *slog.Logger, version, dir string, autoUpdate func() bool, onStaged stagedHook) *updater {
	return &updater{
		version:    version,
		goos:       runtime.GOOS,
		goarch:     runtime.GOARCH,
		dir:        dir,
		apiBase:    cmp.Or(os.Getenv("LOUPE_UPDATE_API"), update.GitHubAPI),
		hc:         &http.Client{Timeout: updateTimeout},
		log:        log,
		autoUpdate: autoUpdate,
		onStaged:   onStaged,
		executable: os.Executable,
		after:      time.After,
		jitter:     func() time.Duration { return rand.N(updateJitter) },
		checkWait:  checkWait,
		token:      make(chan struct{}, 1),
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

	u.checkInTurn(ctx)
	for {
		select {
		case <-ctx.Done():
			return
		case <-u.kick:
		case <-u.after(updateInterval + u.jitter()):
		}
		u.checkInTurn(ctx)
	}
}

// checkInTurn runs one check once no forced check runs.
func (u *updater) checkInTurn(ctx context.Context) {
	select {
	case u.token <- struct{}{}:
	case <-ctx.Done():
		return
	}
	defer func() { <-u.token }()
	u.check(ctx)
}

// checkNow runs a check that ignores the skip list and the autoUpdate key. It
// waits a bounded time for the check in flight. announce runs just before the
// handover execs, which ends the process.
func (u *updater) checkNow(ctx context.Context, announce func(to string)) updateResult {
	u.mu.Lock()
	started := u.cancel != nil
	u.mu.Unlock()
	if !started {
		return u.result(outcomeDeferred, "", "the bridge has not started its update checks yet")
	}
	wait := time.NewTimer(u.checkWait)
	defer wait.Stop()
	select {
	case u.token <- struct{}{}:
	case <-wait.C:
		return u.result(outcomeDeferred, "", "another update check is still running")
	case <-ctx.Done():
		return u.result(outcomeFailed, "", "the bridge is shutting down")
	}
	defer func() { <-u.token }()

	return u.run(withAnnounce(ctx, announce), true)
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
	u.run(ctx, false)
}

// run runs one update check. A forced one ignores the skip list and the
// autoUpdate key.
func (u *updater) run(ctx context.Context, force bool) updateResult {
	u.mu.Lock()
	cliRange := u.cliRange
	u.mu.Unlock()
	if u.version == "" {
		return u.result(updateDev, "", "the bridge runs a development build")
	}
	if cliRange == "" {
		return u.result(outcomeFailed, "", "the server has not sent the CLI range yet")
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

		return u.result(outcomeFailed, "", err.Error())
	}
	skip := st.SkipSet()
	if force {
		skip = nil
	}
	c, found := update.Pick(releases, cliRange, u.goos, u.goarch, skip)
	if !found || !update.ShouldInstall(u.version, c.Version, cliRange) {
		if update.Satisfies(u.version, cliRange) {
			u.setState(updateCurrent, "")

			return u.result(updateCurrent, "", "")
		}
		msg := "The running version is outside the range this Loupe supports, and no release inside it can be installed."
		if u.once("unavailable|" + cliRange + "|" + u.version) {
			u.log.Warn("update_unavailable", "from", u.version, "range", cliRange, "message", msg)
		}

		return u.result(outcomeFailed, "", msg)
	}
	to := c.Version.String()
	if !force && !u.autoUpdate() {
		if u.once("available|" + to) {
			u.log.Info("update_available", "from", u.version, "to", to)
		}
		u.setState(updateOff, to)

		return u.result(updateOff, to, "")
	}
	if err := u.writable(); err != nil {
		if u.once("blocked|" + to) {
			u.log.Warn("update_blocked", "from", u.version, "to", to, "error", err.Error())
		}
		u.setState(updateBlocked, to)

		return u.result(updateBlocked, to, err.Error())
	}

	path, err := u.stage(ctx, st, c)
	if err != nil {
		if errors.As(err, new(rejectedError)) {
			return u.result(outcomeRejected, to, err.Error())
		}

		return u.result(outcomeFailed, to, err.Error())
	}
	prev := u.state()
	u.setState(updateUpdating, to)
	if u.onStaged(ctx, c, path) != stagedRejected {
		u.mu.Lock()
		u.current = prev
		u.mu.Unlock()

		return u.result(outcomeDeferred, to, "the bridge deferred the handover, and its log names the reason")
	}
	if err := skipVersion(u.dir, to); err != nil {
		u.log.Warn("update_skip_failed", "version", to, "error", err.Error())
	}
	u.markRolledBack(to)

	return u.result(outcomeRejected, to, "the new version failed its handover and is now on the skip list; the bridge log names the reason")
}

// rejectedError is a downloaded archive that failed its checks.
type rejectedError struct{ error }

// stage downloads, verifies and writes the binary of c, unless an earlier
// check already staged it. An archive that fails its checks gives a
// rejectedError.
func (u *updater) stage(ctx context.Context, st *update.State, c update.Candidate) (string, error) {
	to := c.Version.String()
	path := update.StagePath(u.dir, to)
	if st.Staged[to] == path {
		if _, err := os.Stat(path); err == nil {
			return path, nil
		}
	}

	u.log.Info("update_download", "from", u.version, "to", to, "url", c.Archive.URL)
	archive, err := update.Download(ctx, u.hc, c.Archive.URL)
	if err != nil {
		u.failed(ctx, err, to)

		return "", err
	}
	checksums, err := update.Download(ctx, u.hc, c.Checksums.URL)
	if err != nil {
		u.failed(ctx, err, to)

		return "", err
	}
	if err := update.Verify(archive, checksums, c.Archive.Name); err != nil {
		u.log.Warn("update_rejected", "from", u.version, "to", to, "reason", err.Error())

		return "", rejectedError{err}
	}
	u.log.Info("update_verified", "from", u.version, "to", to)
	binary, err := update.ExtractBinary(archive, "loupe")
	if err != nil {
		u.log.Warn("update_rejected", "from", u.version, "to", to, "reason", err.Error())

		return "", rejectedError{err}
	}
	if err := update.WriteFile(path, binary, 0o755); err != nil {
		u.log.Warn("update_stage_failed", "from", u.version, "to", to, "error", err.Error())

		return "", err
	}
	st.Staged[to] = path
	if err := st.Save(u.dir); err != nil {
		u.log.Warn("update_stage_failed", "from", u.version, "to", to, "error", err.Error())
	}

	return path, nil
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
