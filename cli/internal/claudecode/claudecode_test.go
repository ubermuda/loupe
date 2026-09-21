package claudecode

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

const correct = `{"mcpServers":{"loupe":{"type":"stdio","command":"loupe","args":["mcp"],"env":{}}}}`

// configure puts body at CLAUDE_CONFIG_DIR's file, so no test reads the real
// configuration of whoever runs the suite.
func configure(t *testing.T, body string) string {
	t.Helper()
	dir := t.TempDir()
	t.Setenv("CLAUDE_CONFIG_DIR", dir)
	if err := os.WriteFile(filepath.Join(dir, configName), []byte(body), 0o600); err != nil {
		t.Fatalf("write fixture: %v", err)
	}

	return dir
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

// fakeClaude puts a claude command on PATH whose body is the given shell, and
// returns the file it logs its arguments to.
func fakeClaude(t *testing.T, body string) string {
	t.Helper()
	dir := t.TempDir()
	log := filepath.Join(dir, "argv.log")
	script := "#!/bin/sh\necho \"$@\" >> " + log + "\n" + body + "\n"
	if err := os.WriteFile(filepath.Join(dir, "claude"), []byte(script), 0o755); err != nil {
		t.Fatalf("write fake claude: %v", err)
	}
	// Prepended, not replaced: the script runs real commands, and a PATH of
	// just this directory leaves it without them.
	t.Setenv("PATH", dir+string(os.PathListSeparator)+os.Getenv("PATH"))

	return log
}

func effective(t *testing.T, dir string) Resolution {
	t.Helper()
	got, err := Effective(dir, "loupe")
	if err != nil {
		t.Fatalf("Effective: %v", err)
	}

	return got
}

func TestNothingDeclaresTheServer(t *testing.T) {
	t.Setenv("CLAUDE_CONFIG_DIR", t.TempDir())

	got := effective(t, t.TempDir())
	if got.Declared() {
		t.Fatalf("Effective: got %+v, want nothing declared", got)
	}
	if got.Correct() {
		t.Fatal("an undeclared server must not read as correct")
	}
}

func TestALocalEntryIsWhatClaudeCodeUses(t *testing.T) {
	dir, resolved := project(t)
	configure(t, `{"projects":{"`+resolved+`":{"mcpServers":{"loupe":{"type":"http","url":"https://loupe.ac/mcp"}}}}}`)

	got := effective(t, dir)
	if ScopeLocal != got.Scope {
		t.Fatalf("Scope: got %q, want local", got.Scope)
	}
	if got.Correct() {
		t.Fatal("an http entry must not read as `loupe mcp`")
	}
	if got.Where() != resolved {
		t.Fatalf("Where: got %q, want the project path", got.Where())
	}
}

// .mcp.json is the lowest of the three scopes, so a user entry hides it.
// Checking local alone reports nothing and leaves the repository inert.
func TestAUserEntryIsUsedWhenNoLocalOneExists(t *testing.T) {
	dir, _ := project(t)
	configure(t, `{"mcpServers":{"loupe":{"url":"https://loupe.ac/mcp"}}}`)

	got := effective(t, dir)
	if ScopeUser != got.Scope {
		t.Fatalf("Scope: got %q, want user", got.Scope)
	}
	if got.Where() != "every project" {
		t.Fatalf("Where: got %q, want it to say the entry is not one project's", got.Where())
	}
}

// Local wins over user, so reporting the user entry first would have somebody
// remove it and find nothing changed.
func TestTheLocalEntryIsReportedBeforeTheUserOne(t *testing.T) {
	dir, resolved := project(t)
	configure(t, `{"mcpServers":{"loupe":{"url":"https://user.example/mcp"}},"projects":{"`+resolved+`":{"mcpServers":{"loupe":{"url":"https://local.example/mcp"}}}}}`)

	got := effective(t, dir)
	if ScopeLocal != got.Scope {
		t.Fatalf("Scope: got %q, want local", got.Scope)
	}
	if got.Entry.Summary() != "https://local.example/mcp" {
		t.Fatalf("Summary: got %q, want the local entry", got.Entry.Summary())
	}
}

// The repository's file is read only when no Claude Code entry declares the
// server, because every other scope beats it.
func TestTheRepositoryFileIsTheLowestScope(t *testing.T) {
	dir, _ := project(t)
	t.Setenv("CLAUDE_CONFIG_DIR", t.TempDir())
	if err := os.WriteFile(filepath.Join(dir, ".mcp.json"), []byte(`{"mcpServers":{"loupe":{"command":"loupe","args":["mcp"]}}}`), 0o644); err != nil {
		t.Fatalf("write fixture: %v", err)
	}

	got := effective(t, dir)
	if ScopeProject != got.Scope {
		t.Fatalf("Scope: got %q, want project", got.Scope)
	}
	if !got.Correct() {
		t.Fatal("a file that starts `loupe mcp` must read as correct")
	}

	configure(t, `{"mcpServers":{"loupe":{"url":"https://user.example/mcp"}}}`)
	if got := effective(t, dir); ScopeUser != got.Scope {
		t.Fatalf("Scope with a user entry present: got %q, want user", got.Scope)
	}
}

func TestAnEntryForAnotherDirectoryIsNotOurs(t *testing.T) {
	dir, _ := project(t)
	_, other := project(t)
	configure(t, `{"projects":{"`+other+`":{"mcpServers":{"loupe":{"url":"https://loupe.ac/mcp"}}}}}`)

	if got := effective(t, dir); got.Declared() {
		t.Fatalf("Effective: got %+v, want nothing declared", got)
	}
}

// t.TempDir gives a path through a symbolic link on macOS, and Claude Code
// files the resolved one. Matching the unresolved path finds nothing, and the
// entry then hides the repository's file in silence.
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

	if got := effective(t, link); ScopeLocal != got.Scope {
		t.Fatalf("Scope through a symbolic link: got %q, want local", got.Scope)
	}
}

