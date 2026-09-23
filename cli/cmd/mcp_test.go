package cmd

import (
	"bytes"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/projectfile"
)

// runMcp runs `loupe mcp` with args and gives back its error.
func runMcp(t *testing.T, args ...string) error {
	t.Helper()
	var out bytes.Buffer
	cmd := newMcpCmd()
	cmd.SetOut(&out)
	cmd.SetErr(&out)
	cmd.SetArgs(args)

	return cmd.Execute()
}

func TestMcpWithNoLoginSaysToLogIn(t *testing.T) {
	inRepo(t, "")

	err := runMcp(t)
	if !errors.Is(err, config.ErrNotLoggedIn) {
		t.Fatalf("mcp with no login: got %v, want ErrNotLoggedIn", err)
	}
}

func TestMcpStopsBeforeConnectingOnAMalformedProjectFile(t *testing.T) {
	dir := inRepo(t, "https://loupe.invalid")
	if err := os.WriteFile(filepath.Join(dir, projectfile.Name), []byte("project: [broken\n"), 0o644); err != nil {
		t.Fatalf("write fixture: %v", err)
	}

	err := runMcp(t)
	if err == nil {
		t.Fatal("mcp with a malformed project file: got no error")
	}
	if !strings.Contains(err.Error(), projectfile.Name) {
		t.Fatalf("error must name the file, got %v", err)
	}
	if !strings.Contains(err.Error(), "loupe init") {
		t.Fatalf("error must say how to write the file, got %v", err)
	}
}

const (
	projectA = "01a0c0d9-905c-7922-a586-ccc8ce043704"
	projectB = "0192f3c4-5d6e-7f80-9123-456789abcdef"
)

// writeProject writes .loupe.yaml in dir the way loupe init does, and sets its
// modification time, so a test does not depend on the clock's granularity.
func writeProject(t *testing.T, dir, id string, mtime time.Time) {
	t.Helper()
	if err := projectfile.Write(dir, id); err != nil {
		t.Fatalf("write fixture: %v", err)
	}
	touch(t, dir, mtime)
}

// writeBadProject writes a .loupe.yaml that does not parse.
func writeBadProject(t *testing.T, dir string, mtime time.Time) {
	t.Helper()
	if err := os.WriteFile(filepath.Join(dir, projectfile.Name), []byte("project: [broken\n"), 0o644); err != nil {
		t.Fatalf("write fixture: %v", err)
	}
	touch(t, dir, mtime)
}

func touch(t *testing.T, dir string, mtime time.Time) {
	t.Helper()
	if err := os.Chtimes(filepath.Join(dir, projectfile.Name), mtime, mtime); err != nil {
		t.Fatalf("chtimes: %v", err)
	}
}

var (
	firstWrite  = time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	secondWrite = time.Date(2026, 1, 2, 0, 0, 0, 0, time.UTC)
)

func TestTheProjectFlagWinsAndTheFileIsNeverRead(t *testing.T) {
	dir := inRepo(t, "")
	writeBadProject(t, dir, firstWrite)

	var notes bytes.Buffer
	project, err := mcpProject(projectA, &notes)
	if err != nil {
		t.Fatalf("mcpProject: %v", err)
	}
	if err := os.Remove(filepath.Join(dir, projectfile.Name)); err != nil {
		t.Fatal(err)
	}
	if got := project(); got != projectA {
		t.Fatalf("project = %q, want the flag", got)
	}
	if notes.Len() != 0 {
		t.Fatalf("notes = %q, want none", notes.String())
	}
}

func TestAMissingProjectFileAtStartNamesNoProject(t *testing.T) {
	inRepo(t, "")

	var notes bytes.Buffer
	project, err := mcpProject("", &notes)
	if err != nil {
		t.Fatalf("mcpProject: %v", err)
	}
	if got := project(); got != "" || notes.Len() != 0 {
		t.Fatalf("project = %q, notes = %q, want neither", got, notes.String())
	}
}

// Two project ids have one length, so the modification time alone shows the
// change.
func TestAChangedProjectFileChangesTheProject(t *testing.T) {
	dir := inRepo(t, "")
	writeProject(t, dir, projectA, firstWrite)

	var notes bytes.Buffer
	project, err := mcpProject("", &notes)
	if err != nil {
		t.Fatalf("mcpProject: %v", err)
	}
	if got := project(); got != projectA {
		t.Fatalf("project = %q, want %s", got, projectA)
	}

	writeProject(t, dir, projectB, secondWrite)
	if got := project(); got != projectB {
		t.Fatalf("project = %q, want %s", got, projectB)
	}
	project()
	if want := "loupe mcp: .loupe.yaml now names project " + projectB + " (was " + projectA + ")\n"; notes.String() != want {
		t.Fatalf("notes = %q, want %q", notes.String(), want)
	}
}

