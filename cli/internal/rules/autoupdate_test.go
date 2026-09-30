package rules

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func writeFile(t *testing.T, body string, mode os.FileMode) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), FileName)
	if err := os.WriteFile(path, []byte(body), mode); err != nil {
		t.Fatal(err)
	}

	return path
}

func TestSetAutoUpdateCreatesAMissingFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), "config", FileName)

	kept, err := SetAutoUpdate(path, true)
	if err != nil || kept != nil {
		t.Fatalf("kept = %v, err = %v", kept, err)
	}
	data, err := os.ReadFile(path)
	if err != nil || string(data) != "autoUpdate: true\n" {
		t.Fatalf("file = %q, %v", data, err)
	}
	if info, _ := os.Stat(path); info.Mode().Perm() != 0o600 {
		t.Fatalf("mode = %v", info.Mode())
	}
}

// The key goes on a new last line, so every byte of the file stays in place.
func TestSetAutoUpdateAppendsAndKeepsCommentsAndLayout(t *testing.T) {
	for name, body := range map[string]string{
		"comments":            "# my rules\nprojects:\n  loupe:\n    dir: /tmp # here\n\n# the end\n",
		"no final newline":    "maxWorkers: 2",
		"a block scalar tail": "rules:\n  - on: board.card_moved\n    prompt: |\n      Do the work.\n",
		"an empty block tail": "notes: |\n",
		"comments only":       "# nothing yet\n",
		"empty":               "",
	} {
		path := writeFile(t, body, 0o640)

		kept, err := SetAutoUpdate(path, false)
		if err != nil || kept != nil {
			t.Fatalf("%s: kept = %v, err = %v", name, kept, err)
		}
		data, _ := os.ReadFile(path)
		prefix := body
		if prefix != "" && !strings.HasSuffix(prefix, "\n") {
			prefix += "\n"
		}
		if string(data) != prefix+"autoUpdate: false\n" {
			t.Fatalf("%s: file = %q", name, data)
		}
		if info, _ := os.Stat(path); info.Mode().Perm() != 0o640 {
			t.Fatalf("%s: mode = %v", name, info.Mode())
		}
		on, present, err := ReadAutoUpdate(path)
		if err != nil || !present || on {
			t.Fatalf("%s: read = %v %v %v", name, on, present, err)
		}
	}
}

func TestSetAutoUpdateKeepsAKeyThatIsThere(t *testing.T) {
	for body, want := range map[string]bool{
		"autoUpdate: false\nmaxWorkers: 2\n": false,
		"maxWorkers: 2\nautoUpdate: true\n":  true,
		"autoUpdate:\n":                      false,
	} {
		path := writeFile(t, body, 0o600)

		kept, err := SetAutoUpdate(path, !want)
		if err != nil || kept == nil || *kept != want {
			t.Fatalf("%q: kept = %v, err = %v", body, kept, err)
		}
		if data, _ := os.ReadFile(path); string(data) != body {
			t.Fatalf("%q: file changed to %q", body, data)
		}
	}
}

// A new last line means something else in some files. The writer refuses
// them and leaves the file alone.
func TestSetAutoUpdateRefusesAFileWhereTheLineWouldNotBeAKey(t *testing.T) {
	for name, body := range map[string]string{
		"an indented mapping":  "  maxWorkers: 2\n",
		"a flow mapping":       "{maxWorkers: 2}\n",
		"a document end":       "maxWorkers: 2\n...\n",
		"a trailing separator": "maxWorkers: 2\n---\n",
	} {
		path := writeFile(t, body, 0o600)

		_, err := SetAutoUpdate(path, true)
		if !errors.Is(err, ErrAppendRefused) || !strings.Contains(err.Error(), "autoUpdate: true") {
			t.Fatalf("%s: err = %v", name, err)
		}
		if data, _ := os.ReadFile(path); string(data) != body {
			t.Fatalf("%s: file changed to %q", name, data)
		}
	}
}

