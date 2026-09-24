package cmd

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"golang.org/x/sys/unix"

	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/update"
)

const (
	// preflightTimeout bounds the check a staged binary runs before it takes over.
	preflightTimeout = 30 * time.Second
	// frozenDrainTimeout bounds the second drain, after the freeze.
	frozenDrainTimeout = 2 * time.Second
	// preflightOutputLimit bounds the output of a failed preflight in the log.
	preflightOutputLimit = 1024
)

var (
	// execFn replaces the process image. Tests replace it.
	execFn = syscall.Exec
	// healthTimeout bounds the wait of a new image for its stream and
	// heartbeat. Tests shorten it.
	healthTimeout = 60 * time.Second
)

// The hidden flags that carry a handover into the next image.
const (
	resumeHandoverFlag = "resume-handover"
	rolledBackFromFlag = "rolled-back-from"
)

// bridgeUpdate hands a running bridge over to another binary through an exec,
// and takes over a bridge that another image handed over.
type bridgeUpdate struct {
	log *slog.Logger
	// dir is the config directory, and file the handover file.
	dir  string
	file string
	// args are the arguments of this image.
	args []string
	// target is the installed binary that a forward update replaces.
	target string
	lock   *bridgeLock
	// control is a descriptor of the control socket, kept open for the exec.
	control *os.File

	exec         func(argv0 string, argv, env []string) error
	preflight    func(ctx context.Context, staged string) error
	executable   func() (string, error)
	drainTimeout time.Duration

	// resumed is the state an exec handed over, from resumeFile, and
	// rolledBackFrom the version that handed it back. recovered is a state
	// that a bridge left behind when it died.
	resumed        *handoverState
	resumeFile     string
	rolledBackFrom string
	recovered      *handoverState
	adopted        atomic.Bool

	connected, beat    chan struct{}
	connOnce, beatOnce sync.Once
}

// newBridgeUpdate prepares the handover of the bridge that reads rulesPath and
// holds lock and ln.
func newBridgeUpdate(log *slog.Logger, rulesPath string, lock *bridgeLock, ln net.Listener) (*bridgeUpdate, error) {
	ul, ok := ln.(*net.UnixListener)
	if !ok {
		return nil, fmt.Errorf("the control socket is no unix socket")
	}
	control, err := ul.File()
	if err != nil {
		return nil, fmt.Errorf("the control socket: %w", err)
	}
	b := &bridgeUpdate{
		log:        log,
		lock:       lock,
		control:    control,
		exec:       execFn,
		executable: os.Executable,
		connected:  make(chan struct{}),
		beat:       make(chan struct{}),
	}
	if b.dir, err = config.Dir(); err != nil {
		control.Close()

		return nil, err
	}
	if b.file, err = handoverPath(rulesPath); err != nil {
		control.Close()

		return nil, err
	}
	if len(os.Args) > 1 {
		b.args = os.Args[1:]
	}
	if exe, err := os.Executable(); err == nil {
		if real, err := filepath.EvalSymlinks(exe); err == nil {
			b.target = real
		}
	}
	b.preflight = func(ctx context.Context, staged string) error { return runPreflightOf(ctx, staged, rulesPath, b.dir) }

	return b, nil
}

func (b *bridgeUpdate) close() {
	b.control.Close()
}

// installed names the binary that a forward update replaces.
func (b *bridgeUpdate) installed() (string, error) {
	if b.target == "" {
		return "", errors.New("the path of the running binary is unknown")
	}

	return b.target, nil
}

// handoverArgs drops the flags that a former handover added.
func handoverArgs(args []string) []string {
	var out []string
	for i := 0; i < len(args); i++ {
		a := args[i]
		switch {
		case a == "--"+resumeHandoverFlag || a == "--"+rolledBackFromFlag:
			i++
		case strings.HasPrefix(a, "--"+resumeHandoverFlag+"=") || strings.HasPrefix(a, "--"+rolledBackFromFlag+"="):
		default:
			out = append(out, a)
		}
	}

	return out
}

