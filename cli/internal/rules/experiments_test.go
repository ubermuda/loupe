package rules

import (
	"fmt"
	"math/rand/v2"
	"slices"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/event"
)

const experimentRule = `
projects:
  loupe:
    dir: {dir}
experiments:
  - name: impl-model
    variants:
      - name: opus
        weight: 1
        model: claude-opus-5-5
      - name: sonnet
        weight: 3
        model: claude-sonnet-5-5
rules:
  - name: implement
    on: board.card_moved
    project: loupe
    to: ready
    experiment: impl-model
    prompt: Implement card {cardNumber}.
`

func TestParseRefusesAnInvalidExperiment(t *testing.T) {
	body := func(experiments, fields string) string {
		return "projects:\n  loupe:\n    dir: {dir}\n" +
			"experiments:\n  " + strings.ReplaceAll(strings.TrimSpace(experiments), "\n", "\n  ") + "\n" +
			"rules:\n  - " + strings.ReplaceAll(strings.TrimSpace("on: board.card_moved\nproject: loupe\nto: ready\nprompt: x\n"+fields), "\n", "\n    ") + "\n"
	}
	variant := func(name string, weight int, model string) string {
		return fmt.Sprintf("\n    - {name: %q, weight: %d, model: %q}", name, weight, model)
	}
	exp := func(name string, variants ...string) string {
		return fmt.Sprintf("- name: %q\n  variants:%s\n", name, strings.Join(variants, ""))
	}
	good := exp("x", variant("a", 1, "opus"), variant("b", 1, "sonnet"))
	many := make([]string, MaxVariants+1)
	for i := range many {
		many[i] = variant(fmt.Sprintf("v%d", i), 1, "opus")
	}

	for name, tc := range map[string]struct {
		body string
		want string
	}{
		"model and experiment":   {body(good, "experiment: x\nmodel: opus"), `rule "1": model and experiment are both set`},
		"interactive experiment": {"launch:\n  command: ['{script}']\n" + body(good, "action: interactive\nexperiment: x"), "experiment names worker behaviour"},
		"unknown experiment":     {body(good, "experiment: y"), `rule "1": experiment "y" is not in experiments, which declares x`},
		"no variants":            {body("- name: x\n  variants: []\n", "experiment: x"), `experiment "x": it has no variants`},
		"too many variants":      {body(exp("x", many...), "experiment: x"), `experiment "x": it has 33 variants, and the server takes at most 32`},
		"zero weight":            {body(exp("x", variant("a", 0, "opus")), "experiment: x"), `experiment "x": variant "a": weight must be at least 1, got 0`},
		"negative weight":        {body(exp("x", variant("a", -2, "opus")), "experiment: x"), `variant "a": weight must be at least 1, got -2`},
		"weight over the cap":    {body(exp("x", variant("a", MaxWeight+1, "opus")), "experiment: x"), fmt.Sprintf(`variant "a": weight must be at most %d`, MaxWeight)},
		"no model":               {body(exp("x", variant("a", 1, "")), "experiment: x"), `experiment "x": variant "a": model is required`},
		"model with a space":     {body(exp("x", variant("a", 1, "opus 5")), "experiment: x"), `variant "a": model "opus 5" holds whitespace`},
		"model over 100":         {body(exp("x", variant("a", 1, strings.Repeat("m", MaxModelLength+1))), "experiment: x"), `variant "a": model is 101 characters, and the server takes at most 100`},
		"duplicate variant":      {body(exp("x", variant("a", 1, "opus"), variant("a", 1, "sonnet")), "experiment: x"), `experiment "x": two variants are named "a"`},
		"duplicate experiment":   {body(good+good, "experiment: x"), `experiment "x": another experiment has the same name`},
		"bad experiment name":    {body(exp("Impl", variant("a", 1, "opus")), ""), `experiment "Impl": a name is 1 to 64 lowercase letters`},
		"long experiment name":   {body(exp(strings.Repeat("a", 65), variant("a", 1, "opus")), ""), "a name is 1 to 64 lowercase letters"},
		"bad variant name":       {body(exp("x", variant("-a", 1, "opus")), ""), `experiment "x": variant "-a": a name is 1 to 64 lowercase letters`},
		"blank variant name":     {body(exp("x", variant("", 1, "opus")), ""), `experiment "x": variant "": a name is 1 to 64 lowercase letters`},
		"unknown field":          {body(good+"  model: opus\n", ""), "field model not found"},
	} {
		t.Run(name, func(t *testing.T) {
			text, _ := file(t, tc.body)
			_, err := Parse([]byte(text), Defaults{})
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want it to contain %q", err, tc.want)
			}
		})
	}
}

