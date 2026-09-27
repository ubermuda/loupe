package rules

import (
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/event"
)

const prSession = "5f0c7e2a-1b3d-4c5e-8f9a-0b1c2d3e4f5a"

// pullRequest is an event of a pull request type on card 12, with every base
// field the server will send.
func pullRequest(typ string) event.Event {
	return event.Event{
		Type:              typ,
		Subject:           event.Subject{Type: "card", ID: cardID},
		ProjectID:         projectID,
		CardID:            cardID,
		CardNumber:        12,
		Forge:             "github",
		Repository:        "acme/shop",
		PullRequestNumber: 42,
		PullRequestURL:    "https://github.com/acme/shop/pull/42",
		HeadSHA:           "0a1b2c3d",
		Actor:             event.ActorSystem,
	}
}

// ruleOn is a rule file with one rule, whose fields are YAML lines.
func ruleOn(fields string) string {
	return "projects:\n  loupe:\n    dir: {dir}\nrules:\n  - name: pr\n    " + strings.ReplaceAll(strings.TrimSpace(fields), "\n", "\n    ") + "\n"
}

func parseErrOf(t *testing.T, body string) error {
	t.Helper()
	text, _ := file(t, body)
	_, err := Parse([]byte(text), Defaults{})

	return err
}

func TestMatchRendersEachPullRequestPlaceholder(t *testing.T) {
	checks := pullRequest(event.ChecksConcludedType)
	checks.Conclusion, checks.FailedChecks = event.ConclusionFailed, []string{"phpunit", "e2e (chromium)"}
	review := pullRequest(event.PullRequestReviewSubmittedType)
	review.Verdict = event.VerdictApproved
	fix := pullRequest(event.FixRequestedType)
	fix.Reason, fix.SessionID, fix.BridgeID = event.ReasonConflict, prSession, prSession
	noNumber := pullRequest("pull_request.merged")
	noNumber.PullRequestNumber = 0

	base := map[string]string{
		"{cardId}":            cardID,
		"{cardNumber}":        "12",
		"{projectId}":         projectID,
		"{project}":           "loupe",
		"{forge}":             "github",
		"{repository}":        "acme/shop",
		"{pullRequestNumber}": "42",
		"{pullRequestUrl}":    "https://github.com/acme/shop/pull/42",
		"{headSha}":           "0a1b2c3d",
	}
	for name, tc := range map[string]struct {
		event  event.Event
		values map[string]string
	}{
		"checks": {checks, map[string]string{"{conclusion}": "failed", "{failedChecks}": `"phpunit", "e2e (chromium)"`}},
		"review": {review, map[string]string{"{verdict}": "approved"}},
		"fix":    {fix, map[string]string{"{reason}": "conflict", "{sessionId}": prSession}},
		"merged": {pullRequest("pull_request.merged"), nil},
		// A type this build does not know yet fills the base set.
		"a future type": {pullRequest("pull_request.reopened"), nil},
	} {
		want := map[string]string{}
		for k, v := range base {
			want[k] = v
		}
		for k, v := range tc.values {
			want[k] = v
		}
		for placeholder, value := range want {
			t.Run(name+" "+placeholder, func(t *testing.T) {
				s := checked(t, ruleOn("on: "+tc.event.Type+"\nproject: loupe\nprompt: 'value "+placeholder+"'"))
				m := s.Match(tc.event)
				if m.Skip != Run || m.Prompt != "value "+value+"\n\n"+directive.Footer {
					t.Fatalf("Match = %+v", m)
				}
			})
		}
	}

	s := checked(t, ruleOn("on: pull_request.merged\nproject: loupe\nprompt: 'number {pullRequestNumber}.'"))
	if got := s.Match(noNumber).Prompt; !strings.HasPrefix(got, "number .") {
		t.Fatalf("prompt = %q, want an empty number", got)
	}
	s = checked(t, ruleOn("on: pull_request.fix_requested\nproject: loupe\nprompt: 'session {sessionId}.'"))
	if got := s.Match(pullRequest(event.FixRequestedType)).Prompt; !strings.HasPrefix(got, "session .") {
		t.Fatalf("prompt = %q, want an empty session", got)
	}
}

