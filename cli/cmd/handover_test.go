//go:build unix

package cmd

import (
	"bytes"
	"context"
	"encoding/json"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/outbound"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// roundTrip passes the state through JSON, as the handover file does.
func roundTrip(t *testing.T, st handoverState) handoverState {
	t.Helper()
	b, err := json.Marshal(st)
	if err != nil {
		t.Fatal(err)
	}
	var back handoverState
	if err := json.Unmarshal(b, &back); err != nil {
		t.Fatal(err)
	}

	return back
}

func stateJSON(t *testing.T, st handoverState) string {
	t.Helper()
	b, err := json.Marshal(st)
	if err != nil {
		t.Fatal(err)
	}

	return string(b)
}

// finalOf is the last state a run reported.
func finalOf(t *testing.T, sent []stateSent, runID string) api.RunStateReport {
	t.Helper()
	of := ofRun(sent, runID)
	if len(of) == 0 {
		t.Fatalf("run %s reported nothing", runID)
	}

	return of[len(of)-1].report
}

// A state frozen, written as JSON and adopted by a fresh router freezes again
// to the same state, and the inventory of the new router names every run.
func TestFreezeAndAdoptKeepTheRoutingState(t *testing.T) {
	h := newHarnessWith(t, withMaxWorkers(defaultRules, 1), rules.Defaults{})
	h.states()
	h.worker.block = make(chan struct{})
	defer close(h.worker.block)

	h.router.onEvent("id-1", []byte(movedPayload(1, "backlog", "next", "agent")))
	h.router.onEvent("id-2", []byte(cardMoved(2)))
	h.router.onEvent("id-3", []byte(cardMoved(3)))
	h.router.onID("id-3")
	h.router.pause()
	if err := h.router.drain(context.Background(), 5*time.Second); err != nil {
		t.Fatal(err)
	}
	st := h.router.freeze()

	if st.Format != handoverFormat || st.LastEventID != "id-3" || !slices.Equal(st.RecentIDs, []string{"id-1", "id-2", "id-3"}) {
		t.Fatalf("state = %+v", st)
	}
	if len(st.Queue) != 2 || st.Queue[0].Event.CardNumber != 2 || st.Queue[1].Event.CardNumber != 3 {
		t.Fatalf("queue = %+v", st.Queue)
	}
	if len(st.Live) != 1 || st.Live[0].Event.CardNumber != 1 || st.Live[0].SessionID != testSession {
		t.Fatalf("live = %+v", st.Live)
	}
	// A queued offer holds no claim, so the server holds the live run alone.
	if st.Sessions[testSession].Key != cardUUID(1) || len(st.Held) != 1 || !st.Running[cardUUID(1)] {
		t.Fatalf("state = %+v", st)
	}

	h2 := newHarnessWith(t, withMaxWorkers(defaultRules, 1), rules.Defaults{})
	rec2 := h2.states()
	release := make(chan struct{})
	h2.router.worker.adopt = func(context.Context, string) workerResult {
		<-release

		return workerResult{hasResult: true}
	}
	h2.router.pause()
	h2.router.adopt(roundTrip(t, st))

	if got, want := stateJSON(t, h2.router.freeze()), stateJSON(t, st); got != want {
		t.Fatalf("adopted state freezes to\n%s\nwant\n%s", got, want)
	}
	h2.router.handler().OnConnect()
	inv := rec2.inventories()
	if len(inv) != 1 || len(inv[0]) != 1 {
		t.Fatalf("inventory = %v, want the adopted run alone", inv)
	}

	close(release)
	h2.router.resume()
	h2.router.wg.Wait()
	if got := startedCards(t, h2); len(got) != 2 || !slices.Contains(got, 2) || !slices.Contains(got, 3) {
		t.Fatalf("started = %v, want both queued offers", got)
	}
}

// An event handled before the freeze is not handled again after the adopt, as
// when the stream replays it.
func TestAnAdoptedRouterSkipsAnEventItHandledBefore(t *testing.T) {
	h := newHarness(t)
	h.router.onEvent("id-1", []byte(cardMoved(1)))
	h.router.wg.Wait()
	st := h.router.freeze()

	h2 := newHarness(t)
	h2.router.adopt(roundTrip(t, st))
	h2.router.onEvent("id-1", []byte(cardMoved(1)))
	h2.router.onEvent("id-2", []byte(cardMoved(2)))
	h2.router.wg.Wait()

	if got := startedCards(t, h2); !slices.Equal(got, []int{2}) {
		t.Fatalf("started = %v, want card 2 alone", got)
	}
	h2.only(t, "event_duplicate")
}

// The recent ids stay bounded, the oldest going first.
func TestTheRouterRemembersABoundedSetOfEventIDs(t *testing.T) {
	h := newHarness(t)
	for i := range recentLimit + 1 {
		h.router.rememberLocked(strconv.Itoa(i))
	}
	if len(h.router.recent) != recentLimit || h.router.recentSet["0"] || !h.router.recentSet[strconv.Itoa(recentLimit)] {
		t.Fatalf("recent holds %d ids, with 0: %v", len(h.router.recent), h.router.recentSet["0"])
	}
}

// A frozen router holds back events and finished runs, and a resume, as after
// a failed handover, handles them all.
func TestResumeReplaysWhatAFreezeHeldBack(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.worker.block = make(chan struct{})
	h.router.onEvent("id-1", []byte(cardMoved(1)))
	if err := h.router.drain(context.Background(), 5*time.Second); err != nil {
		t.Fatal(err)
	}
	st := h.router.freeze()
	h.router.onEvent("id-2", []byte(cardMoved(2)))
	close(h.worker.block)

	deadline := time.Now().Add(5 * time.Second)
	for {
		h.router.mu.Lock()
		n := len(h.router.heldFinishes)
		h.router.mu.Unlock()
		if n == 1 {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("the finished run never reached the held finishes")
		}
		time.Sleep(time.Millisecond)
	}
	if got := startedCards(t, h); !slices.Equal(got, []int{1}) {
		t.Fatalf("started = %v while frozen", got)
	}
	if final := finalOf(t, rec.states(), st.Live[0].RunID); final.State != api.RunRunning {
		t.Fatalf("a frozen router reported %s", final.State)
	}

	h.router.resume()
	h.router.wg.Wait()
	if got := startedCards(t, h); !slices.Equal(got, []int{1, 2}) {
		t.Fatalf("started = %v after resume", got)
	}
	if final := finalOf(t, rec.states(), st.Live[0].RunID); final.State != api.RunSucceeded {
		t.Fatalf("the held run ended as %s", final.State)
	}
}

// pendingQueue is a report queue that never empties.
type pendingQueue struct{ syncQueue }

func (pendingQueue) Pending() int { return 1 }

var _ outbound.Queue = pendingQueue{}

// A report that never leaves the queue fails the drain, so the caller gives up
// the handover.
func TestDrainFailsWhileAReportWaits(t *testing.T) {
	h := newHarness(t)
	if err := h.router.drain(context.Background(), 50*time.Millisecond); err != nil {
		t.Fatalf("drain of an idle router = %v", err)
	}
	h.router.reports = pendingQueue{}
	err := h.router.drain(context.Background(), 50*time.Millisecond)
	if err == nil || !strings.Contains(err.Error(), "1 run reports") {
		t.Fatalf("drain = %v, want the waiting report named", err)
	}
}

// A handover file keeps the state and is private, and a file of another format
// is refused. Format 1 lacks the resume series and the split run output.
func TestTheHandoverFileRoundTripsAndRefusesAnotherFormat(t *testing.T) {
	shortConfigHome(t)
	path, err := handoverPath("rules.yaml")
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(filepath.Base(path), "handover-") || filepath.Ext(path) != ".json" {
		t.Fatalf("path = %s", path)
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		t.Fatal(err)
	}
	want := handoverState{Format: handoverFormat, LastEventID: "id-9", Seq: 4, LockFD: 3, ControlFD: 4, OldVersion: "1.2.0", OldBinary: "/usr/bin/loupe"}
	if err := writeHandover(path, want); err != nil {
		t.Fatal(err)
	}
	info, err := os.Stat(path)
	if err != nil || info.Mode().Perm() != 0o600 {
		t.Fatalf("stat = %v, %v", info, err)
	}
	got, err := readHandover(path)
	if err != nil || stateJSON(t, got) != stateJSON(t, want) {
		t.Fatalf("readHandover = %+v, %v", got, err)
	}

	if err := os.WriteFile(path, []byte(`{"format":1}`), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := readHandover(path); err == nil || !strings.Contains(err.Error(), "format 1") {
		t.Fatalf("readHandover of format 1 = %v", err)
	}
}

// Two live workers that one router started are adopted by another, which
// reports how each ended from its files and then starts the queued card.
func TestAHandoverAdoptsLiveWorkersAndReportsThem(t *testing.T) {
	workerClaude(t, `case "$*" in *"Card 1 ("*) code=3;; *) code=0;; esac
sleep 1
echo "{\"type\":\"result\",\"structured_output\":{\"status\":\"finished\",\"summary\":\"exit $code\"}}"
exit $code
`)
	h := newHarnessWith(t, withMaxWorkers(defaultRules, 2), rules.Defaults{})
	h.states()
	// The first router abandons its workers, as an image that execs does.
	abandon := make(chan struct{})
	defer close(abandon)
	h.router.worker.run = func(_ context.Context, spec workerSpec, onStart func(workerProc)) workerResult {
		cmd, dir, _, err := startWorker(context.Background(), spec)
		if err != nil {
			return workerResult{err: err, dir: dir}
		}
		onStart(workerProc{pid: cmd.Process.Pid, dir: dir})
		<-abandon

		return workerResult{}
	}
	for n := 1; n <= 3; n++ {
		h.router.onEvent("id-"+strconv.Itoa(n), []byte(cardMoved(n)))
	}
	h.router.pause()
	if err := h.router.drain(context.Background(), 5*time.Second); err != nil {
		t.Fatal(err)
	}
	st := h.router.freeze()
	if len(st.Live) != 2 || len(st.Queue) != 1 {
		t.Fatalf("live = %+v, queue = %+v", st.Live, st.Queue)
	}
	path, err := handoverPath("rules.yaml")
	if err != nil {
		t.Fatal(err)
	}
	if err := writeHandover(path, st); err != nil {
		t.Fatal(err)
	}
	st, err = readHandover(path)
	if err != nil {
		t.Fatal(err)
	}

	h2 := newHarnessWith(t, withMaxWorkers(defaultRules, 2), rules.Defaults{})
	rec := h2.states()
	h2.router.worker = defaultWorkerOps()
	h2.router.adopt(st)
	h2.router.wg.Wait()

	sent := rec.states()
	for _, run := range st.Live {
		final := finalOf(t, sent, run.RunID)
		want := 0
		if run.Event.CardNumber == 1 {
			want = 3
		}
		if final.ExitCode == nil || *final.ExitCode != want || final.HasResult == nil || !*final.HasResult {
			t.Fatalf("card %d ended as %+v", run.Event.CardNumber, final)
		}
		if final.Output != "exit "+strconv.Itoa(want) || !final.StartedAt.Equal(run.Began) {
			t.Fatalf("card %d ended as %+v", run.Event.CardNumber, final)
		}
	}
	// The queued offer claims under the new image, so its run starts there.
	third := ofRun(sent, st.Queue[0].RunID)
	wantStates(t, third, api.RunQueued, api.RunRunning, api.RunSucceeded)
	runs, err := config.RunsDir()
	if err != nil {
		t.Fatal(err)
	}
	if left, err := os.ReadDir(runs); err != nil || len(left) != 0 {
		t.Fatalf("runs dir holds %v, %v", left, err)
	}
}

// adoptedRun is a live run of card 9 in the run directory dir. The bridge
// resumes nothing on its own, so a failed run fails at once.
func adoptedRun(dir string) handoverState {
	e := event.Event{Type: event.WorkRequestType, Subject: event.Subject{Type: "work-request", ID: offerID(9)}, ProjectID: testProject, CardID: cardUUID(9), CardNumber: 9}

	return handoverState{
		Format:  handoverFormat,
		Running: map[string]bool{cardUUID(9): true},
		Held:    map[string]api.InventoryRun{"run-9": {RunID: "run-9", ProjectID: testProject, State: api.RunRunning}},
		Live: []handoverRun{{
			RunID: "run-9", Key: cardUUID(9), Rule: "work:plan", Event: e, SessionID: testSession, Began: time.Now(), Dir: dir,
			Origin: &api.WorkRequest{Kind: "plan", ProjectID: testProject, SubjectType: api.SubjectCard, SubjectID: cardUUID(9), CardNumber: 9},
		}},
	}
}

// runDir makes an empty run directory.
func runDir(t *testing.T) string {
	t.Helper()
	shortConfigHome(t)
	runs, err := config.RunsDir()
	if err != nil {
		t.Fatal(err)
	}
	dir := filepath.Join(runs, "run-9")
	if err := os.MkdirAll(dir, 0o700); err != nil {
		t.Fatal(err)
	}

	return dir
}

func adoptInto(t *testing.T, st handoverState) (*harness, api.RunStateReport) {
	t.Helper()
	h := newHarness(t)
	rec := h.states()
	h.router.worker = defaultWorkerOps()
	h.router.adopt(st)
	h.router.wg.Wait()

	return h, finalOf(t, rec.states(), "run-9")
}

// A worker whose parent died is not our child, so its pid is polled until it
// exits, and its outcome still comes from its files.
func TestAnAdoptedWorkerThatIsNoChildIsPolled(t *testing.T) {
	old := adoptPoll
	adoptPoll = 50 * time.Millisecond
	t.Cleanup(func() { adoptPoll = old })
	dir := runDir(t)

	out, err := exec.Command("/bin/sh", "-c",
		`sh -c 'sleep 1; : > "$1/stdout"; echo orphan > "$1/stderr"; echo 5 > "$1/status.exit"' orphan "$0" >/dev/null 2>&1 & echo $!`, dir).Output()
	if err != nil {
		t.Fatal(err)
	}
	pid, err := strconv.Atoi(strings.TrimSpace(string(out)))
	if err != nil {
		t.Fatal(err)
	}
	if err := writeRunRecord(dir, runRecord{PID: pid, StartTime: processStart(pid), RunID: "run-9"}); err != nil {
		t.Fatal(err)
	}
	if !processAlive(pid, "") {
		t.Fatal("the orphan exited before the adopt")
	}

	_, final := adoptInto(t, adoptedRun(dir))
	if final.State != api.RunFailed || final.ExitCode == nil || *final.ExitCode != 5 || final.Output != "orphan" {
		t.Fatalf("the orphan ended as %+v", final)
	}
	if _, err := os.Stat(dir); !os.IsNotExist(err) {
		t.Fatalf("the run directory is still there: %v", err)
	}
}

// A worker that ended before the adopt is reported at once from its files.
func TestAnAdoptedWorkerThatEndedIsReportedAtOnce(t *testing.T) {
	dir := runDir(t)
	cmd := exec.Command("true")
	if err := cmd.Run(); err != nil {
		t.Fatal(err)
	}
	if err := writeRunRecord(dir, runRecord{PID: cmd.Process.Pid, StartTime: "gone", RunID: "run-9"}); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "stdout"), []byte(`{"type":"result","structured_output":{"status":"finished","summary":"done"}}`), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "status.exit"), []byte("0\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	began := time.Now()
	_, final := adoptInto(t, adoptedRun(dir))
	if final.State != api.RunSucceeded || time.Since(began) > time.Second {
		t.Fatalf("the ended worker reported %+v after %s", final, time.Since(began))
	}
}

// A run whose record is gone is reported as failed with the reason, never
// dropped.
func TestAnAdoptedRunWithNoRecordFailsWithAReason(t *testing.T) {
	dir := runDir(t)

	h, final := adoptInto(t, adoptedRun(dir))
	if final.State != api.RunFailed || !strings.Contains(final.Output, "lost this run") {
		t.Fatalf("the run with no record ended as %+v", final)
	}
	h.router.mu.Lock()
	defer h.router.mu.Unlock()
	if h.router.usedLocked() != 0 || len(h.router.running) != 0 || len(h.router.held) != 0 {
		t.Fatalf("active = %d, running = %v, held = %v", h.router.usedLocked(), h.router.running, h.router.held)
	}
}

// A rule the new image no longer has drops its queued event with the usual
// line.
func TestAdoptDropsAQueuedEventWhoseRuleIsGone(t *testing.T) {
	h := newHarness(t)
	h.router.pause()
	h.router.onEvent("id-1", []byte(cardMoved(1)))
	st := h.router.freeze()

	h2 := newHarnessWith(t, strings.ReplaceAll(defaultRules, "  plan:", "  build:"), rules.Defaults{})
	h2.router.adopt(roundTrip(t, st))
	h2.router.wg.Wait()

	if got := dropped(t, h2.only(t, "queue_dropped")); !slices.Equal(got, []string{"1/work:plan"}) {
		t.Fatalf("dropped = %v", got)
	}
	if h2.runs() != 0 {
		t.Fatalf("ran %d workers", h2.runs())
	}
}

// An adopted run takes a slot of the pool the handover names. A file from an
// older image names none, so the pool comes from the rule, else the default.
func TestAnAdoptedRunTakesASlotOfItsPool(t *testing.T) {
	for name, tc := range map[string]struct{ pool, rule, want string }{
		"named":   {pool: "quick", rule: "plan", want: "quick"},
		"derived": {rule: "plan", want: "quick"},
		"unknown": {rule: "gone", want: rules.DefaultPool},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarnessWith(t, quickRules, rules.Defaults{})
			release := make(chan struct{})
			h.router.worker.adopt = func(context.Context, string) workerResult {
				<-release

				return workerResult{hasResult: true}
			}
			st := adoptedRun(t.TempDir())
			st.Live[0].Pool, st.Live[0].Rule, st.Live[0].Origin.Kind = tc.pool, "work:"+tc.rule, tc.rule

			h.router.adopt(roundTrip(t, st))

			wantInUse(t, h, map[string]int{tc.want: 1})
			if pool := str(t, h.only(t, "worker_adopted"), "worker_pool"); pool != tc.want {
				t.Fatalf("worker_pool = %q", pool)
			}
			if live := h.router.freeze().Live; len(live) != 1 || live[0].Pool != tc.want {
				t.Fatalf("live = %+v", live)
			}
			h.router.resume()
			close(release)
			h.router.wg.Wait()
			wantInUse(t, h, map[string]int{})
		})
	}
}

