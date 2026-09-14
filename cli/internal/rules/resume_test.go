package rules

import (
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/event"
)

const (
	askID     = "01a0a1b2-0000-7c3d-8e4f-5a6b7c8d9e0f"
	sessionID = "5f0c7e2a-1b3d-4c5e-8f9a-0b1c2d3e4f5a"
)

const resumeRule = `
projects:
  loupe:
    dir: {dir}
rules:
  - name: resume
    on: inbox.ask_closed
    project: loupe
    resume: true
    prompt: The owner closed ask {askId} of session {sessionId} on card {cardNumber} in {project} ({projectId}).
`

func askClosed(cardNumber int) event.Event {
	e := event.Event{
		Type:      event.AskClosedType,
		Subject:   event.Subject{Type: "inbox-ask", ID: askID},
		ProjectID: projectID,
		SessionID: sessionID,
		Actor:     event.ActorHuman,
	}
	if cardNumber > 0 {
		e.CardID, e.CardNumber = cardID, cardNumber
	}

	return e
}

func TestParseAcceptsAResumeRule(t *testing.T) {
	s := parse(t, resumeRule)
	if r := s.Rules()[0]; !r.Resume || r.On != event.AskClosedType {
		t.Fatalf("rule = %+v", r)
	}
	if got := s.ExtraTypes(); !got[event.AskClosedType] {
		t.Fatalf("ExtraTypes = %v, want the parser to read inbox.ask_closed", got)
	}
}

// resume is an action of its own, so a rule on any other type cannot set it,
// and a rule on inbox.ask_closed has nothing else to do.
func TestParseRefusesAMisplacedResume(t *testing.T) {
	rule := func(fields string) string {
		return "projects:\n  loupe:\n    dir: {dir}\nrules:\n  - " + strings.ReplaceAll(strings.TrimSpace(fields), "\n", "\n    ") + "\n"
	}
	for name, tc := range map[string]struct {
		body string
		want string
	}{
		"resume on card_moved":       {rule("on: board.card_moved\nproject: loupe\nto: ready\nresume: true\nprompt: x"), "resume applies to inbox.ask_closed only"},
		"resume on a generic type":   {rule("on: board.card_created\nproject: loupe\nresume: true\nprompt: x"), "resume applies to inbox.ask_closed only"},
		"ask_closed without resume":  {rule("on: inbox.ask_closed\nproject: loupe\nprompt: x"), "inbox.ask_closed needs resume: true"},
		"ask_closed with resume off": {rule("on: inbox.ask_closed\nproject: loupe\nresume: false\nprompt: x"), "inbox.ask_closed needs resume: true"},
		"ask_closed with a column":   {rule("on: inbox.ask_closed\nproject: loupe\nresume: true\nto: ready\nprompt: x"), "apply to board.card_moved only"},
		"ask_closed {cardId}":        {rule("on: inbox.ask_closed\nproject: loupe\nresume: true\nprompt: 'Card {cardId}'"), "{cardId} has no value for inbox.ask_closed"},
		"ask_closed {to}":            {rule("on: inbox.ask_closed\nproject: loupe\nresume: true\nprompt: 'To {to}'"), "{to} has no value for inbox.ask_closed"},
		"card_moved {askId}":         {rule("on: board.card_moved\nproject: loupe\nto: ready\nprompt: 'Ask {askId}'"), "{askId} has no value for board.card_moved"},
		"generic {sessionId}":        {rule("on: board.card_created\nproject: loupe\nprompt: 'Session {sessionId}'"), "{sessionId} has no value for board.card_created"},
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

func TestMatchRendersAResumePrompt(t *testing.T) {
	s := checked(t, resumeRule)

	m := s.Match(askClosed(33))
	if m.Skip != Run || !m.Resume || m.Rule != "resume" {
		t.Fatalf("Match = %+v", m)
	}
	want := directive.RenderResume("The owner closed ask "+askID+" of session "+sessionID+" on card 33 in loupe ("+projectID+").",
		map[string]string{"sessionId": sessionID})
	if m.Prompt != want {
		t.Fatalf("prompt = %q, want %q", m.Prompt, want)
	}
}

// A card the server and the bridge both do not know renders as a word the
// agent reads, never as an empty gap or a zero.
func TestMatchRendersAnUnknownCardNumber(t *testing.T) {
	s := checked(t, resumeRule)

	got := s.Match(askClosed(0)).Prompt
	if !strings.Contains(got, "on card unknown in loupe") {
		t.Fatalf("prompt = %q", got)
	}
}

// A card rule never resumes.
func TestMatchOfACardRuleIsNoResume(t *testing.T) {
	if m := checked(t, oneRule).Match(moved("backlog", "ready", event.ActorHuman)); m.Resume {
		t.Fatalf("Match = %+v", m)
	}
}
