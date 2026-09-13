package rules

import (
	"context"
	"encoding/json"
	"slices"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/event"
)

const otherID = "0192f3a1-4b2c-7d3e-8f10-000000000002"

// deadRules maps two projects. On loupe, plan and ship name ready, review and
// created do not. other has a rule on ready of its own.
const deadRules = `
projects:
  loupe:
    dir: {dir}
  other:
    dir: {dir}
rules:
  - name: plan
    on: board.card_moved
    project: loupe
    to: ready
    prompt: SECRET PROMPT plan {cardNumber}
  - name: review
    on: board.card_moved
    project: loupe
    to: review
    from: backlog
    prompt: review {cardNumber}
  - name: ship
    on: board.card_moved
    project: loupe
    to: done
    from: ready
    prompt: ship {cardNumber}
  - name: created
    on: board.card_created
    project: loupe
    prompt: created in {project}
  - name: other-plan
    on: board.card_moved
    project: other
    to: ready
    prompt: plan {cardNumber}
`

func checkedDead(t *testing.T) *Set {
	t.Helper()
	s := parse(t, deadRules)
	otherColumns := strings.ReplaceAll(strings.ReplaceAll(loupeColumns, projectID, otherID), `"slug":"loupe"`, `"slug":"other"`)
	if err := s.Check(context.Background(), columnsServer(t, map[string]string{"loupe": loupeColumns, "other": otherColumns})); err != nil {
		t.Fatal(err)
	}

	return s
}

func slugEvent(typ, project string) event.Event {
	return event.Event{Type: typ, Subject: event.Subject{Type: "board_column", ID: cardID}, ProjectID: project, Actor: event.ActorHuman}
}

func names(dead []Dead) []string {
	out := make([]string, len(dead))
	for i, d := range dead {
		out[i] = d.Rule + "/" + d.Project + "/" + d.Reason
	}

	return out
}

func TestARenameKillsTheRulesOnTheOldSlug(t *testing.T) {
	s := checkedDead(t)
	e := slugEvent(event.ColumnRenamedType, projectID)
	e.FromSlug, e.ToSlug = "ready", "ready-now"

	got := names(s.Kill(e))
	if want := []string{"plan/loupe/column_renamed", "ship/loupe/column_renamed"}; !slices.Equal(got, want) {
		t.Fatalf("Kill = %v, want %v", got, want)
	}
	if again := s.Kill(e); len(again) != 0 {
		t.Fatalf("a dead rule died twice: %v", again)
	}

	if m := s.Match(moved("backlog", "ready", event.ActorHuman)); m.Skip != NoRule {
		t.Fatalf("a dead rule matched: %+v", m)
	}
	if m := s.Match(moved("ready", "done", event.ActorHuman)); m.Skip != NoRule {
		t.Fatalf("a dead rule matched: %+v", m)
	}
	if m := s.Match(moved("backlog", "review", event.ActorHuman)); m.Rule != "review" {
		t.Fatalf("a live rule stopped matching: %+v", m)
	}
	other := moved("backlog", "ready", event.ActorHuman)
	other.ProjectID = otherID
	if m := s.Match(other); m.Rule != "other-plan" {
		t.Fatalf("the rule of another project stopped matching: %+v", m)
	}
}

func TestADeleteKillsTheRulesOnTheSlug(t *testing.T) {
	s := checkedDead(t)
	e := slugEvent(event.ColumnDeletedType, projectID)
	e.Slug = "review"

	if got, want := names(s.Kill(e)), []string{"review/loupe/column_deleted"}; !slices.Equal(got, want) {
		t.Fatalf("Kill = %v, want %v", got, want)
	}
	if m := s.Match(moved("backlog", "review", event.ActorHuman)); m.Skip != NoRule {
		t.Fatalf("a dead rule matched: %+v", m)
	}
	if m := s.Match(moved("backlog", "ready", event.ActorHuman)); m.Rule != "plan" {
		t.Fatalf("a live rule stopped matching: %+v", m)
	}
}

func TestAProjectRenameKillsEveryRuleOfTheProject(t *testing.T) {
	s := checkedDead(t)
	e := slugEvent(event.ProjectRenamedType, projectID)
	e.FromSlug, e.ToSlug = "loupe", "loupe-app"

	got := names(s.Kill(e))
	want := []string{"plan/loupe/project_renamed", "review/loupe/project_renamed", "ship/loupe/project_renamed", "created/loupe/project_renamed"}
	if !slices.Equal(got, want) {
		t.Fatalf("Kill = %v, want %v", got, want)
	}
	created := event.Event{Type: "board.card_created", Subject: event.Subject{ID: cardID}, ProjectID: projectID, Actor: event.ActorAgent}
	if m := s.Match(created); m.Skip != NoRule {
		t.Fatalf("a dead generic rule matched: %+v", m)
	}
	other := moved("backlog", "ready", event.ActorHuman)
	other.ProjectID = otherID
	if m := s.Match(other); m.Rule != "other-plan" {
		t.Fatalf("the rule of another project stopped matching: %+v", m)
	}
}

func TestAGoneProjectKillsEveryRuleOfTheProject(t *testing.T) {
	s := checkedDead(t)

	got := names(s.KillProject("other", api.ReasonProjectGone))
	if want := []string{"other-plan/other/project_gone"}; !slices.Equal(got, want) {
		t.Fatalf("KillProject = %v, want %v", got, want)
	}
	other := moved("backlog", "ready", event.ActorHuman)
	other.ProjectID = otherID
	if m := s.Match(other); m.Skip != NoRule {
		t.Fatalf("a dead rule matched: %+v", m)
	}
	if m := s.Match(moved("backlog", "ready", event.ActorHuman)); m.Rule != "plan" {
		t.Fatalf("the rule of another project stopped matching: %+v", m)
	}
}

func TestKillIgnoresOtherEventsAndUnmappedProjects(t *testing.T) {
	s := checkedDead(t)
	unmapped := slugEvent(event.ProjectRenamedType, "0192f3a1-4b2c-7d3e-8f10-ffffffffffff")
	if dead := s.Kill(unmapped); len(dead) != 0 {
		t.Fatalf("an unmapped project killed %v", dead)
	}
	if dead := s.Kill(moved("ready", "done", event.ActorHuman)); len(dead) != 0 {
		t.Fatalf("a card move killed %v", dead)
	}
}

// The report carries every rule of one project in the contract's shape, and
// never the prompt.
func TestHealthReportsEachRuleWithoutItsPrompt(t *testing.T) {
	s := checkedDead(t)
	e := slugEvent(event.ColumnRenamedType, projectID)
	e.FromSlug, e.ToSlug = "ready", "ready-now"
	s.Kill(e)

	body, err := json.Marshal(s.Health("loupe"))
	if err != nil {
		t.Fatal(err)
	}
	want := `[` +
		`{"name":"plan","on":"board.card_moved","columns":["ready"],"state":"dead","reason":"column_renamed"},` +
		`{"name":"review","on":"board.card_moved","columns":["review","backlog"],"state":"live","reason":null},` +
		`{"name":"ship","on":"board.card_moved","columns":["done","ready"],"state":"dead","reason":"column_renamed"},` +
		`{"name":"created","on":"board.card_created","columns":[],"state":"live","reason":null}]`
	if string(body) != want {
		t.Fatalf("health =\n%s\nwant\n%s", body, want)
	}
	if strings.Contains(string(body), "SECRET PROMPT") {
		t.Fatal("the report carries prompt text")
	}
	if other := s.Health("other"); len(other) != 1 || other[0].State != api.RuleLive {
		t.Fatalf("other = %+v", other)
	}
}