// A placeholder that another type fills has no value on this one, and the
// error says so rather than calling it unknown.
func TestParseRefusesAPullRequestPlaceholderOnTheWrongType(t *testing.T) {
	for name, tc := range map[string]struct {
		body string
		want string
	}{
		"card_moved {conclusion}":  {ruleOn("on: board.card_moved\nproject: loupe\nto: ready\nprompt: '{conclusion}'"), "{conclusion} has no value for board.card_moved"},
		"card_moved {repository}":  {ruleOn("on: board.card_moved\nproject: loupe\nto: ready\nprompt: '{repository}'"), "{repository} has no value for board.card_moved"},
		"checks {reason}":          {ruleOn("on: pull_request.checks_concluded\nproject: loupe\nprompt: '{reason}'"), "{reason} has no value for pull_request.checks_concluded"},
		"checks {sessionId}":       {ruleOn("on: pull_request.checks_concluded\nproject: loupe\nprompt: '{sessionId}'"), "{sessionId} has no value for pull_request.checks_concluded"},
		"fix {failedChecks}":       {ruleOn("on: pull_request.fix_requested\nproject: loupe\nprompt: '{failedChecks}'"), "{failedChecks} has no value for pull_request.fix_requested"},
		"merged {verdict}":         {ruleOn("on: pull_request.merged\nproject: loupe\nprompt: '{verdict}'"), "{verdict} has no value for pull_request.merged"},
		"review {column}":          {ruleOn("on: pull_request.review_submitted\nproject: loupe\nprompt: '{column}'"), "{column} has no value for pull_request.review_submitted"},
		"an unknown on a pr event": {ruleOn("on: pull_request.merged\nproject: loupe\nprompt: '{title}'"), "unknown placeholder {title}"},
	} {
		t.Run(name, func(t *testing.T) {
			if err := parseErrOf(t, tc.body); err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want it to contain %q", err, tc.want)
			}
		})
	}
}

func TestParseRefusesAMalformedWhen(t *testing.T) {
	for name, tc := range map[string]struct {
		body string
		want string
	}{
		"a field of another type": {ruleOn("on: pull_request.checks_concluded\nproject: loupe\nwhen: {reason: conflict}\nprompt: x"), "when.reason is not a field of pull_request.checks_concluded; it takes conclusion"},
		"a value off the enum":    {ruleOn("on: pull_request.checks_concluded\nproject: loupe\nwhen: {conclusion: cancelled}\nprompt: x"), `when.conclusion "cancelled" is not passed or failed`},
		"an unknown verdict":      {ruleOn("on: pull_request.review_submitted\nproject: loupe\nwhen: {verdict: commented}\nprompt: x"), `when.verdict "commented" is not approved or changes-requested`},
		"an unknown reason":       {ruleOn("on: pull_request.fix_requested\nproject: loupe\nwhen: {reason: flaky}\nprompt: x"), `when.reason "flaky" is not checks-failed, conflict or changes-requested`},
		"when on a merge":         {ruleOn("on: pull_request.merged\nproject: loupe\nwhen: {reason: conflict}\nprompt: x"), "when applies to pull_request.checks_concluded, pull_request.review_submitted and pull_request.fix_requested only, and this rule is on pull_request.merged"},
		"when on card_moved":      {ruleOn("on: board.card_moved\nproject: loupe\nto: ready\nwhen: {conclusion: failed}\nprompt: x"), "when applies to pull_request.checks_concluded"},
		"when on an interactive":  {ruleOn("action: interactive\non: board.card_moved\nproject: loupe\nto: ready\nwhen: {conclusion: failed}\nprompt: x"), "when names worker behaviour"},
	} {
		t.Run(name, func(t *testing.T) {
			if err := parseErrOf(t, tc.body); err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want it to contain %q", err, tc.want)
			}
		})
	}
}

