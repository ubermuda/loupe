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

// drain waits until the loop has taken every pending event, so a stop that
// follows does not supersede them.
func drain(t *testing.T, hr *hookRunner) {
	t.Helper()
	eventually(t, "the inbox drained", func() bool {
		hr.mu.Lock()
		defer hr.mu.Unlock()

		return len(hr.inbox) == 0
	})
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
	drain(t, hr)
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
	drain(t, hr)
	hr.fire(hookIdle)
	drain(t, hr)
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
	drain(t, hr)
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
	drain(t, hr)
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
	drain(t, hr)
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
	drain(t, hr)
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

// A package that a reload adds while the bridge is busy gets busy at the
// reload, so the idle that follows reaches it.
func TestAPackageAddedWhileBusyGetsTheIdleThatFollows(t *testing.T) {
	script := "echo \"$1\" >> \"$LOUPE_HOOK_STATE_DIR/log\"\n"
	first := scriptHook(t, "acme/first", script, hookBusy, hookIdle, hookStop)
	added := scriptHook(t, "acme/added", script, hookBusy, hookIdle, hookStop)
	hr := newHookRunner([]hooks.Hook{first}, testBridgeID, newBridgeLogger(&syncBuffer{}))

	hr.start()
	hr.fire(hookBusy)
	drain(t, hr)
	hr.setHooks([]hooks.Hook{first, added})
	drain(t, hr)
	hr.fire(hookIdle)
	drain(t, hr)
	hr.stop()

	if got := readFile(t, filepath.Join(first.StateDir, "log")); got != "busy\nidle\nstop\n" {
		t.Fatalf("first ran:\n%s", got)
	}
	if got := readFile(t, filepath.Join(added.StateDir, "log")); got != "busy\nidle\nstop\n" {
		t.Fatalf("added ran:\n%s", got)
	}
}

// A package that a reload adds while the bridge is idle gets no event until
// the bridge turns busy.
func TestAPackageAddedWhileIdleGetsNoEventAtTheReload(t *testing.T) {
	script := "echo \"$1\" >> \"$LOUPE_HOOK_STATE_DIR/log\"\n"
	first := scriptHook(t, "acme/first", script, hookBusy, hookIdle)
	added := scriptHook(t, "acme/added", script, hookBusy, hookIdle, hookStop)
	hr := newHookRunner([]hooks.Hook{first}, testBridgeID, newBridgeLogger(&syncBuffer{}))

	hr.start()
	hr.fire(hookBusy)
	hr.fire(hookIdle)
	drain(t, hr)
	hr.setHooks([]hooks.Hook{first, added})
	drain(t, hr)
	hr.stop()

	if got := readFile(t, filepath.Join(added.StateDir, "log")); got != "stop\n" {
		t.Fatalf("added ran:\n%s", got)
	}
}

// A reload that removes a package runs its stop once, so it can release what
// busy took. A package the reload keeps runs stop only when the bridge stops.
func TestAReloadRunsStopOnARemovedPackage(t *testing.T) {
	script := "echo \"$1\" >> \"$LOUPE_HOOK_STATE_DIR/log\"\n"
	removed := scriptHook(t, "acme/removed", script, hookBusy, hookStop)
	kept := scriptHook(t, "acme/kept", script, hookBusy, hookStop)
	hr := newHookRunner([]hooks.Hook{removed, kept}, testBridgeID, newBridgeLogger(&syncBuffer{}))

	hr.start()
	hr.fire(hookBusy)
	eventually(t, "busy ran on both packages", func() bool {
		_, a := os.Stat(filepath.Join(removed.StateDir, "log"))
		_, b := os.Stat(filepath.Join(kept.StateDir, "log"))

		return a == nil && b == nil
	})
	hr.setHooks([]hooks.Hook{kept})
	hr.fire(hookIdle)
	hr.stop()

	if got := readFile(t, filepath.Join(removed.StateDir, "log")); got != "busy\nstop\n" {
		t.Fatalf("removed ran:\n%s", got)
	}
	if got := readFile(t, filepath.Join(kept.StateDir, "log")); got != "busy\nstop\n" {
		t.Fatalf("kept ran:\n%s", got)
	}
}

// While a hook hangs, only the last busy or idle stays pending, and one that
// repeats the state the hooks last got is dropped.
func TestAHungHookLeavesOnlyTheLastStateChangePending(t *testing.T) {
	for _, tc := range []struct {
		last, want string
	}{
		{hookIdle, "start\nbusy\nidle\nstop\n"},
		{hookBusy, "start\nbusy\nstop\n"},
	} {
		t.Run(tc.last, func(t *testing.T) {
			gate := filepath.Join(t.TempDir(), "gate")
			h, journal := journalHook(t)
			slow := scriptHook(t, "acme/slow", "while [ ! -e "+gate+" ]; do sleep 0.01; done\n", hookBusy)
			hr := newHookRunner([]hooks.Hook{h, slow}, testBridgeID, newBridgeLogger(&syncBuffer{}))

			hr.start()
			hr.fire(hookBusy)
			drain(t, hr)
			for range 5 {
				hr.fire(hookIdle)
				hr.fire(hookBusy)
			}
			if tc.last == hookIdle {
				hr.fire(hookIdle)
			}
			if err := os.WriteFile(gate, nil, 0o644); err != nil {
				t.Fatal(err)
			}
			drain(t, hr)
			hr.stop()

			if got := readFile(t, journal); got != tc.want {
				t.Fatalf("journal:\n%s", got)
			}
		})
	}
}

// stop supersedes a pending busy or idle, so a shutdown does not wait for
// them. A stop that a reload fired for a removed package still runs.
func TestStopDropsAPendingStateChange(t *testing.T) {
	gate := filepath.Join(t.TempDir(), "gate")
	h, journal := journalHook(t)
	slow := scriptHook(t, "acme/slow", "while [ ! -e "+gate+" ]; do sleep 0.01; done\n", hookStart)
	removed := scriptHook(t, "acme/removed", "echo \"$1\" >> \"$LOUPE_HOOK_STATE_DIR/log\"\n", hookStop)
	hr := newHookRunner([]hooks.Hook{slow, h, removed}, testBridgeID, newBridgeLogger(&syncBuffer{}))

	hr.start()
	drain(t, hr)
	hr.fire(hookBusy)
	hr.setHooks([]hooks.Hook{slow, h})
	hr.fire(hookIdle)
	hr.fire(hookBusy)
	done := make(chan struct{})
	go func() { hr.stop(); close(done) }()
	eventually(t, "stop to fire", func() bool {
		hr.mu.Lock()
		defer hr.mu.Unlock()

		return hr.stopped
	})
	if err := os.WriteFile(gate, nil, 0o644); err != nil {
		t.Fatal(err)
	}
	<-done

	if got := readFile(t, journal); got != "start\nstop\n" {
		t.Fatalf("journal:\n%s", got)
	}
	if got := readFile(t, filepath.Join(removed.StateDir, "log")); got != "stop\n" {
		t.Fatalf("removed ran:\n%s", got)
	}
}

// A package that a reload adds while the bridge is busy has no state yet, so
// it gets the next busy even when the other packages already have it.
func TestAPackageAddedWhileBusyGetsTheNextBusy(t *testing.T) {
	gate := filepath.Join(t.TempDir(), "gate")
	slow := scriptHook(t, "acme/slow", "while [ ! -e "+gate+" ]; do sleep 0.01; done\n", hookBusy)
	h, journal := journalHook(t)
	added := scriptHook(t, "acme/added", "echo \"$1\" >> \"$LOUPE_HOOK_STATE_DIR/log\"\n", hookBusy, hookIdle, hookStop)
	hr := newHookRunner([]hooks.Hook{slow, h}, testBridgeID, newBridgeLogger(&syncBuffer{}))

	hr.start()
	hr.fire(hookBusy)
	drain(t, hr)
	hr.setHooks([]hooks.Hook{slow, h, added})
	hr.fire(hookIdle)
	hr.fire(hookBusy)
	if err := os.WriteFile(gate, nil, 0o644); err != nil {
		t.Fatal(err)
	}
	drain(t, hr)
	hr.stop()

	if got := readFile(t, filepath.Join(added.StateDir, "log")); got != "busy\nstop\n" {
		t.Fatalf("added ran:\n%s", got)
	}
	if got := readFile(t, journal); got != "start\nbusy\nstop\n" {
		t.Fatalf("journal:\n%s", got)
	}
}

// A reload that moves a busy package to a new commit keeps its state, so the
// new commit gets the idle that ends the busy of the old one.
func TestAPackageMovedWhileBusyGetsTheNextIdle(t *testing.T) {
	log := filepath.Join(t.TempDir(), "log")
	old := scriptHook(t, "acme/amp", "echo \"$1\" >> "+log+"\n", hookBusy, hookIdle, hookStop)
	old.SHA = "old"
	hr := newHookRunner([]hooks.Hook{old}, testBridgeID, newBridgeLogger(&syncBuffer{}))

	hr.start()
	hr.fire(hookBusy)
	drain(t, hr)
	moved := old
	moved.SHA = "new"
	hr.setHooks([]hooks.Hook{moved})
	hr.fire(hookIdle)
	drain(t, hr)
	hr.stop()

	if got := readFile(t, log); got != "busy\nidle\nstop\n" {
		t.Fatalf("acme/amp ran:\n%s", got)
	}
}

// A package that reloads remove and add back while busy gets stop, so it gets
// the next busy even though a pending idle and busy coalesce around the stop.
func TestAPackageRemovedAndAddedBackWhileBusyGetsTheNextBusy(t *testing.T) {
	gate := filepath.Join(t.TempDir(), "gate")
	slow := scriptHook(t, "acme/slow", "while [ ! -e "+gate+" ]; do sleep 0.01; done\n", hookBusy)
	back := scriptHook(t, "acme/back", "echo \"$1\" >> \"$LOUPE_HOOK_STATE_DIR/log\"\n", hookBusy, hookIdle, hookStop)
	hr := newHookRunner([]hooks.Hook{slow, back}, testBridgeID, newBridgeLogger(&syncBuffer{}))

	hr.start()
	hr.fire(hookBusy)
	drain(t, hr)
	hr.setHooks([]hooks.Hook{slow})
	hr.setHooks([]hooks.Hook{slow, back})
	hr.fire(hookIdle)
	hr.fire(hookBusy)
	if err := os.WriteFile(gate, nil, 0o644); err != nil {
		t.Fatal(err)
	}
	drain(t, hr)
	hr.stop()

	if got := readFile(t, filepath.Join(back.StateDir, "log")); got != "busy\nstop\nbusy\nstop\n" {
		t.Fatalf("acme/back ran:\n%s", got)
	}
}

// A package with no stop keeps its busy when reloads remove it and add it
// back, so it gets the next idle whatever the loop does meanwhile.
func TestAPackageWithoutStopRemovedAndAddedBackGetsTheNextIdle(t *testing.T) {
	for _, running := range []bool{true, false} {
		t.Run(map[bool]string{true: "hook running", false: "loop waking"}[running], func(t *testing.T) {
			gate := filepath.Join(t.TempDir(), "gate")
			if !running {
				if err := os.WriteFile(gate, nil, 0o644); err != nil {
					t.Fatal(err)
				}
			}
			x := scriptHook(t, "acme/x", "echo \"$1\" >> \"$LOUPE_HOOK_STATE_DIR/log\"\n", hookBusy, hookIdle)
			slow := scriptHook(t, "acme/slow", "while [ ! -e "+gate+" ]; do sleep 0.01; done\n", hookBusy)
			hr := newHookRunner([]hooks.Hook{x, slow}, testBridgeID, newBridgeLogger(&syncBuffer{}))

			hr.start()
			hr.fire(hookBusy)
			drain(t, hr)
			hr.setHooks([]hooks.Hook{slow})
			if !running {
				hr.fire(hookBusy)
				drain(t, hr)
			}
			hr.setHooks([]hooks.Hook{x, slow})
			hr.fire(hookIdle)
			if err := os.WriteFile(gate, nil, 0o644); err != nil {
				t.Fatal(err)
			}
			drain(t, hr)
			hr.stop()

			if got := readFile(t, filepath.Join(x.StateDir, "log")); got != "busy\nidle\n" {
				t.Fatalf("acme/x ran:\n%s", got)
			}
		})
	}
}
