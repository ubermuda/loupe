package cmd

import (
	"context"
	"fmt"
	"slices"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
)

const testBridgeID = "0192f3a1-7777-4d3e-8f10-a2b3c4d5e6f7"

func eventually(t *testing.T, what string, ok func() bool) {
	t.Helper()
	deadline := time.After(5 * time.Second)
	for !ok() {
		select {
		case <-deadline:
			t.Fatalf("timed out waiting for %s", what)
		case <-time.After(5 * time.Millisecond):
		}
	}
}

// offered holds every work request a card helper built, so a fake work server
// can answer its claim with no offer of its own.
var offered sync.Map

// offerSeq numbers the work requests the card helpers build, so each call is
// a new request.
var offerSeq atomic.Int64

// offeredRequest is a work request a card helper built, by id.
func offeredRequest(id string) (api.WorkRequest, bool) {
	w, ok := offered.Load(id)
	if !ok {
		return api.WorkRequest{}, false
	}

	return w.(api.WorkRequest), true
}

var (
	finishedRun   = workerResult{hasResult: true, status: "finished", output: "done"}
	unfinishedRun = workerResult{hasResult: true, status: "unfinished", output: "CI still runs"}
)

// projectRenamed renames the loupe project, which stops its work.
func projectRenamed() string {
	return fmt.Sprintf(`{"type":"project.renamed","subject":{"type":"project","id":%q},"projectId":%q,"fromSlug":"loupe","toSlug":"loupe-app","actor":"human"}`, testProject, testProject)
}

// cardHeld reports whether the router still reserves the card key.
func (h *harness) cardHeld(number int) bool {
	h.router.mu.Lock()
	defer h.router.mu.Unlock()

	return h.router.running[cardUUID(number)]
}

// cardReads answers the card read with a fixed column or error. entered and
// release, when set, let a test hold a read.
type cardReads struct {
	mu      sync.Mutex
	calls   []string
	column  string
	err     error
	entered chan struct{}
	release chan struct{}
}

func (c *cardReads) read(_ context.Context, handle, cardID string) (api.CardRead, error) {
	c.mu.Lock()
	c.calls = append(c.calls, handle+" "+cardID)
	c.mu.Unlock()
	if c.entered != nil {
		c.entered <- struct{}{}
	}
	if c.release != nil {
		<-c.release
	}

	return api.CardRead{Column: c.column}, c.err
}

func (c *cardReads) recorded() []string {
	c.mu.Lock()
	defer c.mu.Unlock()

	return append([]string(nil), c.calls...)
}

// outcomeOf is the last state a run sent.
func outcomeOf(t *testing.T, sent []stateSent, runID string) api.RunStateReport {
	t.Helper()
	states := ofRun(sent, runID)
	if len(states) == 0 {
		t.Fatalf("run %s sent nothing", runID)
	}

	return states[len(states)-1].report
}

// runIDs lists the run ids in the order of their first report.
func runIDs(sent []stateSent) []string {
	var out []string
	seen := map[string]bool{}
	for _, s := range sent {
		if !seen[s.runID] {
			seen[s.runID] = true
			out = append(out, s.runID)
		}
	}

	return out
}

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

// withStartDirs gives the harness a transcript lookup that answers dirs.
func withStartDirs(h *harness, dirs map[string]string) *startDirs {
	s := &startDirs{dirs: dirs}
	h.router.startDir = s.lookup

	return s
}

// movedPayload offers a work request for the card, as the server offers one
// when a card enters a column: plan for next, and the column slug for any
// other column. from and actor no longer reach the bridge.
func movedPayload(number int, _, to, _ string) string {
	kind := to
	if to == "next" {
		kind = "plan"
	}

	return offerPayload(number, kind)
}

// foreignBridge is a bridge id that is not testBridgeID.
const foreignBridge = "7d1e2f3a-4b5c-4d6e-9f0a-1b2c3d4e5f6a"

type pinCall struct {
	handle, experiment, cardID, candidate string
	variants                              []string
	weights                               []int
}

// pinServer answers each pin request with answer, and records it.
type pinServer struct {
	mu     sync.Mutex
	calls  []pinCall
	answer func(ctx context.Context, candidate string) (string, string, error)
}

func (s *pinServer) resolve(ctx context.Context, handle, experiment, cardID, candidate string, variants []string, weights []int) (string, string, error) {
	s.mu.Lock()
	s.calls = append(s.calls, pinCall{handle, experiment, cardID, candidate, slices.Clone(variants), slices.Clone(weights)})
	s.mu.Unlock()

	return s.answer(ctx, candidate)
}

func (s *pinServer) recorded() []pinCall {
	s.mu.Lock()
	defer s.mu.Unlock()

	return slices.Clone(s.calls)
}

func (h *harness) pins(answer func(ctx context.Context, candidate string) (string, string, error)) *pinServer {
	s := &pinServer{answer: answer}
	h.router.resolvePin = s.resolve

	return s
}

// wantExperiment checks the experiment fields of every report of a run: none on
// queued, and want on running and on the outcome.
func wantExperiment(t *testing.T, sent []stateSent, want runPin) {
	t.Helper()
	for _, s := range sent {
		r := s.report
		got := runPin{Experiment: r.Experiment, Variant: r.Variant, RequestedModel: r.RequestedModel, SwitchedFrom: r.SwitchedFrom}
		if r.State == api.RunQueued {
			if got != (runPin{}) {
				t.Fatalf("queued = %+v, want no experiment fields", r)
			}

			continue
		}
		if got != want {
			t.Fatalf("%s = %+v, want %+v", r.State, got, want)
		}
	}
}
