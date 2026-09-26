package cmd

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/update"
)

// fakeGitHub serves a releases list and the assets it names, and counts each
// request.
type fakeGitHub struct {
	mu        sync.Mutex
	server    *httptest.Server
	tags      []string
	archive   []byte
	checksums []byte
	failList  bool
	listed    int
	downloads int
}

func newFakeGitHub(t *testing.T, binary string, tags ...string) *fakeGitHub {
	t.Helper()
	g := &fakeGitHub{tags: tags, archive: updateArchive(t, binary)}
	sum := sha256.Sum256(g.archive)
	g.checksums = []byte(hex.EncodeToString(sum[:]) + "  " + g.assetName() + "\n")
	g.server = httptest.NewServer(http.HandlerFunc(g.serve))
	t.Cleanup(g.server.Close)

	return g
}

// assetName is the one archive the fake serves, whatever the tag: a test's
// releases all carry the same bytes.
func (g *fakeGitHub) assetName() string {
	return "archive.tar.gz"
}

func (g *fakeGitHub) serve(w http.ResponseWriter, r *http.Request) {
	g.mu.Lock()
	defer g.mu.Unlock()
	switch r.URL.Path {
	case "/repos/ubermuda/loupe/releases":
		g.listed++
		if g.failList {
			w.WriteHeader(http.StatusBadGateway)

			return
		}
		var releases []update.Release
		for _, tag := range g.tags {
			v, _ := update.ParseVersion(strings.TrimPrefix(tag, "cli/"))
			releases = append(releases, update.Release{TagName: tag, Assets: []update.Asset{
				{Name: update.AssetName(v, "linux", "amd64"), URL: g.server.URL + "/dl/archive"},
				{Name: "checksums.txt", URL: g.server.URL + "/dl/checksums/" + v.String()},
			}})
		}
		_ = json.NewEncoder(w).Encode(releases)
	case "/dl/archive":
		g.downloads++
		_, _ = w.Write(g.archive)
	default:
		if v, ok := strings.CutPrefix(r.URL.Path, "/dl/checksums/"); ok {
			g.downloads++
			parsed, _ := update.ParseVersion(v)
			_, _ = w.Write(bytes.ReplaceAll(g.checksums, []byte(g.assetName()), []byte(update.AssetName(parsed, "linux", "amd64"))))

			return
		}
		w.WriteHeader(http.StatusNotFound)
	}
}

func (g *fakeGitHub) counts() (listed, downloads int) {
	g.mu.Lock()
	defer g.mu.Unlock()

	return g.listed, g.downloads
}

