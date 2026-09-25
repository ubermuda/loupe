package cmd

import (
	"context"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"net"
	"os"
	"path/filepath"
	"time"

	"github.com/ubermuda/loupe/cli/internal/config"
)

// resumeBridge takes over the lock and the control socket that a former image
// handed over in file. It never takes the lock anew: a handed lock that no
// longer excludes another open file is refused.
func resumeBridge(log *slog.Logger, rulesPath, sock, file, rolledBackFrom string) (*bridgeUpdate, net.Listener, error) {
	st, err := readHandover(file)
	if err != nil {
		return nil, nil, err
	}
	if st.LockFD <= 2 || st.ControlFD <= 2 {
		return nil, nil, fmt.Errorf("handover %s names lock fd %d and control fd %d, and both must be above 2", file, st.LockFD, st.ControlFD)
	}
	lock, err := inheritLock(rulesPath, sock, st)
	if err != nil {
		return nil, nil, err
	}
	control := os.NewFile(uintptr(st.ControlFD), sock)
	ln, err := net.FileListener(control)
	if err != nil {
		lock.Close()
		control.Close()

		return nil, nil, fmt.Errorf("the handed control socket: %w", err)
	}
	ul, ok := ln.(*net.UnixListener)
	if !ok {
		ln.Close()
		lock.Close()
		control.Close()

		return nil, nil, fmt.Errorf("the handed control socket is no unix socket")
	}
	// The first image made the socket file, and this one removes it when it
	// stops, as a listener that made it would.
	ul.SetUnlinkOnClose(true)
	closeOnExec(st.ControlFD)

	b := &bridgeUpdate{
		log:            log,
		lock:           lock,
		control:        control,
		args:           os.Args[1:],
		target:         st.OldBinary,
		exec:           execFn,
		executable:     os.Executable,
		resumed:        &st,
		resumeFile:     file,
		rolledBackFrom: rolledBackFrom,
		connected:      make(chan struct{}),
		beat:           make(chan struct{}),
	}
	if b.target == "" {
		if exe, err := os.Executable(); err == nil {
			b.target, _ = filepath.EvalSymlinks(exe)
		}
	}
	if b.dir, err = config.Dir(); err == nil {
		b.file, err = handoverPath(rulesPath)
	}
	if err != nil {
		ln.Close()
		b.close()
		lock.Close()

		return nil, nil, err
	}
	b.preflight = func(ctx context.Context, staged string) error { return runPreflightOf(ctx, staged, rulesPath, b.dir) }
	// The skip comes first, so the first check of this image cannot pick the
	// version it rolled back from.
	if rolledBackFrom != "" {
		if err := skipVersion(b.dir, rolledBackFrom); err != nil {
			log.Warn("update_skip_failed", "version", rolledBackFrom, "error", err.Error())
		}
	}

	return b, ln, nil
}

// inheritLock checks that the handed descriptor holds the lock of the rule
// file: it is that file, a lock on it succeeds, and one on a fresh open fails.
func inheritLock(rulesPath, sock string, st handoverState) (*bridgeLock, error) {
	path := st.LockPath
	if path == "" {
		var err error
		if path, err = lockPath(rulesPath); err != nil {
			return nil, err
		}
	}
	f := os.NewFile(uintptr(st.LockFD), path)
	if err := sameFile(f, path); err != nil {
		f.Close()

		return nil, fmt.Errorf("the handed fd %d is not the lock of %s: %w", st.LockFD, path, err)
	}
	if err := tryLockFile(f); err != nil {
		f.Close()

		return nil, fmt.Errorf("the handed fd %d holds no lock of %s: %w", st.LockFD, path, err)
	}
	probe, err := os.OpenFile(path, os.O_RDWR, 0)
	if err != nil {
		f.Close()

		return nil, fmt.Errorf("open the bridge lock: %w", err)
	}
	err = tryLockFile(probe)
	probe.Close()
	if !lockHeld(err) {
		f.Close()

		return nil, fmt.Errorf("the handed fd %d is not the lock of %s", st.LockFD, path)
	}
	closeOnExec(st.LockFD)

	return &bridgeLock{rulesPath: rulesPath, sock: sock, path: path, f: f, resolve: lockPath}, nil
}