// gateLog blocks the first log line of one event until release closes, which
// holds a goroutine between a change under mu and the report of it.
type gateLog struct {
	buf     *syncBuffer
	name    string
	once    sync.Once
	hit     chan struct{}
	release chan struct{}
}

func newGateLog(h *harness, name string) *gateLog {
	g := &gateLog{buf: h.log, name: name, hit: make(chan struct{}), release: make(chan struct{})}
	h.router.log = newBridgeLogger(g)

	return g
}

func (g *gateLog) Write(p []byte) (int, error) {
	if bytes.Contains(p, []byte(`"event":"`+g.name+`"`)) {
		first := false
		g.once.Do(func() { first = true; close(g.hit) })
		if first {
			<-g.release
		}
	}

	return g.buf.Write(p)
}

// freezeInWindow freezes while the gated line blocks. A freeze that returns in
// that window took its state between the change and its report.
func freezeInWindow(t *testing.T, h *harness, g *gateLog) handoverState {
	t.Helper()
	select {
	case <-g.hit:
	case <-time.After(5 * time.Second):
		t.Fatalf("the log never reached %s", g.name)
	}
	got := make(chan handoverState, 1)
	go func() { got <- h.router.freeze() }()
	select {
	case st := <-got:
		close(g.release)

		return st
	case <-time.After(100 * time.Millisecond):
	}
	close(g.release)

	return <-got
}