func updateArchive(t *testing.T, binary string) []byte {
	t.Helper()
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	if err := tw.WriteHeader(&tar.Header{Name: "loupe", Mode: 0o755, Size: int64(len(binary)), Typeflag: tar.TypeReg}); err != nil {
		t.Fatal(err)
	}
	if _, err := tw.Write([]byte(binary)); err != nil {
		t.Fatal(err)
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	if err := gz.Close(); err != nil {
		t.Fatal(err)
	}

	return buf.Bytes()
}

type updaterHarness struct {
	u       *updater
	gh      *fakeGitHub
	log     *syncBuffer
	auto    bool
	outcome stagedOutcome
	staged  []string
	during  []api.HeartbeatUpdate
}

func newTestUpdater(t *testing.T, gh *fakeGitHub, running string) *updaterHarness {
	t.Helper()
	h := &updaterHarness{gh: gh, log: &syncBuffer{}, auto: true}
	exe := filepath.Join(t.TempDir(), "bin", "loupe")
	if err := os.MkdirAll(filepath.Dir(exe), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(exe, []byte("old"), 0o755); err != nil {
		t.Fatal(err)
	}
	log := newBridgeLogger(h.log)
	h.u = newUpdater(log, running, t.TempDir(), func() bool { return h.auto }, func(ctx context.Context, c update.Candidate, path string) stagedOutcome {
		logStaged(log, running)(ctx, c, path)
		h.staged = append(h.staged, c.Version.String()+" "+path)
		h.during = append(h.during, h.u.state())

		return h.outcome
	})
	h.u.apiBase = gh.server.URL
	h.u.hc = gh.server.Client()
	h.u.goos, h.u.goarch = "linux", "amd64"
	h.u.executable = func() (string, error) { return exe, nil }

	return h
}

func (h *updaterHarness) events(event string) int {
	return strings.Count(h.log.String(), `"event":"`+event+`"`)
}

func TestTheUpdaterStagesAVerifiedRelease(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")

	h.u.check(context.Background())

	path := update.StagePath(h.u.dir, "1.2.0")
	data, err := os.ReadFile(path)
	if err != nil || string(data) != "new binary" {
		t.Fatalf("staged binary = %q, %v", data, err)
	}
	if info, _ := os.Stat(path); info.Mode().Perm() != 0o755 {
		t.Fatalf("mode = %v", info.Mode())
	}
	if len(h.staged) != 1 || h.staged[0] != "1.2.0 "+path {
		t.Fatalf("hook calls = %v", h.staged)
	}
	want := api.HeartbeatUpdate{State: "updating", Version: "1.2.0"}
	if h.during[0] != want || h.u.state() != (api.HeartbeatUpdate{}) {
		t.Fatalf("state during the hook = %+v, after = %+v", h.during[0], h.u.state())
	}
	for _, event := range []string{"update_check", "update_download", "update_verified", "update_staged"} {
		if h.events(event) != 1 {
			t.Fatalf("%s logged %d times: %s", event, h.events(event), h.log.String())
		}
	}
	if !strings.Contains(h.log.String(), `"from":"1.0.0","to":"1.2.0"`) {
		t.Fatalf("the log names no from and to: %s", h.log.String())
	}
	st, _ := update.LoadState(h.u.dir)
	if st.Staged["1.2.0"] != path {
		t.Fatalf("update.json = %+v", st)
	}
}

func TestTheUpdaterDoesNotDownloadAStagedReleaseAgain(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")

	h.u.check(context.Background())
	h.u.check(context.Background())

	if _, downloads := gh.counts(); downloads != 2 {
		t.Fatalf("downloads = %d, want the archive and the checksums once", downloads)
	}
	if len(h.staged) != 2 {
		t.Fatalf("hook calls = %v", h.staged)
	}
}

func TestTheUpdaterRejectsAMismatchedChecksum(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	gh.checksums = []byte(strings.Repeat("0", 64) + "  " + gh.assetName() + "\n")
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")

	h.u.check(context.Background())

	if h.events("update_rejected") != 1 || h.events("update_verified") != 0 || len(h.staged) != 0 {
		t.Fatalf("log = %s", h.log.String())
	}
	if _, err := os.Stat(update.StagePath(h.u.dir, "1.2.0")); err == nil {
		t.Fatal("a rejected archive was staged")
	}
}

func TestTheUpdaterOnlyAnnouncesWhenAutoUpdateIsOff(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	h.auto = false
	h.u.setRange("^1.0")

	h.u.check(context.Background())
	h.u.check(context.Background())

	if h.events("update_available") != 1 {
		t.Fatalf("update_available logged %d times: %s", h.events("update_available"), h.log.String())
	}
	if _, downloads := gh.counts(); downloads != 0 {
		t.Fatalf("downloads = %d", downloads)
	}
	if got := h.u.state(); got != (api.HeartbeatUpdate{State: "off", Version: "1.2.0"}) {
		t.Fatalf("state = %+v", got)
	}
}

// Root writes to a directory whatever its mode, so the test makes the parent of
// the binary a regular file instead.
func TestTheUpdaterIsBlockedByABinaryItCannotReplace(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	notDir := filepath.Join(t.TempDir(), "file")
	if err := os.WriteFile(notDir, nil, 0o600); err != nil {
		t.Fatal(err)
	}
	h.u.executable = func() (string, error) { return filepath.Join(notDir, "loupe"), nil }
	h.u.setRange("^1.0")

	h.u.check(context.Background())
	h.u.check(context.Background())

	if h.events("update_blocked") != 1 {
		t.Fatalf("update_blocked logged %d times: %s", h.events("update_blocked"), h.log.String())
	}
	if _, downloads := gh.counts(); downloads != 0 {
		t.Fatalf("downloads = %d", downloads)
	}
	if got := h.u.state(); got != (api.HeartbeatUpdate{State: "blocked", Version: "1.2.0"}) {
		t.Fatalf("state = %+v", got)
	}
}

func TestTheUpdaterHonoursTheSkipList(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.1.0", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	st, _ := update.LoadState(h.u.dir)
	st.Skip("1.2.0")
	if err := st.Save(h.u.dir); err != nil {
		t.Fatal(err)
	}
	h.u.setRange("^1.0")

	h.u.check(context.Background())

	if len(h.staged) != 1 || !strings.HasPrefix(h.staged[0], "1.1.0 ") {
		t.Fatalf("hook calls = %v", h.staged)
	}
}

func TestTheUpdaterReportsACurrentVersion(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.2.0")
	h.u.setRange("^1.0")

	h.u.check(context.Background())

	if got := h.u.state(); got != (api.HeartbeatUpdate{State: "current"}) {
		t.Fatalf("state = %+v", got)
	}
	if _, downloads := gh.counts(); downloads != 0 || len(h.staged) != 0 {
		t.Fatalf("downloads = %d, hook calls = %v", downloads, h.staged)
	}
}

func TestTheUpdaterWarnsOnceWhenNoReleaseFitsTheRange(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^2.0")

	h.u.check(context.Background())
	h.u.check(context.Background())

	if h.events("update_unavailable") != 1 {
		t.Fatalf("update_unavailable logged %d times: %s", h.events("update_unavailable"), h.log.String())
	}
	if got := h.u.state(); got != (api.HeartbeatUpdate{}) {
		t.Fatalf("state = %+v", got)
	}
}

func TestAFailedCheckKeepsThePreviousState(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.2.0")
	h.u.setRange("^1.0")
	h.u.check(context.Background())
	gh.mu.Lock()
	gh.failList = true
	gh.mu.Unlock()

	h.u.check(context.Background())

	if h.events("update_check_failed") != 1 || h.u.state().State != "current" {
		t.Fatalf("state = %+v, log = %s", h.u.state(), h.log.String())
	}
}

func TestTheUpdaterWaitsForARange(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")

	h.u.check(context.Background())

	if listed, _ := gh.counts(); listed != 0 || h.events("update_check") != 0 {
		t.Fatalf("listed = %d, log = %s", listed, h.log.String())
	}
}

func TestADevelopmentBuildNeverUpdates(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "")
	h.u.setRange("^1.0")
	h.u.start(context.Background())
	t.Cleanup(h.u.stop)

	eventually(t, "the dev state", func() bool { return h.u.state().State == "dev" })
	h.u.setRange("^1.1")
	h.u.stop()

	if listed, _ := gh.counts(); listed != 0 {
		t.Fatalf("listed = %d", listed)
	}
	if h.events("update_skipped") != 1 || !strings.Contains(h.log.String(), `"reason":"development build"`) {
		t.Fatalf("log = %s", h.log.String())
	}
}

// The loop checks at start, when the range changes, and once an hour plus a
// random delay.
func TestTheUpdaterChecksOnARangeChangeAndEachHour(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.0.0")
	h := newTestUpdater(t, gh, "1.0.0")
	timers := &fakeTimers{}
	h.u.after = timers.after
	h.u.jitter = func() time.Duration { return 7 * time.Minute }
	h.u.start(context.Background())
	t.Cleanup(h.u.stop)
	eventually(t, "the first timer", func() bool { return timers.count() == 1 })

	h.u.setRange("^1.0")
	eventually(t, "the check of the range", func() bool {
		listed, _ := gh.counts()

		return listed == 1 && timers.count() == 2
	})
	h.u.setRange("^1.0")
	delay, ch := timers.last()
	if delay != time.Hour+7*time.Minute {
		t.Fatalf("delay = %s", delay)
	}
	ch <- time.Now()
	eventually(t, "the hourly check", func() bool {
		listed, _ := gh.counts()

		return listed == 2 && timers.count() == 3
	})
	h.u.stop()
	if got := h.u.state(); got.State != "current" {
		t.Fatalf("state = %+v", got)
	}
}

// A deferred handover gives back the state before it, so the heartbeat does
// not claim an update that waits for the next check.
func TestADeferredHandoverRestoresThePreviousState(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")
	h.u.setState(updateCurrent, "")

	h.u.check(context.Background())

	if len(h.during) != 1 || h.during[0].State != "updating" || h.u.state() != (api.HeartbeatUpdate{State: "current"}) {
		t.Fatalf("during = %+v, after = %+v", h.during, h.u.state())
	}
	if st, _ := update.LoadState(h.u.dir); st.Skipped("1.2.0") {
		t.Fatal("a deferred version is on the skip list")
	}
}

// A rejected version goes on the skip list, and the state names it as rolled
// back until the bridge stops, even when a later check finds nothing newer.
func TestARejectedReleaseIsSkippedAndStaysRolledBack(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	h.outcome = stagedRejected
	h.u.setRange("^1.0")

	h.u.check(context.Background())
	h.u.check(context.Background())

	if len(h.staged) != 1 {
		t.Fatalf("hook calls = %v", h.staged)
	}
	if st, _ := update.LoadState(h.u.dir); !st.Skipped("1.2.0") {
		t.Fatalf("update.json = %+v", st)
	}
	if got := h.u.state(); got != (api.HeartbeatUpdate{State: "rolled-back", Version: "1.2.0"}) {
		t.Fatalf("state = %+v", got)
	}
}
