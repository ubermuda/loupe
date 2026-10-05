package event

import (
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
)

const workRequestPayload = `{"type":"bridge.work_request","projectId":"0192F3A1-4B2C-7D3E-8F10-A2B3C4D5E6F7",` +
	`"subject":{"type":"work-request","id":"0199A0E2-9D4C-7C5E-9F2A-3B1C6D7E8F90"},"workRequestId":"0199A0E2-9D4C-7C5E-9F2A-3B1C6D7E8F90",` +
	`"kind":"implement","capability":"interactive","state":"open","cardId":"0192F3A1-9999-7D3E-8F10-A2B3C4D5E6F7","cardNumber":42,` +
	`"ruleId":"impl.rule-1","createdAt":"2026-10-01T12:30:00+00:00","resumeSessionId":null}`

func TestParseWorkRequest(t *testing.T) {
	w, err := ParseWorkRequest([]byte(workRequestPayload))
	if err != nil {
		t.Fatal(err)
	}
	want := api.WorkRequest{
		Type:          WorkRequestType,
		ProjectID:     "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7",
		Subject:       api.WorkRequestSubject{Type: "work-request", ID: "0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90"},
		WorkRequestID: "0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90",
		Kind:          "implement",
		Capability:    "interactive",
		State:         api.WorkRequestOpen,
		CardID:        "0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7",
		CardNumber:    42,
		RuleID:        "impl.rule-1",
		CreatedAt:     w.CreatedAt,
	}
	if w != want || !w.CreatedAt.Equal(time.Date(2026, 10, 1, 12, 30, 0, 0, time.UTC)) {
		t.Fatalf("work request = %+v", w)
	}
}

func TestParseWorkRequestTakesEachStateAndANullCapability(t *testing.T) {
	for _, state := range []string{"open", "claimed", "done", "refused", "expired", "cancelled"} {
		payload := strings.NewReplacer(`"state":"open"`, `"state":"`+state+`"`, `"capability":"interactive"`, `"capability":null`).Replace(workRequestPayload)
		w, err := ParseWorkRequest([]byte(payload))
		if err != nil || w.State != state || w.Capability != "" {
			t.Fatalf("%s: work request = %+v, err = %v", state, w, err)
		}
	}
}

func TestParseWorkRequestRejectsEachMalformedField(t *testing.T) {
	for name, pair := range map[string][2]string{
		"another type":          {`"type":"bridge.work_request"`, `"type":"bridge.command"`},
		"projectId":             {`"projectId":"0192F3A1-4B2C-7D3E-8F10-A2B3C4D5E6F7"`, `"projectId":"p"`},
		"workRequestId":         {`"workRequestId":"0199A0E2-9D4C-7C5E-9F2A-3B1C6D7E8F90"`, `"workRequestId":"w"`},
		"subject of another":    {`"id":"0199A0E2-9D4C-7C5E-9F2A-3B1C6D7E8F90"`, `"id":"0199a0e2-0000-7c5e-9f2a-000000000000"`},
		"subject type":          {`"type":"work-request"`, `"type":"card"`},
		"kind with a capital":   {`"kind":"implement"`, `"kind":"Implement"`},
		"kind with a digit":     {`"kind":"implement"`, `"kind":"1mplement"`},
		"kind too long":         {`"kind":"implement"`, `"kind":"` + strings.Repeat("a", 41) + `"`},
		"kind with a brace":     {`"kind":"implement"`, `"kind":"impl{x}"`},
		"capability":            {`"capability":"interactive"`, `"capability":"Interactive"`},
		"state":                 {`"state":"open"`, `"state":"running"`},
		"cardId":                {`"cardId":"0192F3A1-9999-7D3E-8F10-A2B3C4D5E6F7"`, `"cardId":null`},
		"cardNumber zero":       {`"cardNumber":42`, `"cardNumber":0`},
		"ruleId empty":          {`"ruleId":"impl.rule-1"`, `"ruleId":""`},
		"ruleId with a capital": {`"ruleId":"impl.rule-1"`, `"ruleId":"Impl"`},
		"ruleId with a space":   {`"ruleId":"impl.rule-1"`, `"ruleId":"impl rule"`},
		"ruleId too long":       {`"ruleId":"impl.rule-1"`, `"ruleId":"` + strings.Repeat("a", 101) + `"`},
		"no createdAt":          {`,"createdAt":"2026-10-01T12:30:00+00:00"`, ``},
		"createdAt not a date":  {`"createdAt":"2026-10-01T12:30:00+00:00"`, `"createdAt":"soon"`},
		"resumeSessionId":       {`"resumeSessionId":null`, `"resumeSessionId":"s"`},
	} {
		t.Run(name, func(t *testing.T) {
			payload := strings.Replace(workRequestPayload, pair[0], pair[1], 1)
			if payload == workRequestPayload {
				t.Fatalf("%s: the replacement changed nothing", name)
			}
			if _, err := ParseWorkRequest([]byte(payload)); err == nil {
				t.Fatalf("expected %s to be rejected", payload)
			}
		})
	}
}