func TestWhatCountsAsStartingTheShim(t *testing.T) {
	for _, c := range []struct {
		name string
		e    Entry
		want bool
	}{
		{"the command claude mcp add writes", Entry{Type: "stdio", Command: "loupe", Args: []string{"mcp"}}, true},
		{"an absolute path is still loupe", Entry{Command: "/opt/homebrew/bin/loupe", Args: []string{"mcp"}}, true},
		{"another subcommand", Entry{Command: "loupe", Args: []string{"bridge"}}, false},
		{"an extra argument", Entry{Command: "loupe", Args: []string{"mcp", "--verbose"}}, false},
		{"no argument", Entry{Command: "loupe"}, false},
		{"another program", Entry{Command: "loupe-proxy", Args: []string{"mcp"}}, false},
		{"an http entry", Entry{Type: "http", URL: "https://loupe.ac/mcp"}, false},
	} {
		if got := c.e.StartsMcpShim(); got != c.want {
			t.Errorf("%s: got %v, want %v", c.name, got, c.want)
		}
	}
}

// The entry commonly carries an Authorization header. Showing it would print a
// credential to a terminal and into whatever records that terminal.
func TestTheSummaryCarriesNoCredential(t *testing.T) {
	dir, resolved := project(t)
	configure(t, `{"projects":{"`+resolved+`":{"mcpServers":{"loupe":{"type":"http","url":"https://loupe.ac/mcp","headers":{"Authorization":"Bearer s3cr3t"}}}}}}`)

	if summary := effective(t, dir).Entry.Summary(); strings.Contains(summary, "s3cr3t") || strings.Contains(summary, "Bearer") {
		t.Fatalf("Summary leaks the credential: %q", summary)
	}
}

func TestBrokenConfigurationIsAnErrorRatherThanNothingToDo(t *testing.T) {
	dir, _ := project(t)
	configure(t, "{not json")

	if _, err := Effective(dir, "loupe"); err == nil {
		t.Fatal("Effective over broken JSON: got no error")
	}
}

