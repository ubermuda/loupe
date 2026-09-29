package cmd

import (
	"context"
	"errors"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// experimentRules is defaultRules with the plan rule in an experiment.
var experimentRules = strings.Replace(defaultRules, "rules:\n", `experiments:
  - name: impl-model
    variants:
      - name: opus
        weight: 1
        model: claude-opus-5-5
      - name: sonnet
        weight: 1
        model: claude-sonnet-5-5
rules:
`, 1) + "    experiment: impl-model\n"

// testExperiment is the experiment of experimentRules.
var testExperiment = rules.Experiment{Name: "impl-model", Variants: []rules.Variant{
	{Name: "opus", Weight: 1, Model: "claude-opus-5-5"},
	{Name: "sonnet", Weight: 1, Model: "claude-sonnet-5-5"},
}}

// otherVariant is the variant of testExperiment that v is not.
func otherVariant(v rules.Variant) rules.Variant {
	if v.Name == "opus" {
		return testExperiment.Variants[1]
	}

	return testExperiment.Variants[0]
}

type pinCall struct {
	handle, experiment, cardID, candidate string
	variants                              []string
}

// pinServer answers each pin request with answer, and records it.
type pinServer struct {
	mu     sync.Mutex
	calls  []pinCall
	answer func(ctx context.Context, candidate string) (string, string, error)
}

func (s *pinServer) resolve(ctx context.Context, handle, experiment, cardID, candidate string, variants []string) (string, string, error) {
	s.mu.Lock()
	s.calls = append(s.calls, pinCall{handle, experiment, cardID, candidate, slices.Clone(variants)})
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

// A card with no pin takes the candidate, and the worker runs its model.
func TestARunInAnExperimentRunsThePinnedVariant(t *testing.T) {
	h := newHarnessWith(t, experimentRules, rules.Defaults{})
	rec := h.states()
	candidate := testExperiment.Pick(cardUUID(87))
	pins := h.pins(func(_ context.Context, c string) (string, string, error) { return c, "", nil })

	h.send(cardMoved(87))

	calls := pins.recorded()
	want := pinCall{testProject, "impl-model", cardUUID(87), candidate.Name, []string{"opus", "sonnet"}}
	if len(calls) != 1 || calls[0].handle != want.handle || calls[0].experiment != want.experiment ||
		calls[0].cardID != want.cardID || calls[0].candidate != want.candidate || !slices.Equal(calls[0].variants, want.variants) {
		t.Fatalf("pin calls = %+v, want %+v", calls, want)
	}
	if workers := h.worker.recorded(); len(workers) != 1 || workers[0].model != candidate.Model {
		t.Fatalf("workers = %+v, want model %s", workers, candidate.Model)
	}
	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunSucceeded)
	wantExperiment(t, sent, runPin{Experiment: "impl-model", Variant: candidate.Name, RequestedModel: candidate.Model})
}

// A card keeps the variant of its pin, whatever the bridge drew.
func TestARunKeepsTheVariantTheServerPinned(t *testing.T) {
	h := newHarnessWith(t, experimentRules, rules.Defaults{})
	rec := h.states()
	pinned := otherVariant(testExperiment.Pick(cardUUID(87)))
	h.pins(func(context.Context, string) (string, string, error) { return pinned.Name, "", nil })

	h.send(cardMoved(87))

	if workers := h.worker.recorded(); len(workers) != 1 || workers[0].model != pinned.Model {
		t.Fatalf("workers = %+v, want model %s", workers, pinned.Model)
	}
	wantExperiment(t, rec.states(), runPin{Experiment: "impl-model", Variant: pinned.Name, RequestedModel: pinned.Model})
}

// A pin the rule no longer offers moves, and the reports name the old variant.
func TestARunReportsTheVariantItsPinMovedFrom(t *testing.T) {
	h := newHarnessWith(t, experimentRules, rules.Defaults{})
	rec := h.states()
	candidate := testExperiment.Pick(cardUUID(87))
	h.pins(func(_ context.Context, c string) (string, string, error) { return c, "haiku", nil })

	h.send(cardMoved(87))

	wantExperiment(t, rec.states(), runPin{Experiment: "impl-model", Variant: candidate.Name, RequestedModel: candidate.Model, SwitchedFrom: "haiku"})
}

// A pin request that fails runs the candidate, and says why.
func TestAFailedPinRunsTheCandidate(t *testing.T) {
	for name, answer := range map[string]func(ctx context.Context, candidate string) (string, string, error){
		"error": func(context.Context, string) (string, string, error) {
			return "", "", api.ErrExperimentPinsUnsupported
		},
		"unknown variant": func(context.Context, string) (string, string, error) { return "haiku", "", nil },
		"timeout": func(ctx context.Context, _ string) (string, string, error) {
			<-ctx.Done()

			return "", "", ctx.Err()
		},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarnessWith(t, experimentRules, rules.Defaults{})
			h.router.checkTimeout = 20 * time.Millisecond
			rec := h.states()
			candidate := testExperiment.Pick(cardUUID(87))
			h.pins(answer)

			h.send(cardMoved(87))

			if workers := h.worker.recorded(); len(workers) != 1 || workers[0].model != candidate.Model {
				t.Fatalf("workers = %+v, want model %s", workers, candidate.Model)
			}
			wantExperiment(t, rec.states(), runPin{Experiment: "impl-model", Variant: candidate.Name, RequestedModel: candidate.Model})
			line := h.only(t, "experiment_pin_failed")
			if line["level"] != "WARN" || num(t, line, "card") != 87 || str(t, line, "rule") != "plan" ||
				str(t, line, "experiment") != "impl-model" || str(t, line, "variant") != candidate.Name || str(t, line, "error") == "" {
				t.Fatalf("experiment_pin_failed = %v", line)
			}
		})
	}
}

