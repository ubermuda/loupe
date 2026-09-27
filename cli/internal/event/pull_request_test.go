package event

import (
	"errors"
	"strings"
	"testing"
)

// currentChecksPayload copies the keys the server sends today, with no cardId.
const currentChecksPayload = `{"type":"pull_request.checks_concluded","subject":{"type":"card","id":"0192F3A1-7777-7D3E-8F10-A2B3C4D5E6F7"},"projectId":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7","cardNumber":12,"forge":"github","actor":"system"}`

const (
	prCard    = "0192f3a1-7777-7d3e-8f10-a2b3c4d5e6f7"
	prSession = "5F0C7E2A-1B3D-4C5E-8F9A-0B1C2D3E4F5A"
	prBridge  = "7D1E2F3A-4B5C-4D6E-9F0A-1B2C3D4E5F6A"
)

// pullRequest builds a payload of a pull request type with every base field,
// plus extra, a JSON fragment of more fields.
func pullRequest(typ, extra string) string {
	payload := `{"type":"` + typ + `","subject":{"type":"card","id":"` + prCard + `"},"projectId":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7",` +
		`"cardId":"` + prCard + `","cardNumber":12,"forge":"github","repository":"acme/shop","pullRequestNumber":42,` +
		`"pullRequestUrl":"https://github.com/acme/shop/pull/42","headSha":"0a1b2c3d4e5f60718293a4b5c6d7e8f901234567","actor":"system"`
	if extra != "" {
		payload += "," + extra
	}

	return payload + "}"
}

func prRules(types ...string) map[string]bool {
	out := map[string]bool{}
	for _, t := range types {
		out[t] = true
	}

	return out
}

var allPR = prRules(ChecksConcludedType, PullRequestReviewSubmittedType, FixRequestedType, "pull_request.merged", "pull_request.conflicted")

// The server sends no cardId yet. The subject is the card, so the bridge takes
// its id.
func TestParseTakesTheCardOfAPullRequestEventFromItsSubject(t *testing.T) {
	e := parseOK(t, currentChecksPayload, allPR)
	if e.CardID != prCard || e.CardNumber != 12 || e.Subject.ID != prCard {
		t.Fatalf("card = %q %d, subject = %q", e.CardID, e.CardNumber, e.Subject.ID)
	}
	if e.Forge != "github" || e.Actor != ActorSystem {
		t.Fatalf("event = %+v", e)
	}
}

func TestParseReadsEachPullRequestField(t *testing.T) {
	e := parseOK(t, pullRequest(ChecksConcludedType, `"conclusion":"failed","failedChecks":["phpunit","e2e (chromium)"]`), allPR)
	if e.Repository != "acme/shop" || e.PullRequestNumber != 42 || e.PullRequestURL != "https://github.com/acme/shop/pull/42" || e.HeadSHA != "0a1b2c3d4e5f60718293a4b5c6d7e8f901234567" {
		t.Fatalf("event = %+v", e)
	}
	if e.Conclusion != ConclusionFailed || strings.Join(e.FailedChecks, "|") != "phpunit|e2e (chromium)" {
		t.Fatalf("conclusion = %q, failed checks = %v", e.Conclusion, e.FailedChecks)
	}

	e = parseOK(t, pullRequest(PullRequestReviewSubmittedType, `"verdict":"changes-requested"`), allPR)
	if e.Verdict != VerdictChangesRequested {
		t.Fatalf("verdict = %q", e.Verdict)
	}

	e = parseOK(t, pullRequest(FixRequestedType, `"reason":"conflict","sessionId":"`+prSession+`","bridgeId":"`+prBridge+`"`), allPR)
	if e.Reason != ReasonConflict || e.SessionID != strings.ToLower(prSession) || e.BridgeID != strings.ToLower(prBridge) {
		t.Fatalf("event = %+v", e)
	}

	e = parseOK(t, pullRequest("pull_request.merged", ""), allPR)
	if e.CardID != prCard || e.PullRequestNumber != 42 {
		t.Fatalf("event = %+v", e)
	}
}

