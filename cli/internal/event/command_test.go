package event

import (
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
)

const commandPayload = `{"type":"bridge.command","projectId":"0192F3A1-4B2C-7D3E-8F10-A2B3C4D5E6F7",` +
	`"subject":{"type":"bridge-command","id":"0199A0E2-0000-7C5E-9F2A-3B1C6D7E8F90"},"commandId":"0199A0E2-0000-7C5E-9F2A-3B1C6D7E8F90",` +
	`"kind":"stop-run","bridgeId":"7D1E2F3A-4B5C-4D6E-9F0A-1B2C3D4E5F6A","runKey":"0199A0E2-1111-7C5E-9F2A-3B1C6D7E8F90",` +
	`"sessionId":"0199A0E2-2222-7C5E-9F2A-3B1C6D7E8F90","subjectType":"card","subjectId":"0192F3A1-9999-7D3E-8F10-A2B3C4D5E6F7","cardNumber":42,` +
	`"workRequestId":"0199A0E2-3333-7C5E-9F2A-3B1C6D7E8F90","workKind":"plan","ruleId":"plan-on-entry","expiresAt":"2026-09-29T10:15:00+00:00"}`

func TestParseCommand(t *testing.T) {
	c, err := ParseCommand([]byte(commandPayload))
	if err != nil {
		t.Fatal(err)
	}
	want := api.Command{
		Type:          CommandType,
		ProjectID:     "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7",
		Subject:       api.CommandSubject{Type: "bridge-command", ID: "0199a0e2-0000-7c5e-9f2a-3b1c6d7e8f90"},
		CommandID:     "0199a0e2-0000-7c5e-9f2a-3b1c6d7e8f90",
		Kind:          api.CommandStopRun,
		BridgeID:      "7d1e2f3a-4b5c-4d6e-9f0a-1b2c3d4e5f6a",
		RunKey:        "0199a0e2-1111-7c5e-9f2a-3b1c6d7e8f90",
		SessionID:     "0199a0e2-2222-7c5e-9f2a-3b1c6d7e8f90",
		SubjectType:   api.SubjectCard,
		SubjectID:     "0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7",
		CardNumber:    42,
		WorkRequestID: "0199a0e2-3333-7c5e-9f2a-3b1c6d7e8f90",
		WorkKind:      "plan",
		RuleID:        "plan-on-entry",
		ExpiresAt:     c.ExpiresAt,
		Cause:         api.CausePerson,
	}
	if c != want || !c.ExpiresAt.Equal(time.Date(2026, 9, 29, 10, 15, 0, 0, time.UTC)) {
		t.Fatalf("command = %+v", c)
	}
}

// A run of a rules: entry names no work request, and a run with no session
// has no run key or session yet.
func TestParseCommandTakesTheNullFields(t *testing.T) {
	payload := strings.NewReplacer(
		`"runKey":"0199A0E2-1111-7C5E-9F2A-3B1C6D7E8F90"`, `"runKey":null`,
		`"sessionId":"0199A0E2-2222-7C5E-9F2A-3B1C6D7E8F90"`, `"sessionId":null`,
		`"workRequestId":"0199A0E2-3333-7C5E-9F2A-3B1C6D7E8F90"`, `"workRequestId":null`,
		`"workKind":"plan"`, `"workKind":null`,
		`"ruleId":"plan-on-entry"`, `"ruleId":null`,
		`"kind":"stop-run"`, `"kind":"resume-run"`,
	).Replace(commandPayload)
	c, err := ParseCommand([]byte(payload))
	if err != nil {
		t.Fatal(err)
	}
	if c.RunKey != "" || c.SessionID != "" || c.WorkRequestID != "" || c.WorkKind != "" || c.RuleID != "" || c.Kind != api.CommandResumeRun {
		t.Fatalf("command = %+v", c)
	}
}

// A rerun names the run it reruns and no session, because a command run has
// none.
func TestParseCommandTakesARerun(t *testing.T) {
	payload := strings.NewReplacer(
		`"kind":"stop-run"`, `"kind":"rerun-command"`,
		`"sessionId":"0199A0E2-2222-7C5E-9F2A-3B1C6D7E8F90"`, `"sessionId":null`,
	).Replace(commandPayload)
	c, err := ParseCommand([]byte(payload))
	if err != nil {
		t.Fatal(err)
	}
	if c.Kind != api.CommandRerunCommand || c.RunKey != "0199a0e2-1111-7c5e-9f2a-3b1c6d7e8f90" || c.SessionID != "" {
		t.Fatalf("command = %+v", c)
	}
}

// The close of an ask names its cause, so the bridge words the resume for it.
// A server older than the cause sends none, and only a person asked then.
func TestParseCommandReadsTheCause(t *testing.T) {
	for payload, want := range map[string]string{
		commandPayload: api.CausePerson,
		strings.Replace(commandPayload, `"kind":"stop-run"`, `"kind":"resume-run","cause":"ask-closed"`, 1): api.CauseAskClosed,
		strings.Replace(commandPayload, `"kind":"stop-run"`, `"kind":"resume-run","cause":"person"`, 1):     api.CausePerson,
	} {
		c, err := ParseCommand([]byte(payload))
		if err != nil {
			t.Fatal(err)
		}
		if c.Cause != want {
			t.Fatalf("cause = %q, want %q", c.Cause, want)
		}
	}
}

