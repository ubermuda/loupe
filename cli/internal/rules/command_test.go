package rules

import (
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/event"
)

// commandRule runs a command when a card enters ready. FIELDS stands for the
// extra lines of the rule.
const commandRule = `
projects:
  loupe:
    dir: {dir}
rules:
  - name: teardown
    action: command
    on: board.card_moved
    project: loupe
    to: ready
FIELDS`

func withCommand(fields string) string {
	return strings.Replace(commandRule, "FIELDS", "    "+strings.ReplaceAll(strings.TrimSpace(fields), "\n", "\n    ")+"\n", 1)
}

func TestParseReadsTheCommandTimeout(t *testing.T) {
	for name, tc := range map[string]struct {
		fields string
		want   time.Duration
	}{
		"the default":      {"run: [teardown]", DefaultCommandTimeout},
		"an explicit time": {"run: [teardown]\ntimeout: 90s", 90 * time.Second},
		"the maximum":      {"run: [teardown]\ntimeout: 60m", MaxCommandTimeout},
	} {
		t.Run(name, func(t *testing.T) {
			r := parse(t, withCommand(tc.fields)).Rules()[0]
			if r.Action != ActionCommand || r.commandTimeout != tc.want {
				t.Fatalf("rule = %+v, timeout = %s, want %s", r, r.commandTimeout, tc.want)
			}
		})
	}
}

// A command rule starts no agent, so no default reaches it. maxChain keeps its
// default, as on any rule.
func TestParseFillsNoAgentDefaultsIntoACommandRule(t *testing.T) {
	text, _ := file(t, "defaults:\n  permissionMode: plan\n  model: opus\n"+withCommand("run: [teardown]"))
	s, err := Parse([]byte(text), Defaults{PermissionMode: "acceptEdits", Model: "sonnet"})
	if err != nil {
		t.Fatal(err)
	}
	r := s.Rules()[0]
	if r.PermissionMode != "" || r.Model != "" || *r.MaxChain != DefaultMaxChain {
		t.Fatalf("rule = %+v", r)
	}
}

// A command rule takes the events that name a card, because its run reports
// against one.
func TestParseAcceptsACommandRuleOnEachCardEvent(t *testing.T) {
	for _, on := range []string{event.ReviewSubmittedType, event.ChecksConcludedType, event.FixRequestedType, "pull_request.merged"} {
		t.Run(on, func(t *testing.T) {
			body := strings.Replace(strings.Replace(withCommand("run: [teardown]"), "on: board.card_moved", "on: "+on, 1), "    to: ready\n", "", 1)
			parse(t, body)
		})
	}
}

