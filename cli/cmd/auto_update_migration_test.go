package cmd

import (
	"os"
	"path/filepath"
	"slices"
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

func newMigration(t *testing.T, body string, st update.State) migration {
	t.Helper()
	m := migration{dir: t.TempDir(), log: &syncBuffer{}}
	m.rules = filepath.Join(m.dir, "rules.yaml")
	m.write(t, body)
	if err := st.Save(m.dir); err != nil {
		t.Fatal(err)
	}

	return m
}

func (m migration) write(t *testing.T, body string) {
	t.Helper()
	if err := os.WriteFile(m.rules, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
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

func (m migration) state(t *testing.T) *update.State {
	t.Helper()
	st, err := update.LoadState(m.dir)
	if err != nil {
		t.Fatal(err)
	}

	return st
}

// marked reports whether update.json holds the marker of this rule file and
// no pending entry.
func (m migration) marked(t *testing.T) bool {
	st := m.state(t)

	return slices.Equal(st.DefaultOff, []string{ruleFileKey(m.rules)}) && len(st.AutoUpdatePending) == 0
}

func (m migration) pending(t *testing.T) bool {
	st := m.state(t)

	return slices.Equal(st.AutoUpdatePending, []string{ruleFileKey(m.rules)}) && len(st.DefaultOff) == 0
}

const migrationRules = "# my rules\nmaxWorkers: 2\n"

// An image before this one updated a bridge whose file had no key, so the
// first handover to this image writes the key that keeps it so.
func TestAHandoverWritesTheKeyOnce(t *testing.T) {
	m := newMigration(t, migrationRules, update.State{})

	if !m.run(true) {
		t.Fatal("the process must take a missing key as on")
	}
	if got := m.body(t); got != migrationRules+"autoUpdate: true\n" {
		t.Fatalf("rules = %q", got)
	}
	if !m.marked(t) || !strings.Contains(m.log.String(), `"event":"auto_update_migrated","rules":"`+m.rules+`"`) {
		t.Fatalf("state = %+v, log = %s", m.state(t), m.log.String())
	}
}

func TestAHandoverAfterTheMarkerWritesNothing(t *testing.T) {
	m := newMigration(t, migrationRules, update.State{})
	m.run(false)

	if m.run(true) || m.body(t) != migrationRules || m.log.String() != "" {
		t.Fatalf("rules = %q, log = %s", m.body(t), m.log.String())
	}
}

// Each bridge has its own rule file, and update.json serves them all.
func TestTheMarkerOfAnotherRuleFileDoesNotCount(t *testing.T) {
	m := newMigration(t, migrationRules, update.State{DefaultOff: []string{"/elsewhere/rules.yaml"}})

	if !m.run(true) || m.body(t) != migrationRules+"autoUpdate: true\n" {
		t.Fatalf("rules = %q", m.body(t))
	}
	if st := m.state(t); !slices.Equal(st.DefaultOff, []string{"/elsewhere/rules.yaml", ruleFileKey(m.rules)}) {
		t.Fatalf("defaultOff = %v", st.DefaultOff)
	}
}

// The marker names the file that a symlink points to, so two links to one
// file share it.
func TestTheMarkerNamesTheResolvedRuleFile(t *testing.T) {
	m := newMigration(t, migrationRules, update.State{})
	link := filepath.Join(t.TempDir(), "rules.yaml")
	if err := os.Symlink(m.rules, link); err != nil {
		t.Fatal(err)
	}
	m.rules = link

	m.run(false)

	real, _ := filepath.EvalSymlinks(link)
	if st := m.state(t); !slices.Equal(st.DefaultOff, []string{real}) {
		t.Fatalf("defaultOff = %v, want %s", st.DefaultOff, real)
	}
}

func TestAFreshStartOnlyWritesTheMarker(t *testing.T) {
	m := newMigration(t, migrationRules, update.State{})

	if m.run(false) || m.body(t) != migrationRules || !m.marked(t) {
		t.Fatalf("rules = %q, state = %+v", m.body(t), m.state(t))
	}
}

func TestAHandoverKeepsAnExplicitKey(t *testing.T) {
	body := migrationRules + "autoUpdate: false\n"
	m := newMigration(t, body, update.State{})

	if m.run(true) || m.body(t) != body || !m.marked(t) || strings.Contains(m.log.String(), "auto_update_migrated") {
		t.Fatalf("rules = %q, state = %+v, log = %s", m.body(t), m.state(t), m.log.String())
	}
}

// A refused append keeps updates on, and leaves a pending entry that a plain
// restart still honours.
func TestARefusedAppendStaysPendingAcrossARestart(t *testing.T) {
	body := "{maxWorkers: 2}\n"
	m := newMigration(t, body, update.State{})

	if !m.run(true) || m.body(t) != body || !m.pending(t) {
		t.Fatalf("rules = %q, state = %+v", m.body(t), m.state(t))
	}
	if log := m.log.String(); !strings.Contains(log, `"event":"auto_update_migration_failed"`) || !strings.Contains(log, `"line":"autoUpdate: true"`) {
		t.Fatalf("log = %s", log)
	}

	if !m.run(false) || m.body(t) != body || !m.pending(t) {
		t.Fatalf("restart: rules = %q, state = %+v", m.body(t), m.state(t))
	}
}

// Once the file takes the line, any start writes it and clears the entry.
func TestAPendingMigrationCompletesOnAPlainRestart(t *testing.T) {
	m := newMigration(t, migrationRules, update.State{AutoUpdatePending: []string{"/elsewhere/rules.yaml"}})
	st := m.state(t)
	st.AutoUpdatePending = append(st.AutoUpdatePending, ruleFileKey(m.rules))
	if err := st.Save(m.dir); err != nil {
		t.Fatal(err)
	}

	if !m.run(false) || m.body(t) != migrationRules+"autoUpdate: true\n" {
		t.Fatalf("rules = %q", m.body(t))
	}
	st = m.state(t)
	if !slices.Equal(st.AutoUpdatePending, []string{"/elsewhere/rules.yaml"}) || !slices.Equal(st.DefaultOff, []string{ruleFileKey(m.rules)}) {
		t.Fatalf("state = %+v", st)
	}
}

// A person who writes the key by hand ends the pending migration.
func TestAPendingMigrationEndsWhenTheFileHoldsTheKey(t *testing.T) {
	m := newMigration(t, "{maxWorkers: 2}\n", update.State{})
	m.run(true)
	m.write(t, "{maxWorkers: 2, autoUpdate: false}\n")

	if m.run(false) || !m.marked(t) {
		t.Fatalf("state = %+v", m.state(t))
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
