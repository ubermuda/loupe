package cmd

import (
	"slices"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// appPromptRules runs the app prompt of a kind that the work map does not
// hold.
const appPromptRules = "appPrompts: true\n" + workRules

// appPromptOffer is work request n of the review kind, which only the app
// prompt runs.
func appPromptOffer(n int) api.WorkRequest {
	w := workRequest(n, 87, "review", api.WorkRequestOpen)
	w.Prompt = "Review card {cardNumber} for {workRequestId}."

	return w
}

// A bridge that opts in claims an offer of an unmapped kind and runs its
// prompt. A bridge that does not opt in leaves it.
func TestAWorkOfferRunsTheAppPromptOnlyWhenTheBridgeOptsIn(t *testing.T) {
	for name, tc := range map[string]struct {
		body string
		runs bool
	}{
		"opted in":  {appPromptRules, true},
		"no opt-in": {workRules, false},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarnessWith(t, tc.body, rules.Defaults{})
			h.states()
			f := h.withWork()
			h.worker.result = workerResult{hasResult: true, status: "finished"}

			h.offer(f, appPromptOffer(1))

			calls := h.worker.recorded()
			if !tc.runs {
				if len(f.claimed()) != 0 || len(calls) != 0 {
					t.Fatalf("claims = %v, workers = %+v", f.claimed(), calls)
				}

				return
			}
			if got := f.claimed(); !slices.Equal(got, []string{workID(1)}) {
				t.Fatalf("claims = %v", got)
			}
			if len(calls) != 1 || !strings.HasPrefix(calls[0].prompt, "Review card 87 for "+workID(1)+".") || calls[0].rule != "work:review" {
				t.Fatalf("workers = %+v", calls)
			}
		})
	}
}

// A reload that turns appPrompts off drops a queued app prompt offer, and one
// that keeps it on keeps the offer.
func TestAReloadKeepsAQueuedAppPromptOnlyWhileTheBridgeOptsIn(t *testing.T) {
	for name, tc := range map[string]struct {
		body   string
		queued int
	}{
		"kept on":    {appPromptRules, 1},
		"turned off": {workRules, 0},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarnessWith(t, appPromptRules, rules.Defaults{})
			h.states()
			f := h.withWork()
			h.router.personPaused = true
			h.offer(f, appPromptOffer(1))
			if n := h.queueLen(); n != 1 {
				t.Fatalf("queue before the reload = %d", n)
			}

			if res := h.reload(t, tc.body); !res.OK {
				t.Fatalf("reload = %+v", res)
			}

			if n := h.queueLen(); n != tc.queued {
				t.Fatalf("queue = %d, want %d", n, tc.queued)
			}
		})
	}
}

// A person's resume of an app prompt run carries no prompt, and continues the
// session with the default worker settings.
func TestAPersonsResumeContinuesAnAppPromptRun(t *testing.T) {
	h := newHarnessWith(t, appPromptRules, rules.Defaults{})
	h.transcripts(true)
	h.withWork()
	h.states()
	h.worker.result = finishedRun
	c := workResumeOf(endedRunKey)
	c.WorkKind = "review"

	if state, reason := h.resume(c); state != api.CommandDone {
		t.Fatalf("resume = %s %q", state, reason)
	}

	calls := h.worker.recorded()
	if len(calls) != 1 {
		t.Fatalf("workers = %+v", calls)
	}
	if spec := calls[0]; !spec.resume || spec.sessionID != testSession || spec.prompt != directive.RenderResumeByPerson() ||
		spec.rule != rules.WorkRulePrefix+"review" || spec.dir != h.dir {
		t.Fatalf("spec = %+v", spec)
	}
}
