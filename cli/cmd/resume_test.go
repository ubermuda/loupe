package cmd

import (
	"bytes"
	"context"
	"errors"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/zalando/go-keyring"
	"golang.org/x/sys/unix"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/update"
)

// handedBridge takes the lock and the control socket of rulesPath as a former
// image did, and writes a handover that names copies of both descriptors, as
// an exec would leave them.
func handedBridge(t *testing.T, rulesPath string, st handoverState) string {
	t.Helper()
	sock, err := socketPath(rulesPath)
	if err != nil {
		t.Fatal(err)
	}
	lp, err := lockPath(rulesPath)
	if err != nil {
		t.Fatal(err)
	}
	lf, err := lockFileAt(lp, sock)
	if err != nil {
		t.Fatal(err)
	}
	ln, err := listenControl(sock)
	if err != nil {
		t.Fatal(err)
	}
	// The socket file stays until the test ends, as the former image never
	// closes its listener.
	t.Cleanup(func() { ln.Close() })
	cf, err := ln.(*net.UnixListener).File()
	if err != nil {
		t.Fatal(err)
	}
	lockFD, err := unix.Dup(int(lf.Fd()))
	if err != nil {
		t.Fatal(err)
	}
	ctlFD, err := unix.Dup(int(cf.Fd()))
	if err != nil {
		t.Fatal(err)
	}
	lf.Close()
	cf.Close()

	st.Format, st.LockFD, st.ControlFD, st.LockPath = handoverFormat, lockFD, ctlFD, lp
	file, err := handoverPath(rulesPath)
	if err != nil {
		t.Fatal(err)
	}
	if err := writeHandover(file, st); err != nil {
		t.Fatal(err)
	}

	return file
}