// A fix request with no session starts a fresh run, so both ids may be absent.
func TestParseTakesAFixRequestWithNoSession(t *testing.T) {
	for name, extra := range map[string]string{
		"absent": `"reason":"checks-failed"`,
		"null":   `"reason":"checks-failed","sessionId":null,"bridgeId":null`,
	} {
		t.Run(name, func(t *testing.T) {
			if e := parseOK(t, pullRequest(FixRequestedType, extra), allPR); e.SessionID != "" || e.BridgeID != "" {
				t.Fatalf("event = %+v", e)
			}
		})
	}
}

func TestParseRejectsAMalformedPullRequestEvent(t *testing.T) {
	base := pullRequest(ChecksConcludedType, "")
	replace := func(old, new string) string { return strings.Replace(base, old, new, 1) }
	for name, payload := range map[string]string{
		"no card number":        replace(`"cardNumber":12`, `"cardNumber":0`),
		"negative card number":  replace(`"cardNumber":12`, `"cardNumber":-1`),
		"card not a uuid":       replace(`"cardId":"`+prCard+`"`, `"cardId":"12"`),
		"card not the subject":  replace(`"cardId":"`+prCard+`"`, `"cardId":"0192f3a1-8888-7d3e-8f10-a2b3c4d5e6f7"`),
		"subject not a uuid":    replace(`"id":"`+prCard+`"`, `"id":"card"`),
		"unknown actor":         replace(`"actor":"system"`, `"actor":"forge"`),
		"forge not a slug":      replace(`"forge":"github"`, `"forge":"Git Hub"`),
		"repository one part":   replace(`"acme/shop"`, `"shop"`),
		"repository a space":    replace(`"acme/shop"`, `"acme/my shop"`),
		"repository too long":   replace(`"acme/shop"`, `"acme/`+strings.Repeat("a", 251)+`"`),
		"negative pr number":    replace(`"pullRequestNumber":42`, `"pullRequestNumber":-1`),
		"url not https":         replace(`"https://github.com`, `"http://github.com`),
		"url a space":           replace(`/pull/42"`, `/pull/42 x"`),
		"url a no-break space":  replace(`/pull/42"`, "/pull/42 x\""),
		"url a quote":           replace(`/pull/42"`, `/pull/42'"`),
		"url a backtick":        replace(`/pull/42"`, "/pull/42`\""),
		"url a brace":           replace(`/pull/42"`, `/pull/{42}"`),
		"url an angle bracket":  replace(`/pull/42"`, `/pull/<42>"`),
		"url no host":           replace(`"https://github.com/acme/shop/pull/42"`, `"https:///acme"`),
		"url too long":          replace(`/pull/42"`, `/pull/42`+strings.Repeat("a", 2000)+`"`),
		"sha too short":         replace(`"headSha":"0a1b2c3d4e5f60718293a4b5c6d7e8f901234567"`, `"headSha":"0a1b2c"`),
		"sha upper case":        replace(`"headSha":"0a1b2c3d4e5f60718293a4b5c6d7e8f901234567"`, `"headSha":"0A1B2C3D"`),
		"sha not hex":           replace(`"headSha":"0a1b2c3d4e5f60718293a4b5c6d7e8f901234567"`, `"headSha":"0a1b2c3z"`),
		"unknown conclusion":    pullRequest(ChecksConcludedType, `"conclusion":"cancelled"`),
		"failed checks a brace": pullRequest(ChecksConcludedType, `"failedChecks":["{cardId}"]`),
		"failed check empty":    pullRequest(ChecksConcludedType, `"failedChecks":[""]`),
		"failed check too long": pullRequest(ChecksConcludedType, `"failedChecks":["`+strings.Repeat("a", 201)+`"]`),
		"failed check control":  pullRequest(ChecksConcludedType, `"failedChecks":["a\nb"]`),
		"too many checks":       pullRequest(ChecksConcludedType, `"failedChecks":[`+strings.TrimSuffix(strings.Repeat(`"a",`, 101), ",")+`]`),
		"a check not a string":  pullRequest(ChecksConcludedType, `"failedChecks":[1]`),
		"unknown verdict":       pullRequest(PullRequestReviewSubmittedType, `"verdict":"commented"`),
		"unknown reason":        pullRequest(FixRequestedType, `"reason":"flaky"`),
		"session not a uuid":    pullRequest(FixRequestedType, `"sessionId":"--resume x","bridgeId":"`+prBridge+`"`),
		"bridge not a uuid":     pullRequest(FixRequestedType, `"sessionId":"`+prSession+`","bridgeId":"bridge"`),
		"session, no bridge":    pullRequest(FixRequestedType, `"sessionId":"`+prSession+`"`),
		"bridge, no session":    pullRequest(FixRequestedType, `"bridgeId":"`+prBridge+`"`),
	} {
		t.Run(name, func(t *testing.T) {
			if err := parseErr(t, payload, allPR); errors.Is(err, ErrUnknownType) {
				t.Fatalf("must be malformed, not unknown: %v", err)
			}
		})
	}
}