// runPreflightOf runs the preflight of the staged binary, with a handover file
// in the format this image writes.
func runPreflightOf(ctx context.Context, staged, rulesPath, dir string) error {
	probe, err := os.CreateTemp(dir, ".handover-probe-*")
	if err != nil {
		return err
	}
	probe.Close()
	defer os.Remove(probe.Name())
	if err := writeHandover(probe.Name(), handoverState{Format: handoverFormat}); err != nil {
		return err
	}

	ctx, cancel := context.WithTimeout(ctx, preflightTimeout)
	defer cancel()
	cmd := exec.CommandContext(ctx, staged, "bridge", "preflight", "--rules", rulesPath, "--handover", probe.Name())
	cmd.WaitDelay = time.Second
	out, err := cmd.CombinedOutput()
	if err != nil {
		msg := strings.TrimSpace(string(out))
		if len(msg) > preflightOutputLimit {
			msg = msg[:preflightOutputLimit]
		}

		return fmt.Errorf("%w: %s", err, msg)
	}

	return nil
}

// handover is the hook of a staged release. It checks the binary, stops the
// router at a quiet point and execs the binary with the routing state. It
// returns only when the handover did not happen.
func (b *bridgeUpdate) handover(r *router, from string) stagedHook {
	return func(ctx context.Context, c update.Candidate, staged string) stagedOutcome {
		to := c.Version.String()
		if err := b.preflight(ctx, staged); err != nil {
			b.log.Warn("update_rolled_back", "from", from, "to", to, "reason", "preflight", "error", err.Error())

			return stagedRejected
		}
		// A reload moves the lock, so none may run until the exec.
		if !r.reloadMu.TryLock() {
			b.log.Info("update_deferred", "from", from, "to", to, "reason", "a reload is running")

			return stagedDeferred
		}
		defer r.reloadMu.Unlock()

		r.pause()
		if err := r.drain(ctx, b.drainTimeout); err != nil {
			r.resume()
			b.log.Info("update_deferred", "from", from, "to", to, "reason", err.Error())

			return stagedDeferred
		}
		st := r.freeze()
		if err := r.drain(ctx, frozenDrainTimeout); err != nil {
			r.resume()
			b.log.Info("update_deferred", "from", from, "to", to, "reason", err.Error())

			return stagedDeferred
		}
		st.OldVersion, st.OldBinary = from, b.target
		b.log.Info("update_handover", "from", from, "to", to, "file", b.file, "live", len(st.Live), "queued", len(st.Queue))

		err := b.execWith(st, staged)
		os.Remove(b.file)
		r.resume()
		b.log.Error("update_rolled_back", "from", from, "to", to, "reason", "exec", "error", err.Error())

		return stagedRejected
	}
}

// execWith writes st with the descriptors of the lock and of the control
// socket, and execs binary with them. It returns only on a failure, and then
// the descriptors close on an exec again, as a worker must never inherit them.
func (b *bridgeUpdate) execWith(st handoverState, binary string, extra ...string) error {
	lockFD, ctlFD := int(b.lock.f.Fd()), int(b.control.Fd())
	st.LockFD, st.ControlFD, st.LockPath = lockFD, ctlFD, b.lock.path
	if err := writeHandover(b.file, st); err != nil {
		return err
	}
	if err := keepOnExec(lockFD, ctlFD); err != nil {
		closeOnExec(lockFD, ctlFD)

		return err
	}
	argv := append([]string{binary}, handoverArgs(b.args)...)
	argv = append(argv, "--"+resumeHandoverFlag, b.file)
	argv = append(argv, extra...)
	err := b.exec(binary, argv, os.Environ())
	closeOnExec(lockFD, ctlFD)
	if err == nil {
		err = errors.New("exec returned")
	}

	return err
}

// keepOnExec clears the close-on-exec flag of each fd.
func keepOnExec(fds ...int) error {
	for _, fd := range fds {
		flags, err := unix.FcntlInt(uintptr(fd), unix.F_GETFD, 0)
		if err != nil {
			return fmt.Errorf("read the flags of fd %d: %w", fd, err)
		}
		if _, err := unix.FcntlInt(uintptr(fd), unix.F_SETFD, flags&^unix.FD_CLOEXEC); err != nil {
			return fmt.Errorf("keep fd %d open across the exec: %w", fd, err)
		}
	}

	return nil
}

// closeOnExec sets the close-on-exec flag of each fd.
func closeOnExec(fds ...int) {
	for _, fd := range fds {
		syscall.CloseOnExec(fd)
	}
}
