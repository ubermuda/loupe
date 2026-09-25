package rules

import (
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/event"
)

const interactiveRule = `
projects:
  loupe:
    dir: {dir}
launch:
  command: [osascript, -e, 'run {script}']
rules:
  - name: design
    action: interactive
    on: board.card_moved
    project: loupe
    to: ready
    prompt: Design card {cardNumber}.
`

func TestParseRefusesAnInvalidInteractiveRule(t *testing.T) {
	rule := func(fields string) string {
		return "projects:\n  loupe:\n    dir: {dir}\nlaunch:\n  command: ['{script}']\nrules:\n  - " + strings.ReplaceAll(strings.TrimSpace(fields), "\n", "\n    ") + "\n"
	}
	moved := "action: interactive\non: board.card_moved\nproject: loupe\nto: ready\nprompt: x\n"

	for name, tc := range map[string]struct {
		body string
		want string
	}{
		"unknown action":       {rule("action: launch\non: board.card_moved\nproject: loupe\nto: ready\nprompt: x"), `action "launch" is not interactive`},
		"another event":        {rule("action: interactive\non: board.card_created\nproject: loupe\nprompt: x"), "action interactive applies to board.card_moved only, and this rule is on board.card_created"},
		"maxChain":             {rule(moved + "maxChain: 2"), "maxChain names worker behaviour"},
		"maxResumes":           {rule(moved + "maxResumes: 0"), "maxResumes names worker behaviour"},
		"resultFields":         {rule(moved + "resultFields:\n  pr: {type: string}"), "resultFields names worker behaviour"},
		"resume":               {rule(moved + "resume: true"), "resume names worker behaviour"},
		"verdict":              {rule(moved + "verdict: approved"), "verdict names worker behaviour"},
		"no launch command":    {strings.Replace(interactiveRule, "launch:\n  command: [osascript, -e, 'run {script}']\n", "", 1), "launch.command is required"},
		"empty launch command": {strings.Replace(interactiveRule, "[osascript, -e, 'run {script}']", "[]", 1), "launch.command is required"},
		"no script":            {strings.Replace(interactiveRule, "'run {script}'", "'run {dir}'", 1), "no element of launch.command holds {script}"},
		"unknown placeholder":  {strings.Replace(interactiveRule, "osascript", "'{cardId}'", 1), "launch.command: unknown placeholder {cardId}"},
		"bad timeout":          {strings.Replace(interactiveRule, "launch:\n", "launch:\n  timeout: soon\n", 1), `launch.timeout "soon" is not a duration`},
		"zero timeout":         {strings.Replace(interactiveRule, "launch:\n", "launch:\n  timeout: 0s\n", 1), "launch.timeout must be positive"},
		"negative timeout":     {strings.Replace(interactiveRule, "launch:\n", "launch:\n  timeout: -1s\n", 1), "launch.timeout must be positive"},
		"bad launch no rule":   {strings.Replace(oneRule, "rules:\n", "launch:\n  command: [open]\nrules:\n", 1), "no element of launch.command holds {script}"},
		"unknown launch field": {strings.Replace(interactiveRule, "launch:\n", "launch:\n  shell: sh\n", 1), "field shell not found"},
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

func TestParseRefusesAnInteractiveRuleOnWindows(t *testing.T) {
	old := goos
	goos = "windows"
	t.Cleanup(func() { goos = old })

	text, _ := file(t, interactiveRule)
	_, err := Parse([]byte(text), Defaults{})
	if err == nil || !strings.Contains(err.Error(), "needs a POSIX shell on macOS or Linux") {
		t.Fatalf("err = %v", err)
	}
	if _, err := Parse([]byte(strings.ReplaceAll(oneRule, "{dir}", t.TempDir())), Defaults{}); err != nil {
		t.Fatalf("a worker rule on windows: %v", err)
	}
}

// The file defaults and the bridge flags configure a worker. A launch takes
// only what its own rule sets.
func TestParseFillsNoDefaultsIntoAnInteractiveRule(t *testing.T) {
	body := "defaults:\n  permissionMode: plan\n  model: opus\n" + interactiveRule + `  - name: worker
    on: board.card_moved
    project: loupe
    to: review
    prompt: x
  - name: own
    action: interactive
    on: board.card_moved
    project: loupe
    to: done
    permissionMode: acceptEdits
    model: sonnet
    prompt: x
`
	text, _ := file(t, body)
	s, err := Parse([]byte(text), Defaults{PermissionMode: "dontAsk", Model: "haiku"})
	if err != nil {
		t.Fatal(err)
	}

	rs := s.Rules()
	if rs[0].Action != ActionInteractive || rs[0].PermissionMode != "" || rs[0].Model != "" {
		t.Fatalf("interactive rule = %+v", rs[0])
	}
	if rs[1].Action != "" || rs[1].PermissionMode != "plan" || rs[1].Model != "opus" {
		t.Fatalf("worker rule = %+v", rs[1])
	}
	if rs[2].PermissionMode != "acceptEdits" || rs[2].Model != "sonnet" {
		t.Fatalf("interactive rule with its own settings = %+v", rs[2])
	}
}

func TestLaunchSettings(t *testing.T) {
	s := parse(t, interactiveRule)
	if !s.HasInteractive() {
		t.Fatal("HasInteractive = false")
	}
	l := s.Launch()
	if !slices.Equal(l.Command, []string{"osascript", "-e", "run {script}"}) || l.Timeout != DefaultLaunchTimeout {
		t.Fatalf("Launch = %+v", l)
	}
	if DefaultLaunchTimeout != 10*time.Second {
		t.Fatalf("DefaultLaunchTimeout = %v", DefaultLaunchTimeout)
	}

	timed := parse(t, strings.Replace(interactiveRule, "launch:\n", "launch:\n  timeout: 30s\n", 1))
	if got := timed.Launch().Timeout; got != 30*time.Second {
		t.Fatalf("Timeout = %v", got)
	}

	if parse(t, oneRule).HasInteractive() {
		t.Fatal("a worker file HasInteractive")
	}
	withLaunch := parse(t, strings.Replace(oneRule, "rules:\n", "launch:\n  command: ['{script}']\nrules:\n", 1))
	if withLaunch.HasInteractive() || !slices.Equal(withLaunch.Launch().Command, []string{"{script}"}) {
		t.Fatalf("a launch block with no interactive rule: %+v", withLaunch.Launch())
	}
}

func TestLaunchCommandsAreIndependent(t *testing.T) {
	s := parse(t, interactiveRule)
	s.Launch().Command[0] = "changed"
	if s.Launch().Command[0] != "osascript" {
		t.Fatal("Launch shares its command with the caller")
	}
}

func TestMatchCarriesTheAction(t *testing.T) {
	s := checked(t, interactiveRule+`  - name: worker
    on: board.card_moved
    project: loupe
    to: review
    prompt: x
`)
	m := s.Match(moved("backlog", "ready", event.ActorHuman))
	if m.Skip != Run || m.Rule != "design" || m.Action != ActionInteractive || !strings.HasPrefix(m.Prompt, "Design card 87.") {
		t.Fatalf("Match = %+v", m)
	}
	if m, ok := s.MatchRule(moved("backlog", "ready", event.ActorHuman), "design"); !ok || m.Action != ActionInteractive {
		t.Fatalf("MatchRule = %+v, %v", m, ok)
	}
	if m := s.Match(moved("backlog", "review", event.ActorHuman)); m.Action != "" {
		t.Fatalf("worker Match = %+v", m)
	}
}

func TestInteractiveRuleHealthAndKill(t *testing.T) {
	s := checked(t, interactiveRule)
	if h := s.Health("loupe"); len(h) != 1 || h[0].Name != "design" || !slices.Equal(h[0].Columns, []string{"ready"}) {
		t.Fatalf("Health = %+v", h)
	}
	dead := s.Kill(event.Event{Type: event.ColumnDeletedType, ProjectID: projectID, Slug: "ready"})
	if len(dead) != 1 || dead[0].Rule != "design" || s.Live("design") {
		t.Fatalf("Kill = %+v", dead)
	}
}

func TestLaunchArgv(t *testing.T) {
	l := Launch{Command: []string{"term", "--cwd={dir}", "{script}", "{sessionId}-{cardNumber}@{project}", "{other}"}}
	got := l.Argv(LaunchValues{Script: "/tmp/a b.sh", Dir: "/code/x", SessionID: "s1", CardNumber: "87", Project: "loupe"})
	want := []string{"term", "--cwd=/code/x", "/tmp/a b.sh", "s1-87@loupe", "{other}"}
	if !slices.Equal(got, want) {
		t.Fatalf("Argv = %q, want %q", got, want)
	}
	if l.Command[1] != "--cwd={dir}" {
		t.Fatal("Argv changed the command")
	}
}
