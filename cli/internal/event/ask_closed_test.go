package event

import (
	"errors"
	"strings"
	"testing"
)

// askClosedPayload copies the keys the server's ask closer writes.
const askClosedPayload = `{"type":"inbox.ask_closed","projectId":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7","subject":{"type":"inbox-ask","id":"01a0a1b2-0000-7c3d-8e4f-5a6b7c8d9e0f"},"sessionId":"5F0C7E2A-1B3D-4C5E-8F9A-0B1C2D3E4F5A","bridgeId":"7D1E2F3A-4B5C-4D6E-9F0A-1B2C3D4E5F6A","cardId":"0192F3A1-7777-7D3E-8F10-A2B3C4D5E6F7","cardNumber":33,"actor":"human"}`

var askRule = map[string]bool{AskClosedType: true}

func TestParseAskClosed(t *testing.T) {
	e := parseOK(t, askClosedPayload, askRule)
	if e.Type != AskClosedType || e.Subject.ID != "01a0a1b2-0000-7c3d-8e4f-5a6b7c8d9e0f" || e.Actor != ActorHuman {
		t.Fatalf("event = %+v", e)
	}
	if e.SessionID != "5f0c7e2a-1b3d-4c5e-8f9a-0b1c2d3e4f5a" || e.BridgeID != "7d1e2f3a-4b5c-4d6e-9f0a-1b2c3d4e5f6a" {
		t.Fatalf("ids are not folded to lower case: %+v", e)
	}
	if e.CardID != "0192f3a1-7777-7d3e-8f10-a2b3c4d5e6f7" || e.CardNumber != 33 {
		t.Fatalf("card = %q %d", e.CardID, e.CardNumber)
	}
}

// The server sends a null card in four cases, and a null bridge id is the
// router's to drop, not a malformed event.
func TestParseAskClosedTakesANullCardAndANullBridge(t *testing.T) {
	payload := strings.NewReplacer(
		`"cardId":"0192F3A1-7777-7D3E-8F10-A2B3C4D5E6F7"`, `"cardId":null`,
		`"cardNumber":33`, `"cardNumber":null`,
		`"bridgeId":"7D1E2F3A-4B5C-4D6E-9F0A-1B2C3D4E5F6A"`, `"bridgeId":null`,
	).Replace(askClosedPayload)

	e := parseOK(t, payload, askRule)
	if e.CardID != "" || e.CardNumber != 0 || e.BridgeID != "" {
		t.Fatalf("event = %+v", e)
	}
}

// The session id reaches claude's argv, so its shape is checked like any id.
func TestParseRejectsAMalformedAskClosed(t *testing.T) {
	for name, payload := range map[string]string{
		"no session":             strings.Replace(askClosedPayload, `"sessionId":"5F0C7E2A-1B3D-4C5E-8F9A-0B1C2D3E4F5A"`, `"sessionId":null`, 1),
		"session not a uuid":     strings.Replace(askClosedPayload, `"sessionId":"5F0C7E2A-1B3D-4C5E-8F9A-0B1C2D3E4F5A"`, `"sessionId":"--resume x"`, 1),
		"card not a uuid":        strings.Replace(askClosedPayload, `"cardId":"0192F3A1-7777-7D3E-8F10-A2B3C4D5E6F7"`, `"cardId":"87"`, 1),
		"card with no number":    strings.Replace(askClosedPayload, `"cardNumber":33`, `"cardNumber":null`, 1),
		"number with no card":    strings.Replace(askClosedPayload, `"cardId":"0192F3A1-7777-7D3E-8F10-A2B3C4D5E6F7"`, `"cardId":null`, 1),
		"negative card number":   strings.Replace(askClosedPayload, `"cardNumber":33`, `"cardNumber":-1`, 1),
		"subject not a uuid":     strings.Replace(askClosedPayload, `"id":"01a0a1b2-0000-7c3d-8e4f-5a6b7c8d9e0f"`, `"id":"ask"`, 1),
		"unknown actor":          strings.Replace(askClosedPayload, `"actor":"human"`, `"actor":"system"`, 1),
		"bridge id not a string": strings.Replace(askClosedPayload, `"bridgeId":"7D1E2F3A-4B5C-4D6E-9F0A-1B2C3D4E5F6A"`, `"bridgeId":7`, 1),
	} {
		t.Run(name, func(t *testing.T) {
			if err := parseErr(t, payload, askRule); errors.Is(err, ErrUnknownType) {
				t.Fatalf("must be malformed, not unknown: %v", err)
			}
		})
	}
}

func TestForAnotherBridge(t *testing.T) {
	const own = "7d1e2f3a-4b5c-4d6e-9f0a-1b2c3d4e5f6a"
	for name, tc := range map[string]struct {
		payload string
		want    bool
	}{
		"this bridge, another case": {askClosedPayload, false},
		"another bridge":            {strings.Replace(askClosedPayload, "7D1E2F3A", "00000000", 1), true},
		"a null bridge":             {strings.Replace(askClosedPayload, `"7D1E2F3A-4B5C-4D6E-9F0A-1B2C3D4E5F6A"`, `null`, 1), true},
		"an empty bridge":           {strings.Replace(askClosedPayload, `"7D1E2F3A-4B5C-4D6E-9F0A-1B2C3D4E5F6A"`, `""`, 1), true},
		"no bridge key":             {strings.Replace(askClosedPayload, `"bridgeId":"7D1E2F3A-4B5C-4D6E-9F0A-1B2C3D4E5F6A",`, ``, 1), true},
		"a bridge that is a number": {strings.Replace(askClosedPayload, `"7D1E2F3A-4B5C-4D6E-9F0A-1B2C3D4E5F6A"`, `7`, 1), true},
		"another type":              {moved(nil), false},
		"not json":                  {`{`, false},
	} {
		t.Run(name, func(t *testing.T) {
			if got := ForAnotherBridge([]byte(tc.payload), own); got != tc.want {
				t.Fatalf("ForAnotherBridge = %v, want %v", got, tc.want)
			}
		})
	}
}

// A bridge with no resume rule drops the event unread, as any type no rule names.
func TestParseDropsAskClosedWhenNoRuleNamesIt(t *testing.T) {
	if err := parseErr(t, askClosedPayload, nil); !errors.Is(err, ErrUnknownType) {
		t.Fatalf("err = %v, want an unknown type", err)
	}
}
