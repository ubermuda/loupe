package cmd

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/zalando/go-keyring"

	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/update"
)

// started marks the updater as running its loop, without the loop.
func (h *updaterHarness) started() {
	h.u.mu.Lock()
	h.u.cancel = func() {}
	h.u.mu.Unlock()
}

func TestAForcedCheckIgnoresTheSkipListAndAutoUpdate(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	h.auto = false
	h.u.setRange("^1.0")
	if err := skipVersion(h.u.dir, "1.2.0"); err != nil {
		t.Fatal(err)
	}
	h.started()

	res := h.u.checkNow(context.Background(), nil)

	if len(h.staged) != 1 || !strings.HasPrefix(h.staged[0], "1.2.0 ") {
		t.Fatalf("hook calls = %v", h.staged)
	}
	if res.Outcome != "deferred" || res.From != "1.0.0" || res.To != "1.2.0" || !res.OK {
		t.Fatalf("res = %+v", res)
	}
}

func TestAForcedCheckReportsARejectedHandover(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	h.outcome = stagedRejected
	h.u.setRange("^1.0")
	h.started()

	res := h.u.checkNow(context.Background(), nil)

	if res.Outcome != "rejected" || res.OK || res.Problem == "" {
		t.Fatalf("res = %+v", res)
	}
}

func TestAForcedCheckNamesEachOutcomeWithoutAHandover(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0")
	for name, tc := range map[string]struct {
		running, cliRange, want string
		ok                      bool
	}{
		"current":  {"1.0.0", "^1.0", "current", true},
		"dev":      {"", "^1.0", "dev", true},
		"no range": {"1.0.0", "", "failed", false},
		"outside":  {"2.0.0", "^3.0", "failed", false},
	} {
		h := newTestUpdater(t, gh, tc.running)
		h.u.setRange(tc.cliRange)
		h.started()

		res := h.u.checkNow(context.Background(), nil)
		if res.Outcome != tc.want || res.OK != tc.ok || len(h.staged) != 0 {
			t.Fatalf("%s: res = %+v, hook calls = %v", name, res, h.staged)
		}
	}
}

func TestAForcedCheckIsBlockedByAnUnwritableBinaryDir(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")
	h.u.executable = func() (string, error) { return "/nonexistent/dir/loupe", nil }
	h.started()

	if res := h.u.checkNow(context.Background(), nil); res.OK || res.Outcome != "blocked" || res.To != "1.2.0" || res.Problem == "" {
		t.Fatalf("res = %+v", res)
	}
}

func TestAForcedCheckRejectsAnArchiveThatFailsItsChecksum(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	gh.checksums = []byte(strings.Repeat("0", 64) + "  " + gh.assetName() + "\n")
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")
	h.started()

	if res := h.u.checkNow(context.Background(), nil); res.Outcome != "rejected" || res.OK {
		t.Fatalf("res = %+v", res)
	}
}

func TestAForcedCheckWaitsForTheCheckInFlightThenGivesUp(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")
	h.u.checkWait = 50 * time.Millisecond
	h.started()
	h.u.token <- struct{}{}

	res := h.u.checkNow(context.Background(), nil)
	if res.Outcome != "deferred" || !strings.Contains(res.Problem, "still running") || len(h.staged) != 0 {
		t.Fatalf("res = %+v, hook calls = %v", res, h.staged)
	}

	go func() {
		time.Sleep(20 * time.Millisecond)
		<-h.u.token
	}()
	h.u.checkWait = 5 * time.Second
	if res := h.u.checkNow(context.Background(), nil); res.Outcome != "deferred" || len(h.staged) != 1 {
		t.Fatalf("after the wait: res = %+v, hook calls = %v", res, h.staged)
	}
}

func TestAForcedCheckWaitsForTheUpdaterToStart(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")

	if res := h.u.checkNow(context.Background(), nil); res.Outcome != "deferred" || len(h.staged) != 0 {
		t.Fatalf("res = %+v", res)
	}
}

