package cmd

import (
	"fmt"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/rules"
)

// commandRules names the command type in a rule, which the rule file allows.
const commandRules = defaultRules + `
  - name: command
    on: bridge.command
    project: loupe
    prompt: Act on the command.
`

func commandPayload(bridgeID string) string {
	const id = "0199a0e2-0000-7c5e-9f2a-3b1c6d7e8f90"

	return fmt.Sprintf(`{"type":"bridge.command","projectId":%q,"subject":{"type":"bridge-command","id":%q},"commandId":%q,`+
		`"kind":"stop-run","bridgeId":%q,"runKey":null,"sessionId":null,"cardId":%q,"cardNumber":87,"ruleName":"plan",`+
		`"cardColumn":"next","resumeIndex":null,"expiresAt":"2026-09-29T10:15:00+00:00"}`, testProject, id, id, bridgeID, cardUUID(87))
}

// A command is never an event a rule acts on. The router drops it in silence,
// for this bridge and for another, even when a rule names its type.
func TestACommandMatchesNoRule(t *testing.T) {
	for name, bridgeID := range map[string]string{"this bridge": testBridgeID, "another bridge": foreignBridge} {
		t.Run(name, func(t *testing.T) {
			h := newHarnessWith(t, commandRules, rules.Defaults{})

			h.send(commandPayload(bridgeID))

			if calls := h.worker.recorded(); len(calls) != 0 {
				t.Fatalf("workers = %+v", calls)
			}
			if log := strings.TrimSpace(h.log.String()); log != "" {
				t.Fatalf("log = %s, want nothing", log)
			}
		})
	}
}