// Each when entry must equal the event's field. A rule with no when matches
// every value, and an event with no value matches no when.
func TestMatchReadsWhen(t *testing.T) {
	s := checked(t, `
projects:
  loupe:
    dir: {dir}
rules:
  - name: failed
    on: pull_request.checks_concluded
    project: loupe
    when:
      conclusion: failed
    prompt: fix {cardNumber}
  - name: approved
    on: pull_request.review_submitted
    project: loupe
    when: {verdict: approved}
    prompt: merge {cardNumber}
  - name: conflict
    on: pull_request.fix_requested
    project: loupe
    when: {reason: conflict}
    prompt: rebase {cardNumber}
  - name: any
    on: pull_request.checks_concluded
    project: loupe
    prompt: any {cardNumber}
`)
	with := func(typ string, set func(*event.Event)) event.Event {
		e := pullRequest(typ)
		set(&e)

		return e
	}
	for name, tc := range map[string]struct {
		event event.Event
		skip  Skip
		rule  string
	}{
		"failed checks":        {with(event.ChecksConcludedType, func(e *event.Event) { e.Conclusion = event.ConclusionFailed }), Run, "failed"},
		"passed checks":        {with(event.ChecksConcludedType, func(e *event.Event) { e.Conclusion = event.ConclusionPassed }), Run, "any"},
		"no conclusion":        {pullRequest(event.ChecksConcludedType), Run, "any"},
		"an approval":          {with(event.PullRequestReviewSubmittedType, func(e *event.Event) { e.Verdict = event.VerdictApproved }), Run, "approved"},
		"a change request":     {with(event.PullRequestReviewSubmittedType, func(e *event.Event) { e.Verdict = event.VerdictChangesRequested }), NoRule, ""},
		"a conflict":           {with(event.FixRequestedType, func(e *event.Event) { e.Reason = event.ReasonConflict }), Run, "conflict"},
		"failed checks to fix": {with(event.FixRequestedType, func(e *event.Event) { e.Reason = event.ReasonChecksFailed }), NoRule, ""},
	} {
		t.Run(name, func(t *testing.T) {
			m := s.Match(tc.event)
			if m.Skip != tc.skip || m.Rule != tc.rule {
				t.Fatalf("Match = %+v, want skip %d rule %q", m, tc.skip, tc.rule)
			}
		})
	}
	if got := s.ExtraTypes(); !got[event.ChecksConcludedType] || !got[event.FixRequestedType] {
		t.Fatalf("ExtraTypes = %v", got)
	}
}

// A fix request may resume the session the event names. With no session, the
// rule starts a fresh one. Either way, the prompt ends with the card footer,
// because the resume footer is about inbox answers.
func TestMatchResumesTheSessionOfAFixRequest(t *testing.T) {
	s := checked(t, ruleOn("on: pull_request.fix_requested\nproject: loupe\nresume: true\nprompt: 'Fix card {cardNumber}.'"))
	fix := pullRequest(event.FixRequestedType)

	m := s.Match(fix)
	if m.Skip != Run || m.Resume || m.Prompt != "Fix card 12.\n\n"+directive.Footer {
		t.Fatalf("Match with no session = %+v", m)
	}

	fix.SessionID, fix.BridgeID = prSession, prSession
	m = s.Match(fix)
	if m.Skip != Run || !m.Resume || m.Prompt != "Fix card 12.\n\n"+directive.Footer {
		t.Fatalf("Match with a session = %+v", m)
	}

	s = checked(t, ruleOn("on: pull_request.fix_requested\nproject: loupe\nprompt: 'Fix card {cardNumber}.'"))
	if m := s.Match(fix); m.Skip != Run || m.Resume {
		t.Fatalf("Match of a rule with no resume = %+v", m)
	}
}

func TestParseRefusesResumeOnAnotherPullRequestType(t *testing.T) {
	err := parseErrOf(t, ruleOn("on: pull_request.checks_concluded\nproject: loupe\nresume: true\nprompt: x"))
	if want := "resume applies to inbox.ask_closed and pull_request.fix_requested only, and this rule is on pull_request.checks_concluded"; err == nil || !strings.Contains(err.Error(), want) {
		t.Fatalf("err = %v, want it to contain %q", err, want)
	}
}
