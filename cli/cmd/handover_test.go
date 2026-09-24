//go:build unix

package cmd

import (
	"context"
	"encoding/json"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
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
	h := newHarness(t)
	h.states()
	h.router.maxWorkers = 1
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
	if st.Chains[cardUUID(1)]["plan"] != 1 || st.Sessions[testSession].Key != cardUUID(1) || len(st.Held) != 3 || !st.Running[cardUUID(1)] {
		t.Fatalf("state = %+v", st)
	}

	h2 := newHarness(t)
	rec2 := h2.states()
	release := make(chan struct{})
	h2.router.worker.adopt = func(context.Context, string) workerResult {
		<-release

		return workerResult{hasResult: true}
	}
	h2.router.maxWorkers = 1
	h2.router.pause()
	h2.router.adopt(roundTrip(t, st))

	if got, want := stateJSON(t, h2.router.freeze()), stateJSON(t, st); got != want {
		t.Fatalf("adopted state freezes to\n%s\nwant\n%s", got, want)
	}
	h2.router.handler().OnConnect()
	inv := rec2.inventories()
	if len(inv) != 1 || len(inv[0]) != 3 {
		t.Fatalf("inventory = %v, want the adopted run and both queued ones", inv)
	}

	close(release)
	h2.router.resume()
	h2.router.wg.Wait()
	if got := startedCards(t, h2); !slices.Equal(got, []int{2, 3}) {
		t.Fatalf("started = %v, want the queue in order", got)
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
// is refused.
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

	if err := os.WriteFile(path, []byte(`{"format":2}`), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := readHandover(path); err == nil || !strings.Contains(err.Error(), "format 2") {
		t.Fatalf("readHandover of format 2 = %v", err)
	}
}

// Two live workers that one router started are adopted by another, which
// reports how each ended from its files and then starts the queued card.
func TestAHandoverAdoptsLiveWorkersAndReportsThem(t *testing.T) {
	workerClaude(t, `case "$*" in *"Card 1 ("*) code=3;; *) code=0;; esac
sleep 1
echo "STAGE RESULT: exit $code"
exit $code
`)
	h := newHarness(t)
	h.states()
	h.router.maxWorkers = 2
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

	h2 := newHarness(t)
	rec := h2.states()
	h2.router.maxWorkers = 2
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
		if final.Output != "STAGE RESULT: exit "+strconv.Itoa(want) || !final.StartedAt.Equal(run.Began) {
			t.Fatalf("card %d ended as %+v", run.Event.CardNumber, final)
		}
	}
	third := ofRun(sent, st.Queue[0].RunID)
	wantStates(t, third, api.RunRunning, api.RunSucceeded)
	runs, err := config.RunsDir()
	if err != nil {
		t.Fatal(err)
	}
	if left, err := os.ReadDir(runs); err != nil || len(left) != 0 {
		t.Fatalf("runs dir holds %v, %v", left, err)
	}
}

// adoptedRun is a live run of card 9 in the run directory dir.
func adoptedRun(dir string) handoverState {
	e := event.Event{Type: event.CardMovedType, Subject: event.Subject{Type: "card", ID: cardUUID(9)}, ProjectID: testProject, CardNumber: 9, FromStatus: "backlog", ToStatus: "next", Actor: event.ActorHuman}

	return handoverState{
		Format:  handoverFormat,
		Running: map[string]bool{cardUUID(9): true},
		Held:    map[string]api.InventoryRun{"run-9": {RunID: "run-9", ProjectID: testProject, State: api.RunRunning}},
		Live:    []handoverRun{{RunID: "run-9", Key: cardUUID(9), Rule: "plan", Event: e, SessionID: testSession, Began: time.Now(), Dir: dir}},
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
		`sh -c 'sleep 1; echo "STAGE RESULT: orphan" > "$1/output"; echo 5 > "$1/status.exit"' orphan "$0" >/dev/null 2>&1 & echo $!`, dir).Output()
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
	if final.State != api.RunFailed || final.ExitCode == nil || *final.ExitCode != 5 || final.Output != "STAGE RESULT: orphan" {
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
	if err := os.WriteFile(filepath.Join(dir, "output"), []byte("STAGE RESULT: done\n"), 0o600); err != nil {
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
	if h.router.active != 0 || len(h.router.running) != 0 || len(h.router.held) != 0 {
		t.Fatalf("active = %d, running = %v, held = %v", h.router.active, h.router.running, h.router.held)
	}
}

// A rule the new image no longer has drops its queued event with the usual
// line.
func TestAdoptDropsAQueuedEventWhoseRuleIsGone(t *testing.T) {
	h := newHarness(t)
	h.router.pause()
	h.router.onEvent("id-1", []byte(cardMoved(1)))
	st := h.router.freeze()

	h2 := newHarnessWith(t, strings.ReplaceAll(defaultRules, "name: plan", "name: build"), rules.Defaults{})
	h2.router.adopt(roundTrip(t, st))
	h2.router.wg.Wait()

	if got := dropped(t, h2.only(t, "queue_dropped")); !slices.Equal(got, []string{"1/plan"}) {
		t.Fatalf("dropped = %v", got)
	}
	if h2.runs() != 0 {
		t.Fatalf("ran %d workers", h2.runs())
	}
}
