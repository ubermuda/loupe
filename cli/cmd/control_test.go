package cmd

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// shortConfigHome points the config dir at a short temp dir. A socket path
// has a length limit, and t.TempDir can pass it. os.UserConfigDir reads HOME
// on macOS.
func shortConfigHome(t *testing.T) {
	t.Helper()
	dir, err := os.MkdirTemp("/tmp", "lb")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.RemoveAll(dir) })
	t.Setenv("XDG_CONFIG_HOME", dir)
	t.Setenv("HOME", dir)
}

func testSocket(t *testing.T) string {
	t.Helper()
	shortConfigHome(t)
	path, err := socketPath("rules.yaml")
	if err != nil {
		t.Fatal(err)
	}

	return path
}

// serveTest listens on a fresh socket with handle, and stops when the test ends.
func serveTest(t *testing.T, handle func(context.Context) reloadResult) (string, context.CancelFunc, <-chan struct{}) {
	t.Helper()
	path := testSocket(t)
	ln, err := listenControl(path)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := serveControl(ctx, ln, handle)
	t.Cleanup(func() {
		cancel()
		<-done
	})

	return path, cancel, done
}

// askControl sends line and decodes the one line that comes back.
func askControl(t *testing.T, path, line string) reloadResult {
	t.Helper()
	conn, err := net.Dial("unix", path)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	conn.SetDeadline(time.Now().Add(10 * time.Second))
	if _, err := conn.Write([]byte(line)); err != nil {
		t.Fatal(err)
	}
	reply, err := bufio.NewReader(conn).ReadString('\n')
	if err != nil {
		t.Fatalf("read %q: %v", reply, err)
	}
	var res reloadResult
	if err := json.Unmarshal([]byte(reply), &res); err != nil {
		t.Fatalf("reply %q: %v", reply, err)
	}

	return res
}

func TestSocketPathFollowsTheAbsoluteRuleFilePath(t *testing.T) {
	shortConfigHome(t)
	t.Chdir(t.TempDir())
	abs, err := filepath.Abs("rules.yaml")
	if err != nil {
		t.Fatal(err)
	}

	rel, _ := socketPath("rules.yaml")
	full, _ := socketPath(abs)
	other, _ := socketPath(abs + ".other")
	if rel != full || rel == other || rel == "" {
		t.Fatalf("relative %q, absolute %q, other %q", rel, full, other)
	}
	if !strings.HasPrefix(filepath.Base(rel), "bridge-") || !strings.HasSuffix(rel, ".sock") || len(filepath.Base(rel)) != len("bridge-")+12+len(".sock") {
		t.Fatalf("socketPath = %q", rel)
	}
}