// The handover tells the one who asked before it execs, as the exec ends the
// connection.
func TestTheHandoverAnnouncesItselfBeforeTheExec(t *testing.T) {
	h, b, _ := newTestHandoff(t)
	var announced, atExec []string
	b.exec = func(string, []string, []string) error {
		atExec = slices.Clone(announced)

		return errExecCaptured
	}
	ctx := withAnnounce(context.Background(), func(to string) { announced = append(announced, to) })

	b.handover(h.router, "1.0.0")(ctx, candidate(t, "1.2.0"), "/staged/loupe")

	if !slices.Equal(atExec, []string{"1.2.0"}) {
		t.Fatalf("announced before the exec = %v", atExec)
	}
}

func TestTheHandoverAnnouncesNothingWhenItFailsBeforeTheExec(t *testing.T) {
	h, b, calls := newTestHandoff(t)
	b.file = "/nonexistent/handover.json"
	announced := false
	ctx := withAnnounce(context.Background(), func(string) { announced = true })

	got := b.handover(h.router, "1.0.0")(ctx, candidate(t, "1.2.0"), "/staged/loupe")

	if announced || len(*calls) != 0 || got != stagedRejected {
		t.Fatalf("announced = %v, exec calls = %d, outcome = %v", announced, len(*calls), got)
	}
}

// serveUpdateTest listens on the control socket of rulesPath and holds its
// lock, as a running bridge does, and answers an update with handle.
func serveUpdateTest(t *testing.T, rulesPath string, handle func(ctx context.Context, reply func(updateResult)) updateResult) string {
	t.Helper()
	sock, err := socketPath(rulesPath)
	if err != nil {
		t.Fatal(err)
	}
	lock, err := lockBridge(rulesPath, sock)
	if err != nil {
		t.Fatal(err)
	}
	ln, err := listenControl(sock)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := serveControl(ctx, ln, controlOps{update: handle})
	t.Cleanup(func() {
		cancel()
		<-done
		lock.Close()
	})

	return sock
}

