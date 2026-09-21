package mcpjson

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// write puts body in a fresh directory and returns that directory.
func write(t *testing.T, body string) string {
	t.Helper()
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, Name), []byte(body), 0o644); err != nil {
		t.Fatalf("write fixture: %v", err)
	}

	return dir
}

// read gives back the file as text.
func read(t *testing.T, dir string) string {
	t.Helper()
	b, err := os.ReadFile(filepath.Join(dir, Name))
	if err != nil {
		t.Fatalf("read %s: %v", Name, err)
	}

	return string(b)
}

// state is the State alone, for a test that asserts on nothing else.
func state(t *testing.T, dir string) State {
	t.Helper()
	got, _, err := Read(dir)
	if err != nil {
		t.Fatalf("Read: %v", err)
	}

	return got
}

func TestADirectoryWithNoFileHasNoLoupeServer(t *testing.T) {
	if got := state(t, t.TempDir()); Absent != got {
		t.Fatalf("Read of an empty directory: got %v, want Absent", got)
	}
}

func TestAnEmptyFileReadsAsNoLoupeServer(t *testing.T) {
	if got := state(t, write(t, "   \n")); Absent != got {
		t.Fatalf("Read of an empty file: got %v, want Absent", got)
	}
}

func TestAFileNamingAnotherServerHasNoLoupeServer(t *testing.T) {
	if got := state(t, write(t, `{"mcpServers":{"other":{"command":"other"}}}`)); Absent != got {
		t.Fatalf("Read: got %v, want Absent", got)
	}
}

func TestTheDesiredEntryReadsAsCurrent(t *testing.T) {
	dir := write(t, `{"mcpServers":{"loupe":{"command":"loupe","args":["mcp"]}}}`)
	if got := state(t, dir); Current != got {
		t.Fatalf("Read: got %v, want Current", got)
	}
}

func TestAnotherCommandUnderLoupeReadsAsDifferent(t *testing.T) {
	dir := write(t, `{"mcpServers":{"loupe":{"command":"/opt/loupe","args":["mcp","--verbose"]}}}`)
	got, found, err := Read(dir)
	if err != nil {
		t.Fatalf("Read: %v", err)
	}
	if Different != got {
		t.Fatalf("Read: got %v, want Different", got)
	}
	if found.Command != "/opt/loupe" {
		t.Fatalf("Command: got %q, want the command the file holds", found.Command)
	}
}

// A remote entry carries a url and no command, so the caller has no command to
// show. It is still something the person chose, so it must not read as Absent.
func TestARemoteEntryUnderLoupeReadsAsDifferent(t *testing.T) {
	dir := write(t, `{"mcpServers":{"loupe":{"type":"http","url":"https://loupe.ac/mcp"}}}`)
	if got := state(t, dir); Different != got {
		t.Fatalf("Read: got %v, want Different", got)
	}
}

// The same command with the same number of arguments, one of which differs.
// Comparing the lengths alone would read this as the entry we wanted.
func TestOneArgumentThatDiffersReadsAsDifferent(t *testing.T) {
	dir := write(t, `{"mcpServers":{"loupe":{"command":"loupe","args":["bridge"]}}}`)
	if got := state(t, dir); Different != got {
		t.Fatalf("Read: got %v, want Different", got)
	}
}

func TestBrokenJsonIsAnErrorRatherThanAnEmptyFile(t *testing.T) {
	if _, _, err := Read(write(t, "{not json")); err == nil {
		t.Fatal("Read of broken JSON: got no error")
	}
}

func TestAServerListThatIsNotAnObjectIsAnError(t *testing.T) {
	_, _, err := Read(write(t, `{"mcpServers":["loupe"]}`))
	if err == nil {
		t.Fatal("Read of a list: got no error")
	}
	if !strings.Contains(err.Error(), "mcpServers") {
		t.Fatalf("error must name the key, got %v", err)
	}
}

func TestWriteCreatesTheFileWhenThereIsNone(t *testing.T) {
	dir := t.TempDir()
	if err := Write(dir); err != nil {
		t.Fatalf("Write: %v", err)
	}
	if got := state(t, dir); Current != got {
		t.Fatalf("Read after Write: got %v, want Current", got)
	}
}

func TestWriteKeepsEveryOtherServer(t *testing.T) {
	dir := write(t, `{"mcpServers":{"other":{"command":"other","args":["serve"]}}}`)
	if err := Write(dir); err != nil {
		t.Fatalf("Write: %v", err)
	}

	servers := map[string]json.RawMessage{}
	doc := map[string]json.RawMessage{}
	if err := json.Unmarshal([]byte(read(t, dir)), &doc); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}
	if err := json.Unmarshal(doc["mcpServers"], &servers); err != nil {
		t.Fatalf("unmarshal servers: %v", err)
	}
	if _, ok := servers["other"]; !ok {
		t.Fatalf("Write dropped the other server, file is %s", read(t, dir))
	}
	if _, ok := servers[ServerKey]; !ok {
		t.Fatalf("Write did not add the loupe server, file is %s", read(t, dir))
	}
}

func TestWriteKeepsEveryOtherTopLevelKey(t *testing.T) {
	dir := write(t, `{"$schema":"https://example.test/mcp.json","mcpServers":{}}`)
	if err := Write(dir); err != nil {
		t.Fatalf("Write: %v", err)
	}
	if !strings.Contains(read(t, dir), "$schema") {
		t.Fatalf("Write dropped a top-level key, file is %s", read(t, dir))
	}
}

func TestWriteReplacesAnotherLoupeEntry(t *testing.T) {
	dir := write(t, `{"mcpServers":{"loupe":{"command":"/opt/loupe","args":["mcp","--verbose"]}}}`)
	if err := Write(dir); err != nil {
		t.Fatalf("Write: %v", err)
	}
	if got := state(t, dir); Current != got {
		t.Fatalf("Read after Write: got %v, want Current", got)
	}
	if strings.Contains(read(t, dir), "--verbose") {
		t.Fatalf("Write kept the old arguments, file is %s", read(t, dir))
	}
}

// The file is committed and read by people, so the servers must be indented
// rather than written back as the one compact line a RawMessage holds.
func TestTheWrittenFileIsIndented(t *testing.T) {
	dir := t.TempDir()
	if err := Write(dir); err != nil {
		t.Fatalf("Write: %v", err)
	}
	body := read(t, dir)
	if !strings.Contains(body, "\n      \"command\"") {
		t.Fatalf("the entry must be indented, file is %s", body)
	}
	if !strings.HasSuffix(body, "}\n") {
		t.Fatalf("the file must end in a newline, file is %q", body)
	}
}

func TestWriteRefusesAFileTooLargeToBeAServerList(t *testing.T) {
	dir := write(t, "{"+strings.Repeat(" ", maxSize)+"}")
	if err := Write(dir); err == nil {
		t.Fatal("Write over an oversized file: got no error")
	}
}

// A refused write must leave what was there, because the file is somebody
// else's and a half-written one is worse than no change at all.
func TestARefusedWriteLeavesTheFileAlone(t *testing.T) {
	dir := write(t, "{not json")
	if err := Write(dir); err == nil {
		t.Fatal("Write over broken JSON: got no error")
	}
	if body := read(t, dir); body != "{not json" {
		t.Fatalf("file: got %q, want it left alone", body)
	}
}