func TestResumeBridgeTakesTheHandedLockAndSocket(t *testing.T) {
	shortConfigHome(t)
	rulesPath := filepath.Join(t.TempDir(), "rules.yaml")
	file := handedBridge(t, rulesPath, handoverState{OldVersion: "1.0.0", OldBinary: "/usr/local/bin/loupe"})
	st, _ := readHandover(file)
	sock, _ := socketPath(rulesPath)

	b, ln, err := resumeBridge(newBridgeLogger(&syncBuffer{}), rulesPath, sock, file, "")
	if err != nil {
		t.Fatal(err)
	}
	defer b.lock.Close()
	defer b.close()
	defer ln.Close()

	if !lockTaken(t, rulesPath) {
		t.Fatal("the lock is free")
	}
	for _, fd := range []int{st.LockFD, st.ControlFD} {
		if open, kept := fdKeptAcrossExec(fd); !open || kept {
			t.Fatalf("fd %d open %v, kept across an exec %v", fd, open, kept)
		}
	}
	if b.target != "/usr/local/bin/loupe" || b.resumed.OldVersion != "1.0.0" || b.file != file {
		t.Fatalf("target = %q, resumed = %+v, file = %q", b.target, b.resumed, b.file)
	}
	accepted := make(chan error, 1)
	go func() {
		conn, err := ln.Accept()
		if err == nil {
			conn.Close()
		}
		accepted <- err
	}()
	conn, err := net.Dial("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	conn.Close()
	if err := <-accepted; err != nil {
		t.Fatal(err)
	}
}

// A descriptor that is not the lock holder is refused, so two bridges never
// share one rule file.
func TestResumeBridgeRefusesAFDThatHoldsNoLock(t *testing.T) {
	shortConfigHome(t)
	rulesPath := filepath.Join(t.TempDir(), "rules.yaml")
	file := handedBridge(t, rulesPath, handoverState{})
	st, _ := readHandover(file)
	other, err := os.Open(file)
	if err != nil {
		t.Fatal(err)
	}
	defer other.Close()
	st.LockFD = int(other.Fd())
	if err := writeHandover(file, st); err != nil {
		t.Fatal(err)
	}
	sock, _ := socketPath(rulesPath)

	_, _, err = resumeBridge(newBridgeLogger(&syncBuffer{}), rulesPath, sock, file, "")
	if err == nil || !strings.Contains(err.Error(), "is not the lock") {
		t.Fatalf("err = %v", err)
	}
}

func TestResumeBridgeRefusesAStandardFD(t *testing.T) {
	shortConfigHome(t)
	rulesPath := filepath.Join(t.TempDir(), "rules.yaml")
	file, _ := handoverPath(rulesPath)
	if err := os.MkdirAll(filepath.Dir(file), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := writeHandover(file, handoverState{Format: handoverFormat, ControlFD: 7}); err != nil {
		t.Fatal(err)
	}

	_, _, err := resumeBridge(newBridgeLogger(&syncBuffer{}), rulesPath, "sock", file, "")
	if err == nil || !strings.Contains(err.Error(), "above 2") {
		t.Fatalf("err = %v", err)
	}
}

// resumeHome logs in as testBridgeID against a fake Loupe, with a rule file
// and claude on PATH.
func resumeHome(t *testing.T) (*fakeLoupe, string) {
	t.Helper()
	keyring.MockInit()
	shortConfigHome(t)
	fake := &fakeLoupe{}
	server := httptest.NewServer(http.HandlerFunc(fake.serve))
	t.Cleanup(server.Close)
	cfg := testLogin(server.URL)
	cfg.BridgeID = testBridgeID
	if err := config.Save(cfg); err != nil {
		t.Fatal(err)
	}
	original := lookPath
	lookPath = func(string) (string, error) { return "/bin/claude", nil }
	t.Cleanup(func() { lookPath = original })

	return fake, writeRules(t, "loupe")
}

// captureExecFn replaces execFn with one that records each call and fails.
func captureExecFn(t *testing.T) func() []execCall {
	t.Helper()
	var mu sync.Mutex
	var calls []execCall
	original := execFn
	execFn = func(_ string, argv, _ []string) error {
		c := execCall{argv: slices.Clone(argv)}
		if i := slices.Index(argv, "--"+resumeHandoverFlag); i >= 0 && i+1 < len(argv) {
			c.st, c.readErr = readHandover(argv[i+1])
			c.lockOpen, c.lockExec = fdKeptAcrossExec(c.st.LockFD)
			c.ctlOpen, c.ctlExec = fdKeptAcrossExec(c.st.ControlFD)
		}
		mu.Lock()
		calls = append(calls, c)
		mu.Unlock()

		return errExecCaptured
	}
	t.Cleanup(func() { execFn = original })

	return func() []execCall {
		mu.Lock()
		defer mu.Unlock()

		return slices.Clone(calls)
	}
}

// runCommand runs `loupe bridge run` until ctx ends, and gives its output.
func runCommand(ctx context.Context, args ...string) (<-chan error, *syncBuffer) {
	out := &syncBuffer{}
	cmd := newBridgeRunCmd()
	cmd.SetArgs(args)
	cmd.SetOut(out)
	cmd.SetErr(&bytes.Buffer{})
	cmd.SilenceUsage, cmd.SilenceErrors = true, true
	done := make(chan error, 1)
	go func() { done <- cmd.ExecuteContext(ctx) }()

	return done, out
}

// logged reports whether the JSON log holds a line of event.
func logged(out *syncBuffer, event string) bool {
	return strings.Contains(out.String(), `"event":"`+event+`"`)
}

func versionDir(t *testing.T, dir, v string) string {
	t.Helper()
	path := filepath.Join(dir, "versions", v)
	if err := os.MkdirAll(path, 0o755); err != nil {
		t.Fatal(err)
	}

	return path
}

// A resumed image that connects and lands a heartbeat deletes the handover
// file, reports the update, installs its binary over the old one, keeps the
// two last versions, and serves the control socket it took over.
func TestAHealthyResumedBridgeAppliesTheUpdate(t *testing.T) {
	injectVersion(t, "1.2.0")
	_, rulesPath := resumeHome(t)
	calls := captureExecFn(t)
	dir, _ := config.Dir()
	target := filepath.Join(t.TempDir(), "loupe")
	if err := os.WriteFile(target, []byte("old"), 0o755); err != nil {
		t.Fatal(err)
	}
	versionDir(t, dir, "0.9.0")
	versionDir(t, dir, "1.0.0")
	file := handedBridge(t, rulesPath, handoverState{OldVersion: "1.0.0", OldBinary: target})

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done, out := runCommand(ctx, "--rules", rulesPath, "--resume-handover", file, "--log-file", filepath.Join(t.TempDir(), "bridge.log"))
	eventually(t, "the install", func() bool { return logged(out, "update_installed") || logged(out, "update_install_failed") })

	sock, _ := socketPath(rulesPath)
	if res := askControl(t, sock, `{"op":"nope"}`+"\n"); res.OK || len(res.Problems) != 1 {
		t.Fatalf("the control socket answered %+v", res)
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}

	if !logged(out, "update_applied") || !strings.Contains(out.String(), `"from":"1.0.0","to":"1.2.0"`) {
		t.Fatalf("log = %s", out.String())
	}
	if _, err := os.Stat(file); !os.IsNotExist(err) {
		t.Fatalf("the handover file is still there: %v", err)
	}
	exe, _ := os.Executable()
	want, _ := digest(exe)
	if got, _ := digest(target); !bytes.Equal(got, want) {
		t.Fatal("the installed binary is not the running one")
	}
	if _, err := os.Stat(filepath.Join(dir, "versions", "0.9.0")); !os.IsNotExist(err) {
		t.Fatalf("versions/0.9.0 is still there: %v", err)
	}
	if _, err := os.Stat(filepath.Join(dir, "versions", "1.0.0")); err != nil {
		t.Fatalf("versions/1.0.0 is gone: %v", err)
	}
	if len(calls()) != 0 {
		t.Fatalf("exec calls = %+v", calls())
	}
}

// A resumed image that gets no heartbeat through in time hands the bridge
// back to the old binary, which learns the version to skip.
func TestAnUnhealthyResumedBridgeHandsBack(t *testing.T) {
	injectVersion(t, "1.2.0")
	fake, rulesPath := resumeHome(t)
	fake.heartbeatStatus = http.StatusInternalServerError
	calls := captureExecFn(t)
	old := healthTimeout
	healthTimeout = 300 * time.Millisecond
	t.Cleanup(func() { healthTimeout = old })
	target := filepath.Join(t.TempDir(), "loupe")
	file := handedBridge(t, rulesPath, handoverState{OldVersion: "1.0.0", OldBinary: target, RecentIDs: []string{"id-1"}})

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done, out := runCommand(ctx, "--rules", rulesPath, "--resume-handover", file, "--log-file", filepath.Join(t.TempDir(), "bridge.log"))
	eventually(t, "the rollback exec", func() bool { return logged(out, "update_rollback_failed") })
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}

	got := calls()
	if len(got) != 1 {
		t.Fatalf("exec calls = %+v", got)
	}
	c := got[0]
	tail := []string{"--resume-handover", file, "--rolled-back-from", "1.2.0"}
	if c.argv[0] != target || !slices.Equal(c.argv[len(c.argv)-4:], tail) {
		t.Fatalf("argv = %q", c.argv)
	}
	if c.readErr != nil || c.st.OldVersion != "1.2.0" || c.st.OldBinary != target || !slices.Equal(c.st.RecentIDs, []string{"id-1"}) {
		t.Fatalf("state = %+v, %v", c.st, c.readErr)
	}
	if !c.lockOpen || !c.lockExec || !c.ctlOpen || !c.ctlExec {
		t.Fatalf("lock open %v kept %v, control open %v kept %v", c.lockOpen, c.lockExec, c.ctlOpen, c.ctlExec)
	}
	if !logged(out, "update_unhealthy") || logged(out, "update_applied") {
		t.Fatalf("log = %s", out.String())
	}
}

// A resumed image that fails before it adopts anything hands the untouched
// state back at once.
func TestAResumedBridgeThatCannotStartHandsBack(t *testing.T) {
	injectVersion(t, "1.2.0")
	_, rulesPath := resumeHome(t)
	lookPath = func(string) (string, error) { return "", errors.New("not found") }
	calls := captureExecFn(t)
	target := filepath.Join(t.TempDir(), "loupe")
	file := handedBridge(t, rulesPath, handoverState{OldVersion: "1.0.0", OldBinary: target, RecentIDs: []string{"id-1"}})

	done, out := runCommand(context.Background(), "--rules", rulesPath, "--resume-handover", file, "--log-file", filepath.Join(t.TempDir(), "bridge.log"))
	if err := <-done; err == nil || !strings.Contains(err.Error(), "claude is not installed") {
		t.Fatalf("err = %v", err)
	}

	got := calls()
	if len(got) != 1 || got[0].argv[0] != target || !slices.Contains(got[0].argv, "--rolled-back-from") {
		t.Fatalf("exec calls = %+v", got)
	}
	if !slices.Equal(got[0].st.RecentIDs, []string{"id-1"}) || got[0].st.OldVersion != "1.2.0" {
		t.Fatalf("state = %+v", got[0].st)
	}
	if !logged(out, "update_unhealthy") {
		t.Fatalf("log = %s", out.String())
	}
	if _, err := os.Stat(file); err != nil {
		t.Fatalf("the handover file is gone, so a restart cannot recover: %v", err)
	}
}

// The old binary that takes the bridge back skips the version it left and
// reports the rollback, and never hands over again when it is unhealthy.
func TestARolledBackBridgeSkipsTheVersionItLeft(t *testing.T) {
	injectVersion(t, "1.0.0")
	_, rulesPath := resumeHome(t)
	calls := captureExecFn(t)
	dir, _ := config.Dir()
	target := filepath.Join(t.TempDir(), "loupe")
	if err := os.WriteFile(target, []byte("old"), 0o755); err != nil {
		t.Fatal(err)
	}
	file := handedBridge(t, rulesPath, handoverState{OldVersion: "1.2.0", OldBinary: target})

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done, out := runCommand(ctx, "--rules", rulesPath, "--resume-handover", file, "--rolled-back-from", "1.2.0", "--log-file", filepath.Join(t.TempDir(), "bridge.log"))
	eventually(t, "the rollback line", func() bool { return logged(out, "update_rolled_back") })
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}

	if !strings.Contains(out.String(), `"from":"1.2.0","to":"1.0.0","reason":"health"`) || logged(out, "update_applied") {
		t.Fatalf("log = %s", out.String())
	}
	if st, _ := update.LoadState(dir); !st.Skipped("1.2.0") {
		t.Fatalf("update.json = %+v", st)
	}
	if data, _ := os.ReadFile(target); string(data) != "old" {
		t.Fatal("a rollback replaced the installed binary")
	}
	if _, err := os.Stat(file); !os.IsNotExist(err) {
		t.Fatalf("the handover file is still there: %v", err)
	}
	if len(calls()) != 0 {
		t.Fatalf("exec calls = %+v", calls())
	}
}