// wantClosable fails when the state holds a card or an open run that no live
// run and no queued event of the state accounts for, because the next image
// could never close it.
func wantClosable(t *testing.T, st handoverState) {
	t.Helper()
	runs, keys := map[string]bool{}, map[string]bool{}
	for _, q := range st.Queue {
		runs[q.RunID] = true
	}
	for _, l := range st.Live {
		runs[l.RunID], keys[l.Key] = true, true
	}
	for id := range st.Held {
		if !runs[id] {
			t.Errorf("held run %s is neither live nor queued: %+v", id, st)
		}
	}
	for key, on := range st.Running {
		if on && !keys[key] {
			t.Errorf("card %s is held with nothing running: %+v", key, st)
		}
	}
}

// A worker that ends between the drain and the freeze is in the state, or
// fully reported and released before it.
func TestAFreezeWaitsForAWorkerThatIsSettling(t *testing.T) {
	h := newHarness(t)
	h.states()
	g := newGateLog(h, "worker_finished")
	h.worker.block = make(chan struct{})
	h.router.onEvent("id-1", []byte(cardMoved(1)))
	h.router.pause()
	if err := h.router.drain(context.Background(), 5*time.Second); err != nil {
		t.Fatal(err)
	}
	close(h.worker.block)

	wantClosable(t, freezeInWindow(t, h, g))
	h.router.resume()
	h.router.wg.Wait()
}