func TestAddAsksClaudeCodeForTheUserScope(t *testing.T) {
	dir, _ := project(t)
	cfg := configure(t, "{}")
	log := fakeClaude(t, "cat > "+filepath.Join(cfg, configName)+" <<'EOF'\n"+correct+"\nEOF")

	if err := Add(context.Background(), dir, "loupe", ScopeUser); err != nil {
		t.Fatalf("Add: %v", err)
	}

	argv, err := os.ReadFile(log)
	if err != nil {
		t.Fatalf("read argv: %v", err)
	}
	if !strings.Contains(string(argv), "mcp add --scope user loupe -- loupe mcp") {
		t.Fatalf("argv: got %q", argv)
	}
}

// `claude mcp add` refuses a name that already exists, says so, and exits 0. An
// Add that trusts the exit status therefore reports success for a call that
// changed nothing, and the person restarts their agent for no reason.
func TestAddFailsWhenClaudeCodeReportsSuccessAndChangesNothing(t *testing.T) {
	dir, _ := project(t)
	configure(t, `{"mcpServers":{"loupe":{"url":"https://loupe.ac/mcp"}}}`)
	fakeClaude(t, "echo 'MCP server loupe already exists in user config'\nexit 0")

	err := Add(context.Background(), dir, "loupe", ScopeUser)
	if err == nil {
		t.Fatal("Add over a refused call: got no error")
	}
	if !strings.Contains(err.Error(), "still starts") {
		t.Fatalf("error must say the server was not changed, got %v", err)
	}
}

func TestRemoveAsksClaudeCodeForTheScopeItFoundTheEntryIn(t *testing.T) {
	dir, resolved := project(t)
	cfg := configure(t, `{"projects":{"`+resolved+`":{"mcpServers":{"loupe":{"url":"https://loupe.ac/mcp"}}}}}`)
	log := fakeClaude(t, "echo '{}' > "+filepath.Join(cfg, configName))

	if err := Remove(context.Background(), dir, "loupe", ScopeLocal); err != nil {
		t.Fatalf("Remove: %v", err)
	}

	argv, err := os.ReadFile(log)
	if err != nil {
		t.Fatalf("read argv: %v", err)
	}
	if !strings.Contains(string(argv), "mcp remove loupe -s local") {
		t.Fatalf("argv: got %q", argv)
	}
}

func TestRemoveFailsWhenTheEntryIsStillThere(t *testing.T) {
	dir, resolved := project(t)
	configure(t, `{"projects":{"`+resolved+`":{"mcpServers":{"loupe":{"url":"https://loupe.ac/mcp"}}}}}`)
	fakeClaude(t, "exit 0")

	if err := Remove(context.Background(), dir, "loupe", ScopeLocal); err == nil {
		t.Fatal("Remove that changed nothing: got no error")
	}
}

func TestClaudeThatIsNotInstalledIsReportedAsSuch(t *testing.T) {
	t.Setenv("PATH", t.TempDir())
	t.Setenv("CLAUDE_CONFIG_DIR", t.TempDir())

	if err := Add(context.Background(), t.TempDir(), "loupe", ScopeUser); err != ErrNoClaude {
		t.Fatalf("Add with no claude on PATH: got %v, want ErrNoClaude", err)
	}
	if err := Remove(context.Background(), t.TempDir(), "loupe", ScopeLocal); err != ErrNoClaude {
		t.Fatalf("Remove with no claude on PATH: got %v, want ErrNoClaude", err)
	}
}

func TestTheCommandsNameTheScope(t *testing.T) {
	if got := AddCommand("loupe", ScopeUser); got != "claude mcp add --scope user loupe -- loupe mcp" {
		t.Fatalf("AddCommand: got %q", got)
	}
	if got := RemoveCommand("loupe", ScopeLocal); got != "claude mcp remove loupe -s local" {
		t.Fatalf("RemoveCommand(local): got %q", got)
	}
	if got := RemoveCommand("loupe", ScopeUser); got != "claude mcp remove loupe -s user" {
		t.Fatalf("RemoveCommand(user): got %q", got)
	}
}
