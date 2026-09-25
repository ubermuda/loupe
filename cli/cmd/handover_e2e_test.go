//go:build unix

package cmd

import (
	"archive/tar"
	"bufio"
	"bytes"
	"compress/gzip"
	"crypto/sha256"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/update"
)

const (
	e2eOld = "1.0.0"
	e2eNew = "1.1.0"
	// e2eWait bounds each wait of the handover tests for the real processes.
	e2eWait = 30 * time.Second
)

// e2eClaude records the pid of its worker shell, which leads the worker's
// process group, and waits for the test to release it.
const e2eClaude = `#!/bin/sh
eval "card=\${$#}"; card=${card%%[!0-9]*}
echo $PPID > "$LOUPE_E2E_DIR/started-$card.tmp" && mv "$LOUPE_E2E_DIR/started-$card.tmp" "$LOUPE_E2E_DIR/started-$card"
n=0
while [ ! -e "$LOUPE_E2E_DIR/release" ] && [ $n -lt 600 ]; do sleep 0.1; n=$((n+1)); done
echo "STAGE RESULT: done $card"
exit "$(cat "$LOUPE_E2E_DIR/exit-$card" 2>/dev/null || echo 0)"
`

// TestHandoverEndToEnd runs two real builds of the CLI. The bridge of the old
// build finds the new one on a fake GitHub and execs it with two workers in
// flight. LOUPE_E2E_OLD and LOUPE_E2E_NEW name prebuilt binaries instead.
func TestHandoverEndToEnd(t *testing.T) {
	if testing.Short() {
		t.Skip("runs real builds of the CLI")
	}
	old, next := os.Getenv("LOUPE_E2E_OLD"), os.Getenv("LOUPE_E2E_NEW")
	if old == "" || next == "" {
		if _, err := exec.LookPath("go"); err != nil {
			t.Skip("go is not on PATH, and LOUPE_E2E_OLD and LOUPE_E2E_NEW are not set")
		}
		old, next = buildVersions(t)
	}

	t.Run("forward", func(t *testing.T) { testHandoverForward(t, old, next) })
	t.Run("rollback", func(t *testing.T) { testHandoverRollback(t, old, next) })
}

func buildVersions(t *testing.T) (string, string) {
	t.Helper()
	root, err := filepath.Abs("..")
	if err != nil {
		t.Fatal(err)
	}
	dir := t.TempDir()
	paths := []string{filepath.Join(dir, "loupe-"+e2eOld), filepath.Join(dir, "loupe-"+e2eNew)}
	errs := make([]error, 2)
	var wg sync.WaitGroup
	for i, v := range []string{e2eOld, e2eNew} {
		wg.Go(func() {
			cmd := exec.Command("go", "build", "-o", paths[i], "-ldflags", "-X github.com/ubermuda/loupe/cli/cmd.version="+v, ".")
			cmd.Dir = root
			cmd.Env = append(os.Environ(), "CGO_ENABLED=0")
			if out, err := cmd.CombinedOutput(); err != nil {
				errs[i] = fmt.Errorf("build %s: %w: %s", v, err, out)
			}
		})
	}
	wg.Wait()
	if err := errors.Join(errs...); err != nil {
		t.Fatal(err)
	}

	return paths[0], paths[1]
}

// e2eLoupe is a Loupe instance, its Mercure hub and GitHub, as far as a
// bridge that updates itself reaches them.
type e2eLoupe struct {
	archiveName string
	archive     []byte
	checksums   []byte
	// gate holds the releases list back until the test opens it.
	gate     chan struct{}
	gateOnce sync.Once
	// failBeatsOf answers 500 to each heartbeat of this CLI version.
	failBeatsOf string

	mu      sync.Mutex
	events  []heldEvent
	notify  chan struct{}
	lastIDs []string
	reports []api.RunStateReport
	beats   []api.Heartbeat
	unknown []string
}

func newE2ELoupe(t *testing.T, newBinary string) *e2eLoupe {
	t.Helper()
	bin, err := os.ReadFile(newBinary)
	if err != nil {
		t.Fatal(err)
	}
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	if err := tw.WriteHeader(&tar.Header{Name: "loupe", Mode: 0o755, Size: int64(len(bin)), Typeflag: tar.TypeReg}); err != nil {
		t.Fatal(err)
	}
	if _, err := tw.Write(bin); err != nil {
		t.Fatal(err)
	}
	if err := errors.Join(tw.Close(), gz.Close()); err != nil {
		t.Fatal(err)
	}
	v, _ := update.ParseVersion(e2eNew)
	f := &e2eLoupe{
		archiveName: update.AssetName(v, runtime.GOOS, runtime.GOARCH),
		archive:     buf.Bytes(),
		gate:        make(chan struct{}),
		notify:      make(chan struct{}),
	}
	f.checksums = fmt.Appendf(nil, "%x  %s\n", sha256.Sum256(f.archive), f.archiveName)

	return f
}

