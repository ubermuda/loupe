package projectfile

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

const validID = "01a0c0d9-905c-7922-a586-ccc8ce043704"

// write puts body in a fresh directory and returns that directory.
func write(t *testing.T, body string) string {
	t.Helper()
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, Name), []byte(body), 0o644); err != nil {
		t.Fatalf("write fixture: %v", err)
	}

	return dir
}

func TestADirectoryWithNoFileReportsErrNoFile(t *testing.T) {
	if _, err := Load(t.TempDir()); !errors.Is(err, ErrNoFile) {
		t.Fatalf("Load of an empty directory: got %v, want ErrNoFile", err)
	}
}

func TestAFileNamesItsProject(t *testing.T) {
	file, err := Load(write(t, "project: "+validID+"\n"))
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if file.Project != validID {
		t.Fatalf("Project: got %q, want %q", file.Project, validID)
	}
}

func TestAnUnknownKeyIsIgnoredSoALaterFormatStillReads(t *testing.T) {
	file, err := Load(write(t, "project: "+validID+"\nprojects:\n  ./api: "+validID+"\n"))
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if file.Project != validID {
		t.Fatalf("Project: got %q, want %q", file.Project, validID)
	}
}

func TestAMalformedFileFailsAndNamesTheFile(t *testing.T) {
	_, err := Load(write(t, "project: [not, a, string\n"))
	if err == nil {
		t.Fatal("Load of a malformed file: got no error")
	}
	if !strings.Contains(err.Error(), Name) {
		t.Fatalf("error must name the file, got %v", err)
	}
}

func TestAProjectThatIsNotAnIdentifierIsRefused(t *testing.T) {
	_, err := Load(write(t, "project: my-board\n"))
	if err == nil {
		t.Fatal("Load of a non-identifier project: got no error")
	}
	if !strings.Contains(err.Error(), "my-board") {
		t.Fatalf("error must quote the value, got %v", err)
	}
}

func TestAnIdentifierInUpperCaseReadsAsLowerCase(t *testing.T) {
	file, err := Load(write(t, "project: "+strings.ToUpper(validID)+"\n"))
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if file.Project != validID {
		t.Fatalf("Project: got %q, want %q", file.Project, validID)
	}
}

func TestAFileWithNoProjectKeyNamesNoProject(t *testing.T) {
	file, err := Load(write(t, "# nothing here yet\n"))
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if file.Project != "" {
		t.Fatalf("Project: got %q, want the empty string", file.Project)
	}
}

func TestWriteThenLoadGivesBackTheProject(t *testing.T) {
	dir := t.TempDir()
	if err := Write(dir, strings.ToUpper(validID)); err != nil {
		t.Fatalf("Write: %v", err)
	}

	file, err := Load(dir)
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if file.Project != validID {
		t.Fatalf("Project: got %q, want %q", file.Project, validID)
	}
}

func TestWriteRefusesAValueThatIsNotAnIdentifier(t *testing.T) {
	if err := Write(t.TempDir(), "my-board"); err == nil {
		t.Fatal("Write of a non-identifier: got no error")
	}
}

func TestAFileLargerThanTheCapIsRefused(t *testing.T) {
	_, err := Load(write(t, strings.Repeat("# padding\n", maxSize/10+16)))
	if err == nil {
		t.Fatal("Load of an oversized file: got no error")
	}
	if !strings.Contains(err.Error(), Name) {
		t.Fatalf("error must name the file, got %v", err)
	}
}
