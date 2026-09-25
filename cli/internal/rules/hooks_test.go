package rules

import (
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

const sha = "0123456789abcdef0123456789abcdef01234567"

const withHooks = oneRule + `hooks:
  - package: ubermuda/loupe
    path: hooks/amphetamine
    ref: v1.0.0
    sha: ` + sha + `
    settings:
      takeover: true
  - package: acme/notify
    ref: main
    sha: ` + sha + `
`

func TestParseReadsTheHooks(t *testing.T) {
	s := parse(t, withHooks)

	got := s.Hooks()
	if len(got) != 2 {
		t.Fatalf("hooks = %+v", got)
	}
	if got[0].ID() != "ubermuda/loupe/hooks/amphetamine" || got[0].Ref != "v1.0.0" || got[0].SHA != sha || got[0].Settings["takeover"] != "true" {
		t.Fatalf("first hook = %+v", got[0])
	}
	if got[1].ID() != "acme/notify" || got[1].Path != "" || got[1].Settings != nil {
		t.Fatalf("second hook = %+v", got[1])
	}
}

func TestHooksReturnsACopy(t *testing.T) {
	s := parse(t, withHooks)

	got := s.Hooks()
	got[0].Settings["takeover"] = "false"
	got[1].Ref = "changed"
	if again := s.Hooks(); again[0].Settings["takeover"] != "true" || again[1].Ref != "main" {
		t.Fatalf("hooks = %+v", again)
	}
}

func TestAFileWithNoHooksHasNone(t *testing.T) {
	if got := parse(t, oneRule).Hooks(); len(got) != 0 {
		t.Fatalf("hooks = %+v", got)
	}
}

func TestParseRefusesABadHook(t *testing.T) {
	for name, tc := range map[string]struct{ entry, want string }{
		"no package":       {"ref: v1\n    sha: " + sha, `package "" is not owner/repo`},
		"one segment":      {"package: acme\n    ref: v1\n    sha: " + sha, `package "acme" is not owner/repo`},
		"three segments":   {"package: acme/a/b\n    ref: v1\n    sha: " + sha, `package "acme/a/b" is not owner/repo`},
		"bad owner":        {"package: -acme/tool\n    ref: v1\n    sha: " + sha, `package "-acme/tool" is not owner/repo`},
		"dot repo":         {"package: acme/..\n    ref: v1\n    sha: " + sha, `package "acme/.." is not owner/repo`},
		"parent path":      {"package: acme/tool\n    path: a/../b\n    ref: v1\n    sha: " + sha, `path "a/../b" is not a clean relative path`},
		"absolute path":    {"package: acme/tool\n    path: /a\n    ref: v1\n    sha: " + sha, `path "/a" is not a clean relative path`},
		"trailing slash":   {"package: acme/tool\n    path: a/\n    ref: v1\n    sha: " + sha, `path "a/" is not a clean relative path`},
		"no ref":           {"package: acme/tool\n    sha: " + sha, "ref is required"},
		"ref with a space": {"package: acme/tool\n    ref: v 1\n    sha: " + sha, `ref "v 1" is not a git ref`},
		"short sha":        {"package: acme/tool\n    ref: v1\n    sha: abc123", `sha "abc123" is not 40 lowercase hex characters`},
		"upper sha":        {"package: acme/tool\n    ref: v1\n    sha: " + strings.ToUpper(sha), "is not 40 lowercase hex characters"},
		"unknown key":      {"package: acme/tool\n    ref: v1\n    sha: " + sha + "\n    url: x", "field url not found"},
	} {
		t.Run(name, func(t *testing.T) {
			text, _ := file(t, oneRule+"hooks:\n  - "+tc.entry+"\n")
			_, err := Parse([]byte(text), Defaults{})
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want %q", err, tc.want)
			}
		})
	}
}

func TestParseRefusesADuplicateHook(t *testing.T) {
	entry := "  - package: acme/tool\n    path: sub\n    ref: v1\n    sha: " + sha + "\n"
	other := strings.Replace(entry, "acme/tool", "Acme/Tool", 1)
	text, _ := file(t, oneRule+"hooks:\n"+entry+other)
	_, err := Parse([]byte(text), Defaults{})
	if err == nil || !strings.Contains(err.Error(), `hook "Acme/Tool/sub": another hook installs the same package`) {
		t.Fatalf("err = %v", err)
	}

	// The same repository with another path is another package.
	text, _ = file(t, oneRule+"hooks:\n"+entry+strings.Replace(entry, "path: sub", "path: other", 1))
	if _, err := Parse([]byte(text), Defaults{}); err != nil {
		t.Fatal(err)
	}
}

const commented = `# The rule file of this machine.
projects:
  # The main app.
  loupe:
    dir: {dir}
rules:
  # Plans a card.
  - on: board.card_moved
    project: loupe
    to: ready
    prompt: |
      Card {cardNumber} entered {to}. This line is long on purpose, so a rewrap of a long scalar shows up in the test.
`