func sameFile(f *os.File, path string) error {
	have, err := f.Stat()
	if err != nil {
		return err
	}
	want, err := os.Stat(path)
	if err != nil {
		return err
	}
	if !os.SameFile(have, want) {
		return fmt.Errorf("it names another file")
	}

	return nil
}

// leftoverHandover reads the handover file that a bridge left when it died
// between its handover and its health. A file this build cannot read moves
// aside to .bad, so the next start does not trip on it.
func leftoverHandover(file string, log *slog.Logger) *handoverState {
	st, err := readHandover(file)
	if err == nil {
		return &st
	}
	if errors.Is(err, fs.ErrNotExist) {
		return nil
	}
	bad := file + ".bad"
	if rerr := os.Rename(file, bad); rerr != nil {
		log.Error("update_recovery_failed", "file", file, "error", err.Error(), "rename_error", rerr.Error())

		return nil
	}
	log.Error("update_recovery_failed", "file", file, "moved_to", bad, "error", err.Error())

	return nil
}

func (b *bridgeUpdate) markConnected() {
	b.connOnce.Do(func() { close(b.connected) })
}

func (b *bridgeUpdate) markBeat() {
	b.beatOnce.Do(func() { close(b.beat) })
}

// adoptInto hands the router the state that a former image left, before the
// router subscribes.
func (b *bridgeUpdate) adoptInto(r *router) {
	switch {
	case b.resumed != nil:
		r.adopt(*b.resumed)
	case b.recovered != nil:
		st := b.recovered
		r.adopt(*st)
		if err := os.Remove(b.file); err != nil && !errors.Is(err, fs.ErrNotExist) {
			b.log.Warn("update_recovery_failed", "file", b.file, "error", err.Error())
		}
		b.log.Info("update_recovered", "file", b.file, "from", st.OldVersion, "live", len(st.Live), "queued", len(st.Queue))
	default:
		return
	}
	b.adopted.Store(true)
}

func isClosed(ch <-chan struct{}) bool {
	select {
	case <-ch:
		return true
	default:
		return false
	}
}

// watchHealth waits for the stream to connect and for a heartbeat to land. A
// resumed image that gets neither in time hands back to the image before it.
// The updater starts after, so no update starts before the health is known.
func (b *bridgeUpdate) watchHealth(ctx context.Context, r *router, updates *updater) <-chan struct{} {
	done := make(chan struct{})
	if r.heartbeat == nil {
		b.markBeat()
	}
	timeout := healthTimeout
	// LOUPE_HEALTH_TIMEOUT shortens the wait, so a test can reach a rollback.
	if d, err := time.ParseDuration(os.Getenv("LOUPE_HEALTH_TIMEOUT")); err == nil && d > 0 {
		timeout = d
	}
	go func() {
		defer close(done)
		timer := time.NewTimer(timeout)
		defer timer.Stop()
		retry := time.NewTicker(healthBeatRetry)
		defer retry.Stop()
		healthy := true
	wait:
		for _, ch := range []chan struct{}{b.connected, b.beat} {
			for waiting := true; waiting; {
				select {
				case <-ch:
					waiting = false
				case <-retry.C:
					if r.heartbeat != nil && !isClosed(b.beat) {
						r.heartbeat.send()
					}
				case <-ctx.Done():
					return
				case <-timer.C:
					healthy = false

					break wait
				}
			}
		}
		if healthy {
			b.healthy(updates)
		} else {
			b.unhealthy(ctx, r, timeout)
		}
		if updates != nil {
			updates.start(ctx)
		}
	}()

	return done
}