func (f *e2eLoupe) open() { f.gateOnce.Do(func() { close(f.gate) }) }

func (f *e2eLoupe) serve(w http.ResponseWriter, r *http.Request) {
	base := "http://" + r.Host
	path := r.URL.Path
	switch {
	case path == "/api/projects/loupe/board/columns":
		fmt.Fprintf(w, `{"project":{"id":%q,"slug":"loupe"},"columns":[{"slug":"next"}]}`, testProject)
	case path == "/api/events":
		fmt.Fprintf(w, `{"hubUrl":%q,"jwt":"jwt","topic":%q,"projects":[{"id":%q,"slug":"loupe","name":"Loupe"}]}`, base+"/hub", userTopic, testProject)
	case path == "/hub":
		f.stream(w, r)
	case r.Method == http.MethodPut && path == "/api/bridges/"+testBridgeID+"/heartbeat":
		var hb api.Heartbeat
		_ = json.NewDecoder(r.Body).Decode(&hb)
		f.mu.Lock()
		f.beats = append(f.beats, hb)
		f.mu.Unlock()
		if hb.CLIVersion == f.failBeatsOf {
			w.WriteHeader(http.StatusInternalServerError)

			return
		}
		fmt.Fprint(w, `{"cliRange":"^1.0"}`)
	case r.Method == http.MethodPut && path == "/api/bridges/"+testBridgeID+"/runs":
		w.WriteHeader(http.StatusNoContent)
	case r.Method == http.MethodPut && strings.HasSuffix(path, "/bridges/"+testBridgeID+"/rules"):
		w.WriteHeader(http.StatusNoContent)
	case r.Method == http.MethodPut && strings.Contains(path, "/worker-runs/"):
		var report api.RunStateReport
		_ = json.NewDecoder(r.Body).Decode(&report)
		f.mu.Lock()
		f.reports = append(f.reports, report)
		f.mu.Unlock()
		w.WriteHeader(http.StatusCreated)
	case path == "/github/repos/ubermuda/loupe/releases":
		select {
		case <-f.gate:
		case <-r.Context().Done():
			return
		}
		fmt.Fprintf(w, `[{"tag_name":"v%s","assets":[{"name":%q,"browser_download_url":%q},{"name":"checksums.txt","browser_download_url":%q}]}]`,
			e2eNew, f.archiveName, base+"/github/assets/"+f.archiveName, base+"/github/assets/checksums.txt")
	case path == "/github/assets/"+f.archiveName:
		w.Write(f.archive)
	case path == "/github/assets/checksums.txt":
		w.Write(f.checksums)
	default:
		f.mu.Lock()
		f.unknown = append(f.unknown, r.Method+" "+path)
		f.mu.Unlock()
		w.WriteHeader(http.StatusNotFound)
	}
}

// stream replays the events after Last-Event-ID, as Mercure does, then sends
// each new one until the client leaves.
func (f *e2eLoupe) stream(w http.ResponseWriter, r *http.Request) {
	last := r.Header.Get("Last-Event-ID")
	f.mu.Lock()
	f.lastIDs = append(f.lastIDs, last)
	next := len(f.events)
	for i, e := range f.events {
		if last != "" && e.id == last {
			next = i + 1
		}
	}
	f.mu.Unlock()
	w.Header().Set("Content-Type", "text/event-stream")
	w.WriteHeader(http.StatusOK)
	flusher := w.(http.Flusher)
	flusher.Flush()
	for {
		f.mu.Lock()
		pending, notify := f.events[next:], f.notify
		next = len(f.events)
		f.mu.Unlock()
		for _, e := range pending {
			fmt.Fprintf(w, "id: %s\ndata: %s\n\n", e.id, e.data)
		}
		flusher.Flush()
		select {
		case <-notify:
		case <-r.Context().Done():
			return
		}
	}
}