func TestARolledBackBridgeReportsTheVersionItLeft(t *testing.T) {
	shortConfigHome(t)
	dir, _ := config.Dir()
	log := newBridgeLogger(&syncBuffer{})
	u := newUpdater(log, "1.0.0", dir, func() bool { return true }, logStaged(log, "1.0.0"))
	file := filepath.Join(t.TempDir(), "handover.json")
	b := &bridgeUpdate{log: log, dir: dir, resumeFile: file, rolledBackFrom: "1.2.0", resumed: &handoverState{OldVersion: "1.2.0"}}

	b.healthy(u)
	u.setState(updateCurrent, "")

	if got := u.state(); got != (api.HeartbeatUpdate{State: "rolled-back", Version: "1.2.0"}) {
		t.Fatalf("state = %+v", got)
	}
}

// A bridge that died between a handover and its health leaves the file. The
// next plain start adopts its runs and removes it.
func TestAPlainStartRecoversALeftoverHandover(t *testing.T) {
	_, rulesPath := resumeHome(t)
	calls := captureExecFn(t)
	e := event.Event{Type: event.CardMovedType, Subject: event.Subject{Type: "card", ID: cardUUID(9)}, ProjectID: testProject, CardNumber: 9, FromStatus: "backlog", ToStatus: "next", Actor: event.ActorHuman}
	file, _ := handoverPath(rulesPath)
	st := handoverState{
		Format:     handoverFormat,
		OldVersion: "1.2.0",
		Running:    map[string]bool{cardUUID(9): true},
		Live:       []handoverRun{{RunID: "run-9", Key: cardUUID(9), Rule: "plan", Event: e, SessionID: testSession, Began: time.Now(), Dir: t.TempDir()}},
	}
	if err := writeHandover(file, st); err != nil {
		t.Fatal(err)
	}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done, out := runCommand(ctx, "--rules", rulesPath, "--log-file", filepath.Join(t.TempDir(), "bridge.log"))
	eventually(t, "the recovery", func() bool { return logged(out, "update_recovered") })
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}

	if !logged(out, "worker_adopted") || !strings.Contains(out.String(), `"live":1`) {
		t.Fatalf("log = %s", out.String())
	}
	if _, err := os.Stat(file); !os.IsNotExist(err) {
		t.Fatalf("the handover file is still there: %v", err)
	}
	if len(calls()) != 0 {
		t.Fatalf("exec calls = %+v", calls())
	}
}

