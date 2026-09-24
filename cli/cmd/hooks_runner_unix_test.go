//go:build unix

package cmd

import (
	"context"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/hooks"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// scriptHook installs script as ./hook.sh of a new package directory.
func scriptHook(t *testing.T, id, script string, events ...string) hooks.Hook {
	t.Helper()
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "hook.sh"), []byte("#!/bin/sh\n"+script), 0o755); err != nil {
		t.Fatal(err)
	}
	h := testHook(id, dir, events...)
	h.StateDir = filepath.Join(t.TempDir(), "state", id)

	return h
}

func readFile(t *testing.T, path string) string {
	t.Helper()
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}

	return string(data)
}

// One hook runs in its package directory with the bridge's environment, the
// hook variables and an empty stdin. A hook without the event is skipped.
func TestAHookRunsInItsPackageWithItsEnvironment(t *testing.T) {
	t.Setenv("LOUPE_TEST_INHERITED", "yes")
	script := `{
echo "event=$LOUPE_HOOK_EVENT"
echo "package=$LOUPE_HOOK_PACKAGE"
echo "bridge=$LOUPE_BRIDGE_ID"
echo "state=$LOUPE_HOOK_STATE_DIR"
echo "takeover=$LOUPE_HOOK_SETTING_TAKE_OVER"
echo "inherited=$LOUPE_TEST_INHERITED"
echo "arg=$1"
echo "pwd=$(pwd -P)"
echo "stdin=$(cat)"
} > "$LOUPE_HOOK_STATE_DIR/env"
`
	h := scriptHook(t, "acme/tool", script, hookBusy)
	h.Settings = map[string]string{"take_over": "true"}
	other := scriptHook(t, "acme/other", "touch \"$LOUPE_HOOK_STATE_DIR/ran\"\n", hookIdle)
	log := &syncBuffer{}
	hr := newHookRunner([]hooks.Hook{h, other}, testBridgeID, newBridgeLogger(log))

	hr.start()
	hr.fire(hookBusy)
	hr.stop()

	dir, err := filepath.EvalSymlinks(h.Dir)
	if err != nil {
		t.Fatal(err)
	}
	want := strings.Join([]string{
		"event=busy", "package=acme/tool", "bridge=" + testBridgeID, "state=" + h.StateDir,
		"takeover=true", "inherited=yes", "arg=busy", "pwd=" + dir, "stdin=",
	}, "\n") + "\n"
	if got := readFile(t, filepath.Join(h.StateDir, "env")); got != want {
		t.Fatalf("env:\n%s", got)
	}
	if _, err := os.Stat(filepath.Join(other.StateDir, "ran")); !os.IsNotExist(err) {
		t.Fatalf("the idle hook ran on busy: %v", err)
	}
	if !strings.Contains(log.String(), `"event":"hook_ran","package":"acme/tool","hook_event":"busy"`) {
		t.Fatalf("log = %s", log.String())
	}
	rows := hr.rows()
	if rows[0].Outcome != hookOK || rows[0].LastRunAt == "" || rows[0].Error != "" || rows[1].Outcome != hookNever {
		t.Fatalf("rows = %+v", rows)
	}
	if _, err := time.Parse(time.RFC3339, rows[0].LastRunAt); err != nil {
		t.Fatal(err)
	}
}

// Events run one at a time in arrival order, and the hooks of one event run in
// install order. start and stop fire around the rest.
func TestEventsAndHooksRunInOrder(t *testing.T) {
	journal := filepath.Join(t.TempDir(), "journal")
	script := "sleep 0.05\necho \"$LOUPE_HOOK_PACKAGE $1\" >> " + journal + "\n"
	all := []string{hookStart, hookStop, hookBusy, hookIdle}
	hr := newHookRunner([]hooks.Hook{scriptHook(t, "acme/a", script, all...), scriptHook(t, "acme/b", script, all...)}, testBridgeID, newBridgeLogger(&syncBuffer{}))

	hr.start()
	hr.fire(hookBusy)
	hr.fire(hookIdle)
	hr.stop()
	hr.fire(hookBusy)

	want := "acme/a start\nacme/b start\nacme/a busy\nacme/b busy\nacme/a idle\nacme/b idle\nacme/a stop\nacme/b stop\n"
	if got := readFile(t, journal); got != want {
		t.Fatalf("journal:\n%s", got)
	}
}

// A failed hook is logged with the tail of its output, and the next hook
// still runs.
func TestAFailedHookIsLoggedAndTheNextRuns(t *testing.T) {
	failing := scriptHook(t, "acme/failing", "head -c 3000 /dev/zero | tr '\\0' 'x'\necho END\nexit 3\n", hookBusy)
	next := scriptHook(t, "acme/next", "true\n", hookBusy)
	log := &syncBuffer{}
	hr := newHookRunner([]hooks.Hook{failing, next}, testBridgeID, newBridgeLogger(log))

	hr.start()
	hr.fire(hookBusy)
	hr.stop()

	rows := hr.rows()
	wantErr := strings.Repeat("x", 497) + "END"
	if rows[0].Outcome != hookFailed || rows[0].Error != wantErr {
		t.Fatalf("row = %+v", rows[0])
	}
	if rows[1].Outcome != hookOK {
		t.Fatalf("next row = %+v", rows[1])
	}
	if !strings.Contains(log.String(), `"event":"hook_failed","package":"acme/failing","hook_event":"busy","exit_code":3,"output":"`+wantErr+`"`) {
		t.Fatalf("log = %s", log.String())
	}
}

