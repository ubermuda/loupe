package claudecode

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

const token = "Bearer s3cr3t-token-that-must-never-be-shown"

// configure puts body at CLAUDE_CONFIG_DIR's file, so no test reads the real
// configuration of whoever runs the suite.
func configure(t *testing.T, body string) {
	t.Helper()
	dir := t.TempDir()
	t.Setenv("CLAUDE_CONFIG_DIR", dir)
	if err := os.WriteFile(filepath.Join(dir, configName), []byte(body), 0o600); err != nil {
		t.Fatalf("write fixture: %v", err)
	}
}

// project gives a directory, and the path Claude Code would file it under.
func project(t *testing.T) (string, string) {
	t.Helper()
	dir := t.TempDir()
	resolved, err := filepath.EvalSymlinks(dir)
	if err != nil {
		t.Fatalf("EvalSymlinks: %v", err)
	}

	return dir, resolved
}

func TestNoConfigurationFileMeansNothingShadowsUs(t *testing.T) {
	t.Setenv("CLAUDE_CONFIG_DIR", t.TempDir())

	shadow, err := Shadowing(t.TempDir(), "loupe")
	if err != nil {
		t.Fatalf("Shadowing: %v", err)
	}
	if shadow != nil {
		t.Fatalf("Shadowing: got %+v, want nil", shadow)
	}
}

func TestAnEntryForThisDirectoryShadowsUs(t *testing.T) {
	dir, resolved := project(t)
	configure(t, `{"projects":{"`+resolved+`":{"mcpServers":{"loupe":{"type":"http","url":"https://loupe.ac/mcp"}}}}}`)

	shadow, err := Shadowing(dir, "loupe")
	if err != nil {
		t.Fatalf("Shadowing: %v", err)
	}
	if shadow == nil {
		t.Fatal("Shadowing: got nil, want the entry")
	}
	if shadow.Summary != "https://loupe.ac/mcp" {
		t.Fatalf("Summary: got %q, want the url", shadow.Summary)
	}
	if shadow.Path != resolved {
		t.Fatalf("Path: got %q, want %q", shadow.Path, resolved)
	}
	if shadow.Scope != ScopeLocal {
		t.Fatalf("Scope: got %q, want local", shadow.Scope)
	}
}

// t.TempDir gives a path through a symbolic link on macOS, and Claude Code
// files the resolved one. Matching the unresolved path finds nothing, and the
// entry then shadows us in silence.
func TestTheDirectoryIsMatchedThroughASymbolicLink(t *testing.T) {
	real := t.TempDir()
	resolved, err := filepath.EvalSymlinks(real)
	if err != nil {
		t.Fatalf("EvalSymlinks: %v", err)
	}
	link := filepath.Join(t.TempDir(), "link")
	if err := os.Symlink(resolved, link); err != nil {
		t.Fatalf("Symlink: %v", err)
	}
	configure(t, `{"projects":{"`+resolved+`":{"mcpServers":{"loupe":{"command":"loupe","args":["mcp"]}}}}}`)

	shadow, err := Shadowing(link, "loupe")
	if err != nil {
		t.Fatalf("Shadowing: %v", err)
	}
	if shadow == nil {
		t.Fatal("Shadowing through a symbolic link: got nil, want the entry")
	}
	if shadow.Summary != "loupe mcp" {
		t.Fatalf("Summary: got %q, want the command", shadow.Summary)
	}
}

func TestAnEntryForAnotherDirectoryDoesNotShadowUs(t *testing.T) {
	dir, _ := project(t)
	_, other := project(t)
	configure(t, `{"projects":{"`+other+`":{"mcpServers":{"loupe":{"url":"https://loupe.ac/mcp"}}}}}`)

	shadow, err := Shadowing(dir, "loupe")
	if err != nil {
		t.Fatalf("Shadowing: %v", err)
	}
	if shadow != nil {
		t.Fatalf("Shadowing: got %+v, want nil", shadow)
	}
}

func TestAnotherServerNameDoesNotShadowUs(t *testing.T) {
	dir, resolved := project(t)
	configure(t, `{"projects":{"`+resolved+`":{"mcpServers":{"other":{"url":"https://example.test/mcp"}}}}}`)

	shadow, err := Shadowing(dir, "loupe")
	if err != nil {
		t.Fatalf("Shadowing: %v", err)
	}
	if shadow != nil {
		t.Fatalf("Shadowing: got %+v, want nil", shadow)
	}
}

