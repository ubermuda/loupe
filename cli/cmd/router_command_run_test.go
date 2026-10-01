package cmd

import (
	"context"
	"encoding/json"
	"errors"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// commandRunRules starts a worker when a card enters next, and runs a command
// when it enters done. One slot is free for workers.
const commandRunRules = `
maxWorkers: 1
projects:
  loupe:
    dir: {dir}
rules:
  - name: plan
    on: board.card_moved
    project: loupe
    to: next
    prompt: Card {cardNumber}.
  - name: teardown
    action: command
    on: board.card_moved
    project: loupe
    to: done
    run: [teardown, '{cardNumber}']
    timeout: TIMEOUT
`

// fakeCommand stands in for the command of a command rule. It answers with
// results in turn, then with result. started and block let a test hold a
// command, and a held command ends as killed when its context ends.
type fakeCommand struct {
	mu      sync.Mutex
	specs   []procSpec
	results []procResult
	result  procResult
	started chan procSpec
	block   chan struct{}
}

func (f *fakeCommand) run(ctx context.Context, spec procSpec, onStart func(workerProc)) procResult {
	f.mu.Lock()
	f.specs = append(f.specs, spec)
	res := f.result
	if len(f.results) > 0 {
		res, f.results = f.results[0], f.results[1:]
	}
	f.mu.Unlock()
	if res.err == nil {
		onStart(workerProc{pid: 4343})
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

func (f *fakeCommand) recorded() []procSpec {
	f.mu.Lock()
	defer f.mu.Unlock()

	return slices.Clone(f.specs)
}

// withCommand gives the harness commandRunRules with the timeout, and a fake
// command.
func withCommand(t *testing.T, timeout string) (*harness, *fakeCommand) {
	t.Helper()
	h := newHarnessWith(t, strings.Replace(commandRunRules, "TIMEOUT", timeout, 1), rules.Defaults{})
	f := &fakeCommand{}
	h.router.worker.command = f.run

	return h, f
}

func doneMoved(number int) string {
	return movedPayload(number, "review", "done", "human")
}

// wantCommandReports checks that every report of the run names the kind
// command and no session, as the server reads it.
func wantCommandReports(t *testing.T, sent []stateSent) {
	t.Helper()
	for _, s := range sent {
		b, err := json.Marshal(s.report)
		if err != nil {
			t.Fatal(err)
		}
		var body map[string]any
		if err := json.Unmarshal(b, &body); err != nil {
			t.Fatal(err)
		}
		_, session := body["sessionId"]
		_, pool := body["workerPool"]
		if body["kind"] != api.RunKindCommand || session || pool {
			t.Fatalf("%s report = %s, want kind command, no session and no pool", s.report.State, b)
		}
	}
}

// A command rule runs its command in the project dir under the run's id. The
// run reports queued, running with a start, and succeeded with the output,
// starts no claude and takes no worker slot.
func TestACommandRuleRunsItsCommand(t *testing.T) {
	h, f := withCommand(t, "1m")
	rec := h.states()
	f.result = procResult{output: "removed the worktree"}

	h.send(doneMoved(87))

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunSucceeded)
	wantCommandReports(t, sent)
	specs := f.recorded()
	if len(specs) != 1 || !slices.Equal(specs[0].argv, []string{"teardown", "87"}) || specs[0].dir != h.dir || specs[0].runID != sent[0].runID {
		t.Fatalf("command = %+v, want it in %s for run %s", specs, h.dir, sent[0].runID)
	}
	running, done := sent[1].report, sent[2].report
	if running.StartedAt.IsZero() || !done.StartedAt.Equal(running.StartedAt) || done.EndedAt.Before(done.StartedAt) {
		t.Fatalf("running = %+v, succeeded = %+v", running, done)
	}
	if done.ExitCode == nil || *done.ExitCode != 0 || done.HasResult == nil || *done.HasResult || done.FailureReason != nil || done.Output != "removed the worktree" {
		t.Fatalf("succeeded = %+v", done)
	}
	if h.runs() != 0 || h.used() != 0 || h.cardHeld(87) {
		t.Fatalf("runs = %d, used = %d, card held = %v", h.runs(), h.used(), h.cardHeld(87))
	}
	h.only(t, "command_started")
	h.only(t, "command_finished")
}

// A failed command fails the run with its exit code, and the reason first in
// the output. It never resumes.
func TestAFailedCommandReportsItsExitCode(t *testing.T) {
	h, f := withCommand(t, "1m")
	rec := h.states()
	f.result = procResult{exitCode: 2, output: "no such worktree"}

	h.send(doneMoved(87))

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunFailed)
	wantCommandReports(t, sent)
	failed := sent[2].report
	if failed.ExitCode == nil || *failed.ExitCode != 2 || failed.HasResult == nil || *failed.HasResult || failed.FailureReason != nil {
		t.Fatalf("failed = %+v", failed)
	}
	if failed.Output != "the command exited with code 2\nno such worktree" || failed.ResumeSkipped != "" || h.cardHeld(87) {
		t.Fatalf("failed = %+v", failed)
	}
	h.only(t, "command_failed")
}

// The rule's timeout ends a command that runs too long, with exit code -1.
func TestACommandThatRunsPastItsTimeoutFails(t *testing.T) {
	h, f := withCommand(t, "20ms")
	rec := h.states()
	f.block = make(chan struct{})
	defer close(f.block)

	h.send(doneMoved(87))

	failed := outcomeOf(t, rec.states(), rec.states()[0].runID)
	if failed.State != api.RunFailed || failed.ExitCode == nil || *failed.ExitCode != -1 || !strings.HasPrefix(failed.Output, "the command ran past its timeout") {
		t.Fatalf("failed = %+v", failed)
	}
}

// A command that never started fails with exit code -1 and never reads as
// running.
func TestACommandThatNeverStartedFails(t *testing.T) {
	h, f := withCommand(t, "1m")
	rec := h.states()
	f.result = procResult{err: errors.New("chdir /gone: no such file or directory")}

	h.send(doneMoved(87))

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunFailed)
	if failed := sent[1].report; failed.ExitCode == nil || *failed.ExitCode != -1 || !strings.HasPrefix(failed.Output, "the command did not start: chdir /gone") || failed.FailureReason != nil {
		t.Fatalf("failed = %+v", failed)
	}
}

