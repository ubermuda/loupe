package cmd

import (
	"context"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// resumeDirRules runs a before command ahead of the card rule and of the fix
// rule, which resumes the session a fix request names.
const resumeDirRules = `
projects:
  loupe:
    dir: {dir}
rules:
  - name: plan
    on: board.card_moved
    project: loupe
    to: next
    prompt: Card {cardNumber}.
    before:
      run: [prepare, '{cardNumber}']
  - name: fix
    on: pull_request.fix_requested
    project: loupe
    resume: true
    prompt: Fix card {cardNumber} for {reason}.
    before:
      run: [prepare, '{cardNumber}']
`

// startDirs answers the start folder of each session from dirs, and records
// each session it was asked about.
type startDirs struct {
	mu    sync.Mutex
	dirs  map[string]string
	asked []string
}

func (s *startDirs) lookup(sessionID string) (string, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.asked = append(s.asked, sessionID)

	return s.dirs[sessionID], nil
}

func (s *startDirs) recorded() []string {
	s.mu.Lock()
	defer s.mu.Unlock()

	return slices.Clone(s.asked)
}

// withStartDirs gives the harness a transcript lookup that answers dirs.
func withStartDirs(h *harness, dirs map[string]string) *startDirs {
	s := &startDirs{dirs: dirs}
	h.router.startDir = s.lookup

	return s
}

// A resume starts in the folder its conversation started in, although the
// before command still runs and prints another folder.
func TestAResumeStartsInTheFolderItsConversationStartedIn(t *testing.T) {
	h := newHarnessWith(t, resumeDirRules, rules.Defaults{})
	recorded := t.TempDir()
	f := &fakeBefore{result: beforeResult{dir: t.TempDir()}}
	h.router.worker.before = f.run
	lookups := withStartDirs(h, map[string]string{askSession: recorded})

	h.send(fix{card: 87, session: askSession, bridge: testBridgeID}.payload())

	calls := h.worker.recorded()
	if len(f.recorded()) != 1 || len(calls) != 1 || !calls[0].resume || calls[0].sessionID != askSession || calls[0].dir != recorded {
		t.Fatalf("before = %d, worker = %+v, want a resume in %s", len(f.recorded()), calls, recorded)
	}
	if got := lookups.recorded(); !slices.Equal(got, []string{askSession}) {
		t.Fatalf("lookups = %v", got)
	}
}

// A resume whose folder is gone starts a new session in the folder of the
// before command, with the rule's prompt. The run keeps its id, reports the
// new session on running, and never reads as failed.
func TestAResumeWhoseFolderIsGoneStartsAFreshSession(t *testing.T) {
	h := newHarnessWith(t, resumeDirRules, rules.Defaults{})
	rec := h.states()
	folder := t.TempDir()
	f := &fakeBefore{result: beforeResult{dir: folder}}
	h.router.worker.before = f.run
	withStartDirs(h, map[string]string{askSession: filepath.Join(t.TempDir(), "gone")})

	h.send(fix{card: 87, session: askSession, bridge: testBridgeID}.payload())

	calls := h.worker.recorded()
	if len(f.recorded()) != 1 || len(calls) != 1 {
		t.Fatalf("before = %d, worker = %+v", len(f.recorded()), calls)
	}
	if got := calls[0]; got.resume || got.sessionID != testSession || got.dir != folder || got.prompt != fixPrompt("87") {
		t.Fatalf("worker = %+v, want a new session in %s", got, folder)
	}
	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunResumed, api.RunPreparing, api.RunRunning, api.RunSucceeded)
	if ids := runIDs(sent); len(ids) != 1 {
		t.Fatalf("runs = %v, want one", ids)
	}
	if running, done := sent[3].report, sent[4].report; running.SessionID != testSession || done.SessionID != testSession {
		t.Fatalf("running = %+v, succeeded = %+v", running, done)
	}
	gone := h.only(t, "resume_dir_gone")
	if str(t, gone, "session_id") != askSession || str(t, gone, "new_session_id") != testSession {
		t.Fatalf("resume_dir_gone = %v", gone)
	}
	h.router.mu.Lock()
	session := h.router.sessions[testSession]
	h.router.mu.Unlock()
	if session.number != 87 {
		t.Fatalf("session = %+v, want card 87", session)
	}
}

// A conversation with no transcript on this machine resumes in the folder of
// the before command, as before.
func TestAResumeWithNoTranscriptStartsInTheBeforeFolder(t *testing.T) {
	h := newHarnessWith(t, resumeDirRules, rules.Defaults{})
	folder := t.TempDir()
	h.router.worker.before = (&fakeBefore{result: beforeResult{dir: folder}}).run
	withStartDirs(h, nil)

	h.send(fix{card: 87, session: askSession, bridge: testBridgeID}.payload())

	if calls := h.worker.recorded(); len(calls) != 1 || !calls[0].resume || calls[0].sessionID != askSession || calls[0].dir != folder {
		t.Fatalf("worker = %+v, want a resume in %s", calls, folder)
	}
}

