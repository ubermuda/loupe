package cmd

import (
	"context"
	"errors"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"
	"unicode/utf8"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// beforeRules runs a before command ahead of plan, and none ahead of review.
const beforeRules = `
maxWorkers: 2
projects:
  loupe:
    dir: {dir}
work:
  plan:
    prompt: Card {cardNumber}.
    before:
      run: [prepare, '{cardNumber}']
      timeout: TIMEOUT
  review:
    prompt: Review {cardNumber}.
`

// fakeBefore stands in for a before command. It answers with results in turn,
// then with result. started and block let a test hold a command, and a
// held command ends as killed when its context ends.
type fakeBefore struct {
	mu      sync.Mutex
	specs   []procSpec
	results []procResult
	result  procResult
	started chan procSpec
	block   chan struct{}
}

func (f *fakeBefore) run(ctx context.Context, spec procSpec, onStart func(workerProc)) procResult {
	f.mu.Lock()
	f.specs = append(f.specs, spec)
	res := f.result
	if len(f.results) > 0 {
		res, f.results = f.results[0], f.results[1:]
	}
	f.mu.Unlock()
	if res.err == nil {
		onStart(workerProc{pid: 4242})
	}
	if f.started != nil {
		f.started <- spec
	}
	if f.block != nil {
		select {
		case <-f.block:
		case <-ctx.Done():
			return procResult{exitCode: -1, killed: true, timedOut: errors.Is(ctx.Err(), context.DeadlineExceeded)}
		}
	}

	return res
}

func (f *fakeBefore) recorded() []procSpec {
	f.mu.Lock()
	defer f.mu.Unlock()

	return slices.Clone(f.specs)
}

// withBefore gives the harness beforeRules with the timeout, and a fake
// before command that prints folder.
func withBefore(t *testing.T, timeout string) (*harness, *fakeBefore, string) {
	t.Helper()
	h := newHarnessWith(t, strings.Replace(beforeRules, "TIMEOUT", timeout, 1), rules.Defaults{})
	folder := t.TempDir()
	f := &fakeBefore{result: procResult{dir: folder}}
	h.router.worker.before = f.run

	return h, f, folder
}

// The before command runs in the project dir under the run's id, and reports
// preparing with no session. claude then starts in the folder it printed, and
// every report of the run starts when the before command started.
func TestABeforeCommandRunsAheadOfTheWorker(t *testing.T) {
	h, f, folder := withBefore(t, "1m")
	rec := h.states()

	h.send(cardMoved(87))

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunPreparing, api.RunRunning, api.RunSucceeded)
	specs := f.recorded()
	if len(specs) != 1 || !slices.Equal(specs[0].argv, []string{"prepare", "87"}) || specs[0].dir != h.dir || specs[0].runID != sent[0].runID {
		t.Fatalf("before = %+v, want it in %s for run %s", specs, h.dir, sent[0].runID)
	}
	calls := h.worker.recorded()
	if len(calls) != 1 || calls[0].dir != folder {
		t.Fatalf("worker = %+v, want it in %s", calls, folder)
	}
	preparing, running, done := sent[1].report, sent[2].report, sent[3].report
	if preparing.SessionID != "" || preparing.StartedAt.IsZero() {
		t.Fatalf("preparing = %+v", preparing)
	}
	if running.SessionID != testSession || !running.StartedAt.Equal(preparing.StartedAt) || !done.StartedAt.Equal(preparing.StartedAt) {
		t.Fatalf("running = %+v, succeeded = %+v, want the start of preparing", running, done)
	}
}

// A rule with no before command starts claude at once, as before.
func TestARuleWithNoBeforeReportsNoPreparing(t *testing.T) {
	h, f, _ := withBefore(t, "1m")
	rec := h.states()

	h.send(reviewMoved(87))

	wantStates(t, rec.states(), api.RunQueued, api.RunRunning, api.RunSucceeded)
	if len(f.recorded()) != 0 || h.worker.recorded()[0].dir != h.dir {
		t.Fatalf("before = %+v, worker = %+v", f.recorded(), h.worker.recorded())
	}
}

