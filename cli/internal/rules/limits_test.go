package rules

import (
	"fmt"
	"regexp"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
)

// Each case breaks one limit of the rule health endpoint. A file past a limit
// would start, then get a 422 for every report, so Parse refuses it.
func TestParseRefusesWhatTheReportEndpointRejects(t *testing.T) {
	rule := func(fields string) string {
		return "projects:\n  loupe:\n    dir: {dir}\nrules:\n  - " + strings.ReplaceAll(strings.TrimSpace(fields), "\n", "\n    ") + "\n"
	}
	longSlug := strings.Repeat("a", MaxSlugLength+1)

	for name, tc := range map[string]struct {
		body string
		want string
	}{
		"blank name":            {rule("name: '   '\non: board.card_moved\nproject: loupe\nto: ready\nprompt: x"), `name "   " is blank`},
		"name over 100":         {rule("name: " + strings.Repeat("é", MaxNameLength+1) + "\non: board.card_moved\nproject: loupe\nto: ready\nprompt: x"), "name is 101 characters, and the server takes at most 100"},
		"names equal trimmed":   {"projects:\n  loupe:\n    dir: {dir}\nrules:\n  - {name: a, on: board.card_moved, project: loupe, to: ready, prompt: x}\n  - {name: ' a ', on: board.card_moved, project: loupe, to: done, prompt: x}\n", "same name"},
		"on over 100":           {rule("on: board." + strings.Repeat("a", MaxOnLength) + "\nproject: loupe\nprompt: x"), "on is 106 characters, and the server takes at most 100"},
		"on starts with digit":  {rule("on: board.1moved\nproject: loupe\nprompt: x"), "is not an event type"},
		"on segment underscore": {rule("on: _board.moved\nproject: loupe\nprompt: x"), "is not an event type"},
		"to over 2000":          {rule("on: board.card_moved\nproject: loupe\nto: " + longSlug + "\nprompt: x"), "to is 2001 characters"},
		"from over 2000":        {rule("on: board.card_moved\nproject: loupe\nto: ready\nfrom: " + longSlug + "\nprompt: x"), "from is 2001 characters"},
		"rules over 200":        {manyRules(MaxRulesPerProject + 1), `project "loupe" has 201 rules, and the server takes at most 200`},
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

func TestParseAcceptsTheLimits(t *testing.T) {
	s := parse(t, manyRules(MaxRulesPerProject))
	if n := len(s.Rules()); n != MaxRulesPerProject {
		t.Fatalf("rules = %d", n)
	}

	// A non-breaking space is not in PHP's trim set, so the server counts it
	// and it stays part of the name.
	s = parse(t, "projects:\n  loupe:\n    dir: {dir}\nrules:\n  - {name: \" plan\", on: board.card_moved, project: loupe, to: ready, prompt: x}\n")
	if got := s.Rules()[0].Name; got != " plan" {
		t.Fatalf("name = %q, want the non-breaking space kept", got)
	}

	name := strings.Repeat("é", MaxNameLength)
	s = parse(t, "projects:\n  loupe:\n    dir: {dir}\nrules:\n  - {name: '  "+name+"  ', on: board.card_moved, project: loupe, to: ready, prompt: x}\n")
	if got := s.Rules()[0].Name; got != name {
		t.Fatalf("name = %q, want it trimmed", got)
	}
}

func manyRules(n int) string {
	var b strings.Builder
	b.WriteString("projects:\n  loupe:\n    dir: {dir}\nrules:\n")
	for i := range n {
		fmt.Fprintf(&b, "  - {name: r%d, on: board.card_created, project: loupe, prompt: x}\n", i)
	}

	return b.String()
}

// The bridge writes state and reason itself, so they must fit the server's
// choices and pattern with no check at start.
func TestTheReportStatesAndReasonsFitTheServer(t *testing.T) {
	reason := regexp.MustCompile(`^[a-z][a-z0-9_]*$`)
	for _, r := range []string{api.ReasonColumnRenamed, api.ReasonColumnDeleted, api.ReasonProjectRenamed, api.ReasonProjectGone} {
		if len(r) > 64 || !reason.MatchString(r) {
			t.Fatalf("reason %q does not fit the server", r)
		}
	}
	if api.RuleLive != "live" || api.RuleDead != "dead" {
		t.Fatalf("states = %q, %q", api.RuleLive, api.RuleDead)
	}
}