// The variants name the model, so neither default fills a rule that joins an
// experiment.
func TestTheDefaultsSkipAnExperimentRule(t *testing.T) {
	text, _ := file(t, strings.Replace(experimentRule, "projects:", "defaults:\n  model: haiku\nprojects:", 1))
	s, err := Parse([]byte(text), Defaults{PermissionMode: "acceptEdits", Model: "sonnet"})
	if err != nil {
		t.Fatal(err)
	}

	r := s.Rules()[0]
	if r.Model != "" || r.PermissionMode != "acceptEdits" || r.Experiment != "impl-model" {
		t.Fatalf("rule = %+v", r)
	}
}

func TestAnExperimentNoRuleJoinsLoads(t *testing.T) {
	text, _ := file(t, strings.Replace(experimentRule, "    experiment: impl-model\n", "", 1))
	if _, err := Parse([]byte(text), Defaults{}); err != nil {
		t.Fatal(err)
	}
}

func TestMatchCarriesTheExperiment(t *testing.T) {
	s := checked(t, experimentRule)

	m := s.Match(moved("backlog", "ready", event.ActorHuman))
	if m.Skip != Run || m.Model != "" || m.Experiment == nil {
		t.Fatalf("Match = %+v", m)
	}
	want := Experiment{Name: "impl-model", Variants: []Variant{
		{Name: "opus", Weight: 1, Model: "claude-opus-5-5"},
		{Name: "sonnet", Weight: 3, Model: "claude-sonnet-5-5"},
	}}
	if m.Experiment.Name != want.Name || !slices.Equal(m.Experiment.Variants, want.Variants) {
		t.Fatalf("Experiment = %+v, want %+v", *m.Experiment, want)
	}

	m.Experiment.Variants[0].Model = "changed"
	if again := s.Match(moved("backlog", "ready", event.ActorHuman)); again.Experiment.Variants[0].Model != "claude-opus-5-5" {
		t.Fatalf("a caller changed the set's experiment: %+v", again.Experiment)
	}
}

func TestMatchCarriesNoExperimentForARuleWithout(t *testing.T) {
	if m := checked(t, oneRule).Match(moved("backlog", "ready", event.ActorHuman)); m.Skip != Run || m.Experiment != nil {
		t.Fatalf("Match = %+v", m)
	}
}

// The golden picks pin the hash, so every bridge draws the same candidate.
func TestPickIsDeterministic(t *testing.T) {
	for _, tc := range []struct {
		weights [2]int
		want    string
	}{
		{[2]int{1, 1}, "ababbb"},
		{[2]int{1, 3}, "bbabbb"},
	} {
		e := Experiment{Name: "impl-model", Variants: []Variant{
			{Name: "a", Weight: tc.weights[0], Model: "opus"},
			{Name: "b", Weight: tc.weights[1], Model: "sonnet"},
		}}
		got := ""
		for i := range 6 {
			got += e.Pick(fmt.Sprintf("card-%d", i+1)).Name
		}
		if got != tc.want || e.Pick("card-1") != e.Pick("card-1") {
			t.Fatalf("weights %v: picks = %s, want %s", tc.weights, got, tc.want)
		}
	}

	e := Experiment{Name: "impl-model", Variants: []Variant{{Name: "a", Weight: 1, Model: "opus"}, {Name: "b", Weight: 1, Model: "sonnet"}}}
	other := Experiment{Name: "other", Variants: e.Variants}
	seen := map[bool]bool{}
	for i := range 64 {
		key := fmt.Sprintf("card-%d", i)
		seen[e.Pick(key) == other.Pick(key)] = true
	}
	if !seen[false] {
		t.Fatal("the experiment name does not change the pick")
	}
}

func TestPickSpreadsByWeight(t *testing.T) {
	rng := rand.New(rand.NewPCG(1, 2))
	for _, weights := range [][2]int{{1, 1}, {1, 3}} {
		e := Experiment{Name: "impl-model", Variants: []Variant{
			{Name: "a", Weight: weights[0], Model: "opus"},
			{Name: "b", Weight: weights[1], Model: "sonnet"},
		}}
		const n = 10000
		first := 0
		for range n {
			if e.Pick(uuidV7(rng)).Name == "a" {
				first++
			}
		}
		want := n * weights[0] / (weights[0] + weights[1])
		if diff := first - want; diff < -300 || diff > 300 {
			t.Fatalf("weights %v: %d of %d picked a, want about %d", weights, first, n, want)
		}
	}
}

// uuidV7 is a random id in the shape of a uuid v7, with a timestamp near now.
func uuidV7(rng *rand.Rand) string {
	ms := 0x0192f3a1_0000 + rng.Uint64N(1<<32)
	a, b := rng.Uint64(), rng.Uint64()

	return fmt.Sprintf("%08x-%04x-7%03x-%04x-%012x", ms>>16, ms&0xffff, a&0xfff, 0x8000|(b>>48)&0x3fff, b&0xffffffffffff)
}
