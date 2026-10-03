package cmd

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// fixRules adds a rule that resumes the session a fix request names to the
// card rule of defaultRules.
const fixRules = defaultRules + `
  - name: fix
    on: pull_request.fix_requested
    project: loupe
    resume: true
    prompt: Fix card {cardNumber} for {reason}.
`

// missingSession is what claude prints when it has no session to resume.
const missingSession = "No conversation found with session ID: " + askSession

// fix describes one pull_request.fix_requested payload. An empty session sends
// neither a session nor a bridge.
type fix struct {
	card    int
	session string
	bridge  string
}

func (f fix) payload() string {
	session := ""
	if f.session != "" {
		session = fmt.Sprintf(`,"sessionId":%q,"bridgeId":%q`, f.session, f.bridge)
	}

	return fmt.Sprintf(`{"type":"pull_request.fix_requested","subject":{"type":"card","id":%q},"projectId":%q,"cardNumber":%d,"forge":"github","reason":"checks-failed","actor":"system"%s}`,
		cardUUID(f.card), testProject, f.card, session)
}

func fixPrompt(card string) string {
	return "Fix card " + card + " for checks-failed.\n\n" + directive.Footer
}

// A fix request resumes the session it names with the card footer. An ask
// check reads an inbox ask, and a fix request has none, so none runs.
func TestAFixRequestResumesTheSessionItNames(t *testing.T) {
	h := newHarnessWith(t, fixRules, rules.Defaults{})
	rec := h.states()
	asks := &checks{}
	h.router.checkAsk = asks.check

	h.send(fix{card: 87, session: askSession, bridge: testBridgeID}.payload())

	calls := h.worker.recorded()
	if len(calls) != 1 {
		t.Fatalf("workers = %+v", calls)
	}
	if got := calls[0]; !got.resume || got.sessionID != askSession || got.prompt != fixPrompt("87") {
		t.Fatalf("worker = %+v", got)
	}
	if got := asks.recorded(); len(got) != 0 {
		t.Fatalf("ask checks = %v, want none", got)
	}
	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunResumed, api.RunRunning, api.RunSucceeded)
	if r := sent[0].report; r.CardID != cardUUID(87) || r.CardNumber != 87 || r.AskID != "" {
		t.Fatalf("queued = %+v", r)
	}
	started := h.only(t, "worker_started")
	if num(t, started, "card") != 87 || str(t, started, "session_id") != askSession || str(t, started, "rule") != "fix" {
		t.Fatalf("worker_started = %v", started)
	}
}

// A fix request knows no column, so its resume keeps the column the session
// had. A later ask on the session reads it.
func TestAFixRequestKeepsTheColumnOfItsSession(t *testing.T) {
	h := newHarnessWith(t, fixRules, rules.Defaults{})
	h.router.sessions = map[string]sessionCard{askSession: {key: cardUUID(87), id: cardUUID(87), number: 87, column: "next"}}

	h.send(fix{card: 87, session: askSession, bridge: testBridgeID}.payload())

	h.router.mu.Lock()
	defer h.router.mu.Unlock()
	if got := h.router.sessions[askSession]; got.column != "next" {
		t.Fatalf("session = %+v", got)
	}
}

// A fix request with no session starts a new one.
func TestAFixRequestWithNoSessionStartsAFreshOne(t *testing.T) {
	h := newHarnessWith(t, fixRules, rules.Defaults{})

	h.send(fix{card: 87}.payload())

	calls := h.worker.recorded()
	if len(calls) != 1 || calls[0].resume || calls[0].sessionID != testSession || calls[0].prompt != fixPrompt("87") {
		t.Fatalf("workers = %+v", calls)
	}
}

// Only the bridge that ran the session may resume it.
func TestAFixRequestForAnotherBridgeIsDropped(t *testing.T) {
	h := newHarnessWith(t, fixRules, rules.Defaults{})

	h.send(fix{card: 87, session: askSession, bridge: foreignBridge}.payload())

	if calls := h.worker.recorded(); len(calls) != 0 {
		t.Fatalf("workers = %+v", calls)
	}
	if log := strings.TrimSpace(h.log.String()); log != "" {
		t.Fatalf("log = %s, want nothing", log)
	}
}

// A session this machine no longer has cannot resume. The run fails, and one
// new session runs the same prompt, with its own inbox line.
func TestAFixRequestWhoseSessionIsMissingStartsAFreshRun(t *testing.T) {
	h := newHarnessWith(t, fixRules, rules.Defaults{})
	h.router.onRefresh(listed(map[string]any{api.InboxFlag: true}))
	rec := h.states()
	h.worker.results = []workerResult{{exitCode: 1, output: missingSession}}
	h.worker.result = finishedRun

	h.send(fix{card: 87, session: askSession, bridge: testBridgeID}.payload())

	calls := h.worker.recorded()
	if len(calls) != 2 {
		t.Fatalf("workers = %+v", calls)
	}
	if got := calls[0]; !got.resume || got.sessionID != askSession || got.prompt != fixPrompt("87")+"\n"+inboxLine(askSession) {
		t.Fatalf("first = %+v", got)
	}
	if got := calls[1]; got.resume || got.sessionID != testSession || got.prompt != fixPrompt("87")+"\n"+inboxLine(testSession) {
		t.Fatalf("fresh = %+v", got)
	}
	sent := rec.states()
	ids := runIDs(sent)
	if len(ids) != 2 {
		t.Fatalf("runs = %v", ids)
	}
	wantStates(t, ofRun(sent, ids[0]), api.RunQueued, api.RunResumed, api.RunRunning, api.RunFailed)
	wantStates(t, ofRun(sent, ids[1]), api.RunQueued, api.RunRunning, api.RunSucceeded)
	if queued := ofRun(sent, ids[1])[0].report; queued.Continues != "" {
		t.Fatalf("fresh queued = %+v", queued)
	}
	line := h.only(t, "resume_session_missing")
	if num(t, line, "card") != 87 || str(t, line, "session_id") != askSession || str(t, line, "rule") != "fix" {
		t.Fatalf("resume_session_missing = %v", line)
	}
	if h.cardHeld(87) {
		t.Fatal("the card is still held")
	}
}