// A command waits while a worker holds its card, and a worker that arrives
// after it waits for the command.
func TestACommandWaitsForTheWorkerOfItsCard(t *testing.T) {
	h, f := withCommand(t, "1m")
	h.worker.started, h.worker.block = make(chan workerSpec, 2), make(chan struct{})
	f.started, f.block = make(chan procSpec, 1), make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(doneMoved(87)))
	if len(f.recorded()) != 0 {
		t.Fatal("the command started while the worker of its card ran")
	}
	h.worker.block <- struct{}{}
	<-f.started
	h.router.onData([]byte(cardMoved(87)))
	if h.runs() != 1 {
		t.Fatalf("runs = %d, want the second worker to wait for the command", h.runs())
	}
	close(f.block)
	<-h.worker.started
	close(h.worker.block)
	h.router.wg.Wait()

	if h.runs() != 2 || len(f.recorded()) != 1 {
		t.Fatalf("runs = %d, commands = %d", h.runs(), len(f.recorded()))
	}
}

// A command takes no worker slot, so it starts while every slot is busy.
func TestACommandDoesNotWaitForAWorkerSlot(t *testing.T) {
	h, f := withCommand(t, "1m")
	h.worker.started, h.worker.block = make(chan workerSpec, 1), make(chan struct{})

	h.router.onData([]byte(cardMoved(1)))
	<-h.worker.started
	h.router.onData([]byte(doneMoved(2)))
	eventually(t, "the command of card 2", func() bool { return len(f.recorded()) == 1 })
	if h.used() != 1 {
		t.Fatalf("used = %d, want the worker's slot alone", h.used())
	}
	close(h.worker.block)
	h.router.wg.Wait()
}

