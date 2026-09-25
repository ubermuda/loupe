package cmd

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"io"
	"net"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

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
	gh := newFakeGitHub(t, "new binary", "v1.2.0")
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
	gh := newFakeGitHub(t, "new binary", "v1.2.0")
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
	gh := newFakeGitHub(t, "new binary", "v1.0.0")
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
	gh := newFakeGitHub(t, "new binary", "v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")
	h.u.executable = func() (string, error) { return "/nonexistent/dir/loupe", nil }
	h.started()

	if res := h.u.checkNow(context.Background(), nil); res.Outcome != "blocked" || res.To != "1.2.0" || res.Problem == "" {
		t.Fatalf("res = %+v", res)
	}
}

func TestAForcedCheckRejectsAnArchiveThatFailsItsChecksum(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "v1.2.0")
	gh.checksums = []byte(strings.Repeat("0", 64) + "  " + gh.assetName() + "\n")
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")
	h.started()

	if res := h.u.checkNow(context.Background(), nil); res.Outcome != "rejected" || res.OK {
		t.Fatalf("res = %+v", res)
	}
}

func TestAForcedCheckWaitsForTheCheckInFlightThenGivesUp(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "v1.2.0")
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
	gh := newFakeGitHub(t, "new binary", "v1.2.0")
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

func TestServeControlAnswersAnUpdateBeforeTheExec(t *testing.T) {
	shortConfigHome(t)
	release := make(chan struct{})
	defer close(release)
	sock := serveUpdateTest(t, "rules.yaml", func(_ context.Context, reply func(updateResult)) updateResult {
		reply(updateResult{OK: true, From: "1.0.0", To: "1.2.0", Outcome: "handing-over"})
		<-release

		return updateResult{Outcome: "rejected"}
	})

	conn, err := net.Dial("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	conn.SetDeadline(time.Now().Add(5 * time.Second))
	conn.Write([]byte(`{"op":"update"}` + "\n"))
	all, err := io.ReadAll(conn)
	if err != nil {
		t.Fatalf("read %q: %v", all, err)
	}
	var res updateResult
	if err := json.Unmarshal(all, &res); err != nil || res.Outcome != "handing-over" || res.To != "1.2.0" || bytes.Count(all, []byte("\n")) != 1 {
		t.Fatalf("answer %q: %v", all, err)
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

func noSelfUpdate(t *testing.T) selfUpdate {
	return selfUpdate{apiBase: "http://127.0.0.1:1", version: "1.0.0", executable: func() (string, error) {
		t.Fatal("the command updated itself while a bridge runs")

		return "", nil
	}}
}

func TestUpdateAsksEachRunningBridge(t *testing.T) {
	shortConfigHome(t)
	dir := t.TempDir()
	a, b := filepath.Join(dir, "a.yaml"), filepath.Join(dir, "b.yaml")
	sockA := serveUpdateTest(t, a, func(_ context.Context, reply func(updateResult)) updateResult {
		reply(updateResult{OK: true, From: "1.0.0", To: "1.2.0", Outcome: "handing-over"})

		return updateResult{}
	})
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
		version:    running,
		goos:       "linux",
		goarch:     "amd64",
		apiBase:    gh.server.URL,
		hc:         gh.server.Client(),
		executable: func() (string, error) { return exe, nil },
	}, exe
}

func TestUpdateWithNoBridgeReplacesTheBinaryWithTheHighestOfItsMajor(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "v1.0.0", "v1.2.0", "v2.0.0")
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
	if !strings.Contains(out, "highest 1.x release") || !strings.Contains(out, "installed 1.2.0 over "+exe) {
		t.Fatalf("out = %q", out)
	}
}

func TestUpdateWithNoBridgeOnADevBuildTakesTheHighestRelease(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "v1.2.0", "v2.0.0")
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
	gh := newFakeGitHub(t, "new binary", "v1.2.0")
	self, exe := selfUpdateAgainst(t, gh, "1.2.0")

	out, err := updateCmd(t, self)
	if data, _ := os.ReadFile(exe); err != nil || string(data) != "old" || !strings.Contains(out, "current at 1.2.0") {
		t.Fatalf("err = %v, out = %q, binary = %q", err, out, data)
	}
}

func TestUpdateWithNoBridgeFailsOnABadChecksum(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "v1.2.0")
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