// Each type-specific field belongs to one type. On another, the server and
// the bridge disagree on what the event means, so the bridge refuses it.
func TestParseRefusesAPullRequestFieldOnTheWrongType(t *testing.T) {
	for name, payload := range map[string]string{
		"conclusion on a review":    pullRequest(PullRequestReviewSubmittedType, `"conclusion":"passed"`),
		"failed checks on a fix":    pullRequest(FixRequestedType, `"failedChecks":["phpunit"]`),
		"verdict on checks":         pullRequest(ChecksConcludedType, `"verdict":"approved"`),
		"reason on checks":          pullRequest(ChecksConcludedType, `"reason":"conflict"`),
		"reason on a merge":         pullRequest("pull_request.merged", `"reason":"conflict"`),
		"session on checks":         pullRequest(ChecksConcludedType, `"sessionId":"`+prSession+`","bridgeId":"`+prBridge+`"`),
		"bridge on a conflict":      pullRequest("pull_request.conflicted", `"bridgeId":"`+prBridge+`"`),
		"conclusion on a conflict":  pullRequest("pull_request.conflicted", `"conclusion":"failed"`),
		"verdict on a fix":          pullRequest(FixRequestedType, `"verdict":"approved"`),
		"failed checks on a review": pullRequest(PullRequestReviewSubmittedType, `"failedChecks":["phpunit"]`),
	} {
		t.Run(name, func(t *testing.T) {
			if err := parseErr(t, payload, allPR); errors.Is(err, ErrUnknownType) {
				t.Fatalf("must be malformed, not unknown: %v", err)
			}
		})
	}
}

// A bridge with no rule on the type drops the event unread.
func TestParseDropsAPullRequestEventWhenNoRuleNamesIt(t *testing.T) {
	if err := parseErr(t, currentChecksPayload, nil); !errors.Is(err, ErrUnknownType) {
		t.Fatalf("err = %v, want an unknown type", err)
	}
}

// A fix request with no bridge, or with a null one, is for any bridge. One
// that names another bridge, or names a bridge that is not a string, is not
// ours.
func TestForAnotherBridgeReadsAFixRequest(t *testing.T) {
	own := strings.ToLower(prBridge)
	withBridge := func(bridge string) string {
		return pullRequest(FixRequestedType, `"reason":"conflict","sessionId":"`+prSession+`","bridgeId":`+bridge)
	}
	for name, tc := range map[string]struct {
		payload string
		want    bool
	}{
		"this bridge, another case": {withBridge(`"` + prBridge + `"`), false},
		"another bridge":            {withBridge(`"00000000-4B5C-4D6E-9F0A-1B2C3D4E5F6A"`), true},
		"a null bridge":             {withBridge(`null`), false},
		"an empty bridge":           {withBridge(`""`), false},
		"no bridge key":             {pullRequest(FixRequestedType, `"reason":"conflict"`), false},
		"a bridge that is a number": {withBridge(`7`), true},
		"another pull request type": {pullRequest(ChecksConcludedType, `"bridgeId":"00000000-4B5C-4D6E-9F0A-1B2C3D4E5F6A"`), false},
	} {
		t.Run(name, func(t *testing.T) {
			if got := ForAnotherBridge([]byte(tc.payload), own); got != tc.want {
				t.Fatalf("ForAnotherBridge = %v, want %v", got, tc.want)
			}
		})
	}
}