// A failed before command fails the run with its exit code and its output,
// starts no claude and no resume, and frees the slot and the card, so the next
// event of the card starts.
func TestAFailedBeforeFailsTheRunAndFreesTheCard(t *testing.T) {
	h, f, _ := withBefore(t, "1m")
	rec := h.states()
	f.result = procResult{exitCode: 2, output: "npm ci failed"}
	f.started, f.block = make(chan procSpec, 1), make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-f.started
	h.router.onData([]byte(reviewMoved(87)))
	if h.runs() != 0 {
		t.Fatal("the review of the card started while its before command ran")
	}
	close(f.block)
	h.router.wg.Wait()

	runID := rec.states()[0].runID
	wantStates(t, ofRun(rec.states(), runID), api.RunQueued, api.RunPreparing, api.RunFailed)
	failed := outcomeOf(t, rec.states(), runID)
	if failed.ExitCode == nil || *failed.ExitCode != 2 || failed.HasResult == nil || *failed.HasResult || failed.FailureReason != nil {
		t.Fatalf("failed = %+v", failed)
	}
	if failed.Output != "the before command exited with code 2\nnpm ci failed" || failed.SessionID != "" || failed.ResumeSkipped != "" {
		t.Fatalf("failed = %+v", failed)
	}
	calls := h.worker.recorded()
	if len(calls) != 1 || !strings.HasPrefix(calls[0].prompt, "Review 87.") {
		t.Fatalf("worker = %+v, want the review alone", calls)
	}
	if len(h.events(t, "worker_resuming")) != 0 || h.used() != 0 || h.cardHeld(87) {
		t.Fatalf("resumed = %d, used = %d, card held = %v", len(h.events(t, "worker_resuming")), h.used(), h.cardHeld(87))
	}
	h.only(t, "before_failed")
}

// A failed before command with a long output reports the reason first, then
// the end of the output after one truncation marker, within the cap.
func TestAFailedBeforeReportsTheEndOfItsOutput(t *testing.T) {
	h, f, _ := withBefore(t, "1m")
	rec := h.states()
	long := tailOf(strings.Repeat("x", 9000)+"\nthe real error", maxOutput)
	f.result = procResult{exitCode: 1, output: long}

	h.send(cardMoved(87))

	out := outcomeOf(t, rec.states(), rec.states()[0].runID).Output
	if !strings.HasPrefix(out, "the before command exited with code 1\n"+truncatedMark) || !strings.HasSuffix(out, "the real error") || len(out) > maxOutput {
		t.Fatalf("output = %d bytes, %q…", len(out), out[:min(80, len(out))])
	}
	if n := strings.Count(out, "truncated"); n != 1 {
		t.Fatalf("output holds %d truncation markers", n)
	}
}

// A reason longer than the cap keeps its start, and no output follows it.
func TestAFailedBeforeCapsALongReason(t *testing.T) {
	h, f, _ := withBefore(t, "1m")
	rec := h.states()
	f.result = procResult{reason: "the before command printed " + strings.Repeat("y", 9000), output: "rest"}

	h.send(cardMoved(87))

	out := outcomeOf(t, rec.states(), rec.states()[0].runID).Output
	if !strings.HasPrefix(out, "the before command printed y") || strings.Contains(out, "rest") || len(out) > maxOutput+len(truncatedMark) {
		t.Fatalf("output = %d bytes", len(out))
	}
}

// The tail of a text cuts at the start of a rune.
func TestTailOfCutsAtARune(t *testing.T) {
	got := tailOf(strings.Repeat("é", 100), 51)
	if !strings.HasPrefix(got, truncatedMark) || len(got) > 51 || !utf8.ValidString(got) {
		t.Fatalf("tailOf = %q", got)
	}
	if tailOf("short", 51) != "short" {
		t.Fatal("tailOf changed a short text")
	}
}