// A refresh that drops a queued event while the freeze runs is in the state,
// or fully reported before it.
func TestAFreezeWaitsForARefreshThatDropsTheQueue(t *testing.T) {
	h := newHarness(t)
	h.states()
	g := newGateLog(h, "queue_dropped")
	h.router.pause()
	h.router.onEvent("id-1", []byte(cardMoved(1)))
	go h.router.onRefresh(api.Events{})

	wantClosable(t, freezeInWindow(t, h, g))
	h.router.resume()
	h.router.wg.Wait()
}

// freezeInBefore starts card 87 under beforeRules, and freezes while its
// before command runs.
func freezeInBefore(t *testing.T, h *harness) handoverState {
	t.Helper()
	h.router.onData([]byte(cardMoved(87)))
	eventually(t, "the before command", func() bool {
		h.router.mu.Lock()
		defer h.router.mu.Unlock()

		return len(h.router.live) == 1
	})
	h.router.pause()
	if err := h.router.drain(context.Background(), 5*time.Second); err != nil {
		t.Fatal(err)
	}

	return h.router.freeze()
}

// A run whose before command runs crosses a handover in its before phase. The
// next image waits for the command, then starts claude in its folder with the
// session and the prompt of the run.
func TestAHandoverAdoptsARunInItsBeforeCommand(t *testing.T) {
	h, f, _ := withBefore(t, "1m")
	h.states()
	f.block = make(chan struct{})
	defer close(f.block)
	st := roundTrip(t, freezeInBefore(t, h))
	if len(st.Live) != 1 {
		t.Fatalf("live = %+v", st.Live)
	}
	run := st.Live[0]
	if run.Phase != phaseBefore || run.PID != 4242 || run.SessionID != testSession || !strings.HasPrefix(run.Prompt, "Card 87.") {
		t.Fatalf("live = %+v", run)
	}

	for name, res := range map[string]procResult{
		"a command that succeeds": {dir: t.TempDir()},
		"a command that fails":    {exitCode: 1, output: "npm ci failed"},
	} {
		t.Run(name, func(t *testing.T) {
			h2, _, _ := withBefore(t, "1m")
			rec := h2.states()
			h2.router.worker.adoptBefore = func(context.Context, string) procResult { return res }
			h2.router.adopt(st)
			h2.router.wg.Wait()

			calls := h2.worker.recorded()
			final := finalOf(t, rec.states(), run.RunID)
			if res.exitCode != 0 {
				if len(calls) != 0 || final.State != api.RunFailed || final.Output != "the before command exited with code 1\nnpm ci failed" {
					t.Fatalf("worker = %+v, final = %+v", calls, final)
				}

				return
			}
			if len(calls) != 1 || calls[0].dir != res.dir || calls[0].sessionID != testSession || calls[0].prompt != run.Prompt || calls[0].resume {
				t.Fatalf("worker = %+v, want it in %s", calls, res.dir)
			}
			wantStates(t, ofRun(rec.states(), run.RunID), api.RunRunning, api.RunSucceeded)
			if !final.StartedAt.Equal(run.Began) || h2.used() != 0 {
				t.Fatalf("final = %+v, used = %d", final, h2.used())
			}
		})
	}
	if h.runs() != 0 {
		t.Fatal("the frozen image started claude")
	}
}