// loupe init replaces the file with a rename. A new file with the old size and
// the old time is still a change.
func TestAReplacedProjectFileWithTheSameTimeIsStillRead(t *testing.T) {
	dir := inRepo(t, "")
	writeProject(t, dir, projectA, firstWrite)

	project, err := mcpProject("", io.Discard)
	if err != nil {
		t.Fatalf("mcpProject: %v", err)
	}
	project()

	writeProject(t, dir, projectB, firstWrite)
	if got := project(); got != projectB {
		t.Fatalf("project = %q, want %s", got, projectB)
	}
}

func TestABadProjectFileKeepsTheLastProjectAndNotesOnce(t *testing.T) {
	dir := inRepo(t, "")
	writeProject(t, dir, projectA, firstWrite)

	var notes bytes.Buffer
	project, err := mcpProject("", &notes)
	if err != nil {
		t.Fatalf("mcpProject: %v", err)
	}

	writeBadProject(t, dir, secondWrite)
	for range 3 {
		if got := project(); got != projectA {
			t.Fatalf("project = %q, want the last good %s", got, projectA)
		}
	}
	if lines := strings.Count(notes.String(), "\n"); lines != 1 || !strings.Contains(notes.String(), projectfile.Name) {
		t.Fatalf("notes = %q, want one line that names the file", notes.String())
	}
}

// A fix can leave the stat as it was, as a new file mode does. The source reads
// a broken file again on each call, so it sees the fix.
func TestABrokenProjectFileIsReadAgainUntilItLoads(t *testing.T) {
	dir := inRepo(t, "")
	writeProject(t, dir, projectA, firstWrite)

	var notes bytes.Buffer
	project, err := mcpProject("", &notes)
	if err != nil {
		t.Fatalf("mcpProject: %v", err)
	}

	path := filepath.Join(dir, projectfile.Name)
	good := "project: " + projectB + "\n"
	bad := "project: [" + strings.Repeat("x", len(good)-len("project: [")-1) + "\n"
	for _, body := range []string{bad, good} {
		if err := os.WriteFile(path, []byte(body), 0o644); err != nil {
			t.Fatalf("write fixture: %v", err)
		}
		touch(t, dir, secondWrite)
		project()
	}
	if got := project(); got != projectB {
		t.Fatalf("project = %q, want %s after the fix", got, projectB)
	}
}

func TestARemovedProjectFileNamesNoProject(t *testing.T) {
	dir := inRepo(t, "")
	writeProject(t, dir, projectA, firstWrite)

	var notes bytes.Buffer
	project, err := mcpProject("", &notes)
	if err != nil {
		t.Fatalf("mcpProject: %v", err)
	}

	if err := os.Remove(filepath.Join(dir, projectfile.Name)); err != nil {
		t.Fatal(err)
	}
	for range 3 {
		if got := project(); got != "" {
			t.Fatalf("project = %q, want none", got)
		}
	}
	if lines := strings.Count(notes.String(), "\n"); lines != 1 || !strings.Contains(notes.String(), projectA) {
		t.Fatalf("notes = %q, want one line that names the old project", notes.String())
	}
}

// Run with -race: requests read the project from many goroutines.
func TestConcurrentProjectReadsAreSafe(t *testing.T) {
	dir := inRepo(t, "")
	writeProject(t, dir, projectA, firstWrite)

	project, err := mcpProject("", io.Discard)
	if err != nil {
		t.Fatalf("mcpProject: %v", err)
	}

	var wg sync.WaitGroup
	for range 8 {
		wg.Go(func() {
			for range 50 {
				if got := project(); got != projectA && got != projectB {
					t.Errorf("project = %q", got)
				}
			}
		})
	}
	for i := range 20 {
		id := projectA
		if i%2 == 0 {
			id = projectB
		}
		writeProject(t, dir, id, firstWrite.Add(time.Duration(i+1)*time.Hour))
	}
	wg.Wait()
}

func TestMcpRefusesAProjectFileWhoseProjectIsNotAnIdentifier(t *testing.T) {
	dir := inRepo(t, "https://loupe.invalid")
	if err := os.WriteFile(filepath.Join(dir, projectfile.Name), []byte("project: my-board\n"), 0o644); err != nil {
		t.Fatalf("write fixture: %v", err)
	}

	err := runMcp(t)
	if err == nil {
		t.Fatal("mcp with a project that is not an identifier: got no error")
	}
	if !strings.Contains(err.Error(), "my-board") {
		t.Fatalf("error must quote the value, got %v", err)
	}
}