// The rule's timeout ends a before command that runs too long.
func TestABeforeThatRunsPastItsTimeoutFails(t *testing.T) {
	h, f, _ := withBefore(t, "20ms")
	rec := h.states()
	f.block = make(chan struct{})
	defer close(f.block)

	h.send(cardMoved(87))

	failed := outcomeOf(t, rec.states(), rec.states()[0].runID)
	if failed.State != api.RunFailed || failed.ExitCode == nil || *failed.ExitCode != -1 || !strings.HasPrefix(failed.Output, "the before command ran past its timeout") {
		t.Fatalf("failed = %+v", failed)
	}
	if failed.ResumeSkipped != "" || h.runs() != 0 {
		t.Fatalf("failed = %+v, runs = %d", failed, h.runs())
	}
}

// A folder that is no directory fails the run, although the command exited 0.
func TestABeforeFolderThatIsNoDirectoryFails(t *testing.T) {
	h, f, _ := withBefore(t, "1m")
	rec := h.states()
	f.result = procResult{reason: `the before command printed "x", which is not a directory`}

	h.send(cardMoved(87))

	failed := outcomeOf(t, rec.states(), rec.states()[0].runID)
	if failed.State != api.RunFailed || failed.ExitCode == nil || *failed.ExitCode != -1 || failed.Output != f.result.reason || h.runs() != 0 {
		t.Fatalf("failed = %+v, runs = %d", failed, h.runs())
	}
}

// A before command that never started fails the run too, and never reads as
// preparing.
func TestABeforeThatNeverStartedFails(t *testing.T) {
	h, f, _ := withBefore(t, "1m")
	rec := h.states()
	f.result = procResult{err: errors.New("chdir /gone: no such file or directory")}

	h.send(cardMoved(87))

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunFailed)
	if failed := sent[1].report; failed.ExitCode == nil || *failed.ExitCode != -1 || !strings.Contains(failed.Output, "chdir /gone") || h.runs() != 0 {
		t.Fatalf("failed = %+v, runs = %d", failed, h.runs())
	}
}

// A shutdown while the before command runs kills it, and the run reads as a
// killed run does.
func TestAShutdownDuringBeforeIsAKill(t *testing.T) {
	h, f, _ := withBefore(t, "1m")
	rec := h.states()
	ctx, cancel := context.WithCancel(context.Background())
	h.router.ctx = ctx
	f.started, f.block = make(chan procSpec, 1), make(chan struct{})
	defer close(f.block)

	h.router.onData([]byte(cardMoved(87)))
	<-f.started
	cancel()
	h.router.wg.Wait()

	failed := outcomeOf(t, rec.states(), rec.states()[0].runID)
	if failed.State != api.RunFailed || failed.ResumeSkipped != api.DropShutdown || h.runs() != 0 {
		t.Fatalf("failed = %+v, runs = %d", failed, h.runs())
	}
}

// A person's stop reaches the process group of the before command. The run
// then reports stopped, with no session, and starts no claude.
func TestAStopDuringBeforeStopsTheRun(t *testing.T) {
	for name, after := range map[string]procResult{
		"the command dies":     {exitCode: -1},
		"the command succeeds": {},
	} {
		t.Run(name, func(t *testing.T) {
			h, f, folder := withBefore(t, "1m")
			rec := h.states()
			s := h.stopper()
			s.gone = true
			if after.exitCode == 0 {
				after.dir = folder
			}
			f.result = after
			f.started, f.block = make(chan procSpec, 1), make(chan struct{})
			s.on = func(sig stopSignal) {
				if sig == stopInt {
					close(f.block)
				}
			}

			h.router.onData([]byte(cardMoved(87)))
			<-f.started
			h.stop(t, firstRun(t, rec))
			h.router.wg.Wait()

			wantStates(t, rec.states(), api.RunQueued, api.RunPreparing, api.RunStopping, api.RunStopped)
			stopped := outcomeOf(t, rec.states(), rec.states()[0].runID)
			if stopped.SessionID != "" || h.runs() != 0 || h.used() != 0 || h.cardHeld(87) {
				t.Fatalf("stopped = %+v, runs = %d, used = %d", stopped, h.runs(), h.used())
			}
		})
	}
}