// push publishes a card move and returns its id.
func (f *e2eLoupe) push(card int) string {
	f.mu.Lock()
	defer f.mu.Unlock()
	id := "urn:uuid:event-" + strconv.Itoa(len(f.events)+1)
	f.events = append(f.events, heldEvent{id: id, data: []byte(cardMoved(card))})
	close(f.notify)
	f.notify = make(chan struct{})

	return id
}

func (f *e2eLoupe) connections() []string {
	f.mu.Lock()
	defer f.mu.Unlock()

	return append([]string(nil), f.lastIDs...)
}

// report is the last report of card in state, and whether there is one.
func (f *e2eLoupe) report(card int, states ...string) (api.RunStateReport, bool) {
	f.mu.Lock()
	defer f.mu.Unlock()
	for i := len(f.reports) - 1; i >= 0; i-- {
		rep := f.reports[i]
		for _, s := range states {
			if rep.CardNumber == card && rep.State == s {
				return rep, true
			}
		}
	}

	return api.RunStateReport{}, false
}

// e2eBridge is one bridge process, started from the old binary.
type e2eBridge struct {
	t         *testing.T
	fake      *e2eLoupe
	dir       string
	configDir string
	installed string
	logPath   string
	stdout    string
	cmd       *exec.Cmd
	exited    chan struct{}
}

// startE2EBridge sets up a home with a login and a rule file, a fake claude
// on PATH and the old binary installed, and starts `bridge run`.
func startE2EBridge(t *testing.T, fake *e2eLoupe, old string, env ...string) *e2eBridge {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(fake.serve))
	t.Cleanup(server.Close)
	t.Cleanup(fake.open)

	// A unix socket path has a length limit, and t.TempDir can pass it.
	home, err := os.MkdirTemp("/tmp", "le2e")
	if err != nil {
		t.Fatal(err)
	}
	t.Setenv("HOME", home)
	t.Setenv("XDG_CONFIG_HOME", filepath.Join(home, ".config"))
	configDir, err := config.Dir()
	if err != nil {
		t.Fatal(err)
	}
	b := &e2eBridge{t: t, fake: fake, dir: filepath.Join(home, "e2e"), configDir: configDir, exited: make(chan struct{})}
	for _, d := range []string{configDir, b.dir, filepath.Join(home, "bin"), filepath.Join(home, "work")} {
		if err := os.MkdirAll(d, 0o700); err != nil {
			t.Fatal(err)
		}
	}
	cfg := config.Config{
		BaseURL:  server.URL,
		OAuth:    &config.OAuthTokens{AccessToken: "access", RefreshToken: "refresh", ExpiresAt: time.Now().Add(time.Hour)},
		BridgeID: testBridgeID,
	}
	raw, _ := json.Marshal(cfg)
	rulesPath := filepath.Join(home, "rules.yaml")
	rulesBody := "projects:\n  loupe:\n    dir: " + filepath.Join(home, "work") + "\nrules:\n" +
		"  - on: board.card_moved\n    project: loupe\n    to: next\n    prompt: \"{cardNumber}\"\n"
	b.installed = filepath.Join(home, "bin", "loupe")
	oldBytes, err := os.ReadFile(old)
	if err != nil {
		t.Fatal(err)
	}
	for path, body := range map[string][]byte{
		filepath.Join(configDir, "config.json"): raw,
		rulesPath:                               []byte(rulesBody),
		filepath.Join(b.dir, "claude"):          []byte(e2eClaude),
		b.installed:                             oldBytes,
	} {
		if err := os.WriteFile(path, body, 0o700); err != nil {
			t.Fatal(err)
		}
	}
	t.Setenv("PATH", b.dir+string(os.PathListSeparator)+os.Getenv("PATH"))
	t.Setenv("LOUPE_E2E_DIR", b.dir)
	t.Setenv("LOUPE_UPDATE_API", server.URL+"/github")

	b.logPath = filepath.Join(home, "bridge.log")
	b.stdout = filepath.Join(home, "stdout")
	out, err := os.Create(b.stdout)
	if err != nil {
		t.Fatal(err)
	}
	defer out.Close()
	b.cmd = exec.Command(b.installed, "bridge", "run", "--rules", rulesPath, "--log-file", b.logPath)
	b.cmd.Env = append(os.Environ(), env...)
	b.cmd.Stdout, b.cmd.Stderr = out, out
	b.cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	if err := b.cmd.Start(); err != nil {
		t.Fatal(err)
	}
	go func() {
		_ = b.cmd.Wait()
		close(b.exited)
	}()
	t.Cleanup(func() { b.stop(home) })

	return b
}