// symlinkedRules makes two rule files and a link to the first.
func symlinkedRules(t *testing.T) (file, other, link string) {
	t.Helper()
	shortConfigHome(t)
	dir := t.TempDir()
	file = filepath.Join(dir, "rules.yaml")
	other = filepath.Join(dir, "other.yaml")
	link = filepath.Join(dir, "link.yaml")
	for _, path := range []string{file, other} {
		if err := os.WriteFile(path, nil, 0o600); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.Symlink(file, link); err != nil {
		t.Fatal(err)
	}

	return file, other, link
}

// A reload names the rule file as the bridge got it. When the operator
// repoints a link, the reload must still reach the running bridge.
func TestSocketPathKeepsTheGivenPathOfASymlink(t *testing.T) {
	file, other, link := symlinkedRules(t)

	viaFile, _ := socketPath(file)
	before, _ := socketPath(link)
	if err := os.Remove(link); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(other, link); err != nil {
		t.Fatal(err)
	}
	after, _ := socketPath(link)
	if before != after || before == viaFile || before == "" {
		t.Fatalf("file %q, link before %q, link after %q", viaFile, before, after)
	}
}

func TestLockBridgeAllowsOneBridgePerRuleFile(t *testing.T) {
	file, other, link := symlinkedRules(t)
	lock, _ := lockPath(file)

	first, err := lockBridge(file, "/tmp/first.sock")
	if err != nil {
		t.Fatal(err)
	}
	for _, path := range []string{file, link} {
		_, err := lockBridge(path, "/tmp/second.sock")
		if err == nil || !strings.Contains(err.Error(), lock) {
			t.Fatalf("lock %s: err = %v, want it to name %s", path, err, lock)
		}
		if runtime.GOOS != "windows" && !strings.Contains(err.Error(), "/tmp/first.sock") {
			t.Fatalf("lock %s: err = %v, want it to name the socket of the first bridge", path, err)
		}
	}
	elsewhere, err := lockBridge(other, "/tmp/other.sock")
	if err != nil {
		t.Fatalf("another rule file: %v", err)
	}
	elsewhere.Close()

	first.Close()
	again, err := lockBridge(link, "/tmp/second.sock")
	if err != nil {
		t.Fatalf("after the release: %v", err)
	}
	again.Close()
}

// lockTaken reports whether a bridge holds the lock of rulesPath.
func lockTaken(t *testing.T, rulesPath string) bool {
	t.Helper()
	lock, err := lockBridge(rulesPath, "/tmp/probe.sock")
	if err != nil {
		return true
	}
	lock.Close()

	return false
}

// repoint makes link name target.
func repoint(t *testing.T, link, target string) {
	t.Helper()
	if err := os.Remove(link); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(target, link); err != nil {
		t.Fatal(err)
	}
}

// A reload after a repoint reads the new target, so the lock moves there. A
// reload that fails keeps the old lock.
func TestAReloadMovesTheLockToTheNewTarget(t *testing.T) {
	file, other, link := symlinkedRules(t)
	h := newHarness(t)
	lock, err := lockBridge(link, "/tmp/bridge.sock")
	if err != nil {
		t.Fatal(err)
	}
	defer lock.Close()
	repoint(t, link, other)

	src := h.source("projects: {}\nrules: []\n")
	src.lock = lock.follow
	if res := h.router.reload(context.Background(), src); res.OK || res.Stage != "parse" {
		t.Fatalf("result = %+v, want a parse failure", res)
	}
	if !lockTaken(t, file) || lockTaken(t, other) {
		t.Fatal("a failed reload must keep the old lock and free the new one")
	}

	src = h.source(twoRuleFile)
	src.lock = lock.follow
	if res := h.router.reload(context.Background(), src); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	if lockTaken(t, file) || !lockTaken(t, other) {
		t.Fatal("an applied reload must hold the new lock and free the old one")
	}
}

func TestAReloadOntoTheRuleFileOfAnotherBridgeFails(t *testing.T) {
	file, other, link := symlinkedRules(t)
	h := newHarness(t)
	old := h.router.rules()
	lock, err := lockBridge(link, "/tmp/bridge.sock")
	if err != nil {
		t.Fatal(err)
	}
	defer lock.Close()
	second, err := lockBridge(other, "/tmp/second.sock")
	if err != nil {
		t.Fatal(err)
	}
	defer second.Close()
	repoint(t, link, other)

	src := h.source(twoRuleFile)
	src.lock = lock.follow
	res := h.router.reload(context.Background(), src)

	if res.OK || res.Stage != "lock" || len(res.Problems) != 1 || !strings.Contains(res.Problems[0], "another bridge") {
		t.Fatalf("result = %+v, want a lock failure", res)
	}
	if runtime.GOOS != "windows" && !strings.Contains(res.Problems[0], "/tmp/second.sock") {
		t.Fatalf("problem = %q, want it to name the other bridge", res.Problems[0])
	}
	if h.router.rules() != old {
		t.Fatal("a failed reload swapped the set")
	}
	second.Close()
	if !lockTaken(t, file) {
		t.Fatal("a failed reload must keep the old lock")
	}
}

// The link moves again after the reload takes the new lock, so the loaded file
// may not be the locked one. The reload fails and keeps the old lock.
func TestAReloadWhoseRuleFileMovesFails(t *testing.T) {
	file, other, link := symlinkedRules(t)
	h := newHarness(t)
	old := h.router.rules()
	lock, err := lockBridge(link, "/tmp/bridge.sock")
	if err != nil {
		t.Fatal(err)
	}
	defer lock.Close()
	repoint(t, link, other)
	calls := 0
	lock.resolve = func(path string) (string, error) {
		calls++
		if calls > 1 {
			return lockPath(file)
		}

		return lockPath(path)
	}

	src := h.source(twoRuleFile)
	src.lock = lock.follow
	res := h.router.reload(context.Background(), src)

	if res.OK || res.Stage != "lock" || len(res.Problems) != 1 || !strings.Contains(res.Problems[0], "moved during the reload") {
		t.Fatalf("result = %+v, want a lock failure", res)
	}
	if h.router.rules() != old {
		t.Fatal("a failed reload swapped the set")
	}
	if !lockTaken(t, file) || lockTaken(t, other) {
		t.Fatal("a failed reload must keep the old lock and free the new one")
	}
}

// A bridge that crashed leaves its socket file behind. Nothing listens on it,
// so the next bridge removes it and starts.
func TestListenControlRemovesAStaleSocket(t *testing.T) {
	path := testSocket(t)
	ln, err := listenControl(path)
	if err != nil {
		t.Fatal(err)
	}
	ln.(*net.UnixListener).SetUnlinkOnClose(false)
	ln.Close()
	if _, err := os.Stat(path); err != nil {
		t.Fatalf("the stale file must stay for the test: %v", err)
	}

	ln, err = listenControl(path)
	if err != nil {
		t.Fatal(err)
	}
	ln.Close()
}

// A link that the operator repoints gives a second bridge its own lock, and
// the same socket path. The second bridge must not take the socket.
func TestListenControlRefusesASecondBridge(t *testing.T) {
	path := testSocket(t)
	ln, err := listenControl(path)
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close()

	if _, err := listenControl(path); err == nil || !strings.Contains(err.Error(), path) {
		t.Fatalf("err = %v, want it to name %s", err, path)
	}
}

func TestListenControlRefusesATooLongPath(t *testing.T) {
	path := "/tmp/" + strings.Repeat("a", 120) + ".sock"

	if _, err := listenControl(path); err == nil || !strings.Contains(err.Error(), path) || !strings.Contains(err.Error(), "104") {
		t.Fatalf("err = %v", err)
	}
}

func TestServeControlAnswersOnlyAReload(t *testing.T) {
	want := reloadResult{OK: true, Added: []string{"plan"}, Projects: []string{"loupe"}}
	var calls atomic.Int32
	path, _, _ := serveTest(t, func(context.Context) reloadResult {
		calls.Add(1)

		return want
	})

	for name, line := range map[string]string{
		"unknown op": `{"op":"stop"}` + "\n",
		"malformed":  "reload\n",
		"oversized":  `{"op":"reload","pad":"` + strings.Repeat("x", 1100) + `"}` + "\n",
	} {
		if res := askControl(t, path, line); res.OK || len(res.Problems) != 1 {
			t.Fatalf("%s: %+v", name, res)
		}
	}
	if n := calls.Load(); n != 0 {
		t.Fatalf("the handler ran %d times for a bad request", n)
	}

	res := askControl(t, path, `{"op":"reload"}`+"\n")
	if !res.OK || calls.Load() != 1 || res.Added[0] != "plan" || res.Projects[0] != "loupe" {
		t.Fatalf("res = %+v, calls = %d", res, calls.Load())
	}
}

func TestServeControlRemovesTheSocketWhenItStops(t *testing.T) {
	path, cancel, done := serveTest(t, func(context.Context) reloadResult { return reloadResult{OK: true} })

	cancel()
	<-done
	if _, err := os.Stat(path); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("stat = %v", err)
	}
}