// The entry commonly carries an Authorization header. Showing it would print a
// credential to a terminal and into whatever records that terminal.
func TestTheSummaryCarriesNoCredential(t *testing.T) {
	dir, resolved := project(t)
	configure(t, `{"projects":{"`+resolved+`":{"mcpServers":{"loupe":{"type":"http","url":"https://loupe.ac/mcp","headers":{"Authorization":"`+token+`"}}}}}}`)

	shadow, err := Shadowing(dir, "loupe")
	if err != nil {
		t.Fatalf("Shadowing: %v", err)
	}
	if shadow == nil {
		t.Fatal("Shadowing: got nil, want the entry")
	}
	if strings.Contains(shadow.Summary, "s3cr3t") || strings.Contains(shadow.Summary, "Bearer") {
		t.Fatalf("Summary leaks the credential: %q", shadow.Summary)
	}
}

func TestBrokenConfigurationIsAnErrorRatherThanNothingToDo(t *testing.T) {
	dir, _ := project(t)
	configure(t, "{not json")

	if _, err := Shadowing(dir, "loupe"); err == nil {
		t.Fatal("Shadowing over broken JSON: got no error")
	}
}

func TestRemoveSaysSoWhenClaudeIsNotInstalled(t *testing.T) {
	t.Setenv("PATH", t.TempDir())

	if err := Remove(context.Background(), t.TempDir(), "loupe", ScopeLocal); err != ErrNoClaude {
		t.Fatalf("Remove with no claude on PATH: got %v, want ErrNoClaude", err)
	}
}

func TestRemoveCommandNamesTheScope(t *testing.T) {
	if got := RemoveCommand("loupe", ScopeLocal); got != "claude mcp remove loupe -s local" {
		t.Fatalf("RemoveCommand(local): got %q", got)
	}
	if got := RemoveCommand("loupe", ScopeUser); got != "claude mcp remove loupe -s user" {
		t.Fatalf("RemoveCommand(user): got %q", got)
	}
}

// .mcp.json is the lowest of the three scopes, so a user entry hides it just as
// a local one does. Checking local alone reports nothing and leaves the file
// inert.
func TestAUserEntryShadowsUsToo(t *testing.T) {
	dir, _ := project(t)
	configure(t, `{"mcpServers":{"loupe":{"type":"http","url":"https://loupe.ac/mcp"}}}`)

	shadow, err := Shadowing(dir, "loupe")
	if err != nil {
		t.Fatalf("Shadowing: %v", err)
	}
	if shadow == nil {
		t.Fatal("Shadowing: got nil, want the user entry")
	}
	if shadow.Scope != ScopeUser {
		t.Fatalf("Scope: got %q, want user", shadow.Scope)
	}
	if shadow.Where() != "every project" {
		t.Fatalf("Where: got %q, want it to say the entry is not one project's", shadow.Where())
	}
}

// Local wins over user, so reporting the user entry first would have somebody
// remove it and find the file still hidden.
func TestTheLocalEntryIsReportedBeforeTheUserOne(t *testing.T) {
	dir, resolved := project(t)
	configure(t, `{"mcpServers":{"loupe":{"url":"https://user.example/mcp"}},"projects":{"`+resolved+`":{"mcpServers":{"loupe":{"url":"https://local.example/mcp"}}}}}`)

	shadow, err := Shadowing(dir, "loupe")
	if err != nil {
		t.Fatalf("Shadowing: %v", err)
	}
	if shadow == nil {
		t.Fatal("Shadowing: got nil, want the local entry")
	}
	if shadow.Scope != ScopeLocal {
		t.Fatalf("Scope: got %q, want local", shadow.Scope)
	}
	if shadow.Summary != "https://local.example/mcp" {
		t.Fatalf("Summary: got %q, want the local entry", shadow.Summary)
	}
}

func TestAUserEntryUnderAnotherNameDoesNotShadowUs(t *testing.T) {
	dir, _ := project(t)
	configure(t, `{"mcpServers":{"other":{"url":"https://example.test/mcp"}}}`)

	shadow, err := Shadowing(dir, "loupe")
	if err != nil {
		t.Fatalf("Shadowing: %v", err)
	}
	if shadow != nil {
		t.Fatalf("Shadowing: got %+v, want nil", shadow)
	}
}