// A hook that cannot start fails with the exec error.
func TestAHookThatCannotStartFails(t *testing.T) {
	h := scriptHook(t, "acme/broken", "true\n", hookBusy)
	if err := os.Chmod(filepath.Join(h.Dir, "hook.sh"), 0o644); err != nil {
		t.Fatal(err)
	}
	log := &syncBuffer{}
	hr := newHookRunner([]hooks.Hook{h}, testBridgeID, newBridgeLogger(log))

	hr.start()
	hr.fire(hookBusy)
	hr.stop()

	row := hr.rows()[0]
	if row.Outcome != hookFailed || !strings.Contains(row.Error, "permission denied") {
		t.Fatalf("row = %+v", row)
	}
	if !strings.Contains(log.String(), `"event":"hook_failed"`) || !strings.Contains(log.String(), "permission denied") {
		t.Fatalf("log = %s", log.String())
	}
}

// A hook past its time limit is killed with its whole process group.
func TestAHookPastItsTimeLimitIsKilled(t *testing.T) {
	h := scriptHook(t, "acme/slow", "echo started\nsleep 30 &\nsleep 30\n", hookBusy)
	log := &syncBuffer{}
	hr := newHookRunner([]hooks.Hook{h}, testBridgeID, newBridgeLogger(log))
	hr.timeout = 200 * time.Millisecond

	began := time.Now()
	hr.start()
	hr.fire(hookBusy)
	hr.stop()

	if elapsed := time.Since(began); elapsed > 3*time.Second {
		t.Fatalf("the runner waited %s", elapsed)
	}
	row := hr.rows()[0]
	if row.Outcome != hookTimeout || row.Error != "started" {
		t.Fatalf("row = %+v", row)
	}
	if !strings.Contains(log.String(), `"event":"hook_timeout","package":"acme/slow","hook_event":"busy"`) {
		t.Fatalf("log = %s", log.String())
	}
}

// journalHook appends each event it gets to the returned file.
func journalHook(t *testing.T) (hooks.Hook, string) {
	t.Helper()
	journal := filepath.Join(t.TempDir(), "journal")

	return scriptHook(t, "acme/journal", "echo \"$1\" >> "+journal+"\n", hookStart, hookStop, hookBusy, hookIdle), journal
}

// The bridge fires start before it connects, and stop once the stream ends.
// subscribe returns after stop has run.
func TestTheBridgeFiresStartThenStop(t *testing.T) {
	fake := &fakeLoupe{}
	server := httptest.NewServer(http.HandlerFunc(fake.serve))
	t.Cleanup(server.Close)
	cfg := testLogin(server.URL)
	set, _ := loadRules(t, defaultRules, rules.Defaults{})
	h, journal := journalHook(t)
	r := withRules(&router{log: newBridgeLogger(&syncBuffer{}), maxWorkers: defaultMaxWorkers, worker: (&fakeWorker{}).ops()}, set)
	r.hookRunner = newHookRunner([]hooks.Hook{h}, testBridgeID, r.log)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	cmd := &cobra.Command{}
	cmd.SetContext(ctx)
	done := make(chan error, 1)
	go func() { done <- subscribe(cmd, cfg, r) }()

	eventually(t, "the start hook", func() bool {
		data, _ := os.ReadFile(journal)

		return string(data) == "start\n"
	})
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
	if got := readFile(t, journal); got != "start\nstop\n" {
		t.Fatalf("journal:\n%s", got)
	}
}

// A bridge that fails before it connects still fires stop.
func TestAnEarlyReturnStillFiresStop(t *testing.T) {
	broken := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	t.Cleanup(broken.Close)
	set, _ := loadRules(t, defaultRules, rules.Defaults{})
	h, journal := journalHook(t)
	r := withRules(&router{log: newBridgeLogger(&syncBuffer{}), maxWorkers: defaultMaxWorkers, worker: (&fakeWorker{}).ops()}, set)
	r.hookRunner = newHookRunner([]hooks.Hook{h}, testBridgeID, r.log)
	cmd := &cobra.Command{}
	cmd.SetContext(context.Background())

	if err := subscribe(cmd, testLogin(broken.URL), r); err == nil {
		t.Fatal("subscribe passed")
	}
	if got := readFile(t, journal); got != "start\nstop\n" {
		t.Fatalf("journal:\n%s", got)
	}
}

// Each run sends the rows to the heartbeat at once.
func TestARunSendsTheRowsToTheHeartbeat(t *testing.T) {
	client := &fakeHeartbeats{}
	hh := startHeartbeater(t, client, time.Minute)
	hr := newHookRunner([]hooks.Hook{scriptHook(t, "acme/tool", "true\n", hookBusy)}, testBridgeID, newBridgeLogger(&syncBuffer{}))

	hr.attach(hh.h)
	hr.start()
	hr.fire(hookBusy)
	hr.stop()

	eventually(t, "a heartbeat with the run", func() bool {
		client.mu.Lock()
		defer client.mu.Unlock()
		last := client.sent[len(client.sent)-1]

		return slices.EqualFunc(last.Hooks, []api.HookReport{{Package: "acme/tool", Event: hookBusy}}, func(a, b api.HookReport) bool {
			return a.Package == b.Package && a.Event == b.Event && a.Outcome == hookOK
		})
	})
}