// The fresh run never falls back again. When it fails, its own session
// resumes as any failed run does.
func TestAMissingSessionFallsBackOnce(t *testing.T) {
	h := newHarnessWith(t, fixRules, rules.Defaults{})
	h.worker.results = []workerResult{{exitCode: 1, output: missingSession}, {exitCode: 1, output: missingSession}}
	h.worker.result = finishedRun

	h.send(fix{card: 87, session: askSession, bridge: testBridgeID}.payload())

	calls := h.worker.recorded()
	if len(calls) != 3 {
		t.Fatalf("workers = %+v", calls)
	}
	if got := calls[2]; !got.resume || got.sessionID != testSession || got.prompt != directive.RenderResumeUnfinished("exit code 1") {
		t.Fatalf("third = %+v", got)
	}
	if got := h.events(t, "resume_session_missing"); len(got) != 1 {
		t.Fatalf("resume_session_missing = %v", got)
	}
}

// An ask resume whose session is missing resumes the same session again, as
// before. The fallback is for fix requests alone.
func TestAnAskResumeWithAMissingSessionIsUnchanged(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	h.worker.results = []workerResult{{exitCode: 1, output: missingSession}}
	h.worker.result = finishedRun

	h.send(h.mine(ask{card: 87}))

	calls := h.worker.recorded()
	if len(calls) != 2 || !calls[1].resume || calls[1].sessionID != askSession || calls[1].prompt != directive.RenderResumeUnfinished("exit code 1") {
		t.Fatalf("workers = %+v", calls)
	}
	if got := h.events(t, "resume_session_missing"); len(got) != 0 {
		t.Fatalf("resume_session_missing = %v", got)
	}
}

// A fix request resume that a handover adopts while it runs is still a
// resume. When its session is missing, the adopter starts one fresh run.
func TestAnAdoptedFixRequestResumeFallsBackToAFreshRun(t *testing.T) {
	dir := endedRunDir(t, "", 1)
	if err := os.WriteFile(filepath.Join(dir, "stderr"), []byte(missingSession+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	e, err := event.Parse([]byte(fix{card: 87, session: askSession, bridge: testBridgeID}.payload()), map[string]bool{event.FixRequestedType: true})
	if err != nil {
		t.Fatal(err)
	}
	p := pending{key: cardUUID(87), rule: "fix", event: e, runID: "run-9", seq: 3}
	p.spec.resume, p.spec.sessionID = true, askSession
	h1 := newHarnessWith(t, fixRules, rules.Defaults{})
	h1.router.mu.Lock()
	h1.router.hold(p.key)
	h1.router.takeLocked(rules.DefaultPool)
	h1.router.trackLocked(liveRun{p: p, began: time.Now(), proc: workerProc{dir: dir}})
	h1.router.mu.Unlock()
	st := roundTrip(t, h1.router.freeze())

	h2 := newHarnessWith(t, fixRules, rules.Defaults{})
	h2.worker.result = finishedRun
	h2.router.adopt(st)
	h2.router.wg.Wait()

	calls := h2.worker.recorded()
	if len(calls) != 1 || calls[0].resume || calls[0].sessionID != testSession || calls[0].prompt != fixPrompt("87") {
		t.Fatalf("workers = %+v", calls)
	}
	if got := h2.events(t, "resume_session_missing"); len(got) != 1 {
		t.Fatalf("resume_session_missing = %v", got)
	}
}

// A queued fresh run crosses a handover as a fresh run. The adopter matches it
// again, and must not turn it back into a resume of the missing session.
func TestAQueuedFreshRunCrossesAHandover(t *testing.T) {
	e, err := event.Parse([]byte(fix{card: 87, session: askSession, bridge: testBridgeID}.payload()), map[string]bool{event.FixRequestedType: true})
	if err != nil {
		t.Fatal(err)
	}
	p := pending{key: cardUUID(87), rule: "fix", event: e, runID: "run-10", seq: 3, checked: true, fresh: true}
	p.spec.prompt = fixPrompt("87")
	h1 := newHarnessWith(t, fixRules, rules.Defaults{})
	h1.router.mu.Lock()
	h1.router.hold(p.key)
	h1.router.queue = []pending{p}
	h1.router.mu.Unlock()
	st := roundTrip(t, h1.router.freeze())

	h2 := newHarnessWith(t, fixRules, rules.Defaults{})
	h2.router.adopt(st)
	h2.router.wg.Wait()

	calls := h2.worker.recorded()
	if len(calls) != 1 || calls[0].resume || calls[0].sessionID != testSession || calls[0].prompt != fixPrompt("87") {
		t.Fatalf("workers = %+v", calls)
	}
}
