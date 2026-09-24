package cmd

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"golang.org/x/sys/unix"

	"github.com/ubermuda/loupe/cli/internal/update"
)

// execCall is what a fake exec saw: the argv, the handover file as it stood,
// and whether each handed fd would survive the exec.
type execCall struct {
	argv       []string
	st         handoverState
	readErr    error
	lockOpen   bool
	ctlOpen    bool
	lockExec   bool
	ctlExec    bool
	lockExists bool
}

var errExecCaptured = errors.New("exec captured")

// captureExec replaces the exec of b with one that records the call and fails,
// as syscall.Exec returns only on a failure.
func captureExec(b *bridgeUpdate) *[]execCall {
	calls := &[]execCall{}
	b.exec = func(argv0 string, argv, _ []string) error {
		c := execCall{argv: slices.Clone(argv)}
		c.st, c.readErr = readHandover(b.file)
		if c.readErr == nil {
			c.lockOpen, c.lockExec = fdKeptAcrossExec(c.st.LockFD)
			c.ctlOpen, c.ctlExec = fdKeptAcrossExec(c.st.ControlFD)
		}
		*calls = append(*calls, c)

		return errExecCaptured
	}

	return calls
}

// fdKeptAcrossExec reports whether fd is open, and whether an exec keeps it.
func fdKeptAcrossExec(fd int) (open, kept bool) {
	flags, err := unix.FcntlInt(uintptr(fd), unix.F_GETFD, 0)
	if err != nil {
		return false, false
	}

	return true, flags&unix.FD_CLOEXEC == 0
}

// newTestHandoff is a running bridge with a real lock and a real control
// socket, whose exec is captured.
func newTestHandoff(t *testing.T) (*harness, *bridgeUpdate, *[]execCall) {
	t.Helper()
	shortConfigHome(t)
	h := newHarness(t)
	h.states()
	rulesPath := filepath.Join(t.TempDir(), "rules.yaml")
	sock, err := socketPath(rulesPath)
	if err != nil {
		t.Fatal(err)
	}
	lock, err := lockBridge(rulesPath, sock)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { lock.Close() })
	ln, err := listenControl(sock)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { ln.Close() })
	b, err := newBridgeUpdate(h.router.log, rulesPath, lock, ln)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(b.close)
	b.args = []string{"bridge", "run", "--rules", rulesPath}
	b.target = filepath.Join(t.TempDir(), "loupe")
	b.preflight = func(context.Context, string) error { return nil }
	h.router.update = b

	return h, b, captureExec(b)
}

func candidate(t *testing.T, v string) update.Candidate {
	t.Helper()
	parsed, ok := update.ParseVersion(v)
	if !ok {
		t.Fatalf("version %q", v)
	}

	return update.Candidate{Version: parsed}
}

func (h *harness) frozen() (paused, frozen bool) {
	h.router.mu.Lock()
	defer h.router.mu.Unlock()

	return h.router.paused, h.router.frozen
}

// A staged binary that fails its preflight is rejected before the router
// stops anything.
func TestAFailedPreflightRejectsTheVersionAndFreezesNothing(t *testing.T) {
	h, b, calls := newTestHandoff(t)
	var staged string
	b.preflight = func(_ context.Context, path string) error {
		staged = path

		return errors.New("exit status 1: rule file: bad")
	}

	got := b.handover(h.router, "1.0.0")(context.Background(), candidate(t, "1.2.0"), "/staged/loupe")

	if got != stagedRejected || staged != "/staged/loupe" || len(*calls) != 0 {
		t.Fatalf("outcome = %v, preflight of %q, exec calls = %d", got, staged, len(*calls))
	}
	if paused, frozen := h.frozen(); paused || frozen {
		t.Fatal("a failed preflight paused the router")
	}
	line := h.only(t, "update_rolled_back")
	if str(t, line, "reason") != "preflight" || str(t, line, "from") != "1.0.0" || str(t, line, "to") != "1.2.0" || !strings.Contains(str(t, line, "error"), "rule file: bad") {
		t.Fatalf("update_rolled_back = %v", line)
	}
}