// The early line goes out before the exec. When the exec fails, the result
// follows as a second line.
func TestServeControlAnswersAnUpdateBeforeTheExec(t *testing.T) {
	shortConfigHome(t)
	release := make(chan struct{})
	sock := serveUpdateTest(t, "rules.yaml", func(_ context.Context, reply func(updateResult)) updateResult {
		reply(updateResult{OK: true, From: "1.0.0", To: "1.2.0", Outcome: "handing-over"})
		<-release

		return updateResult{From: "1.0.0", To: "1.2.0", Outcome: "rejected"}
	})

	conn, err := net.Dial("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	conn.SetDeadline(time.Now().Add(5 * time.Second))
	conn.Write([]byte(`{"op":"update"}` + "\n"))
	rd := bufio.NewReader(conn)
	var first, second updateResult
	line, err := rd.ReadString('\n')
	if err != nil || json.Unmarshal([]byte(line), &first) != nil || first.Outcome != "handing-over" || first.To != "1.2.0" {
		t.Fatalf("first line %q: %v", line, err)
	}
	close(release)
	rest, err := io.ReadAll(rd)
	if err != nil || json.Unmarshal(rest, &second) != nil || second.Outcome != "rejected" || bytes.Count(rest, []byte("\n")) != 1 {
		t.Fatalf("rest %q: %v", rest, err)
	}
}

// handedOverTest answers an update with the handing-over line and closes the
// connection, as a successful exec does.
func handedOverTest(t *testing.T, rulesPath string) string {
	t.Helper()
	sock, err := socketPath(rulesPath)
	if err != nil {
		t.Fatal(err)
	}
	lock, err := lockBridge(rulesPath, sock)
	if err != nil {
		t.Fatal(err)
	}
	ln, err := listenControl(sock)
	if err != nil {
		t.Fatal(err)
	}
	go func() {
		for {
			conn, err := ln.Accept()
			if err != nil {
				return
			}
			bufio.NewReader(conn).ReadString('\n')
			conn.Write([]byte(`{"ok":true,"from":"1.0.0","to":"1.2.0","outcome":"handing-over"}` + "\n"))
			conn.Close()
		}
	}()
	t.Cleanup(func() {
		ln.Close()
		lock.Close()
	})

	return sock
}

func TestRequestUpdateTakesAClosedConnectionAfterTheAnnounceAsAHandover(t *testing.T) {
	shortConfigHome(t)
	sock := handedOverTest(t, "rules.yaml")

	res, err := requestUpdate(sock)
	if err != nil || !res.OK || res.Outcome != "handing-over" || res.To != "1.2.0" {
		t.Fatalf("res = %+v, err = %v", res, err)
	}
}

// An exec that fails after the announce makes the command fail.
func TestUpdateFailsWhenTheExecFailsAfterTheAnnounce(t *testing.T) {
	shortConfigHome(t)
	sock := serveUpdateTest(t, "rules.yaml", func(_ context.Context, reply func(updateResult)) updateResult {
		reply(updateResult{OK: true, From: "1.0.0", To: "1.2.0", Outcome: "handing-over"})

		return updateResult{From: "1.0.0", To: "1.2.0", Outcome: "rejected", Problem: "exec failed"}
	})

	out, err := updateCmd(t, noSelfUpdate(t))

	if err == nil || !strings.HasPrefix(out, sock+": rejected, from 1.0.0 to 1.2.0: exec failed\n") {
		t.Fatalf("err = %v, out = %q", err, out)
	}
}

func TestServeControlAnswersAnUpdateWithItsResult(t *testing.T) {
	shortConfigHome(t)
	sock := serveUpdateTest(t, "rules.yaml", func(context.Context, func(updateResult)) updateResult {
		return updateResult{OK: true, From: "1.0.0", Outcome: "current"}
	})

	res, err := requestUpdate(sock)
	if err != nil || res.Outcome != "current" || res.From != "1.0.0" {
		t.Fatalf("res = %+v, err = %v", res, err)
	}
}

// updateCmd runs `loupe update` with args, and returns its output.
func updateCmd(t *testing.T, self selfUpdate, args ...string) (string, error) {
	t.Helper()
	cmd := newUpdateCmdWith(self)
	var out bytes.Buffer
	cmd.SetOut(&out)
	cmd.SetErr(&out)
	cmd.SetArgs(append([]string{}, args...))
	err := cmd.Execute()

	return out.String(), err
}

// noSelfUpdate is a binary outside Homebrew that has no way to update itself.
func noSelfUpdate(t *testing.T) selfUpdate {
	exe := filepath.Join(t.TempDir(), "loupe")

	return selfUpdate{apiBase: "http://127.0.0.1:1", version: "1.0.0", executable: func() (string, error) { return exe, nil }, serverRange: func(context.Context) (string, error) {
		t.Fatal("the command updated itself while a bridge runs")

		return "", nil
	}}
}

func TestUpdateAsksEachRunningBridge(t *testing.T) {
	shortConfigHome(t)
	dir := t.TempDir()
	a, b := filepath.Join(dir, "a.yaml"), filepath.Join(dir, "b.yaml")
	sockA := handedOverTest(t, a)
	sockB := serveUpdateTest(t, b, func(context.Context, func(updateResult)) updateResult {
		return updateResult{OK: true, From: "1.2.0", Outcome: "current"}
	})

	out, err := updateCmd(t, noSelfUpdate(t))
	if err != nil {
		t.Fatalf("err = %v, out = %q", err, out)
	}
	lines := strings.Split(strings.TrimSpace(out), "\n")
	if len(lines) != 2 || !strings.Contains(out, sockA+": handing-over, from 1.0.0 to 1.2.0\n") || !strings.Contains(out, sockB+": current at 1.2.0\n") {
		t.Fatalf("out = %q", out)
	}
}

func TestUpdateWithRulesAsksThatBridgeOnly(t *testing.T) {
	shortConfigHome(t)
	dir := t.TempDir()
	a, b := filepath.Join(dir, "a.yaml"), filepath.Join(dir, "b.yaml")
	for _, p := range []string{a, b} {
		if err := os.WriteFile(p, nil, 0o600); err != nil {
			t.Fatal(err)
		}
	}
	serveUpdateTest(t, a, func(context.Context, func(updateResult)) updateResult {
		return updateResult{OK: true, From: "1.0.0", Outcome: "current"}
	})
	serveUpdateTest(t, b, func(context.Context, func(updateResult)) updateResult {
		t.Error("the other bridge was asked")

		return updateResult{}
	})

	out, err := updateCmd(t, noSelfUpdate(t), "--rules", a)
	if err != nil || out != a+": current at 1.0.0\n" {
		t.Fatalf("err = %v, out = %q", err, out)
	}
}

func TestUpdateWithRulesFindsTheBridgeAfterItsSymlinkIsRepointed(t *testing.T) {
	shortConfigHome(t)
	dir := t.TempDir()
	first, second, link := filepath.Join(dir, "first.yaml"), filepath.Join(dir, "second.yaml"), filepath.Join(dir, "rules.yaml")
	for _, p := range []string{first, second} {
		if err := os.WriteFile(p, nil, 0o600); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.Symlink(first, link); err != nil {
		t.Fatal(err)
	}
	serveUpdateTest(t, link, func(context.Context, func(updateResult)) updateResult {
		return updateResult{OK: true, From: "1.0.0", Outcome: "current"}
	})
	if err := os.Remove(link); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(second, link); err != nil {
		t.Fatal(err)
	}

	out, err := updateCmd(t, noSelfUpdate(t), "--rules", link)
	if err != nil || out != link+": current at 1.0.0\n" {
		t.Fatalf("err = %v, out = %q", err, out)
	}
}

func TestUpdateWithRulesPrefersTheBridgeOnTheSocketOfTheRepointedSymlink(t *testing.T) {
	shortConfigHome(t)
	dir := t.TempDir()
	first, second, link := filepath.Join(dir, "first.yaml"), filepath.Join(dir, "second.yaml"), filepath.Join(dir, "rules.yaml")
	for _, p := range []string{first, second} {
		if err := os.WriteFile(p, nil, 0o600); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.Symlink(first, link); err != nil {
		t.Fatal(err)
	}
	serveUpdateTest(t, link, func(context.Context, func(updateResult)) updateResult {
		return updateResult{OK: true, From: "1.0.0", Outcome: "current"}
	})
	if err := os.Remove(link); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(second, link); err != nil {
		t.Fatal(err)
	}
	serveUpdateTest(t, second, func(context.Context, func(updateResult)) updateResult {
		t.Error("the bridge on the new target was asked")

		return updateResult{}
	})

	out, err := updateCmd(t, noSelfUpdate(t), "--rules", link)
	if err != nil || out != link+": current at 1.0.0\n" {
		t.Fatalf("err = %v, out = %q", err, out)
	}
}

func TestUpdateWithRulesFindsTheBridgeThroughASymlinkToItsFile(t *testing.T) {
	shortConfigHome(t)
	dir := t.TempDir()
	file, link := filepath.Join(dir, "rules.yaml"), filepath.Join(dir, "alias.yaml")
	if err := os.WriteFile(file, nil, 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(file, link); err != nil {
		t.Fatal(err)
	}
	serveUpdateTest(t, file, func(context.Context, func(updateResult)) updateResult {
		return updateResult{OK: true, From: "1.0.0", Outcome: "current"}
	})

	out, err := updateCmd(t, noSelfUpdate(t), "--rules", link)
	if err != nil || out != link+": current at 1.0.0\n" {
		t.Fatalf("err = %v, out = %q", err, out)
	}
}

func TestUpdateWithRulesNeedsThatBridgeRunning(t *testing.T) {
	shortConfigHome(t)
	path := filepath.Join(t.TempDir(), "rules.yaml")

	if _, err := updateCmd(t, noSelfUpdate(t), "--rules", path); err == nil || !strings.Contains(err.Error(), "no running bridge reads") {
		t.Fatalf("err = %v", err)
	}
}

func TestUpdateFailsWhenABridgeFailsOrIsTooOld(t *testing.T) {
	shortConfigHome(t)
	dir := t.TempDir()
	serveUpdateTest(t, filepath.Join(dir, "a.yaml"), func(context.Context, func(updateResult)) updateResult {
		return updateResult{From: "1.0.0", To: "1.2.0", Outcome: "failed", Problem: "GitHub answered 502"}
	})
	out, err := updateCmd(t, noSelfUpdate(t))
	if err == nil || !strings.Contains(out, ": failed, from 1.0.0 to 1.2.0: GitHub answered 502\n") {
		t.Fatalf("err = %v, out = %q", err, out)
	}
}

func TestUpdatePrintsTheProblemsOfABridgeThatKnowsNoUpdate(t *testing.T) {
	shortConfigHome(t)
	path := filepath.Join(t.TempDir(), "a.yaml")
	sock, _ := socketPath(path)
	lock, err := lockBridge(path, sock)
	if err != nil {
		t.Fatal(err)
	}
	defer lock.Close()
	ln, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close()
	go func() {
		conn, err := ln.Accept()
		if err != nil {
			return
		}
		defer conn.Close()
		bufio.NewReader(conn).ReadString('\n')
		conn.Write([]byte(`{"ok":false,"problems":["unknown op \"update\": only reload is known"]}` + "\n"))
	}()

	out, err := updateCmd(t, noSelfUpdate(t))
	if err == nil || !strings.Contains(out, sock+": failed: unknown op \"update\": only reload is known\n") {
		t.Fatalf("err = %v, out = %q", err, out)
	}
}

// selfUpdateAgainst updates a fake installed binary from gh.
func selfUpdateAgainst(t *testing.T, gh *fakeGitHub, running string) (selfUpdate, string) {
	t.Helper()
	exe := filepath.Join(t.TempDir(), "loupe")
	if err := os.WriteFile(exe, []byte("old"), 0o755); err != nil {
		t.Fatal(err)
	}

	return selfUpdate{
		version:     running,
		goos:        "linux",
		goarch:      "amd64",
		apiBase:     gh.server.URL,
		hc:          gh.server.Client(),
		executable:  func() (string, error) { return exe, nil },
		serverRange: func(context.Context) (string, error) { return "", config.ErrNotLoggedIn },
	}, exe
}

func withServerRange(self selfUpdate, cliRange string) selfUpdate {
	self.serverRange = func(context.Context) (string, error) { return cliRange, nil }

	return self
}

// A newer major can ship while the server still supports the old one, so the
// server range decides, and the skip list does not.
func TestUpdateWithNoBridgeTakesTheHighestReleaseInTheServerRange(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0", "cli/v1.2.0", "cli/v2.0.0")
	self, exe := selfUpdateAgainst(t, gh, "1.0.0")
	if err := os.MkdirAll(mustConfigDir(t), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := skipVersion(mustConfigDir(t), "1.2.0"); err != nil {
		t.Fatal(err)
	}

	out, err := updateCmd(t, withServerRange(self, "^1.0"))
	if err != nil || !strings.Contains(out, "range ^1.0") || !strings.Contains(out, "installed 1.2.0 over "+exe) {
		t.Fatalf("err = %v, out = %q", err, out)
	}
	if data, _ := os.ReadFile(exe); string(data) != "new binary" {
		t.Fatalf("binary = %q", data)
	}
}

// A plain vX.Y.Z tag belongs to the server's version track.
func TestUpdateWithNoBridgeIgnoresAPlainVersionTag(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0", "v1.5.0")
	self, exe := selfUpdateAgainst(t, gh, "")

	out, err := updateCmd(t, withServerRange(self, "^1.0"))
	if err != nil || !strings.Contains(out, "installed 1.2.0 over "+exe) {
		t.Fatalf("err = %v, out = %q", err, out)
	}
}

func TestUpdateWithNoBridgeLeavesABinaryInRangeWhenOnlyANewerMajorShips(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "cli/v2.0.0")
	self, exe := selfUpdateAgainst(t, gh, "1.0.0")

	out, err := updateCmd(t, withServerRange(self, "^1.0"))
	if data, _ := os.ReadFile(exe); err != nil || string(data) != "old" || !strings.Contains(out, "current at 1.0.0") {
		t.Fatalf("err = %v, out = %q, binary = %q", err, out, data)
	}
}

func TestUpdateWithNoBridgeOnADevBuildStaysInTheServerRange(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0", "cli/v2.0.0")
	self, exe := selfUpdateAgainst(t, gh, "")

	out, err := updateCmd(t, withServerRange(self, "^1.0"))
	if err != nil || !strings.Contains(out, "installed 1.2.0 over "+exe) {
		t.Fatalf("err = %v, out = %q", err, out)
	}
}

func TestUpdateWithNoBridgeFailsWhenNoReleaseIsInTheServerRange(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "cli/v2.0.0")
	self, exe := selfUpdateAgainst(t, gh, "3.0.0")

	_, err := updateCmd(t, withServerRange(self, "^1.0"))
	if data, _ := os.ReadFile(exe); err == nil || !strings.Contains(err.Error(), "^1.0") || string(data) != "old" {
		t.Fatalf("err = %v, binary = %q", err, data)
	}
}

func TestReadServerRangeAsksGetEventsWithTheStoredLogin(t *testing.T) {
	keyring.MockInit()
	shortConfigHome(t)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/events" || r.Header.Get("Authorization") != "Bearer access-1" {
			w.WriteHeader(http.StatusNotFound)

			return
		}
		w.Write([]byte(`{"hubUrl":"h","jwt":"j","topic":"t","projects":[],"flags":{},"cliRange":"^1.0"}`))
	}))
	defer server.Close()
	if err := config.Save(testLogin(server.URL)); err != nil {
		t.Fatal(err)
	}

	if got, err := readServerRange(context.Background()); err != nil || got != "^1.0" {
		t.Fatalf("range = %q, err = %v", got, err)
	}
}