// The before command holds one slot of its pool alone, so another card's
// worker starts in the other slot meanwhile.
func TestAnotherCardStartsWhileABeforeCommandRuns(t *testing.T) {
	h, f, _ := withBefore(t, "1m")
	f.started, f.block = make(chan procSpec, 1), make(chan struct{})

	h.router.onData([]byte(cardMoved(1)))
	<-f.started
	h.router.onData([]byte(reviewMoved(2)))
	eventually(t, "the review of card 2", func() bool { return h.runs() == 1 })
	if h.used() != 1 {
		t.Fatalf("used = %d while card 1 prepares, want 1", h.used())
	}
	close(f.block)
	h.router.wg.Wait()

	if got := startedCards(t, h); !slices.Equal(got, []int{1, 2}) || h.runs() != 2 {
		t.Fatalf("started = %v, runs = %d", got, h.runs())
	}
}

// A work request that resumes the session of an unfinished run runs the
// before command again, and starts in the folder it prints then.
func TestAResumeRunsTheBeforeCommandAgain(t *testing.T) {
	h, f, folder := withBefore(t, "1m")
	h.router.findTranscript = func(string) error { return nil }
	w := workRequest(1, 87, "plan", api.WorkRequestOpen)
	w.ResumeSessionID = testSession
	offered.Store(w.WorkRequestID, w)

	h.send(workPayload(w))

	calls := h.worker.recorded()
	if len(f.recorded()) != 1 || len(calls) != 1 || !calls[0].resume || calls[0].sessionID != testSession || calls[0].dir != folder {
		t.Fatalf("before = %d, worker = %+v", len(f.recorded()), calls)
	}
}

// The before command of a resume reads the pull request of the request, as
// bridge-before.sh takes it, while the worker resumes with its own prompt.
func TestAResumeFillsTheBeforeCommandWithTheContext(t *testing.T) {
	text := strings.NewReplacer("TIMEOUT", "1m", "[prepare, '{cardNumber}']", "[prepare, '{cardNumber}', '{pullRequestNumber}', '{headSha}']").Replace(beforeRules)
	h := newHarnessWith(t, text, rules.Defaults{})
	f := &fakeBefore{result: procResult{dir: t.TempDir()}}
	h.router.worker.before = f.run
	h.router.findTranscript = func(string) error { return nil }
	w := workRequest(1, 87, "plan", api.WorkRequestOpen)
	w.ResumeSessionID = testSession
	w.Context = api.WorkRequestContext{PullRequestNumber: 42, HeadSHA: "abc1234"}
	offered.Store(w.WorkRequestID, w)

	h.send(workPayload(w))

	specs := f.recorded()
	if len(specs) != 1 || !slices.Equal(specs[0].argv, []string{"prepare", "87", "42", "abc1234"}) {
		t.Fatalf("before = %+v", specs)
	}
	if calls := h.worker.recorded(); len(calls) != 1 || !calls[0].resume {
		t.Fatalf("worker = %+v", calls)
	}
}

// A before command that ends while a handover pauses the router keeps its run
// for the next image. A resume of the router starts claude then.
func TestABeforeThatEndsInAHandoverPauseWaitsForIt(t *testing.T) {
	h, f, folder := withBefore(t, "1m")
	f.started, f.block = make(chan procSpec, 1), make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-f.started
	h.router.pause()
	close(f.block)
	eventually(t, "the held start", func() bool {
		h.router.mu.Lock()
		defer h.router.mu.Unlock()

		return len(h.router.heldFinishes) == 1
	})
	if err := h.router.drain(context.Background(), time.Second); err != nil {
		t.Fatal(err)
	}
	st := h.router.freeze()
	if len(st.Live) != 1 || st.Live[0].Phase != phaseBefore || st.Live[0].PID != 4242 || h.runs() != 0 {
		t.Fatalf("live = %+v, runs = %d", st.Live, h.runs())
	}

	h.router.resume()
	h.router.wg.Wait()
	if calls := h.worker.recorded(); len(calls) != 1 || calls[0].dir != folder {
		t.Fatalf("worker = %+v", calls)
	}
}