func TestALeftoverHandoverItCannotReadMovesAside(t *testing.T) {
	file := filepath.Join(t.TempDir(), "handover.json")
	if err := os.WriteFile(file, []byte(`{"format":99}`), 0o600); err != nil {
		t.Fatal(err)
	}
	out := &syncBuffer{}

	if st := leftoverHandover(file, newBridgeLogger(out)); st != nil {
		t.Fatalf("state = %+v", st)
	}
	if _, err := os.Stat(file + ".bad"); err != nil {
		t.Fatal(err)
	}
	if !logged(out, "update_recovery_failed") {
		t.Fatalf("log = %s", out.String())
	}
	if st := leftoverHandover(filepath.Join(t.TempDir(), "none.json"), newBridgeLogger(out)); st != nil {
		t.Fatalf("a missing file gave %+v", st)
	}
}

// Two swaps at once both end with the staged bytes, and a target that holds
// them already is not written.
func TestSwapBinaryReplacesTheTargetOnce(t *testing.T) {
	dir := t.TempDir()
	staged := filepath.Join(dir, "versions", "1.2.0", "loupe")
	if err := update.WriteFile(staged, bytes.Repeat([]byte("new binary "), 100000), 0o755); err != nil {
		t.Fatal(err)
	}
	bin := t.TempDir()
	target := filepath.Join(bin, "loupe")
	if err := os.WriteFile(target, []byte("old"), 0o755); err != nil {
		t.Fatal(err)
	}
	link := filepath.Join(bin, "loupe-link")
	if err := os.Symlink(target, link); err != nil {
		t.Fatal(err)
	}

	var wg sync.WaitGroup
	errs := make(chan error, 2)
	for _, path := range []string{target, link} {
		wg.Add(1)
		go func() {
			defer wg.Done()
			_, err := swapBinary(dir, staged, path)
			errs <- err
		}()
	}
	wg.Wait()
	close(errs)
	for err := range errs {
		if err != nil {
			t.Fatal(err)
		}
	}

	want, _ := digest(staged)
	if got, _ := digest(target); !bytes.Equal(got, want) {
		t.Fatal("the target does not hold the staged bytes")
	}
	if info, err := os.Lstat(link); err != nil || info.Mode()&os.ModeSymlink == 0 {
		t.Fatalf("the symlink was replaced: %v", err)
	}
	if info, _ := os.Stat(target); info.Mode().Perm() != 0o755 {
		t.Fatalf("mode = %v", info.Mode())
	}
	past := time.Now().Add(-time.Hour).Truncate(time.Second)
	if err := os.Chtimes(target, past, past); err != nil {
		t.Fatal(err)
	}

	swapped, err := swapBinary(dir, staged, target)
	if err != nil || swapped {
		t.Fatalf("swapped = %v, err = %v", swapped, err)
	}
	if info, _ := os.Stat(target); !info.ModTime().Equal(past) {
		t.Fatalf("mtime = %v, want %v", info.ModTime(), past)
	}
	if left, _ := filepath.Glob(filepath.Join(bin, ".loupe-install-*")); len(left) != 0 {
		t.Fatalf("temp files left: %v", left)
	}
}