// The run of a subject that is no card carries no card number.
func TestParseCommandTakesASubjectThatIsNoCard(t *testing.T) {
	payload := strings.Replace(commandPayload, `"subjectType":"card","subjectId":"0192F3A1-9999-7D3E-8F10-A2B3C4D5E6F7","cardNumber":42,`,
		`"subjectType":"analysis","subjectId":"0192F3A1-8888-7D3E-8F10-A2B3C4D5E6F7","cardNumber":null,`, 1)
	c, err := ParseCommand([]byte(payload))
	if err != nil || c.SubjectType != "analysis" || c.SubjectID != "0192f3a1-8888-7d3e-8f10-a2b3c4d5e6f7" || c.CardNumber != 0 {
		t.Fatalf("command = %+v, err = %v", c, err)
	}
}

func TestParseCommandRejectsEachMalformedField(t *testing.T) {
	for name, pair := range map[string][2]string{
		"another type":        {`"type":"bridge.command"`, `"type":"board.card_moved"`},
		"projectId":           {`"projectId":"0192F3A1-4B2C-7D3E-8F10-A2B3C4D5E6F7"`, `"projectId":"p"`},
		"commandId":           {`"commandId":"0199A0E2-0000-7C5E-9F2A-3B1C6D7E8F90"`, `"commandId":"c"`},
		"subject of another":  {`"id":"0199A0E2-0000-7C5E-9F2A-3B1C6D7E8F90"`, `"id":"0199a0e2-0000-7c5e-9f2a-000000000000"`},
		"subject type":        {`"type":"bridge-command"`, `"type":"card"`},
		"kind":                {`"kind":"stop-run"`, `"kind":"pause"`},
		"bridgeId":            {`"bridgeId":"7D1E2F3A-4B5C-4D6E-9F0A-1B2C3D4E5F6A"`, `"bridgeId":null`},
		"runKey":              {`"runKey":"0199A0E2-1111-7C5E-9F2A-3B1C6D7E8F90"`, `"runKey":"r"`},
		"sessionId":           {`"sessionId":"0199A0E2-2222-7C5E-9F2A-3B1C6D7E8F90"`, `"sessionId":"s"`},
		"no subjectType":      {`"subjectType":"card",`, ``},
		"subjectType":         {`"subjectType":"card"`, `"subjectType":"a card"`},
		"subjectId":           {`"subjectId":"0192F3A1-9999-7D3E-8F10-A2B3C4D5E6F7"`, `"subjectId":null`},
		"cardNumber":          {`"cardNumber":42`, `"cardNumber":0`},
		"cardNumber off card": {`"subjectType":"card"`, `"subjectType":"analysis"`},
		"workRequestId":       {`"workRequestId":"0199A0E2-3333-7C5E-9F2A-3B1C6D7E8F90"`, `"workRequestId":"w"`},
		"workKind":            {`"workKind":"plan"`, `"workKind":"Not A Kind"`},
		"cause":               {`"kind":"stop-run"`, `"kind":"stop-run","cause":"a whim"`},
		"no expiry":           {`,"expiresAt":"2026-09-29T10:15:00+00:00"`, ``},
		"expiry not a date":   {`"expiresAt":"2026-09-29T10:15:00+00:00"`, `"expiresAt":"soon"`},
	} {
		t.Run(name, func(t *testing.T) {
			payload := strings.Replace(commandPayload, pair[0], pair[1], 1)
			if payload == commandPayload {
				t.Fatalf("%s: the replacement changed nothing", name)
			}
			if _, err := ParseCommand([]byte(payload)); err == nil {
				t.Fatalf("expected %s to be rejected", payload)
			}
		})
	}
}

// sessionUsagePayload asks for the usage of one interactive run.
var sessionUsagePayload = strings.Replace(commandPayload, `"kind":"stop-run"`,
	`"kind":"collect-session-usage","runId":"0199A0E2-4444-7C5E-9F2A-3B1C6D7E8F90",`+
		`"startedAt":"2026-09-29T10:00:00+00:00","endedAt":"2026-09-29T10:05:00+00:00"`, 1)

func TestParseCommandTakesASessionUsageRequest(t *testing.T) {
	c, err := ParseCommand([]byte(sessionUsagePayload))
	if err != nil {
		t.Fatal(err)
	}
	if c.Kind != api.CommandCollectSessionUsage || c.RunID != "0199a0e2-4444-7c5e-9f2a-3b1c6d7e8f90" ||
		c.SessionID != "0199a0e2-2222-7c5e-9f2a-3b1c6d7e8f90" {
		t.Fatalf("command = %+v", c)
	}
	if c.StartedAt == nil || !c.StartedAt.Equal(time.Date(2026, 9, 29, 10, 0, 0, 0, time.UTC)) ||
		c.EndedAt == nil || !c.EndedAt.Equal(time.Date(2026, 9, 29, 10, 5, 0, 0, time.UTC)) {
		t.Fatalf("window = %v to %v", c.StartedAt, c.EndedAt)
	}
}

