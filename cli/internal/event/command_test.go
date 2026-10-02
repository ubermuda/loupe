package event

import (
	"errors"
	"fmt"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
)

const commandPayload = `{"type":"bridge.command","projectId":"0192F3A1-4B2C-7D3E-8F10-A2B3C4D5E6F7",` +
	`"subject":{"type":"bridge-command","id":"0199A0E2-0000-7C5E-9F2A-3B1C6D7E8F90"},"commandId":"0199A0E2-0000-7C5E-9F2A-3B1C6D7E8F90",` +
	`"kind":"stop-run","bridgeId":"7D1E2F3A-4B5C-4D6E-9F0A-1B2C3D4E5F6A","runKey":"0199A0E2-1111-7C5E-9F2A-3B1C6D7E8F90",` +
	`"sessionId":"0199A0E2-2222-7C5E-9F2A-3B1C6D7E8F90","cardId":"0192F3A1-9999-7D3E-8F10-A2B3C4D5E6F7","cardNumber":42,` +
	`"ruleName":"plan","cardColumn":"implementation","resumeIndex":1,"expiresAt":"2026-09-29T10:15:00+00:00"}`

func TestParseCommand(t *testing.T) {
	c, err := ParseCommand([]byte(commandPayload))
	if err != nil {
		t.Fatal(err)
	}
	want := api.Command{
		Type:        CommandType,
		ProjectID:   "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7",
		Subject:     api.CommandSubject{Type: "bridge-command", ID: "0199a0e2-0000-7c5e-9f2a-3b1c6d7e8f90"},
		CommandID:   "0199a0e2-0000-7c5e-9f2a-3b1c6d7e8f90",
		Kind:        api.CommandStopRun,
		BridgeID:    "7d1e2f3a-4b5c-4d6e-9f0a-1b2c3d4e5f6a",
		RunKey:      "0199a0e2-1111-7c5e-9f2a-3b1c6d7e8f90",
		SessionID:   "0199a0e2-2222-7c5e-9f2a-3b1c6d7e8f90",
		CardID:      "0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7",
		CardNumber:  42,
		RuleName:    "plan",
		CardColumn:  "implementation",
		ResumeIndex: c.ResumeIndex,
		ExpiresAt:   c.ExpiresAt,
	}
	if c != want || c.ResumeIndex == nil || *c.ResumeIndex != 1 || !c.ExpiresAt.Equal(time.Date(2026, 9, 29, 10, 15, 0, 0, time.UTC)) {
		t.Fatalf("command = %+v", c)
	}
}

// A first run has no resume index, and a run with no session has no run key
// or session yet.
func TestParseCommandTakesTheNullFields(t *testing.T) {
	payload := strings.NewReplacer(
		`"runKey":"0199A0E2-1111-7C5E-9F2A-3B1C6D7E8F90"`, `"runKey":null`,
		`"sessionId":"0199A0E2-2222-7C5E-9F2A-3B1C6D7E8F90"`, `"sessionId":null`,
		`"cardColumn":"implementation"`, `"cardColumn":null`,
		`"resumeIndex":1`, `"resumeIndex":null`,
		`"kind":"stop-run"`, `"kind":"resume-run"`,
	).Replace(commandPayload)
	c, err := ParseCommand([]byte(payload))
	if err != nil {
		t.Fatal(err)
	}
	if c.RunKey != "" || c.SessionID != "" || c.CardColumn != "" || c.ResumeIndex != nil || c.Kind != api.CommandResumeRun {
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

func TestParseCommandRejectsEachMalformedField(t *testing.T) {
	for name, pair := range map[string][2]string{
		"another type":       {`"type":"bridge.command"`, `"type":"board.card_moved"`},
		"projectId":          {`"projectId":"0192F3A1-4B2C-7D3E-8F10-A2B3C4D5E6F7"`, `"projectId":"p"`},
		"commandId":          {`"commandId":"0199A0E2-0000-7C5E-9F2A-3B1C6D7E8F90"`, `"commandId":"c"`},
		"subject of another": {`"id":"0199A0E2-0000-7C5E-9F2A-3B1C6D7E8F90"`, `"id":"0199a0e2-0000-7c5e-9f2a-000000000000"`},
		"subject type":       {`"type":"bridge-command"`, `"type":"card"`},
		"kind":               {`"kind":"stop-run"`, `"kind":"pause"`},
		"bridgeId":           {`"bridgeId":"7D1E2F3A-4B5C-4D6E-9F0A-1B2C3D4E5F6A"`, `"bridgeId":null`},
		"runKey":             {`"runKey":"0199A0E2-1111-7C5E-9F2A-3B1C6D7E8F90"`, `"runKey":"r"`},
		"sessionId":          {`"sessionId":"0199A0E2-2222-7C5E-9F2A-3B1C6D7E8F90"`, `"sessionId":"s"`},
		"cardId":             {`"cardId":"0192F3A1-9999-7D3E-8F10-A2B3C4D5E6F7"`, `"cardId":null`},
		"cardNumber":         {`"cardNumber":42`, `"cardNumber":0`},
		"ruleName":           {`"ruleName":"plan"`, `"ruleName":""`},
		"cardColumn":         {`"cardColumn":"implementation"`, `"cardColumn":"Not A Slug"`},
		"negative index":     {`"resumeIndex":1`, `"resumeIndex":-1`},
		"index too large":    {`"resumeIndex":1`, `"resumeIndex":32768`},
		"index of a string":  {`"resumeIndex":1`, `"resumeIndex":"1"`},
		"no expiry":          {`,"expiresAt":"2026-09-29T10:15:00+00:00"`, ``},
		"expiry not a date":  {`"expiresAt":"2026-09-29T10:15:00+00:00"`, `"expiresAt":"soon"`},
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

// A command is never an event a rule acts on, even when a rule names its type.
func TestParseDropsACommandAsAnUnknownType(t *testing.T) {
	for _, extra := range []map[string]bool{nil, {CommandType: true}} {
		if err := parseErr(t, commandPayload, extra); !errors.Is(err, ErrUnknownType) {
			t.Fatalf("extra %v: err = %v, want an unknown type", extra, err)
		}
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

// An older server sends no held key, and that reads as nil, apart from false.
func TestParseCardMovedReadsTheHold(t *testing.T) {
	for name, tc := range map[string]struct {
		card string
		want string
	}{
		"true":   {`{"interactiveRun":false,"held":true}`, "true"},
		"false":  {`{"interactiveRun":false,"held":false}`, "false"},
		"absent": {`{"interactiveRun":false}`, "nil"},
	} {
		t.Run(name, func(t *testing.T) {
			e := parseOK(t, moved(map[string]string{"card": tc.card}), nil)
			got := "nil"
			if e.Card.Held != nil {
				got = fmt.Sprint(*e.Card.Held)
			}
			if got != tc.want {
				t.Fatalf("held = %s, want %s", got, tc.want)
			}
		})
	}
}