func TestPruneVersionsKeepsTheCurrentAndThePrevious(t *testing.T) {
	dir := t.TempDir()
	st := &update.State{Staged: map[string]string{}}
	for _, v := range []string{"0.9.0", "1.0.0", "1.2.0"} {
		versionDir(t, dir, v)
		st.Staged[v] = update.StagePath(dir, v)
	}
	if err := st.Save(dir); err != nil {
		t.Fatal(err)
	}

	if err := pruneVersions(dir, "1.2.0", "1.0.0"); err != nil {
		t.Fatal(err)
	}

	entries, _ := os.ReadDir(filepath.Join(dir, "versions"))
	var names []string
	for _, e := range entries {
		names = append(names, e.Name())
	}
	if !slices.Equal(names, []string{"1.0.0", "1.2.0"}) {
		t.Fatalf("versions = %v", names)
	}
	if got, _ := update.LoadState(dir); len(got.Staged) != 2 || got.Staged["0.9.0"] != "" {
		t.Fatalf("update.json = %+v", got)
	}
}

// A version string with a v prefix still keeps its directory.
func TestPruneVersionsReadsAVPrefix(t *testing.T) {
	dir := t.TempDir()
	versionDir(t, dir, "1.2.0")

	if err := pruneVersions(dir, "v1.2.0"); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(dir, "versions", "1.2.0")); err != nil {
		t.Fatal(err)
	}
}

