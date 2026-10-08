package rules

import (
	"fmt"
	"math/rand/v2"
	"strings"
	"testing"
)

// The variants of a work entry form the experiment named after its kind, so
// each variant gets the checks an experiment had.
func TestParseRefusesInvalidVariants(t *testing.T) {
	body := func(variants ...string) string {
		return claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: {dir}\nwork:\n  implement:\n    prompt: x\n    variants:" + strings.Join(variants, "") + "\n"
	}
	variant := func(name string, weight int, model string) string {
		return fmt.Sprintf("\n      - {name: %q, weight: %d, model: %q}", name, weight, model)
	}

	for name, tc := range map[string]struct {
		body string
		want string
	}{
		"no variants":         {claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: {dir}\nwork:\n  implement:\n    prompt: x\n    variants: []\n", "it has no variants"},
		"zero weight":         {body(variant("a", 0, "opus")), `variant "a": weight must be at least 1, got 0`},
		"negative weight":     {body(variant("a", -2, "opus")), `variant "a": weight must be at least 1, got -2`},
		"weight over the cap": {body(variant("a", MaxWeight+1, "opus")), fmt.Sprintf(`variant "a": weight must be at most %d`, MaxWeight)},
		"no model":            {body(variant("a", 1, "")), `variant "a": model or account is required`},
		"model with a space":  {body(variant("a", 1, "opus 5")), `variant "a": model "opus 5" holds whitespace`},
		"model over 100":      {body(variant("a", 1, strings.Repeat("m", MaxModelLength+1))), `variant "a": model is 101 characters, and the server takes at most 100`},
		"duplicate variant":   {body(variant("a", 1, "opus"), variant("a", 1, "sonnet")), `two variants are named "a"`},
		"bad variant name":    {body(variant("-a", 1, "opus")), `variant "-a": a name is 1 to 64 lowercase letters`},
		"blank variant name":  {body(variant("", 1, "opus")), `variant "": a name is 1 to 64 lowercase letters`},
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