func TestParseRefusesAnInvalidCommandRule(t *testing.T) {
	onCreated := strings.Replace(strings.Replace(withCommand("run: [teardown]"), "on: board.card_moved", "on: board.card_created", 1), "    to: ready\n", "", 1)
	onAsk := strings.Replace(strings.Replace(withCommand("run: [teardown]"), "on: board.card_moved", "on: inbox.ask_closed", 1), "    to: ready\n", "", 1)
	onPR := strings.Replace(strings.Replace(withCommand("run: [teardown, '{to}']"), "on: board.card_moved", "on: pull_request.merged", 1), "    to: ready\n", "", 1)
	worker := strings.Replace(withCommand("run: [teardown]\ntimeout: 1m\nprompt: x"), "    action: command\n", "", 1)
	interactive := strings.Replace(withCommand("run: [teardown]\ntimeout: 1m\nprompt: x"), "action: command", "action: interactive", 1)
	interactive = strings.Replace(interactive, "rules:\n", "launch:\n  command: ['{script}']\nrules:\n", 1)

	for name, tc := range map[string]struct {
		body string
		want string
	}{
		"a timeout over the maximum": {withCommand("run: [teardown]\ntimeout: 61m"), "timeout is 61m, and the most it takes is 1h0m0s"},
		"a zero timeout":             {withCommand("run: [teardown]\ntimeout: 0s"), "timeout must be positive"},
		"a negative timeout":         {withCommand("run: [teardown]\ntimeout: -1m"), "timeout must be positive"},
		"an unparsable timeout":      {withCommand("run: [teardown]\ntimeout: soon"), `timeout "soon" is not a duration`},
		"no run":                     {withCommand("timeout: 1m"), "run is required, and its first element names the program"},
		"an empty run":               {withCommand("run: []"), "run is required"},
		"a blank program":            {withCommand("run: ['  ', x]"), "run is required"},
		"an unknown placeholder":     {withCommand("run: [teardown, '{title}']"), "run: unknown placeholder {title}"},
		"another type's placeholder": {onPR, "run: placeholder {to} has no value for pull_request.merged events"},
		"an event with no card":      {onCreated, "action command applies to board.card_moved, document.review_submitted and pull_request.* events only"},
		"an ask event":               {onAsk, "action command applies to board.card_moved, document.review_submitted and pull_request.* events only"},
		"a prompt":                   {withCommand("run: [teardown]\nprompt: x"), "prompt names agent behaviour, and action command starts no agent"},
		"a model":                    {withCommand("run: [teardown]\nmodel: opus"), "model names agent behaviour"},
		"a permission mode":          {withCommand("run: [teardown]\npermissionMode: plan"), "permissionMode names agent behaviour"},
		"a resume":                   {withCommand("run: [teardown]\nresume: true"), "resume names agent behaviour"},
		"result fields":              {withCommand("run: [teardown]\nresultFields:\n  pr: {type: string}"), "resultFields names agent behaviour"},
		"an experiment":              {withCommand("run: [teardown]\nexperiment: x"), "experiment names agent behaviour"},
		"a worker pool":              {withCommand("run: [teardown]\nworkerPool: quick"), "workerPool names agent behaviour"},
		"a resume cap":               {withCommand("run: [teardown]\nmaxResumes: 1"), "maxResumes names agent behaviour"},
		"a before command":           {withCommand("run: [teardown]\nbefore:\n  run: [prepare]"), "before names agent behaviour"},
		"run on a worker rule":       {worker, "run belongs to action command"},
		"timeout on a worker rule":   {worker, "timeout belongs to action command"},
		"run on an interactive rule": {interactive, "run belongs to action command"},
		"timeout on interactive":     {interactive, "timeout belongs to action command"},
		"an unknown action":          {strings.Replace(withCommand("run: [x]"), "action: command", "action: shell", 1), `action "shell" is not interactive or command`},
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

// Each element of run is filled on its own, so a value never splits into two
// arguments. A command takes no pool, no model and no prompt.
func TestMatchRendersTheCommand(t *testing.T) {
	s := checked(t, withCommand("run: [teardown, '--card={cardNumber}', '{cardId} {to}']\ntimeout: 2m"))

	m := s.Match(moved("backlog", "ready", event.ActorHuman))
	want := []string{"teardown", "--card=87", cardID + " ready"}
	if m.Skip != Run || m.Action != ActionCommand || m.Command == nil || !slices.Equal(m.Command.Argv, want) || m.Command.Timeout != 2*time.Minute {
		t.Fatalf("match = %+v, command = %+v, want argv %q", m, m.Command, want)
	}
	if m.Pool != "" || m.Model != "" || m.PermissionMode != "" || m.Prompt != "" || m.Schema != "" || m.Before != nil || m.MaxChain != DefaultMaxChain {
		t.Fatalf("match = %+v", m)
	}
	m.Command.Argv[0] = "changed"
	if again := s.Match(moved("backlog", "ready", event.ActorHuman)); again.Command.Argv[0] != "teardown" {
		t.Fatalf("a change to one match reached the rule: %q", again.Command.Argv)
	}
}

func TestAWorkerMatchHasNoCommand(t *testing.T) {
	if m := checked(t, oneRule).Match(moved("backlog", "ready", event.ActorHuman)); m.Skip != Run || m.Command != nil {
		t.Fatalf("Match = %+v", m)
	}
}

// A rerun carries the card and nothing else of the event that started the
// run. The command takes the card, and an empty string for each other name.
func TestMatchRuleFillsTheCommandOfARerun(t *testing.T) {
	s := checked(t, withCommand("run: [teardown, '{cardId}', '{cardNumber}', '--to={to}']"))
	rerun := event.Event{Type: event.CommandType, Subject: event.Subject{Type: "card", ID: cardID}, ProjectID: projectID, CardNumber: 87, Actor: event.ActorHuman}

	m, ok := s.MatchRule(rerun, "teardown")
	want := []string{"teardown", cardID, "87", "--to="}
	if !ok || m.Command == nil || !slices.Equal(m.Command.Argv, want) || m.Command.Timeout != DefaultCommandTimeout {
		t.Fatalf("command = %+v, want argv %q", m.Command, want)
	}
}

// A rerun knows the card and the project only, so RerunGaps names every other
// placeholder of the run once.
func TestRerunGapsNamesWhatARerunCannotFill(t *testing.T) {
	for name, tc := range map[string]struct {
		run  string
		want []string
	}{
		"card and project": {"run: [teardown, '{cardNumber}', '{cardId}', '{project}', '{projectId}']", nil},
		"event values":     {"run: [teardown, '{to}', '--from={from}', '{to}']", []string{"to", "from"}},
	} {
		t.Run(name, func(t *testing.T) {
			if got := parse(t, withCommand(tc.run)).RerunGaps("teardown"); !slices.Equal(got, tc.want) {
				t.Fatalf("gaps = %v, want %v", got, tc.want)
			}
		})
	}
}
