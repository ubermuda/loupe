// Package claudecode works out how Claude Code starts an MCP server for a
// directory, and asks Claude Code to change it.
//
// Claude Code resolves one server name across three scopes. A local entry wins,
// then a user entry, and the repository's .mcp.json is the lowest of the three.
// So a correct .mcp.json has no effect while an entry of the same name exists
// above it, and nothing says so: the agent starts, connects to the other
// server, and looks correct.
//
// A user entry is enough for every repository. Claude Code starts a stdio
// server with the repository as its working directory, and `loupe mcp` reads
// .loupe.yaml from there, so one entry serves every project.
//
// This package never writes Claude Code's configuration file. That file holds
// every project's configuration and other services' credentials, Claude Code
// rewrites it while it runs, and it keeps its own backups beside it. A
// read-modify-write from here would race that. So it reads the file, and every
// change runs `claude mcp`, which lets Claude Code edit its own file.
package claudecode

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"

	"github.com/ubermuda/loupe/cli/internal/mcpjson"
)

// configName is the file, in the home directory or in CLAUDE_CONFIG_DIR.
const configName = ".claude.json"

// maxSize caps the file the reader buffers. The file grows with history, so the
// cap is generous, and it bounds a pathological read rather than judging the
// file.
const maxSize = 128 << 20

// ErrNoClaude says the claude command is not on PATH, so this CLI cannot ask
// Claude Code to change anything.
var ErrNoClaude = errors.New("claude is not on PATH")

// Scope is where Claude Code keeps an entry, and what `claude mcp` takes. The
// order here is the order Claude Code resolves them in.
type Scope string

const (
	// ScopeLocal is private to one project, and wins over every other scope.
	ScopeLocal Scope = "local"
	// ScopeUser applies to every project, and wins over the repository's file.
	ScopeUser Scope = "user"
	// ScopeProject is the repository's .mcp.json, which every other scope beats.
	ScopeProject Scope = "project"
)

// Entry is the part of a server declaration worth reading. Claude Code writes
// several shapes, and a key this package does not name is left unread.
type Entry struct {
	Type    string   `json:"type"`
	URL     string   `json:"url"`
	Command string   `json:"command"`
	Args    []string `json:"args"`
}

// Summary says what the entry points at, and never what it authenticates with,
// because an entry commonly carries an Authorization header.
func (e Entry) Summary() string {
	if e.URL != "" {
		return e.URL
	}
	if e.Command != "" {
		return strings.TrimSpace(e.Command + " " + strings.Join(e.Args, " "))
	}
	if e.Type != "" {
		return e.Type
	}

	return "another server"
}

// StartsMcpShim reports whether the entry starts `loupe mcp`. The command may be
// an absolute path, so only its last element is compared, and the type and the
// environment are left out because `claude mcp add` fills them in.
func (e Entry) StartsMcpShim() bool {
	if filepath.Base(e.Command) != "loupe" {
		return false
	}

	return 1 == len(e.Args) && "mcp" == e.Args[0]
}

// Resolution is the entry Claude Code uses for a server name in a directory.
type Resolution struct {
	// Scope is empty when no scope declares the server at all.
	Scope Scope
	Entry Entry
	// Path is the project path a local entry is filed under, which is the
	// working directory with its symbolic links resolved.
	Path string
}

// Declared reports whether any scope declares the server.
func (r Resolution) Declared() bool {
	return r.Scope != ""
}

// Correct reports whether the server Claude Code would start is `loupe mcp`.
func (r Resolution) Correct() bool {
	return r.Declared() && r.Entry.StartsMcpShim()
}

// Where names what the entry covers, for showing to a person.
func (r Resolution) Where() string {
	switch r.Scope {
	case ScopeUser:
		return "every project"
	case ScopeProject:
		return mcpjson.Name + " in this repository"
	default:
		return r.Path
	}
}