func writeRules(t *testing.T, body string) string {
	t.Helper()
	text, _ := file(t, body)
	path := filepath.Join(t.TempDir(), FileName)
	if err := os.WriteFile(path, []byte(text), 0o640); err != nil {
		t.Fatal(err)
	}

	return path
}

func readFile(t *testing.T, path string) string {
	t.Helper()
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}

	return string(data)
}

func TestEditHooksKeepsTheComments(t *testing.T) {
	path := writeRules(t, commented)

	err := EditHooks(path, func(hooks []HookEntry) ([]HookEntry, error) {
		if len(hooks) != 0 {
			t.Fatalf("hooks = %+v", hooks)
		}

		return append(hooks, HookEntry{Package: "ubermuda/loupe", Path: "hooks/amphetamine", Ref: "v1", SHA: sha, Settings: map[string]string{"takeover": "false"}}), nil
	})
	if err != nil {
		t.Fatal(err)
	}

	got := readFile(t, path)
	for _, want := range []string{
		"# The rule file of this machine.\n",
		"  # The main app.\n  loupe:\n",
		"  # Plans a card.\n  - on: board.card_moved\n",
		"This line is long on purpose, so a rewrap of a long scalar shows up in the test.\n",
	} {
		if !strings.Contains(got, want) {
			t.Fatalf("file lost %q:\n%s", want, got)
		}
	}
	s, err := Parse([]byte(got), Defaults{})
	if err != nil {
		t.Fatalf("Parse: %v\n%s", err, got)
	}
	if hooks := s.Hooks(); len(hooks) != 1 || hooks[0].ID() != "ubermuda/loupe/hooks/amphetamine" || hooks[0].Settings["takeover"] != "false" {
		t.Fatalf("hooks = %+v", hooks)
	}
	if runtime.GOOS != "windows" {
		if info, err := os.Stat(path); err != nil || info.Mode().Perm() != 0o640 {
			t.Fatalf("mode = %v, %v", info.Mode(), err)
		}
	}
}

func TestEditHooksReplacesTheList(t *testing.T) {
	path := writeRules(t, withHooks)

	err := EditHooks(path, func(hooks []HookEntry) ([]HookEntry, error) {
		if len(hooks) != 2 || hooks[0].Settings["takeover"] != "true" {
			t.Fatalf("hooks = %+v", hooks)
		}
		hooks[1].Ref = "v2"

		return hooks, nil
	})
	if err != nil {
		t.Fatal(err)
	}

	got := readFile(t, path)
	if strings.Count(got, "hooks:") != 1 || strings.Contains(got, "path: \"\"") {
		t.Fatalf("file:\n%s", got)
	}
	hooks := parse(t, got).Hooks()
	if len(hooks) != 2 || hooks[1].Ref != "v2" || hooks[0].Path != "hooks/amphetamine" {
		t.Fatalf("hooks = %+v", hooks)
	}
}

func TestEditHooksDropsTheKeyWithTheLastHook(t *testing.T) {
	path := writeRules(t, withHooks)

	err := EditHooks(path, func([]HookEntry) ([]HookEntry, error) { return nil, nil })
	if err != nil {
		t.Fatal(err)
	}

	if got := readFile(t, path); strings.Contains(got, "hooks") {
		t.Fatalf("file:\n%s", got)
	}
}

func TestEditHooksLeavesTheFileOnAFailure(t *testing.T) {
	path := writeRules(t, withHooks)
	before := readFile(t, path)
	boom := errors.New("boom")

	for name, edit := range map[string]func([]HookEntry) ([]HookEntry, error){
		"invalid entry": func(hooks []HookEntry) ([]HookEntry, error) {
			return append(hooks, HookEntry{Package: "acme/tool", Ref: "v1", SHA: "bad"}), nil
		},
		"edit error": func([]HookEntry) ([]HookEntry, error) { return nil, boom },
	} {
		t.Run(name, func(t *testing.T) {
			if err := EditHooks(path, edit); err == nil {
				t.Fatal("EditHooks passed")
			}
			if got := readFile(t, path); got != before {
				t.Fatalf("file changed:\n%s", got)
			}
			entries, err := os.ReadDir(filepath.Dir(path))
			if err != nil || len(entries) != 1 {
				t.Fatalf("dir = %v, %v", entries, err)
			}
		})
	}
}

func TestEditHooksRefusesAMissingFile(t *testing.T) {
	err := EditHooks(filepath.Join(t.TempDir(), FileName), func(h []HookEntry) ([]HookEntry, error) { return h, nil })
	if !errors.Is(err, ErrMissing) {
		t.Fatalf("err = %v", err)
	}
}