// A run that never started still names its variant, because the pin comes
// first.
func TestARunThatNeverStartedNamesItsVariant(t *testing.T) {
	h := newHarnessWith(t, experimentRules, rules.Defaults{})
	rec := h.states()
	h.worker.result = workerResult{err: errors.New("fork/exec claude: permission denied")}
	candidate := testExperiment.Pick(cardUUID(87))
	h.pins(func(_ context.Context, c string) (string, string, error) { return c, "", nil })

	h.send(cardMoved(87))

	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunNotStarted)
	wantExperiment(t, sent, runPin{Experiment: "impl-model", Variant: candidate.Name, RequestedModel: candidate.Model})
}

// The resume of an unfinished run asks for its pin again, as any run does.
func TestAResumeResolvesItsPinAgain(t *testing.T) {
	h := newHarnessWith(t, experimentRules, rules.Defaults{})
	rec := h.states()
	h.worker.results = []workerResult{unfinishedRun}
	h.worker.result = finishedRun
	candidate := testExperiment.Pick(cardUUID(87))
	moved := otherVariant(candidate)
	answers := []string{candidate.Name, moved.Name}
	pins := h.pins(func(context.Context, string) (string, string, error) {
		a := answers[0]
		answers = answers[1:]

		return a, "", nil
	})

	h.send(cardMoved(87))

	if calls := pins.recorded(); len(calls) != 2 || calls[1].cardID != cardUUID(87) {
		t.Fatalf("pin calls = %+v", calls)
	}
	workers := h.worker.recorded()
	if len(workers) != 2 || workers[0].model != candidate.Model || workers[1].model != moved.Model {
		t.Fatalf("workers = %+v", workers)
	}
	sent := rec.states()
	ids := runIDs(sent)
	wantExperiment(t, ofRun(sent, ids[0]), runPin{Experiment: "impl-model", Variant: candidate.Name, RequestedModel: candidate.Model})
	wantExperiment(t, ofRun(sent, ids[1]), runPin{Experiment: "impl-model", Variant: moved.Name, RequestedModel: moved.Model})
}

// A rule with no experiment asks for no pin and sends no experiment field.
func TestAPlainRuleAsksForNoPin(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	pins := h.pins(func(context.Context, string) (string, string, error) {
		t.Error("a plain rule asked for a pin")

		return "", "", nil
	})

	h.send(cardMoved(87))

	if calls := pins.recorded(); len(calls) != 0 {
		t.Fatalf("pin calls = %+v", calls)
	}
	sent := rec.states()
	wantStates(t, sent, api.RunQueued, api.RunRunning, api.RunSucceeded)
	wantExperiment(t, sent, runPin{})
}

// A run with no card draws on its key, and asks the server nothing.
func TestARunWithNoCardDrawsOnItsKey(t *testing.T) {
	h := newHarnessWith(t, experimentRules, rules.Defaults{})
	pins := h.pins(func(context.Context, string) (string, string, error) {
		t.Error("a run with no card asked for a pin")

		return "", "", nil
	})
	exp := testExperiment

	model, pin := h.router.resolveVariant(pending{key: "document-1", rule: "plan", experiment: &exp, event: event.Event{Type: "document.review_submitted"}})

	want := testExperiment.Pick("document-1")
	if model != want.Model || pin != (runPin{Experiment: "impl-model", Variant: want.Name, RequestedModel: want.Model}) {
		t.Fatalf("model = %q, pin = %+v, want %+v", model, pin, want)
	}
	if calls := pins.recorded(); len(calls) != 0 {
		t.Fatalf("pin calls = %+v", calls)
	}
}