// healthy ends a handover that worked. A forward update installs its binary,
// and a rollback reports the version it left.
func (b *bridgeUpdate) healthy(updates *updater) {
	b.dropResumeFile()
	if b.rolledBackFrom != "" {
		b.log.Warn("update_rolled_back", "from", b.rolledBackFrom, "to", version, "reason", "health")
		if updates != nil {
			updates.markRolledBack(b.rolledBackFrom)
		}

		return
	}
	old := b.resumed.OldVersion
	b.log.Info("update_applied", "from", old, "to", version)
	if updates != nil {
		updates.setState(updateCurrent, "")
	}
	b.install(old)
}

// dropResumeFile removes the handover file once the router adopted it, so the
// next start does not adopt it again.
func (b *bridgeUpdate) dropResumeFile() {
	if !b.adopted.Load() {
		return
	}
	if err := os.Remove(b.resumeFile); err != nil && !errors.Is(err, fs.ErrNotExist) {
		b.log.Warn("update_cleanup_failed", "file", b.resumeFile, "error", err.Error())
	}
}

// install copies the running binary over the installed one, and keeps the
// staged versions of this image and the one before it.
func (b *bridgeUpdate) install(old string) {
	if b.target == "" {
		b.log.Warn("update_install_failed", "error", "the path of the installed binary is unknown")

		return
	}
	staged, err := b.executable()
	if err == nil {
		staged, err = filepath.EvalSymlinks(staged)
	}
	swapped := false
	if err == nil {
		swapped, err = swapBinary(b.dir, staged, b.target)
	}
	if err != nil {
		b.log.Warn("update_install_failed", "path", b.target, "error", err.Error())

		return
	}
	if swapped {
		b.log.Info("update_installed", "path", b.target, "version", version)
	}
	if err := pruneVersions(b.dir, version, old); err != nil {
		b.log.Warn("update_prune_failed", "error", err.Error())
	}
}

// unhealthy hands the bridge back to the image before it. A rolled back image
// never hands back again, so two images cannot pass the bridge back and forth.
func (b *bridgeUpdate) unhealthy(ctx context.Context, r *router, timeout time.Duration) {
	b.log.Error("update_unhealthy", "from", b.resumed.OldVersion, "to", version, "timeout_seconds", int(timeout/time.Second))
	if b.rolledBackFrom != "" || b.resumed.OldBinary == "" {
		b.log.Error("update_rollback_skipped", "message", "The bridge keeps running on this version.")
		b.dropResumeFile()

		return
	}
	r.reloadMu.Lock()
	defer r.reloadMu.Unlock()
	r.pause()
	if err := r.drain(ctx, b.drainTimeout); err != nil {
		b.log.Warn("update_drain_failed", "error", err.Error())
	}
	st := r.freeze()
	st.OldVersion, st.OldBinary = version, b.target
	err := b.execWith(st, b.resumed.OldBinary, nil, "--"+rolledBackFromFlag, version)
	os.Remove(b.file)
	r.resume()
	b.log.Error("update_rollback_failed", "to", b.resumed.OldVersion, "error", err.Error())
}

// rollbackAtStart hands the untouched state back to the image before this one
// when this one fails to start. It returns only when it cannot.
func (b *bridgeUpdate) rollbackAtStart(cause error) {
	if b.adopted.Load() || b.rolledBackFrom != "" || b.resumed.OldBinary == "" {
		return
	}
	b.log.Error("update_unhealthy", "from", b.resumed.OldVersion, "to", version, "error", cause.Error())
	st := *b.resumed
	st.OldVersion, st.OldBinary = version, b.target
	if err := b.execWith(st, b.resumed.OldBinary, nil, "--"+rolledBackFromFlag, version); err != nil {
		b.log.Error("update_rollback_failed", "to", b.resumed.OldVersion, "error", err.Error())
	}
}
