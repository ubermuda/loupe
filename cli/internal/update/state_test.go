package update

import (
	"os"
	"path/filepath"
	"testing"
)

func TestLoadStateOfAMissingFileIsEmpty(t *testing.T) {
	s, err := LoadState(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	if s.Skipped("1.3.0") || len(s.Staged) != 0 {
		t.Fatalf("state = %+v", s)
	}
}

func TestStateSurvivesASaveAndALoad(t *testing.T) {
	dir := t.TempDir()
	s, _ := LoadState(dir)
	s.Skip("1.3.0")
	s.Skip("1.3.0")
	s.Staged["1.4.0"] = StagePath(dir, "1.4.0")
	if err := s.Save(dir); err != nil {
		t.Fatal(err)
	}

	raw, _ := os.ReadFile(filepath.Join(dir, "update.json"))
	want := `{"skip":["1.3.0"],"staged":{"1.4.0":"` + filepath.Join(dir, "versions", "1.4.0", "loupe") + `"}}`
	if string(raw) != want {
		t.Fatalf("update.json = %s, want %s", raw, want)
	}
	got, err := LoadState(dir)
	if err != nil {
		t.Fatal(err)
	}
	if !got.Skipped("1.3.0") || got.Skipped("1.4.0") || got.Staged["1.4.0"] != StagePath(dir, "1.4.0") || !got.SkipSet()["1.3.0"] {
		t.Fatalf("state = %+v", got)
	}
}

func TestLoadStateRefusesABrokenFile(t *testing.T) {
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "update.json"), []byte("{"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := LoadState(dir); err == nil {
		t.Fatal("a broken file loaded")
	}
}
