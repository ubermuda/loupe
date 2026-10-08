package rules

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// migrate writes body to a rule file, runs the migration on it and gives the
// file as it then reads.
func migrate(t *testing.T, body string) (changed bool, block string, after string, err error) {
	t.Helper()
	path := filepath.Join(t.TempDir(), "rules.yaml")
	if err := os.WriteFile(path, []byte(body), 0o640); err != nil {
		t.Fatal(err)
	}
	changed, block, err = MigrateAccounts(path)
	data, rerr := os.ReadFile(path)
	if rerr != nil {
		t.Fatal(rerr)
	}

	return changed, block, string(data), err
}

const oldRules = `# my rules

projects:
  loupe:
    dir: {dir}

# what the bridge runs by default
defaults:
  model: opus   # the big one
  permissionMode: dontAsk

work:
  implement:
    prompt: Implement {cardNumber}.
    permissionMode: plan  # look first
  fix:
    prompt: Fix {cardNumber}.
    permissionMode: acceptEdits
  ship:
    prompt: Ship {cardNumber}.
    permissionMode: bypassPermissions
  review:
    prompt: Review {cardNumber}.
    permissionMode: auto
  plain:
    prompt: Plain {cardNumber}.
    model: sonnet
  test:
    action: command
    run: [make, test]
`

const migratedRules = `# my rules

projects:
  loupe:
    dir: {dir}

# what the bridge runs by default
accounts:
  claude:
    harness: claude-code
    model: opus   # the big one
    permissionMode: dontAsk
defaults:
  account: claude

work:
  implement:
    prompt: Implement {cardNumber}.
    permissions: read-only  # look first
  fix:
    prompt: Fix {cardNumber}.
    permissions: workspace
  ship:
    prompt: Ship {cardNumber}.
    permissions: full
  review:
    prompt: Review {cardNumber}.
    permissions: workspace
  plain:
    prompt: Plain {cardNumber}.
    model: sonnet
  test:
    action: command
    run: [make, test]
`

func TestMigrateAccountsMovesTheOldKeysAndKeepsTheRest(t *testing.T) {
	changed, _, after, err := migrate(t, oldRules)
	if err != nil || !changed {
		t.Fatalf("changed = %v, err = %v", changed, err)
	}
	if after != migratedRules {
		t.Fatalf("file =\n%s\nwant\n%s", after, migratedRules)
	}
}

func TestMigrateAccountsMovesADefaultModelAlone(t *testing.T) {
	_, _, after, err := migrate(t, "defaults:\n  model: \"opus\"\nwork:\n  implement:\n    prompt: Go.\n")
	want := "accounts:\n  claude:\n    harness: claude-code\n    model: \"opus\"\ndefaults:\n  account: claude\nwork:\n  implement:\n    prompt: Go.\n"
	if err != nil || after != want {
		t.Fatalf("err = %v, file =\n%s", err, after)
	}
}

func TestMigrateAccountsAddsADefaultsBlockAtTheEnd(t *testing.T) {
	_, _, after, err := migrate(t, "work:\n  implement:\n    prompt: Go.\n    permissionMode: plan\n# the end")
	want := "work:\n  implement:\n    prompt: Go.\n    permissions: read-only\n# the end\naccounts:\n  claude:\n    harness: claude-code\ndefaults:\n  account: claude\n"
	if err != nil || after != want {
		t.Fatalf("err = %v, file =\n%s", err, after)
	}
}

// A defaults key with no value gets the account, and the accounts block goes
// above it.
func TestMigrateAccountsFillsAnEmptyDefaults(t *testing.T) {
	changed, _, after, err := migrate(t, "defaults:\n  # nothing yet\nwork:\n  implement:\n    prompt: Go.\n")
	want := "accounts:\n  claude:\n    harness: claude-code\ndefaults:\n  account: claude\n  # nothing yet\nwork:\n  implement:\n    prompt: Go.\n"
	if err != nil || !changed || after != want {
		t.Fatalf("changed = %v, err = %v, file =\n%s", changed, err, after)
	}
}

func TestMigrateAccountsKeepsTheOtherDefaults(t *testing.T) {
	_, _, after, err := migrate(t, "defaults:\n    permissions: workspace\n    permissionMode: plan\nwork:\n  implement:\n    prompt: Go.\n")
	want := "accounts:\n  claude:\n    harness: claude-code\n    permissionMode: plan\ndefaults:\n    account: claude\n    permissions: workspace\nwork:\n  implement:\n    prompt: Go.\n"
	if err != nil || after != want {
		t.Fatalf("err = %v, file =\n%s", err, after)
	}
}

func TestMigrateAccountsMapsEachEntryModeToALevel(t *testing.T) {
	for mode, level := range map[string]string{"plan": "read-only", "acceptEdits": "workspace", "auto": "workspace", "bypassPermissions": "full"} {
		_, _, after, err := migrate(t, "work:\n  implement:\n    prompt: Go.\n    permissionMode: "+mode+"\n")
		if err != nil || !strings.Contains(after, "    permissions: "+level+"\n") || strings.Contains(after, "permissionMode") {
			t.Fatalf("%s: err = %v, file =\n%s", mode, err, after)
		}
	}
}

