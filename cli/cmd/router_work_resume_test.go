package cmd

import (
	"slices"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// unfinishedSession is the session of an unfinished run that a work request
// asks the bridge to resume.
const unfinishedSession = "0199a0e2-0000-4000-8000-0000000000aa"

// resumingRequest is work request n on card 87 of the implement kind, which
// resumes unfinishedSession.
func resumingRequest(n int) api.WorkRequest {
	w := workRequest(n, 87, "implement", api.WorkRequestOpen)
	w.ResumeSessionID = unfinishedSession

	return w
}

// A work request that names the session of an unfinished run resumes that
// session, and claims and settles as any other.
func TestAWorkRequestResumesTheSessionItNames(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	h.transcripts(true)
	rec := h.states()
	f := h.withWork()
	h.worker.result = workerResult{hasResult: true, status: "finished"}

	h.offer(f, resumingRequest(1))

	calls := h.worker.recorded()
	if len(calls) != 1 || !calls[0].resume || calls[0].sessionID != unfinishedSession || !strings.HasPrefix(calls[0].prompt, directive.RenderResumeUnfinished("status unfinished")) {
		t.Fatalf("workers = %+v", calls)
	}
	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunSucceeded)
	if sent[1].report.SessionID != unfinishedSession {
		t.Fatalf("running = %+v", sent[1].report)
	}
	if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " done"}) {
		t.Fatalf("results = %v", got)
	}
}

// A session that is not on this machine cannot resume, so the work starts a
// new session with the prompt of its entry.
func TestAWorkRequestStartsFreshWhenItsSessionIsNotHere(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	h.transcripts(false)
	h.states()
	f := h.withWork()
	h.worker.result = workerResult{hasResult: true, status: "finished"}

	h.offer(f, resumingRequest(1))

	calls := h.worker.recorded()
	if len(calls) != 1 || calls[0].resume || calls[0].sessionID == unfinishedSession || !strings.HasPrefix(calls[0].prompt, "Implement card 87 for "+workID(1)+".") {
		t.Fatalf("workers = %+v", calls)
	}
}

// When the folder the session started in is gone, the work starts a new
// session in the folder of its project, with the prompt of its entry.
func TestAWorkResumeStartsFreshWhenItsFolderIsGone(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	h.transcripts(true)
	h.states()
	f := h.withWork()
	withStartDirs(h, map[string]string{unfinishedSession: h.dir + "/gone"})
	h.worker.result = workerResult{hasResult: true, status: "finished"}

	h.offer(f, resumingRequest(1))

	calls := h.worker.recorded()
	if len(calls) != 1 || calls[0].resume || calls[0].sessionID == unfinishedSession || calls[0].dir != h.dir || !strings.HasPrefix(calls[0].prompt, "Implement card 87 for "+workID(1)+".") {
		t.Fatalf("workers = %+v", calls)
	}
	if got := f.settled(); !slices.Equal(got, []string{workID(1) + " " + tokenOf(1) + " done"}) {
		t.Fatalf("results = %v", got)
	}
}

// A command takes no session, so a command work request runs its command.
func TestACommandWorkRequestIgnoresTheSessionItNames(t *testing.T) {
	h := newHarnessWith(t, workRules, rules.Defaults{})
	h.transcripts(true)
	h.states()
	f := h.withWork()
	c := &fakeCommand{}
	h.router.worker.command = c.run
	w := workRequest(1, 87, "check", api.WorkRequestOpen)
	w.ResumeSessionID = unfinishedSession

	h.offer(f, w)

	if got := c.recorded(); len(got) != 1 || !slices.Equal(got[0].argv, []string{"check", "87"}) {
		t.Fatalf("commands = %+v", got)
	}
}