// The effort of a run crosses a handover in its before phase, so claude
// starts with it under the next image.
func TestAHandoverInTheBeforePhaseKeepsTheEffort(t *testing.T) {
	h, f, _ := withBefore(t, "1m")
	h.states()
	f.block = make(chan struct{})
	defer close(f.block)
	h.router.onData([]byte(cardMoved(87)))
	eventually(t, "the before command", func() bool {
		h.router.mu.Lock()
		defer h.router.mu.Unlock()

		return len(h.router.live) == 1
	})
	h.router.mu.Lock()
	for id, run := range h.router.live {
		run.p.spec.effort = "high"
		h.router.live[id] = run
	}
	h.router.mu.Unlock()
	h.router.pause()
	if err := h.router.drain(context.Background(), 5*time.Second); err != nil {
		t.Fatal(err)
	}
	st := roundTrip(t, h.router.freeze())
	if len(st.Live) != 1 || st.Live[0].Effort != "high" {
		t.Fatalf("live = %+v", st.Live)
	}

	h2, _, _ := withBefore(t, "1m")
	h2.states()
	h2.router.worker.adoptBefore = func(context.Context, string) procResult { return procResult{dir: t.TempDir()} }
	h2.router.adopt(st)
	h2.router.wg.Wait()

	if calls := h2.worker.recorded(); len(calls) != 1 || calls[0].effort != "high" {
		t.Fatalf("worker = %+v", calls)
	}
}

