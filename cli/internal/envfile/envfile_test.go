package envfile

import (
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

func write(t *testing.T, body string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "env")
	if err := os.WriteFile(path, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}

	return path
}

func TestReadTakesEachShape(t *testing.T) {
	path := write(t, strings.Join([]string{
		"# a comment",
		"",
		"   ",
		"PLAIN=value",
		"export EXPORTED=yes",
		`DOUBLE="two words"`,
		`SINGLE='it''s'`,
		`HALF="open`,
		"EMPTY=",
		"EQUALS=a=b",
		"NOEXPAND=$HOME",
		"  SPACED = padded ",
		`QUOTED=" keep "`,
		"_under9=x",
	}, "\n"))

	got, err := Read(path)
	if err != nil {
		t.Fatal(err)
	}
	want := []string{
		"PLAIN=value", "EXPORTED=yes", "DOUBLE=two words", "SINGLE=it''s", `HALF="open`, "EMPTY=",
		"EQUALS=a=b", "NOEXPAND=$HOME", "SPACED=padded", "QUOTED= keep ", "_under9=x",
	}
	if !slices.Equal(got, want) {
		t.Fatalf("Read = %q, want %q", got, want)
	}
}

func TestReadNamesTheLineOfABadKeyAndHidesTheValue(t *testing.T) {
	for _, line := range []string{"9KEY=secret-value", "BAD-KEY=secret-value", "=secret-value", "export =secret-value"} {
		path := write(t, "OK=1\n"+line+"\n")
		_, err := Read(path)
		if err == nil {
			t.Fatalf("Read(%q) gave no error", line)
		}
		if !strings.Contains(err.Error(), path+":2") || strings.Contains(err.Error(), "secret-value") {
			t.Fatalf("Read(%q) = %v", line, err)
		}
	}
}

func TestReadRefusesALineWithNoEquals(t *testing.T) {
	path := write(t, "secret-value\n")
	_, err := Read(path)
	if err == nil || !strings.Contains(err.Error(), path+":1") || strings.Contains(err.Error(), "secret-value") {
		t.Fatalf("Read = %v", err)
	}
}

func TestReadNamesAMissingFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), "absent.env")
	_, err := Read(path)
	if err == nil || !strings.Contains(err.Error(), path) {
		t.Fatalf("Read = %v", err)
	}
}

func TestOverlayReplacesInheritedKeys(t *testing.T) {
	got := Overlay([]string{"PATH=/bin", "SHARED=old", "KEEP=1"}, []string{"SHARED=new", "ADDED=2"})
	if want := []string{"PATH=/bin", "KEEP=1", "SHARED=new", "ADDED=2"}; !slices.Equal(got, want) {
		t.Fatalf("Overlay = %q, want %q", got, want)
	}
}

func TestReadKeepsTheLastValueOfARepeatedKey(t *testing.T) {
	got, err := Read(write(t, "KEY=first\r\nOTHER=x\r\nKEY=second\r\n"))
	if want := []string{"OTHER=x", "KEY=second"}; err != nil || !slices.Equal(got, want) {
		t.Fatalf("Read = %q, %v; want %q", got, err, want)
	}
}