// An agent's move that runs a command never counts toward the chain cap.
func TestACommandRunCountsNoChain(t *testing.T) {
	h, f := withCommand(t, "1m")

	for range 5 {
		h.send(movedPayload(87, "review", "done", "agent"))
	}

	h.router.mu.Lock()
	chains := len(h.router.chains)
	h.router.mu.Unlock()
	if len(f.recorded()) != 5 || chains != 0 {
		t.Fatalf("commands = %d, chains = %d", len(f.recorded()), chains)
	}
}

// A person's stop reaches the process group of the command, and the run
// reports stopped with no session.
func TestAStopEndsACommandRun(t *testing.T) {
	h, f := withCommand(t, "1m")
	rec := h.states()
	s := h.stopper()
	s.gone = true
	f.result = procResult{exitCode: -1}
	f.started, f.block = make(chan procSpec, 1), make(chan struct{})
	s.on = func(sig stopSignal) {
		if sig == stopInt {
			close(f.block)
		}
	}

	h.router.onData([]byte(doneMoved(87)))
	<-f.started
	h.stop(t, firstRun(t, rec))
	h.router.wg.Wait()

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunStopping, api.RunStopped)
	wantCommandReports(t, sent)
	if sigs, _ := s.recorded(); len(sigs) == 0 || sigs[0] != stopInt {
		t.Fatalf("signals = %v", sigs)
	}
	if h.cardHeld(87) {
		t.Fatal("the stopped command still holds its card")
	}
}

// freezeInCommand freezes the router while the command of card 87 runs.
func freezeInCommand(t *testing.T, h *harness) handoverState {
	t.Helper()
	h.router.onData([]byte(doneMoved(87)))
	eventually(t, "the command", func() bool {
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

// A command run crosses a handover in its command phase. The next image waits
// for the command and reports how it ended, with the kind command.
func TestAHandoverAdoptsACommandRun(t *testing.T) {
	h, f := withCommand(t, "1m")
	h.states()
	f.block = make(chan struct{})
	defer close(f.block)
	st := roundTrip(t, freezeInCommand(t, h))
	if len(st.Live) != 1 {
		t.Fatalf("live = %+v", st.Live)
	}
	run := st.Live[0]
	if run.Phase != phaseCommand || run.PID != 4343 || run.SessionID != "" || run.Pool != "" {
		t.Fatalf("live = %+v", run)
	}

	for name, tc := range map[string]struct {
		res   procResult
		state string
	}{
		"a command that succeeds": {procResult{output: "done"}, api.RunSucceeded},
		"a command that fails":    {procResult{exitCode: 1, output: "boom"}, api.RunFailed},
	} {
		t.Run(name, func(t *testing.T) {
			h2, _ := withCommand(t, "1m")
			rec := h2.states()
			h2.router.worker.adoptCommand = func(context.Context, string) procResult { return tc.res }
			h2.router.adopt(st)
			h2.router.wg.Wait()

			final := finalOf(t, rec.states(), run.RunID)
			wantCommandReports(t, ofRun(rec.states(), run.RunID))
			if final.State != tc.state || !final.StartedAt.Equal(run.Began) || h2.used() != 0 || h2.cardHeld(87) || h2.runs() != 0 {
				t.Fatalf("final = %+v, used = %d", final, h2.used())
			}
		})
	}
}

// A command queued behind a worker crosses a handover in the queue, and keeps
// its kind.
func TestAHandoverKeepsAQueuedCommand(t *testing.T) {
	h, _ := withCommand(t, "1m")
	h.states()
	h.worker.started, h.worker.block = make(chan workerSpec, 1), make(chan struct{})
	defer close(h.worker.block)
	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(doneMoved(87)))
	h.router.pause()
	if err := h.router.drain(context.Background(), 5*time.Second); err != nil {
		t.Fatal(err)
	}
	st := roundTrip(t, h.router.freeze())

	h2, f2 := withCommand(t, "1m")
	h2.router.worker.adopt = func(context.Context, string) workerResult { return workerResult{hasResult: true, status: "finished"} }
	h2.router.adopt(st)
	h2.router.wg.Wait()

	if specs := f2.recorded(); len(specs) != 1 || !slices.Equal(specs[0].argv, []string{"teardown", "87"}) {
		t.Fatalf("command = %+v", specs)
	}
}