// stop releases the workers, ends the bridge and kills whatever it left.
func (b *e2eBridge) stop(home string) {
	_ = os.WriteFile(filepath.Join(b.dir, "release"), nil, 0o600)
	pid := b.cmd.Process.Pid
	_ = syscall.Kill(-pid, syscall.SIGTERM)
	select {
	case <-b.exited:
	case <-time.After(15 * time.Second):
		_ = syscall.Kill(-pid, syscall.SIGKILL)
		<-b.exited
	}
	started, _ := filepath.Glob(filepath.Join(b.dir, "started-*"))
	for _, path := range started {
		if raw, err := os.ReadFile(path); err == nil {
			if pgid, err := strconv.Atoi(strings.TrimSpace(string(raw))); err == nil && pgid > 1 {
				_ = syscall.Kill(-pgid, syscall.SIGKILL)
			}
		}
	}
	os.RemoveAll(home)
}

// lines reads the JSON log that both images append to.
func (b *e2eBridge) lines() []map[string]any {
	f, err := os.Open(b.logPath)
	if err != nil {
		return nil
	}
	defer f.Close()
	var out []map[string]any
	sc := bufio.NewScanner(f)
	sc.Buffer(nil, 1<<20)
	for sc.Scan() {
		var line map[string]any
		if json.Unmarshal(sc.Bytes(), &line) == nil {
			out = append(out, line)
		}
	}

	return out
}

// find is the first log line of event from the index from on, and its index.
func (b *e2eBridge) find(event string, from int) (map[string]any, int) {
	lines := b.lines()
	for i := from; i < len(lines); i++ {
		if lines[i]["event"] == event {
			return lines[i], i
		}
	}

	return nil, -1
}

func (b *e2eBridge) dump() string {
	log, _ := os.ReadFile(b.logPath)
	out, _ := os.ReadFile(b.stdout)
	b.fake.mu.Lock()
	defer b.fake.mu.Unlock()

	return fmt.Sprintf("log:\n%s\nstdout:\n%s\nunknown requests: %v\nhub Last-Event-IDs: %q", log, out, b.fake.unknown, b.fake.lastIDs)
}

// wait polls ok, and fails when the bridge exits first.
func (b *e2eBridge) wait(what string, ok func() bool) {
	b.t.Helper()
	deadline := time.After(e2eWait)
	for !ok() {
		select {
		case <-b.exited:
			if ok() {
				return
			}
			b.t.Fatalf("the bridge exited while waiting for %s\n%s", what, b.dump())
		case <-deadline:
			b.t.Fatalf("timed out waiting for %s\n%s", what, b.dump())
		case <-time.After(50 * time.Millisecond):
		}
	}
}

func (b *e2eBridge) waitEvent(event string) map[string]any {
	b.t.Helper()
	var line map[string]any
	b.wait(event, func() bool {
		line, _ = b.find(event, 0)

		return line != nil
	})

	return line
}

// startTwoWorkers pushes two cards and waits until both workers run. Card 2
// exits with 3. It returns the id of the last event.
func (b *e2eBridge) startTwoWorkers() string {
	b.t.Helper()
	if err := os.WriteFile(filepath.Join(b.dir, "exit-2"), []byte("3\n"), 0o600); err != nil {
		b.t.Fatal(err)
	}
	b.wait("the first hub connection", func() bool { return len(b.fake.connections()) == 1 })
	b.fake.push(1)
	last := b.fake.push(2)
	b.wait("two running workers", func() bool {
		_, one := b.fake.report(1, api.RunRunning)
		_, two := b.fake.report(2, api.RunRunning)

		return one && two
	})

	return last
}

// release ends the fake workers, and checks the final report of each card.
func (b *e2eBridge) release(codes map[int]int) {
	b.t.Helper()
	if err := os.WriteFile(filepath.Join(b.dir, "release"), nil, 0o600); err != nil {
		b.t.Fatal(err)
	}
	for card, code := range codes {
		want := api.RunSucceeded
		if code != 0 {
			want = api.RunFailed
		}
		var rep api.RunStateReport
		b.wait(fmt.Sprintf("the final report of card %d", card), func() bool {
			var ok bool
			rep, ok = b.fake.report(card, api.RunSucceeded, api.RunFailed, api.RunNoResult, api.RunNotStarted)

			return ok
		})
		if rep.State != want || rep.ExitCode == nil || *rep.ExitCode != code || rep.HasResult == nil || !*rep.HasResult ||
			!strings.Contains(rep.Output, fmt.Sprintf("STAGE RESULT: done %d", card)) {
			b.t.Fatalf("card %d: final report = %+v, want %s with exit %d and its result line\n%s", card, rep, want, code, b.dump())
		}
	}
}