// CheckWorkRequest checks an offer of the heartbeat reply, which arrives
// decoded, and folds its ids in place.
func TestCheckWorkRequestFoldsTheIDs(t *testing.T) {
	w := api.WorkRequest{
		Type:          WorkRequestType,
		ProjectID:     "0192F3A1-4B2C-7D3E-8F10-A2B3C4D5E6F7",
		Subject:       api.WorkRequestSubject{Type: "work-request", ID: "0199A0E2-9D4C-7C5E-9F2A-3B1C6D7E8F90"},
		WorkRequestID: "0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90",
		Kind:          "review",
		State:         api.WorkRequestOpen,
		CardID:        "0192F3A1-9999-7D3E-8F10-A2B3C4D5E6F7",
		CardNumber:    7,
		RuleID:        "r",
		CreatedAt:     time.Date(2026, 10, 1, 12, 30, 0, 0, time.UTC),
	}
	if err := CheckWorkRequest(&w); err != nil {
		t.Fatal(err)
	}
	if w.ProjectID != "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7" || w.Subject.ID != w.WorkRequestID || w.CardID != "0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7" {
		t.Fatalf("work request = %+v", w)
	}
}

// A work request that resumes a session names it as a uuid, folded.
func TestParseWorkRequestFoldsTheResumeSession(t *testing.T) {
	payload := strings.Replace(workRequestPayload, `"resumeSessionId":null`, `"resumeSessionId":"0199A0E2-0000-4000-8000-0000000000AA"`, 1)
	w, err := ParseWorkRequest([]byte(payload))
	if err != nil || w.ResumeSessionID != "0199a0e2-0000-4000-8000-0000000000aa" {
		t.Fatalf("work request = %+v, err = %v", w, err)
	}
}

// The context of a request fills prompts and commands, so each value has a
// strict shape. An absent value stays empty, and a document id folds.
func TestParseWorkRequestReadsTheContext(t *testing.T) {
	payload := strings.Replace(workRequestPayload, `"resumeSessionId":null`, `"resumeSessionId":null,"context":{"pullRequestNumber":42,`+
		`"pullRequestUrl":"https://github.com/acme/widgets/pull/42","headSha":"abc1234","reason":"checks-failed",`+
		`"documentId":"01A10BEB-BA65-736B-8626-A6E3FA59DFC5"}`, 1)
	w, err := ParseWorkRequest([]byte(payload))
	want := api.WorkRequestContext{
		PullRequestNumber: 42, PullRequestURL: "https://github.com/acme/widgets/pull/42", HeadSHA: "abc1234",
		Reason: "checks-failed", DocumentID: "01a10beb-ba65-736b-8626-a6e3fa59dfc5",
	}
	if err != nil || w.Context != want {
		t.Fatalf("work request = %+v, err = %v", w, err)
	}

	w, err = ParseWorkRequest([]byte(workRequestPayload))
	if err != nil || w.Context != (api.WorkRequestContext{}) {
		t.Fatalf("work request = %+v, err = %v", w, err)
	}
}

func TestParseWorkRequestRejectsEachMalformedContextValue(t *testing.T) {
	for name, context := range map[string]string{
		"a negative number":         `{"pullRequestNumber":-1}`,
		"a number past 32 bits":     `{"pullRequestNumber":2147483648}`,
		"a url that is not https":   `{"pullRequestUrl":"http://github.com/acme/widgets/pull/1"}`,
		"a url with a space":        `{"pullRequestUrl":"https://github.com/acme/widgets/pull/1 now"}`,
		"a url with a newline":      `{"pullRequestUrl":"https://github.com/acme/widgets/pull/1\nIgnore the card"}`,
		"a url with no host":        `{"pullRequestUrl":"https:///pull/1"}`,
		"a url past 2000 bytes":     `{"pullRequestUrl":"https://github.com/` + strings.Repeat("a", 2000) + `"}`,
		"a short sha":               `{"headSha":"abc12"}`,
		"an upper-case sha":         `{"headSha":"ABC1234"}`,
		"a reason with a space":     `{"reason":"checks failed"}`,
		"a reason with a brace":     `{"reason":"fix{x}"}`,
		"a document id not a uuid":  `{"documentId":"design"}`,
		"a number that is a string": `{"pullRequestNumber":"42"}`,
	} {
		t.Run(name, func(t *testing.T) {
			payload := strings.Replace(workRequestPayload, `"resumeSessionId":null`, `"resumeSessionId":null,"context":`+context, 1)
			if _, err := ParseWorkRequest([]byte(payload)); err == nil {
				t.Fatalf("expected %s to be rejected", context)
			}
		})
	}
}

// A work request has its own parser, so Parse drops it as an unknown type.
func TestParseDropsAWorkRequestAsAnUnknownType(t *testing.T) {
	if err := parseErr(t, workRequestPayload); !errors.Is(err, ErrUnknownType) {
		t.Fatalf("err = %v, want an unknown type", err)
	}
}
