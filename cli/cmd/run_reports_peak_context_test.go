package cmd

import (
	"errors"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
)

// The outcome of a run that ran carries the peak context it read, and nil
// when it read none.
func TestTheOutcomeCarriesThePeakContext(t *testing.T) {
	for name, peak := range map[string]*int64{"read": ptr(int64(31772)), "none": nil} {
		t.Run(name, func(t *testing.T) {
			h := newHarness(t)
			rec := h.states()
			h.worker.result = workerResult{hasResult: true, status: "finished", peakContextTokens: peak}

			h.send(cardMoved(87))

			sent := rec.states()
			if got := outcomeOf(t, sent, runIDs(sent)[0]).PeakContextTokens; show(got) != show(peak) {
				t.Fatalf("peakContextTokens = %v, want %v", show(got), show(peak))
			}
		})
	}
}

// A run that never started has no peak context.
func TestARunThatNeverRanSendsNoPeakContext(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.worker.result = workerResult{err: errors.New("fork/exec claude: permission denied"), peakContextTokens: ptr(int64(5))}

	h.send(cardMoved(87))

	sent := rec.states()
	if got := outcomeOf(t, sent, runIDs(sent)[0]); got.State != api.RunNotStarted || got.PeakContextTokens != nil {
		t.Fatalf("outcome = %+v", got)
	}
}

// The stopped report of a worker carries the peak context it read.
func TestTheStoppedReportCarriesThePeakContext(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.worker.result = workerResult{hasResult: true, status: "unfinished", peakContextTokens: ptr(int64(31772))}
	stopRunning(t, h, rec)

	wantStates(t, rec.states(), api.RunQueued, api.RunRunning, api.RunStopping, api.RunStopped)
	stopped := outcomeOf(t, rec.states(), firstRun(t, rec))
	if stopped.PeakContextTokens == nil || *stopped.PeakContextTokens != 31772 {
		t.Fatalf("peakContextTokens = %v", show(stopped.PeakContextTokens))
	}
}

func show(p *int64) any {
	if p == nil {
		return nil
	}

	return *p
}