// A resumed image that stops before its health has adopted the state, so the
// file must not reach the next start, which would run its queue again.
func TestAResumedBridgeThatStopsBeforeItsHealthRemovesTheFile(t *testing.T) {
	injectVersion(t, "1.2.0")
	fake, rulesPath := resumeHome(t)
	fake.heartbeatStatus = http.StatusInternalServerError
	captureExecFn(t)
	file := handedBridge(t, rulesPath, handoverState{OldVersion: "1.0.0", OldBinary: filepath.Join(t.TempDir(), "loupe")})

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done, out := runCommand(ctx, "--rules", rulesPath, "--resume-handover", file, "--log-file", filepath.Join(t.TempDir(), "bridge.log"))
	eventually(t, "the connection", func() bool { return logged(out, "connected") })
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(file); !os.IsNotExist(err) {
		t.Fatalf("the handover file is still there: %v", err)
	}
}

// The heartbeat interval can be as long as the health window, so a resumed
// image sends the heartbeat again until one lands, and one failure does not
// roll it back.
func TestAResumedBridgeRetriesAFailedFirstHeartbeat(t *testing.T) {
	injectVersion(t, "1.2.0")
	fake, rulesPath := resumeHome(t)
	fake.flags = `{"bridge.heartbeat_interval_seconds":3600}`
	fake.heartbeatFailures = 1
	calls := captureExecFn(t)
	oldTimeout, oldRetry := healthTimeout, healthBeatRetry
	healthTimeout, healthBeatRetry = 3*time.Second, 50*time.Millisecond
	t.Cleanup(func() { healthTimeout, healthBeatRetry = oldTimeout, oldRetry })
	file := handedBridge(t, rulesPath, handoverState{OldVersion: "1.0.0", OldBinary: filepath.Join(t.TempDir(), "loupe")})

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done, out := runCommand(ctx, "--rules", rulesPath, "--resume-handover", file, "--log-file", filepath.Join(t.TempDir(), "bridge.log"))
	eventually(t, "the health verdict", func() bool { return logged(out, "update_applied") || logged(out, "update_unhealthy") })
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}

	if !logged(out, "update_applied") || logged(out, "update_unhealthy") || len(calls()) != 0 {
		t.Fatalf("exec calls = %d, log = %s", len(calls()), out.String())
	}
}

func TestAResumedBridgeRetriesItsHeartbeatWhileTheStreamConnects(t *testing.T) {
	injectVersion(t, "1.2.0")
	fake, rulesPath := resumeHome(t)
	fake.flags = `{"bridge.heartbeat_interval_seconds":3600}`
	fake.heartbeatFailures = 1
	fake.hubDelay = 500 * time.Millisecond
	captureExecFn(t)
	oldTimeout, oldRetry := healthTimeout, healthBeatRetry
	healthTimeout, healthBeatRetry = 3*time.Second, 50*time.Millisecond
	t.Cleanup(func() { healthTimeout, healthBeatRetry = oldTimeout, oldRetry })
	file := handedBridge(t, rulesPath, handoverState{OldVersion: "1.0.0", OldBinary: filepath.Join(t.TempDir(), "loupe")})

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done, out := runCommand(ctx, "--rules", rulesPath, "--resume-handover", file, "--log-file", filepath.Join(t.TempDir(), "bridge.log"))
	eventually(t, "the health verdict", func() bool { return logged(out, "update_applied") || logged(out, "update_unhealthy") })
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}

	fake.mu.Lock()
	defer fake.mu.Unlock()
	if fake.heartbeatsAtHub < 2 {
		t.Fatalf("heartbeats before the stream connected = %d, want a retry", fake.heartbeatsAtHub)
	}
}

// stuckQueue is a report queue whose pending count a test sets.
type stuckQueue struct {
	syncQueue
	n *atomic.Int32
}