// A rule with no before command resumes in the recorded folder too.
func TestAResumeWithNoBeforeStartsInTheRecordedFolder(t *testing.T) {
	h := newHarnessWith(t, fixRules, rules.Defaults{})
	recorded := t.TempDir()
	withStartDirs(h, map[string]string{askSession: recorded})

	h.send(fix{card: 87, session: askSession, bridge: testBridgeID}.payload())

	if calls := h.worker.recorded(); len(calls) != 1 || !calls[0].resume || calls[0].dir != recorded {
		t.Fatalf("worker = %+v, want a resume in %s", calls, recorded)
	}
}

// A run that starts a new session never reads a transcript.
func TestANewSessionNeverReadsATranscript(t *testing.T) {
	h := newHarnessWith(t, resumeDirRules, rules.Defaults{})
	h.router.worker.before = (&fakeBefore{result: beforeResult{dir: t.TempDir()}}).run
	lookups := withStartDirs(h, nil)

	h.send(cardMoved(87))
	h.send(fix{card: 88}.payload())

	if h.runs() != 2 || len(lookups.recorded()) != 0 {
		t.Fatalf("runs = %d, lookups = %v", h.runs(), lookups.recorded())
	}
}

// The resume of an unfinished run whose folder is gone starts a new session
// with the rule's prompt, in the folder of the before command.
func TestAnUnfinishedRunWhoseFolderIsGoneStartsAFreshSession(t *testing.T) {
	h := newHarnessWith(t, resumeDirRules, rules.Defaults{})
	folder := t.TempDir()
	h.router.worker.before = (&fakeBefore{result: beforeResult{dir: folder}}).run
	withStartDirs(h, map[string]string{testSession: filepath.Join(t.TempDir(), "gone")})
	h.worker.results = []workerResult{unfinishedRun}
	h.worker.result = finishedRun

	h.send(cardMoved(87))

	calls := h.worker.recorded()
	if len(calls) != 2 {
		t.Fatalf("worker = %+v", calls)
	}
	if got := calls[1]; got.resume || got.sessionID != sessionUUID(2) || got.dir != folder || !strings.HasPrefix(got.prompt, "Card 87.") {
		t.Fatalf("resume = %+v, want a new session in %s", got, folder)
	}
}

// A resume that crosses a handover in its before phase starts, under the next
// image, in the folder its conversation started in.
func TestAnAdoptedResumeStartsInTheFolderItsConversationStartedIn(t *testing.T) {
	h := newHarnessWith(t, resumeDirRules, rules.Defaults{})
	h.states()
	f := &fakeBefore{block: make(chan struct{})}
	defer close(f.block)
	h.router.worker.before = f.run
	h.router.onData([]byte(fix{card: 87, session: askSession, bridge: testBridgeID}.payload()))
	eventually(t, "the before command", func() bool {
		h.router.mu.Lock()
		defer h.router.mu.Unlock()

		return len(h.router.live) == 1
	})
	h.router.pause()
	if err := h.router.drain(context.Background(), 5*time.Second); err != nil {
		t.Fatal(err)
	}
	st := roundTrip(t, h.router.freeze())

	h2 := newHarnessWith(t, resumeDirRules, rules.Defaults{})
	h2.states()
	recorded := t.TempDir()
	withStartDirs(h2, map[string]string{askSession: recorded})
	h2.router.worker.adoptBefore = func(context.Context, string) beforeResult { return beforeResult{dir: t.TempDir()} }
	h2.router.adopt(st)
	h2.router.wg.Wait()

	if calls := h2.worker.recorded(); len(calls) != 1 || !calls[0].resume || calls[0].sessionID != askSession || calls[0].dir != recorded {
		t.Fatalf("worker = %+v, want a resume in %s", calls, recorded)
	}
}

// A person's resume of a conversation whose folder is gone fails with the
// reason, and starts no claude, because the rule's prompt has nothing of the
// event that started the series.
func TestAPersonsResumeWhoseFolderIsGoneFails(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.transcripts(true)
	gone := filepath.Join(t.TempDir(), "gone")
	withStartDirs(h, map[string]string{testSession: gone})

	if state, reason := h.resume(resumeOf(endedRunKey)); state != api.CommandDone {
		t.Fatalf("resume = %s %q", state, reason)
	}

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunFailed)
	failed := sent[1].report
	if failed.ExitCode == nil || *failed.ExitCode != -1 || !strings.Contains(failed.Output, gone) || failed.ResumeSkipped != "" {
		t.Fatalf("failed = %+v", failed)
	}
	if h.runs() != 0 || h.used() != 0 || h.cardHeld(87) {
		t.Fatalf("runs = %d, used = %d, card held = %v", h.runs(), h.used(), h.cardHeld(87))
	}
}