// same checks that the bridge still runs in the process the test started.
func (b *e2eBridge) same(start string) {
	b.t.Helper()
	select {
	case <-b.exited:
		b.t.Fatalf("the bridge process exited\n%s", b.dump())
	default:
	}
	if got := processStart(b.cmd.Process.Pid); got != start {
		b.t.Fatalf("pid %d started at %q, and now at %q", b.cmd.Process.Pid, start, got)
	}
}

func (b *e2eBridge) noHandoverFile() {
	b.t.Helper()
	for _, pattern := range []string{"handover-*", ".handover-*"} {
		left, _ := filepath.Glob(filepath.Join(b.configDir, pattern))
		if len(left) > 0 {
			b.t.Fatalf("handover files remain: %v", left)
		}
	}
}

func sameBytes(t *testing.T, path, want string) bool {
	t.Helper()
	have, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	wanted, err := os.ReadFile(want)
	if err != nil {
		t.Fatal(err)
	}

	return bytes.Equal(have, wanted)
}

func testHandoverForward(t *testing.T, old, next string) {
	fake := newE2ELoupe(t, next)
	b := startE2EBridge(t, fake, old)
	start := processStart(b.cmd.Process.Pid)
	last := b.startTwoWorkers()

	fake.open()
	applied := b.waitEvent("update_applied")
	handover, at := b.find("update_handover", 0)
	if _, appliedAt := b.find("update_applied", 0); handover == nil || at > appliedAt {
		t.Fatalf("update_handover must come before update_applied\n%s", b.dump())
	}
	if handover["from"] != e2eOld || handover["to"] != e2eNew || handover["live"] != float64(2) {
		t.Fatalf("update_handover = %v", handover)
	}
	if applied["from"] != e2eOld || applied["to"] != e2eNew {
		t.Fatalf("update_applied = %v", applied)
	}
	b.same(start)
	b.wait("the hub reconnect", func() bool { return len(fake.connections()) >= 2 })
	if conns := fake.connections(); len(conns) != 2 || conns[0] != "" || conns[1] != last {
		t.Fatalf("hub Last-Event-IDs = %q, want the new image to resume from %q", conns, last)
	}

	fake.push(3)
	b.wait("a worker for the card pushed after the handover", func() bool {
		_, ok := fake.report(3, api.RunRunning)

		return ok
	})
	b.release(map[int]int{1: 0, 2: 3, 3: 0})

	b.waitEvent("update_installed")
	if !sameBytes(t, b.installed, next) {
		t.Fatalf("%s does not hold the new build\n%s", b.installed, b.dump())
	}
	b.same(start)
	b.noHandoverFile()
}

func testHandoverRollback(t *testing.T, old, next string) {
	fake := newE2ELoupe(t, next)
	fake.failBeatsOf = e2eNew
	b := startE2EBridge(t, fake, old, "LOUPE_HEALTH_TIMEOUT=2s")
	start := processStart(b.cmd.Process.Pid)
	last := b.startTwoWorkers()

	fake.open()
	back := b.waitEvent("update_rolled_back")
	if back["from"] != e2eNew || back["to"] != e2eOld || back["reason"] != "health" {
		t.Fatalf("update_rolled_back = %v\n%s", back, b.dump())
	}
	if line, _ := b.find("update_unhealthy", 0); line == nil {
		t.Fatalf("no update_unhealthy\n%s", b.dump())
	}
	if line, _ := b.find("update_applied", 0); line != nil {
		t.Fatalf("update_applied = %v", line)
	}
	b.same(start)
	st, err := update.LoadState(b.configDir)
	if err != nil {
		t.Fatal(err)
	}
	if !st.Skipped(e2eNew) {
		t.Fatalf("the skip list = %v, want %s on it", st.Skips, e2eNew)
	}
	if conns := fake.connections(); len(conns) < 2 || conns[len(conns)-1] != last {
		t.Fatalf("hub Last-Event-IDs = %q, want each image to resume from %q", conns, last)
	}

	b.release(map[int]int{1: 0, 2: 3})
	if !sameBytes(t, b.installed, old) {
		t.Fatalf("%s no longer holds the old build", b.installed)
	}
	b.same(start)
	b.noHandoverFile()
}