func TestSetAutoUpdateRefusesABrokenFile(t *testing.T) {
	path := writeFile(t, "rules: [\n", 0o600)

	if _, err := SetAutoUpdate(path, true); err == nil || errors.Is(err, ErrAppendRefused) {
		t.Fatalf("err = %v", err)
	}
}

// A rule file can be a symlink, and the write must not replace the link.
func TestSetAutoUpdateWritesThroughASymlink(t *testing.T) {
	real := writeFile(t, "maxWorkers: 2\n", 0o600)
	link := filepath.Join(t.TempDir(), FileName)
	if err := os.Symlink(real, link); err != nil {
		t.Fatal(err)
	}

	if _, err := SetAutoUpdate(link, true); err != nil {
		t.Fatal(err)
	}
	if info, _ := os.Lstat(link); info.Mode()&os.ModeSymlink == 0 {
		t.Fatal("the link is gone")
	}
	if data, _ := os.ReadFile(real); string(data) != "maxWorkers: 2\nautoUpdate: true\n" {
		t.Fatalf("file = %q", data)
	}
}

// The flip changes the value on the key's own line, and every other byte stays.
func TestReplaceAutoUpdateFlipsTheValueInPlace(t *testing.T) {
	for _, tc := range []struct{ name, body, want string }{
		{"a comment", "# mine\nautoUpdate: false # asked at install\nmaxWorkers: 2\n", "# mine\nautoUpdate: true # asked at install\nmaxWorkers: 2\n"},
		{"the last line", "maxWorkers: 2\nautoUpdate: False", "maxWorkers: 2\nautoUpdate: true"},
		{"CRLF", "autoUpdate: false\r\nmaxWorkers: 2\r\n", "autoUpdate: true\r\nmaxWorkers: 2\r\n"},
		{"the same value", "autoUpdate: true\n", "autoUpdate: true\n"},
	} {
		path := writeFile(t, tc.body, 0o640)

		if err := ReplaceAutoUpdate(path, true); err != nil {
			t.Fatalf("%s: %v", tc.name, err)
		}
		if data, _ := os.ReadFile(path); string(data) != tc.want {
			t.Fatalf("%s: file = %q", tc.name, data)
		}
		if info, _ := os.Stat(path); info.Mode().Perm() != 0o640 {
			t.Fatalf("%s: mode = %v", tc.name, info.Mode())
		}
	}
}

func TestReplaceAutoUpdateRefusesAValueItCannotEditOnItsLine(t *testing.T) {
	for name, body := range map[string]string{
		"a flow mapping":      "{autoUpdate: false}\n",
		"a value on its line": "autoUpdate:\n  false\n",
		"an empty value":      "autoUpdate:\n",
		"a tag":               "autoUpdate: !!bool false\n",
		"an alias":            "x: &no false\nautoUpdate: *no\n",
		"no key":              "maxWorkers: 2\n",
	} {
		path := writeFile(t, body, 0o600)

		if err := ReplaceAutoUpdate(path, true); !errors.Is(err, ErrEditRefused) {
			t.Fatalf("%s: err = %v", name, err)
		}
		if data, _ := os.ReadFile(path); string(data) != body {
			t.Fatalf("%s: file changed to %q", name, data)
		}
	}
}

func TestReadAutoUpdateOfAMissingFileOrKey(t *testing.T) {
	for _, path := range []string{filepath.Join(t.TempDir(), FileName), writeFile(t, "maxWorkers: 2\n", 0o600)} {
		if on, present, err := ReadAutoUpdate(path); err != nil || present || on {
			t.Fatalf("%s: read = %v %v %v", path, on, present, err)
		}
	}
	if _, _, err := ReadAutoUpdate(writeFile(t, "autoUpdate: sometimes\n", 0o600)); err == nil {
		t.Fatal("a value that is not true or false must fail")
	}
}
