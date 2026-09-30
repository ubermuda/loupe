package cmd

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/rules"
	"github.com/ubermuda/loupe/cli/internal/update"
)

// migration is a config dir and a rule file for one start.
type migration struct {
	dir, rules string
	log        *syncBuffer
}

func newMigration(t *testing.T, body string, marked bool) migration {
	t.Helper()
	m := migration{dir: t.TempDir(), log: &syncBuffer{}}
	m.rules = filepath.Join(m.dir, "rules.yaml")
	if err := os.WriteFile(m.rules, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
	if marked {
		if err := (&update.State{DefaultOff: true}).Save(m.dir); err != nil {
			t.Fatal(err)
		}
	}

	return m
}

func (m migration) run(handover bool) bool {
	return migrateAutoUpdate(newBridgeLogger(m.log), m.dir, m.rules, handover)
}

func (m migration) body(t *testing.T) string {
	t.Helper()
	data, err := os.ReadFile(m.rules)
	if err != nil {
		t.Fatal(err)
	}

	return string(data)
}

func (m migration) marked(t *testing.T) bool {
	t.Helper()
	st, err := update.LoadState(m.dir)
	if err != nil {
		t.Fatal(err)
	}

	return st.DefaultOff
}

const migrationRules = "# my rules\nmaxWorkers: 2\n"

// An image before this one updated a bridge whose file had no key, so the
// first handover to this image writes the key that keeps it so.
func TestAHandoverWritesTheKeyOnce(t *testing.T) {
	m := newMigration(t, migrationRules, false)

	if !m.run(true) {
		t.Fatal("the process must take a missing key as on")
	}
	if got := m.body(t); got != migrationRules+"autoUpdate: true\n" {
		t.Fatalf("rules = %q", got)
	}
	if !m.marked(t) || !strings.Contains(m.log.String(), `"event":"auto_update_migrated","rules":"`+m.rules+`"`) {
		t.Fatalf("marked = %v, log = %s", m.marked(t), m.log.String())
	}
}

func TestAHandoverAfterTheMarkerWritesNothing(t *testing.T) {
	m := newMigration(t, migrationRules, true)

	if m.run(true) || m.body(t) != migrationRules || m.log.String() != "" {
		t.Fatalf("rules = %q, log = %s", m.body(t), m.log.String())
	}
}

func TestAFreshStartOnlyWritesTheMarker(t *testing.T) {
	m := newMigration(t, migrationRules, false)

	if m.run(false) || m.body(t) != migrationRules || !m.marked(t) {
		t.Fatalf("rules = %q, marked = %v", m.body(t), m.marked(t))
	}
}

func TestAHandoverKeepsAnExplicitKey(t *testing.T) {
	body := migrationRules + "autoUpdate: false\n"
	m := newMigration(t, body, false)

	if m.run(true) || m.body(t) != body || !m.marked(t) || strings.Contains(m.log.String(), "auto_update_migrated") {
		t.Fatalf("rules = %q, marked = %v, log = %s", m.body(t), m.marked(t), m.log.String())
	}
}

// A refused append keeps updates on for the process and leaves the marker
// unset, so the next handover tries again.
func TestARefusedAppendKeepsUpdatesOnAndWarns(t *testing.T) {
	body := "{maxWorkers: 2}\n"
	m := newMigration(t, body, false)

	if !m.run(true) || m.body(t) != body || m.marked(t) {
		t.Fatalf("rules = %q, marked = %v", m.body(t), m.marked(t))
	}
	if log := m.log.String(); !strings.Contains(log, `"event":"auto_update_migration_failed"`) || !strings.Contains(log, `"line":"autoUpdate: true"`) {
		t.Fatalf("log = %s", log)
	}
}

// The migration keeps a missing key on for the process. A key that a reload
// reads wins over it.
func TestAutoUpdateOnTakesAMissingKeyAsOnAfterTheMigration(t *testing.T) {
	parse := func(line string) *rules.Set {
		set, err := rules.Parse([]byte(line+"projects:\n  loupe:\n    dir: "+t.TempDir()+"\nrules:\n  - {on: board.card_moved, project: loupe, to: next, prompt: go}\n"), rules.Defaults{})
		if err != nil {
			t.Fatal(err)
		}

		return set
	}
	for _, tc := range []struct {
		line   string
		assume bool
		want   bool
	}{
		{"", false, false},
		{"", true, true},
		{"autoUpdate: false\n", true, false},
		{"autoUpdate: true\n", false, true},
	} {
		if got := autoUpdateOn(parse(tc.line), tc.assume); got != tc.want {
			t.Fatalf("%q, assume %v: got %v", tc.line, tc.assume, got)
		}
	}
}