// A real before command that a frozen image started finishes under the next
// image, which starts claude in its folder and removes the run directory.
func TestAnAdoptedBeforeCommandFinishesUnderTheNextImage(t *testing.T) {
	shortConfigHome(t)
	body := strings.Replace(strings.Replace(beforeRules, "TIMEOUT", "1m", 1), "[prepare, '{cardNumber}']", `[sh, -c, 'sleep 1; mkdir -p tree; echo tree']`, 1)
	h := newHarnessWith(t, body, rules.Defaults{})
	h.states()
	st := roundTrip(t, freezeInBefore(t, h))

	h2 := newHarnessWith(t, body, rules.Defaults{})
	rec := h2.states()
	h2.router.adopt(st)
	h2.router.wg.Wait()

	calls := h2.worker.recorded()
	if len(calls) != 1 || calls[0].dir != filepath.Join(h.dir, "tree") {
		t.Fatalf("worker = %+v, want it in %s", calls, filepath.Join(h.dir, "tree"))
	}
	wantStates(t, ofRun(rec.states(), st.Live[0].RunID), api.RunRunning, api.RunSucceeded)
	runs, err := config.RunsDir()
	if err != nil {
		t.Fatal(err)
	}
	if left, err := os.ReadDir(runs); err != nil || len(left) != 0 {
		t.Fatalf("runs dir holds %v, %v", left, err)
	}
}