func TestReadServerRangeFailsWithNoLoginOrNoRange(t *testing.T) {
	keyring.MockInit()
	shortConfigHome(t)
	if _, err := readServerRange(context.Background()); !errors.Is(err, config.ErrNotLoggedIn) {
		t.Fatalf("err = %v", err)
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte(`{"hubUrl":"h","jwt":"j","topic":"t","projects":[],"flags":{}}`))
	}))
	defer server.Close()
	if err := config.Save(testLogin(server.URL)); err != nil {
		t.Fatal(err)
	}
	if _, err := readServerRange(context.Background()); err == nil {
		t.Fatal("a server with no range gave no error")
	}
}

func TestUpdateWithNoBridgeReplacesTheBinaryWithTheHighestOfItsMajor(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0", "cli/v1.2.0", "cli/v2.0.0")
	self, exe := selfUpdateAgainst(t, gh, "1.0.0")
	dir, _ := config.Dir()
	if err := os.MkdirAll(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	if err := skipVersion(dir, "1.2.0"); err != nil {
		t.Fatal(err)
	}

	out, err := updateCmd(t, self)
	if err != nil {
		t.Fatalf("err = %v, out = %q", err, out)
	}
	if data, _ := os.ReadFile(exe); string(data) != "new binary" {
		t.Fatalf("binary = %q", data)
	}
	if !strings.Contains(out, "could not read the CLI range of the server") || !strings.Contains(out, "highest 1.x release") || !strings.Contains(out, "installed 1.2.0 over "+exe) {
		t.Fatalf("out = %q", out)
	}
}

func TestUpdateWithNoBridgeOnADevBuildTakesTheHighestRelease(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0", "cli/v2.0.0")
	self, exe := selfUpdateAgainst(t, gh, "")

	out, err := updateCmd(t, self)
	if err != nil || !strings.Contains(out, "development build") || !strings.Contains(out, "installed 2.0.0 over "+exe) {
		t.Fatalf("err = %v, out = %q", err, out)
	}
	if data, _ := os.ReadFile(exe); string(data) != "new binary" {
		t.Fatalf("binary = %q", data)
	}
	if _, err := os.Stat(update.StagePath(mustConfigDir(t), "2.0.0")); err != nil {
		t.Fatalf("staged = %v", err)
	}
}

func TestUpdateWithNoBridgeLeavesACurrentBinary(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	self, exe := selfUpdateAgainst(t, gh, "1.2.0")

	out, err := updateCmd(t, self)
	if data, _ := os.ReadFile(exe); err != nil || string(data) != "old" || !strings.Contains(out, "current at 1.2.0") {
		t.Fatalf("err = %v, out = %q, binary = %q", err, out, data)
	}
}

func TestUpdateWithNoBridgeFailsOnABadChecksum(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	gh.checksums = []byte(strings.Repeat("0", 64) + "  " + gh.assetName() + "\n")
	self, exe := selfUpdateAgainst(t, gh, "1.0.0")

	_, err := updateCmd(t, self)
	if data, _ := os.ReadFile(exe); err == nil || string(data) != "old" {
		t.Fatalf("err = %v, binary = %q", err, data)
	}
}

func mustConfigDir(t *testing.T) string {
	t.Helper()
	dir, err := config.Dir()
	if err != nil {
		t.Fatal(err)
	}

	return dir
}

// Homebrew owns a binary in its keg, so the update in place leaves it to brew.
func TestUpdateWithNoBridgeRefusesAHomebrewInstall(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	self, _ := selfUpdateAgainst(t, gh, "1.0.0")
	keg, link := homebrewBinary(t)
	self.executable = func() (string, error) { return link, nil }

	_, err := updateCmd(t, withServerRange(self, "^1.0"))
	if err == nil || err.Error() != "loupe was installed with Homebrew. Run: brew upgrade loupe" {
		t.Fatalf("err = %v", err)
	}
	if data, _ := os.ReadFile(keg); string(data) != "old" {
		t.Fatalf("binary = %q", data)
	}
	if listed, downloads := gh.counts(); listed != 0 || downloads != 0 {
		t.Fatalf("listed = %d, downloads = %d", listed, downloads)
	}
}

// A running bridge would replace the binary in the keg, so the command asks
// no bridge either.
func TestUpdateRefusesAHomebrewInstallWhileABridgeRuns(t *testing.T) {
	shortConfigHome(t)
	serveUpdateTest(t, filepath.Join(t.TempDir(), "rules.yaml"), func(context.Context, func(updateResult)) updateResult {
		t.Error("the bridge was asked")

		return updateResult{}
	})
	self := noSelfUpdate(t)
	_, link := homebrewBinary(t)
	self.executable = func() (string, error) { return link, nil }

	_, err := updateCmd(t, self)
	if err == nil || err.Error() != "loupe was installed with Homebrew. Run: brew upgrade loupe" {
		t.Fatalf("err = %v", err)
	}
}

func TestUpdateAutoWorksUnderHomebrew(t *testing.T) {
	shortConfigHome(t)
	path := defaultRuleFile(t, "")
	self := noSelfUpdate(t)
	_, link := homebrewBinary(t)
	self.executable = func() (string, error) { return link, nil }

	if out, err := updateCmd(t, self, "auto", "on"); err != nil || !strings.HasPrefix(out, "Automatic updates: on\n") {
		t.Fatalf("out = %q, err = %v", out, err)
	}
	if data, _ := os.ReadFile(path); string(data) != "autoUpdate: true\n" {
		t.Fatalf("file = %q", data)
	}
}

// autoCmd runs `loupe update auto` with args.
func autoCmd(t *testing.T, args ...string) (string, error) {
	t.Helper()

	return updateCmd(t, noSelfUpdate(t), append([]string{"auto"}, args...)...)
}

// defaultRuleFile writes body to the default rule file. An empty body writes
// nothing.
func defaultRuleFile(t *testing.T, body string) string {
	t.Helper()
	path := filepath.Join(mustConfigDir(t), "rules.yaml")
	if body == "" {
		return path
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}

	return path
}

func TestUpdateAutoShowsTheValue(t *testing.T) {
	shortConfigHome(t)
	for _, tc := range []struct{ body, want string }{
		{"", "Automatic updates: off (default)\n"},
		{"maxWorkers: 2\n", "Automatic updates: off (default)\n"},
		{"autoUpdate: true\n", "Automatic updates: on\n"},
		{"autoUpdate: false\n", "Automatic updates: off\n"},
	} {
		path := defaultRuleFile(t, tc.body)
		out, err := autoCmd(t)
		if err != nil || out != tc.want {
			t.Fatalf("%q: out = %q, err = %v", tc.body, out, err)
		}
		os.Remove(path)
	}
}

func TestUpdateAutoWritesAMissingKey(t *testing.T) {
	shortConfigHome(t)
	path := defaultRuleFile(t, "")

	out, err := autoCmd(t, "on")
	if err != nil || out != "Automatic updates: on\nWrote autoUpdate: true to "+path+". A running bridge reads it on loupe bridge reload.\n" {
		t.Fatalf("out = %q, err = %v", out, err)
	}
	if data, _ := os.ReadFile(path); string(data) != "autoUpdate: true\n" {
		t.Fatalf("file = %q", data)
	}
}

func TestUpdateAutoTakesTheRulesFlag(t *testing.T) {
	shortConfigHome(t)
	path := filepath.Join(t.TempDir(), "other.yaml")

	if out, err := autoCmd(t, "off", "--rules", path); err != nil || !strings.HasPrefix(out, "Automatic updates: off\n") {
		t.Fatalf("out = %q, err = %v", out, err)
	}
	if data, _ := os.ReadFile(path); string(data) != "autoUpdate: false\n" {
		t.Fatalf("file = %q", data)
	}
}

// The command changes the value on the key's line, and keeps the rest.
func TestUpdateAutoFlipsAnExistingKey(t *testing.T) {
	shortConfigHome(t)
	path := defaultRuleFile(t, "# mine\nautoUpdate: false # asked at install\n")

	out, err := autoCmd(t, "off")
	if err != nil || out != "Automatic updates: off\n" {
		t.Fatalf("same value: out = %q, err = %v", out, err)
	}
	out, err = autoCmd(t, "on")
	if err != nil || out != "Automatic updates: on\nWrote autoUpdate: true to "+path+". A running bridge reads it on loupe bridge reload.\n" {
		t.Fatalf("other value: out = %q, err = %v", out, err)
	}
	if data, _ := os.ReadFile(path); string(data) != "# mine\nautoUpdate: true # asked at install\n" {
		t.Fatalf("file = %q", data)
	}
}

// A value that cannot change on its line alone is left to the user.
func TestUpdateAutoNamesTheLineToEditWhenItCannotFlipTheKey(t *testing.T) {
	shortConfigHome(t)
	path := defaultRuleFile(t, "{autoUpdate: false}\n")

	out, err := autoCmd(t, "on")
	if err == nil || err.Error() != path+" already holds autoUpdate: false; edit that line to autoUpdate: true" || strings.Contains(out, "Automatic updates") {
		t.Fatalf("out = %q, err = %v", out, err)
	}
	if data, _ := os.ReadFile(path); string(data) != "{autoUpdate: false}\n" {
		t.Fatalf("file = %q", data)
	}
}

func TestUpdateAutoKeepsAnyExistingKeyWithKeep(t *testing.T) {
	shortConfigHome(t)
	for _, arg := range []string{"on", "off"} {
		path := defaultRuleFile(t, "autoUpdate: false\n")
		out, err := autoCmd(t, arg, "--keep")
		if err != nil || out != "Automatic updates: off (kept from "+path+")\n" {
			t.Fatalf("%s: out = %q, err = %v", arg, out, err)
		}
		os.Remove(path)
	}
	if out, err := autoCmd(t, "on", "--keep"); err != nil || !strings.HasPrefix(out, "Automatic updates: on\nWrote ") {
		t.Fatalf("no key: out = %q, err = %v", out, err)
	}
}

func TestUpdateAutoRefusesAFileItCannotAppendTo(t *testing.T) {
	shortConfigHome(t)
	path := defaultRuleFile(t, "{maxWorkers: 2}\n")

	_, err := autoCmd(t, "on")
	if err == nil || !strings.Contains(err.Error(), path) || !strings.Contains(err.Error(), `"autoUpdate: true"`) {
		t.Fatalf("err = %v", err)
	}
}

func TestUpdateAutoRefusesAnUnknownValue(t *testing.T) {
	shortConfigHome(t)
	if _, err := autoCmd(t, "yes"); err == nil {
		t.Fatal("yes must fail")
	}
}