func TestMigrateAccountsRefusesAnEntryModeWithNoLevel(t *testing.T) {
	for _, mode := range []string{"default", "dontAsk", "manual"} {
		body := "defaults:\n  model: opus\nwork:\n  implement:\n    prompt: Go.\n    permissionMode: " + mode + "\n"
		changed, block, after, err := migrate(t, body)
		if err == nil || changed || after != body {
			t.Fatalf("%s: changed = %v, err = %v, file =\n%s", mode, changed, err, after)
		}
		if !strings.Contains(err.Error(), `"implement"`) || !strings.Contains(err.Error(), mode) {
			t.Fatalf("%s: error = %v", mode, err)
		}
		if !strings.Contains(block, "accounts:\n  claude:\n    harness: claude-code\n    model: opus\n") {
			t.Fatalf("%s: block =\n%s", mode, block)
		}
	}
}

func TestMigrateAccountsLeavesAFileWithAccounts(t *testing.T) {
	body := claudeAccount + claudeDefaults + "work:\n  implement:\n    prompt: Go.\n"
	changed, block, after, err := migrate(t, body)
	if err != nil || changed || block != "" || after != body {
		t.Fatalf("changed = %v, block = %q, err = %v, file =\n%s", changed, block, err, after)
	}
}

func TestMigrateAccountsRefusesWhatALineEditCannotChange(t *testing.T) {
	for name, body := range map[string]string{
		"flow defaults":    "defaults: {model: opus}\nwork:\n  implement:\n    prompt: Go.\n",
		"flow entry":       "work:\n  implement: {prompt: Go., permissionMode: plan}\n",
		"anchored model":   "defaults:\n  model: &m opus\nwork:\n  implement:\n    prompt: Go.\n    model: *m\n",
		"multi-line model": "defaults:\n  model: >-\n    opus\nwork:\n  implement:\n    prompt: Go.\n",
		"defaults account": "defaults:\n  account: claude\n  model: opus\nwork:\n  implement:\n    prompt: Go.\n",
		"merged mode":      "base: &b\n  permissionMode: plan\nwork:\n  implement:\n    <<: *b\n    prompt: Go.\n",
	} {
		changed, _, after, err := migrate(t, body)
		if !errors.Is(err, ErrMigrationRefused) || changed || after != body {
			t.Fatalf("%s: changed = %v, err = %v, file =\n%s", name, changed, err, after)
		}
	}
}

func TestMigrateAccountsWritesThroughASymlinkAndKeepsTheMode(t *testing.T) {
	dir := t.TempDir()
	real := filepath.Join(dir, "real.yaml")
	if err := os.WriteFile(real, []byte("work:\n  implement:\n    prompt: Go.\n"), 0o640); err != nil {
		t.Fatal(err)
	}
	link := filepath.Join(dir, "rules.yaml")
	if err := os.Symlink(real, link); err != nil {
		t.Fatal(err)
	}
	if changed, _, err := MigrateAccounts(link); err != nil || !changed {
		t.Fatalf("changed = %v, err = %v", changed, err)
	}
	if target, err := os.Readlink(link); err != nil || target != real {
		t.Fatalf("link = %q, %v", target, err)
	}
	info, err := os.Stat(real)
	if err != nil || info.Mode().Perm() != 0o640 {
		t.Fatalf("mode = %v, %v", info.Mode().Perm(), err)
	}
}

func TestMigrateAccountsDoesNothingTwice(t *testing.T) {
	path := filepath.Join(t.TempDir(), "rules.yaml")
	if err := os.WriteFile(path, []byte(oldRules), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, _, err := MigrateAccounts(path); err != nil {
		t.Fatal(err)
	}
	if changed, _, err := MigrateAccounts(path); err != nil || changed {
		t.Fatalf("changed = %v, err = %v", changed, err)
	}
	if changed, _, err := MigrateAccounts(filepath.Join(t.TempDir(), "absent.yaml")); err != nil || changed {
		t.Fatalf("absent: changed = %v, err = %v", changed, err)
	}
}

func TestAMigratedFileRunsEachWorkerAsBefore(t *testing.T) {
	text, _ := file(t, oldRules)
	path := filepath.Join(t.TempDir(), "rules.yaml")
	if err := os.WriteFile(path, []byte(text), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, _, err := MigrateAccounts(path); err != nil {
		t.Fatal(err)
	}
	s, err := Load(path, Defaults{Model: "haiku", PermissionMode: "default"})
	if err != nil {
		t.Fatal(err)
	}
	if s.AgentsOff() != "" {
		t.Fatalf("agents off: %s", s.AgentsOff())
	}
	checkLoupe(t, s)
	// acceptEdits has no level of its own, and workspace runs as auto.
	for kind, want := range map[string][2]string{
		"implement": {"opus", "plan"},
		"fix":       {"opus", "auto"},
		"ship":      {"opus", "bypassPermissions"},
		"review":    {"opus", "auto"},
		"plain":     {"sonnet", "dontAsk"},
	} {
		m := s.MatchWork(workRequest(kind))
		if m.Skip != Run || m.Model != want[0] || m.PermissionMode != want[1] || m.Account != "claude" {
			t.Fatalf("%s: match = %+v", kind, m)
		}
	}
}