// reloadCmd runs `loupe bridge reload` with args, and returns its stdout.
func reloadCmd(t *testing.T, args ...string) (string, string, error) {
	t.Helper()
	root := newRootCmd()
	var out, errOut bytes.Buffer
	root.SetOut(&out)
	root.SetErr(&errOut)
	root.SetArgs(append([]string{"bridge", "reload"}, args...))
	err := root.Execute()

	return out.String(), errOut.String(), err
}

func TestBridgeReloadPrintsWhatChanged(t *testing.T) {
	serveTest(t, func(context.Context) reloadResult {
		return reloadResult{OK: true, Added: []string{"plan", "build"}, Changed: []string{"fix"}, Projects: []string{"loupe", "other"}}
	})
	abs, _ := filepath.Abs("rules.yaml")

	out, errOut, err := reloadCmd(t, "--rules", "rules.yaml")
	if err != nil || errOut != "" {
		t.Fatalf("err = %v, stderr = %q", err, errOut)
	}
	if want := "reloaded " + abs + "\nadded: plan, build\nchanged: fix\nprojects: loupe, other\n"; out != want {
		t.Fatalf("stdout = %q, want %q", out, want)
	}
}

func TestBridgeReloadSaysWhenNoRuleChanged(t *testing.T) {
	serveTest(t, func(context.Context) reloadResult { return reloadResult{OK: true, Projects: []string{"loupe"}} })

	out, _, err := reloadCmd(t, "--rules", "rules.yaml")
	if err != nil || !strings.HasSuffix(out, "\nno rule changed\nprojects: loupe\n") {
		t.Fatalf("err = %v, stdout = %q", err, out)
	}
}