// A request for the usage of a run needs the session, the run and a window
// that does not end before it starts. Another kind needs none of them.
func TestParseCommandRejectsASessionUsageRequestWithNoWindow(t *testing.T) {
	for name, pair := range map[string][2]string{
		"no session":      {`"sessionId":"0199A0E2-2222-7C5E-9F2A-3B1C6D7E8F90"`, `"sessionId":null`},
		"no run":          {`"runId":"0199A0E2-4444-7C5E-9F2A-3B1C6D7E8F90"`, `"runId":null`},
		"a run not uuid":  {`"runId":"0199A0E2-4444-7C5E-9F2A-3B1C6D7E8F90"`, `"runId":"r"`},
		"no start":        {`"startedAt":"2026-09-29T10:00:00+00:00"`, `"startedAt":null`},
		"no end":          {`"endedAt":"2026-09-29T10:05:00+00:00"`, `"endedAt":null`},
		"an end too soon": {`"endedAt":"2026-09-29T10:05:00+00:00"`, `"endedAt":"2026-09-29T09:59:59+00:00"`},
	} {
		t.Run(name, func(t *testing.T) {
			payload := strings.Replace(sessionUsagePayload, pair[0], pair[1], 1)
			if payload == sessionUsagePayload {
				t.Fatalf("%s: the replacement changed nothing", name)
			}
			if _, err := ParseCommand([]byte(payload)); err == nil {
				t.Fatalf("expected %s to be rejected", payload)
			}
		})
	}

	stop := strings.Replace(commandPayload, `"kind":"stop-run"`, `"kind":"stop-run","runId":null,"startedAt":null,"endedAt":null`, 1)
	if _, err := ParseCommand([]byte(stop)); err != nil {
		t.Fatalf("a stop with no window: %v", err)
	}
}

// A command has its own parser, so Parse drops it as an unknown type.
// A command carries the context of the work request of its run. An older
// server sends none, and a malformed value rejects the command.
func TestParseCommandReadsTheContext(t *testing.T) {
	payload := strings.Replace(commandPayload, `"workKind":"plan"`, `"workKind":"plan","context":{"pullRequestNumber":42,`+
		`"pullRequestUrl":null,"headSha":"abc1234","reason":"conflict","documentId":"01A10BEB-BA65-736B-8626-A6E3FA59DFC5"}`, 1)
	c, err := ParseCommand([]byte(payload))
	want := api.WorkRequestContext{PullRequestNumber: 42, HeadSHA: "abc1234", Reason: "conflict", DocumentID: "01a10beb-ba65-736b-8626-a6e3fa59dfc5"}
	if err != nil || c.Context != want {
		t.Fatalf("command = %+v, err = %v", c, err)
	}

	if c, err := ParseCommand([]byte(commandPayload)); err != nil || c.Context != (api.WorkRequestContext{}) {
		t.Fatalf("command = %+v, err = %v", c, err)
	}

	bad := strings.Replace(commandPayload, `"workKind":"plan"`, `"workKind":"plan","context":{"headSha":"not a sha"}`, 1)
	if _, err := ParseCommand([]byte(bad)); err == nil {
		t.Fatal("expected a malformed context to be rejected")
	}
}

func TestParseDropsACommandAsAnUnknownType(t *testing.T) {
	if err := parseErr(t, commandPayload); !errors.Is(err, ErrUnknownType) {
		t.Fatalf("err = %v, want an unknown type", err)
	}
}

func TestForAnotherBridgeReadsACommand(t *testing.T) {
	const own = "7d1e2f3a-4b5c-4d6e-9f0a-1b2c3d4e5f6a"
	const bridge = `"7D1E2F3A-4B5C-4D6E-9F0A-1B2C3D4E5F6A"`
	for name, tc := range map[string]struct {
		payload string
		want    bool
	}{
		"this bridge, another case": {commandPayload, false},
		"another bridge":            {strings.Replace(commandPayload, "7D1E2F3A", "00000000", 1), true},
		"a null bridge":             {strings.Replace(commandPayload, bridge, `null`, 1), true},
		"an empty bridge":           {strings.Replace(commandPayload, bridge, `""`, 1), true},
		"no bridge key":             {strings.Replace(commandPayload, `"bridgeId":`+bridge+`,`, ``, 1), true},
		"a bridge that is a number": {strings.Replace(commandPayload, bridge, `7`, 1), true},
	} {
		t.Run(name, func(t *testing.T) {
			if got := ForAnotherBridge([]byte(tc.payload), own); got != tc.want {
				t.Fatalf("ForAnotherBridge = %v, want %v", got, tc.want)
			}
		})
	}
}