func (q stuckQueue) Pending() int { return int(q.n.Load()) }

// unhealthyResume is a resumed image that never connects, whose reports stay
// pending while stuck holds more than zero.
func unhealthyResume(t *testing.T) (*harness, *bridgeUpdate, *[]execCall, *atomic.Int32) {
	t.Helper()
	h, b, calls := newTestHandoff(t)
	stuck := &atomic.Int32{}
	stuck.Store(1)
	h.router.reports = stuckQueue{n: stuck}
	b.drainTimeout = 20 * time.Millisecond
	b.resumed = &handoverState{Format: handoverFormat, OldVersion: "1.0.0", OldBinary: b.target}
	b.resumeFile = b.file
	old := healthTimeout
	healthTimeout = 100 * time.Millisecond
	t.Cleanup(func() { healthTimeout = old })

	return h, b, calls, stuck
}

// A rollback whose reports cannot leave waits for another health window,
// because an exec would lose them. Once they leave, a missed deadline rolls
// back.
func TestARollbackWaitsForTheReportsToDrain(t *testing.T) {
	injectVersion(t, "1.2.0")
	h, b, calls, stuck := unhealthyResume(t)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	if err := writeHandover(b.resumeFile, *b.resumed); err != nil {
		t.Fatal(err)
	}

	done := b.watchHealth(ctx, h.router, nil)
	eventually(t, "a deferred rollback", func() bool { return logged(h.log, "update_rollback_deferred") })
	if len(*calls) != 0 {
		t.Fatalf("exec calls = %d while reports wait", len(*calls))
	}
	if paused, frozen := h.frozen(); paused || frozen {
		t.Fatal("a deferred rollback left the router paused")
	}
	// The router runs on, so a crash must not adopt the old state again.
	if _, err := os.Stat(b.resumeFile); !os.IsNotExist(err) {
		t.Fatalf("the stale handover file is still there: %v", err)
	}
	stuck.Store(0)
	<-done

	if len(*calls) != 1 || (*calls)[0].argv[0] != b.target || !slices.Contains((*calls)[0].argv, "--rolled-back-from") {
		t.Fatalf("exec calls = %+v", *calls)
	}
	if c := (*calls)[0]; c.readErr != nil || c.st.OldVersion != "1.2.0" || c.st.LockFD <= 2 {
		t.Fatalf("the rollback passed no fresh file: %+v, %v", c.st, c.readErr)
	}
}

// lateQueue is empty at its first read and then holds what stuck holds, as
// a report a run queues between the drain and the freeze.
type lateQueue struct {
	syncQueue
	reads, stuck *atomic.Int32
}

func (q lateQueue) Pending() int {
	if q.reads.Add(1) == 1 {
		return 0
	}

	return int(q.stuck.Load())
}

// A report that arrives after the first drain also defers the rollback, as
// the drain after the freeze catches it.
func TestARollbackDrainsAgainAfterTheFreeze(t *testing.T) {
	injectVersion(t, "1.2.0")
	h, b, calls, stuck := unhealthyResume(t)
	h.router.reports = lateQueue{reads: &atomic.Int32{}, stuck: stuck}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	done := b.watchHealth(ctx, h.router, nil)
	eventually(t, "a deferred rollback", func() bool { return logged(h.log, "update_rollback_deferred") })
	if len(*calls) != 0 {
		t.Fatalf("exec calls = %d while a report waits", len(*calls))
	}
	if paused, frozen := h.frozen(); paused || frozen {
		t.Fatal("a deferred rollback left the router frozen")
	}
	stuck.Store(0)
	<-done

	if len(*calls) != 1 || (*calls)[0].argv[0] != b.target {
		t.Fatalf("exec calls = %+v", *calls)
	}
}

// Health that arrives in a later window applies the update.
func TestHealthAfterADeferredRollbackAppliesTheUpdate(t *testing.T) {
	injectVersion(t, "1.2.0")
	h, b, calls, _ := unhealthyResume(t)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	done := b.watchHealth(ctx, h.router, nil)
	eventually(t, "a deferred rollback", func() bool { return logged(h.log, "update_rollback_deferred") })
	b.markConnected()
	<-done

	if !logged(h.log, "update_applied") || len(*calls) != 0 {
		t.Fatalf("exec calls = %d, log = %s", len(*calls), h.log.String())
	}
}
