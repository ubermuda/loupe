package event

import (
	"errors"
	"strings"
	"testing"
)

// reviewSubmittedPayload copies the keys the server's outbox listener writes.
const reviewSubmittedPayload = `{"type":"document.review_submitted","subject":{"type":"document","id":"01A0A1B2-5555-7C3D-8E4F-5A6B7C8D9E0F"},"projectId":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7","verdict":"approved","cardIds":["0192f3a1-7777-7d3e-8f10-a2b3c4d5e6f7"],"actor":"human","cardId":"0192F3A1-7777-7D3E-8F10-A2B3C4D5E6F7","cardNumber":33,"column":"tech-design"}`

var reviewRule = map[string]bool{ReviewSubmittedType: true}

// noCard replaces the three card fields with the nulls the server sends when no
// stage card exists.
func noCard(payload string) string {
	return strings.NewReplacer(
		`"cardId":"0192F3A1-7777-7D3E-8F10-A2B3C4D5E6F7"`, `"cardId":null`,
		`"cardNumber":33`, `"cardNumber":null`,
		`"column":"tech-design"`, `"column":null`,
	).Replace(payload)
}

func TestParseReviewSubmitted(t *testing.T) {
	e := parseOK(t, reviewSubmittedPayload, reviewRule)
	if e.Type != ReviewSubmittedType || e.Subject.ID != "01a0a1b2-5555-7c3d-8e4f-5a6b7c8d9e0f" || e.Actor != ActorHuman {
		t.Fatalf("event = %+v", e)
	}
	if e.Verdict != "approved" || e.Column != "tech-design" {
		t.Fatalf("verdict = %q, column = %q", e.Verdict, e.Column)
	}
	if e.CardID != "0192f3a1-7777-7d3e-8f10-a2b3c4d5e6f7" || e.CardNumber != 33 {
		t.Fatalf("card = %q %d", e.CardID, e.CardNumber)
	}
}

func TestParseReviewSubmittedAsksForChanges(t *testing.T) {
	e := parseOK(t, strings.Replace(reviewSubmittedPayload, `"approved"`, `"changes-requested"`, 1), reviewRule)
	if e.Verdict != "changes-requested" {
		t.Fatalf("verdict = %q", e.Verdict)
	}
}

// The server sends the three card fields as null together when the document
// has no stage card.
func TestParseReviewSubmittedTakesANullCard(t *testing.T) {
	e := parseOK(t, noCard(reviewSubmittedPayload), reviewRule)
	if e.CardID != "" || e.CardNumber != 0 || e.Column != "" {
		t.Fatalf("event = %+v", e)
	}
}

// The server omits the card key when the review names no stage card.
func TestParseReviewSubmittedReadsTheInteractiveRun(t *testing.T) {
	withCard := func(card string) string {
		return strings.Replace(reviewSubmittedPayload, `"column":"tech-design"`, `"column":"tech-design","card":`+card, 1)
	}
	for name, tc := range map[string]struct {
		payload string
		want    bool
	}{
		"true":          {withCard(`{"interactiveRun":true}`), true},
		"false":         {withCard(`{"interactiveRun":false}`), false},
		"absent":        {reviewSubmittedPayload, false},
		"no stage card": {noCard(reviewSubmittedPayload), false},
	} {
		t.Run(name, func(t *testing.T) {
			if e := parseOK(t, tc.payload, reviewRule); e.Card.InteractiveRun != tc.want {
				t.Fatalf("interactiveRun = %v, want %v", e.Card.InteractiveRun, tc.want)
			}
		})
	}
}

func TestParseRejectsAMalformedReviewSubmitted(t *testing.T) {
	for name, payload := range map[string]string{
		"withdrawn verdict":   strings.Replace(reviewSubmittedPayload, `"approved"`, `"withdrawn"`, 1),
		"no verdict":          strings.Replace(reviewSubmittedPayload, `"verdict":"approved",`, ``, 1),
		"card not a uuid":     strings.Replace(reviewSubmittedPayload, `"cardId":"0192F3A1-7777-7D3E-8F10-A2B3C4D5E6F7"`, `"cardId":"87"`, 1),
		"card with no number": strings.Replace(reviewSubmittedPayload, `"cardNumber":33`, `"cardNumber":null`, 1),
		"number with no card": strings.Replace(reviewSubmittedPayload, `"cardId":"0192F3A1-7777-7D3E-8F10-A2B3C4D5E6F7"`, `"cardId":null`, 1),
		"column not a slug":   strings.Replace(reviewSubmittedPayload, `"tech-design"`, `"Tech Design"`, 1),
		"card with no column": strings.Replace(reviewSubmittedPayload, `"column":"tech-design"`, `"column":null`, 1),
		"column with no card": strings.Replace(noCard(reviewSubmittedPayload), `"column":null`, `"column":"tech-design"`, 1),
		"subject not a uuid":  strings.Replace(reviewSubmittedPayload, `"id":"01A0A1B2-5555-7C3D-8E4F-5A6B7C8D9E0F"`, `"id":"doc"`, 1),
	} {
		t.Run(name, func(t *testing.T) {
			if err := parseErr(t, payload, reviewRule); errors.Is(err, ErrUnknownType) {
				t.Fatalf("must be malformed, not unknown: %v", err)
			}
		})
	}
}

// The error names the verdict it refused, so the log says what the server sent.
func TestParseNamesAnUnknownVerdict(t *testing.T) {
	err := parseErr(t, strings.Replace(reviewSubmittedPayload, `"approved"`, `"withdrawn"`, 1), reviewRule)
	if !strings.Contains(err.Error(), `"withdrawn"`) {
		t.Fatalf("err = %v", err)
	}
}

// A bridge with no rule on the type drops the event unread.
func TestParseDropsReviewSubmittedWhenNoRuleNamesIt(t *testing.T) {
	if err := parseErr(t, reviewSubmittedPayload, nil); !errors.Is(err, ErrUnknownType) {
		t.Fatalf("err = %v, want an unknown type", err)
	}
}