// A report that does not leave in time defers the handover to the next check,
// and the router runs on.
func TestADrainThatTimesOutDefersTheHandover(t *testing.T) {
	h, b, calls := newTestHandoff(t)
	h.router.reports = pendingQueue{}
	b.drainTimeout = 50 * time.Millisecond

	got := b.handover(h.router, "1.0.0")(context.Background(), candidate(t, "1.2.0"), "/staged/loupe")

	if got != stagedDeferred || len(*calls) != 0 {
		t.Fatalf("outcome = %v, exec calls = %d", got, len(*calls))
	}
	if paused, frozen := h.frozen(); paused || frozen {
		t.Fatal("the router stays paused")
	}
	if line := h.only(t, "update_deferred"); !strings.Contains(str(t, line, "reason"), "run reports") {
		t.Fatalf("update_deferred = %v", line)
	}
}

// The handover writes the state with both descriptors open across the exec,
// and runs the staged binary with the original arguments. An exec that fails
// gives the descriptors back their close-on-exec flag and resumes the router.
func TestTheHandoverExecsTheStagedBinaryWithTheState(t *testing.T) {
	h, b, calls := newTestHandoff(t)
	h.router.onEvent("id-1", []byte(cardMoved(1)))
	h.router.wg.Wait()
	b.args = []string{"bridge", "run", "--rules", "r.yaml", "--resume-handover", "old.json", "--rolled-back-from=0.9.0"}

	got := b.handover(h.router, "1.0.0")(context.Background(), candidate(t, "1.2.0"), "/staged/loupe")

	if len(*calls) != 1 {
		t.Fatalf("exec calls = %d", len(*calls))
	}
	c := (*calls)[0]
	want := []string{"/staged/loupe", "bridge", "run", "--rules", "r.yaml", "--resume-handover", b.file}
	if !slices.Equal(c.argv, want) {
		t.Fatalf("argv = %q, want %q", c.argv, want)
	}
	if c.readErr != nil {
		t.Fatal(c.readErr)
	}
	if c.st.LockFD <= 2 || c.st.ControlFD <= 2 || c.st.LockFD == c.st.ControlFD {
		t.Fatalf("lockFd = %d, controlFd = %d", c.st.LockFD, c.st.ControlFD)
	}
	if !c.lockOpen || !c.ctlOpen || !c.lockExec || !c.ctlExec {
		t.Fatalf("lock open %v kept %v, control open %v kept %v", c.lockOpen, c.lockExec, c.ctlOpen, c.ctlExec)
	}
	if c.st.OldVersion != "1.0.0" || c.st.OldBinary != b.target || c.st.LockPath != b.lock.path || c.st.LastEventID != "" || len(c.st.RecentIDs) != 1 {
		t.Fatalf("state = %+v", c.st)
	}

	if got != stagedRejected {
		t.Fatalf("outcome = %v", got)
	}
	if _, kept := fdKeptAcrossExec(c.st.LockFD); kept {
		t.Fatal("the lock fd stays open across an exec after the failure")
	}
	if _, kept := fdKeptAcrossExec(c.st.ControlFD); kept {
		t.Fatal("the control fd stays open across an exec after the failure")
	}
	if _, err := os.Stat(b.file); !os.IsNotExist(err) {
		t.Fatalf("the handover file is still there: %v", err)
	}
	if paused, frozen := h.frozen(); paused || frozen {
		t.Fatal("the router stays frozen")
	}
	if line := h.only(t, "update_rolled_back"); str(t, line, "reason") != "exec" {
		t.Fatalf("update_rolled_back = %v", line)
	}
	if h.only(t, "update_handover") == nil {
		t.Fatal("no update_handover line")
	}
}

// A reload that runs holds the lock the handover passes on, so the handover
// waits for the next check.
func TestAHandoverDuringAReloadIsDeferred(t *testing.T) {
	h, b, calls := newTestHandoff(t)
	h.router.reloadMu.Lock()
	defer h.router.reloadMu.Unlock()

	got := b.handover(h.router, "1.0.0")(context.Background(), candidate(t, "1.2.0"), "/staged/loupe")

	if got != stagedDeferred || len(*calls) != 0 {
		t.Fatalf("outcome = %v, exec calls = %d", got, len(*calls))
	}
}

func TestHandoverArgsDropTheFlagsOfAFormerHandover(t *testing.T) {
	got := handoverArgs([]string{"bridge", "run", "--resume-handover", "a", "--rules=x", "--rolled-back-from", "1.0.0", "--resume-handover=b", "--rolled-back-from=2.0.0", "--model", "m"})

	if want := []string{"bridge", "run", "--rules=x", "--model", "m"}; !slices.Equal(got, want) {
		t.Fatalf("args = %q, want %q", got, want)
	}
}