func TestBridgeReloadPrintsAChangedDir(t *testing.T) {
	serveTest(t, func(context.Context) reloadResult {
		return reloadResult{OK: true, Dirs: []string{"loupe", "other"}, Projects: []string{"loupe", "other"}}
	})
	abs, _ := filepath.Abs("rules.yaml")

	out, _, err := reloadCmd(t, "--rules", "rules.yaml")
	if want := "reloaded " + abs + "\ndir changed: loupe, other\nprojects: loupe, other\n"; err != nil || out != want {
		t.Fatalf("err = %v, stdout = %q, want %q", err, out, want)
	}
}

func TestBridgeReloadPrintsEachProblemAndFails(t *testing.T) {
	serveTest(t, func(context.Context) reloadResult {
		return reloadResult{Stage: "check", Problems: []string{"rule plan: no column next", "rule fix: no project x"}}
	})

	out, errOut, err := reloadCmd(t, "--rules", "rules.yaml")
	if err == nil || out != "" {
		t.Fatalf("err = %v, stdout = %q", err, out)
	}
	if want := "check: rule plan: no column next\ncheck: rule fix: no project x\n"; errOut != want {
		t.Fatalf("stderr = %q, want %q", errOut, want)
	}
}

func TestBridgeReloadPrintsAProblemWithNoStage(t *testing.T) {
	serveTest(t, func(context.Context) reloadResult {
		return reloadResult{Problems: []string{"a reload is already running"}}
	})

	_, errOut, err := reloadCmd(t, "--rules", "rules.yaml")
	if err == nil || errOut != "a reload is already running\n" {
		t.Fatalf("err = %v, stderr = %q", err, errOut)
	}
}

func TestBridgeReloadNeedsARunningBridge(t *testing.T) {
	shortConfigHome(t)
	abs, _ := filepath.Abs("rules.yaml")

	_, _, err := reloadCmd(t, "--rules", "rules.yaml")
	if err == nil || err.Error() != "no running bridge reads "+abs {
		t.Fatalf("err = %v", err)
	}
}

// A running bridge answers a reload on its socket, and removes the socket when
// it stops.
func TestTheBridgeAnswersAReloadOnItsSocket(t *testing.T) {
	fake := &fakeLoupe{}
	server := httptest.NewServer(http.HandlerFunc(fake.serve))
	t.Cleanup(server.Close)
	cfg := testLogin(server.URL)
	path := filepath.Join(t.TempDir(), "rules.yaml")
	body := "projects:\n  loupe:\n    dir: " + t.TempDir() + "\nrules:\n" +
		"  - name: plan\n    on: board.card_moved\n    project: loupe\n    to: next\n    prompt: go\n"
	if err := os.WriteFile(path, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
	set, err := rules.Load(path, rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	if err := set.Check(context.Background(), apiClient(cfg)); err != nil {
		t.Fatal(err)
	}
	sock := testSocket(t)
	control, err := listenControl(sock)
	if err != nil {
		t.Fatal(err)
	}
	log := &syncBuffer{}
	r := withRules(&router{log: newBridgeLogger(log), maxWorkers: defaultMaxWorkers, worker: (&fakeWorker{result: finishedRun}).ops(), control: control, source: newReloadSource(path, rules.Defaults{}, cfg, nil)}, set)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	cmd := &cobra.Command{}
	cmd.SetContext(ctx)
	done := make(chan error, 1)
	go func() { done <- subscribe(cmd, cfg, r) }()
	eventually(t, "the control socket", func() bool {
		return strings.Contains(log.String(), `"event":"control_listening"`)
	})

	if res := askControl(t, sock, `{"op":"reload"}`+"\n"); !res.OK || len(res.Projects) != 1 || res.Projects[0] != "loupe" {
		t.Fatalf("res = %+v, log = %s", res, log.String())
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(sock); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("stat = %v", err)
	}
}