// Effective reports the entry Claude Code resolves for server in dir, across
// all three scopes. An undeclared server is not an error: Claude Code may not
// be installed at all.
func Effective(dir, server string) (Resolution, error) {
	path, err := configPath()
	if err != nil {
		return Resolution{}, err
	}

	// Claude Code files a project under its resolved path, so /tmp and
	// /private/tmp are one key and the unresolved one matches nothing.
	resolved, err := filepath.EvalSymlinks(dir)
	if err != nil {
		resolved = dir
	}

	raw, err := read(path)
	if err != nil {
		return Resolution{}, err
	}

	var doc struct {
		McpServers map[string]Entry `json:"mcpServers"`
		Projects   map[string]struct {
			McpServers map[string]Entry `json:"mcpServers"`
		} `json:"projects"`
	}
	if raw != nil {
		if err := json.Unmarshal(raw, &doc); err != nil {
			return Resolution{}, fmt.Errorf("%s: %w", path, err)
		}
	}

	if found, ok := doc.Projects[resolved].McpServers[server]; ok {
		return Resolution{Scope: ScopeLocal, Entry: found, Path: resolved}, nil
	}
	if found, ok := doc.McpServers[server]; ok {
		return Resolution{Scope: ScopeUser, Entry: found}, nil
	}

	return fromRepository(dir, server)
}

// fromRepository reads the lowest scope, the repository's own file.
func fromRepository(dir, server string) (Resolution, error) {
	state, entry, err := mcpjson.Read(dir)
	if err != nil {
		return Resolution{}, err
	}
	if mcpjson.Absent == state {
		return Resolution{}, nil
	}

	return Resolution{Scope: ScopeProject, Entry: Entry{Command: entry.Command, Args: entry.Args}}, nil
}

// Add asks Claude Code to declare server as `loupe mcp` in scope, and confirms
// it afterwards.
//
// The confirmation is the point. `claude mcp add` refuses a name that already
// exists, says so, and exits 0, so the exit status alone reports success for a
// call that changed nothing.
func Add(ctx context.Context, dir, server string, scope Scope) error {
	if _, err := run(ctx, dir, "add", "--scope", string(scope), server, "--", "loupe", "mcp"); err != nil {
		return err
	}

	got, err := Effective(dir, server)
	if err != nil {
		return err
	}
	if !got.Correct() {
		return fmt.Errorf("%s still starts %s", server, got.Entry.Summary())
	}

	return nil
}

// Remove asks Claude Code to drop server from scope, and confirms that the
// scope no longer declares it.
func Remove(ctx context.Context, dir, server string, scope Scope) error {
	if _, err := run(ctx, dir, "remove", server, "-s", string(scope)); err != nil {
		return err
	}

	got, err := Effective(dir, server)
	if err != nil {
		return err
	}
	if got.Declared() && got.Scope == scope {
		return fmt.Errorf("%s is still declared in %s scope", server, scope)
	}

	return nil
}

// AddCommand and RemoveCommand are what somebody runs by hand to do what Add
// and Remove do.
func AddCommand(server string, scope Scope) string {
	return "claude mcp add --scope " + string(scope) + " " + server + " -- loupe mcp"
}

func RemoveCommand(server string, scope Scope) string {
	return "claude mcp remove " + server + " -s " + string(scope)
}

// run invokes the claude command with dir as its working directory, which is
// what selects the project a local-scope change applies to.
func run(ctx context.Context, dir string, args ...string) (string, error) {
	binary, err := exec.LookPath("claude")
	if err != nil {
		return "", ErrNoClaude
	}

	cmd := exec.CommandContext(ctx, binary, append([]string{"mcp"}, args...)...)
	cmd.Dir = dir
	out, err := cmd.CombinedOutput()
	if err != nil {
		return "", fmt.Errorf("%s: %w", strings.TrimSpace(string(out)), err)
	}

	return string(out), nil
}

// configPath is CLAUDE_CONFIG_DIR's file when that is set, and the one in the
// home directory otherwise.
func configPath() (string, error) {
	if dir := strings.TrimSpace(os.Getenv("CLAUDE_CONFIG_DIR")); dir != "" {
		return filepath.Join(dir, configName), nil
	}

	home, err := os.UserHomeDir()
	if err != nil {
		return "", err
	}

	return filepath.Join(home, configName), nil
}

// read gives the file, or nil when there is none.
func read(path string) ([]byte, error) {
	info, err := os.Stat(path)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, fmt.Errorf("%s: %w", path, err)
	}
	if info.Size() > maxSize {
		return nil, fmt.Errorf("%s: larger than %d bytes", path, maxSize)
	}

	b, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("%s: %w", path, err)
	}

	return b, nil
}
