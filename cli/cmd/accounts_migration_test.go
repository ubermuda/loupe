package cmd

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/rules"
)

func writeRuleFile(t *testing.T, body string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "rules.yaml")
	if err := os.WriteFile(path, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}

	return path
}

func TestTheAccountsMigrationLogsAWrite(t *testing.T) {
	path := writeRuleFile(t, "defaults:\n  model: opus\nwork:\n  plan:\n    prompt: go\n")
	log := &syncBuffer{}

	migrateAccounts(path).log(newBridgeLogger(log), path)

	if !strings.Contains(log.String(), `"event":"accounts_migration_done"`) {
		t.Fatalf("log = %s", log)
	}
	if data, _ := os.ReadFile(path); !strings.Contains(string(data), "accounts:") {
		t.Fatalf("file = %s", data)
	}
}

func TestTheAccountsMigrationLogsTheBlockOfAFailure(t *testing.T) {
	path := writeRuleFile(t, "defaults: {model: opus}\nwork:\n  plan:\n    prompt: go\n")
	log := &syncBuffer{}

	migrateAccounts(path).log(newBridgeLogger(log), path)

	out := log.String()
	if !strings.Contains(out, `"event":"accounts_migration_failed"`) || !strings.Contains(out, `"error":"`) ||
		!strings.Contains(out, `"block":"accounts:\n  claude:\n    harness: claude-code\n    model: opus\n`) {
		t.Fatalf("log = %s", out)
	}
}

func TestTheAccountsMigrationLogsNothingForAFileWithAccounts(t *testing.T) {
	path := writeRuleFile(t, defaultRules)
	log := &syncBuffer{}

	migrateAccounts(path).log(newBridgeLogger(log), path)

	if log.String() != "" {
		t.Fatalf("log = %s", log)
	}
}

func TestAFileWithNoAccountsLogsThatItsAgentsAreOff(t *testing.T) {
	set, err := rules.Parse([]byte("projects:\n  loupe:\n    dir: "+t.TempDir()+"\nwork:\n  plan: {prompt: go}\n"), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	log := &syncBuffer{}

	warnAgentsOff(newBridgeLogger(log), set)

	if out := log.String(); !strings.Contains(out, `"event":"agents_off"`) || !strings.Contains(out, set.AgentsOff()) {
		t.Fatalf("log = %s", out)
	}
}

func TestAReloadWarnsThatTheAgentsAreOff(t *testing.T) {
	h := newHarness(t)

	if res := h.reload(t, strings.Replace(defaultRules, "accounts:\n  claude:\n    harness: claude-code\ndefaults:\n  account: claude\n", "", 1)); !res.OK {
		t.Fatalf("result = %+v", res)
	}
	h.only(t, "agents_off")
}

// A start that stops before its log would open still logs the rewrite, which
// the next start does not repeat.
func TestAStartThatStopsEarlyLogsTheAccountsMigration(t *testing.T) {
	t.Setenv("XDG_CONFIG_HOME", t.TempDir())
	t.Setenv("HOME", t.TempDir())
	path := writeRuleFile(t, "defaults:\n  model: opus\nwork:\n  plan:\n    prompt: Plan {nope}.\n")
	logPath := filepath.Join(t.TempDir(), "bridge.log")

	if err := runBridge(t, "--rules", path, "--log-file", logPath); err == nil {
		t.Fatal("the bridge started")
	}
	if data, _ := os.ReadFile(logPath); !strings.Contains(string(data), `"event":"accounts_migration_done"`) {
		t.Fatalf("log = %s", data)
	}
}
