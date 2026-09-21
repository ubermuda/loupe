// Package claudecode reads what Claude Code has configured for a directory, and
// asks Claude Code to change it.
//
// Claude Code resolves a server name across three scopes, and a local-scope
// entry wins over the .mcp.json in the repository. So a .mcp.json this CLI
// writes has no effect while a local entry of the same name exists, and nothing
// says so: the agent starts, connects to the other server, and looks correct.
//
// This package reads the configuration file to find that entry. It never writes
// it. The file holds every project's configuration and other services'
// credentials, Claude Code rewrites it while it runs, and it keeps its own
// backups beside it. A read-modify-write from here would race that. Removal
// therefore runs `claude mcp remove`, so Claude Code edits its own file.
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
)

// configName is the file, in the home directory or in CLAUDE_CONFIG_DIR.
const configName = ".claude.json"

// maxSize caps the file the reader buffers. The file grows with history, so the
// cap is generous, and it exists to bound a pathological read rather than to
// judge the file.
const maxSize = 128 << 20

// ErrNoClaude says the claude command is not on PATH, so this CLI cannot ask
// Claude Code to change anything.
var ErrNoClaude = errors.New("claude is not on PATH")

// Shadow is a Claude Code server entry that hides the .mcp.json entry of the
// same name.
type Shadow struct {
	// Summary names what the entry starts or connects to, for showing to the
	// person who has to decide about it. It carries no header and no token,
	// because the entry commonly holds an Authorization header.
	Summary string
	// Path is the project path Claude Code filed the entry under, which is the
	// working directory with its symbolic links resolved.
	Path string
}

// entry is the part of a server declaration worth showing. Claude Code writes
// several shapes, and a key this package does not name is left unread.
type entry struct {
	Type    string   `json:"type"`
	URL     string   `json:"url"`
	Command string   `json:"command"`
	Args    []string `json:"args"`
}

// summary says what the entry points at, and never what it authenticates with.
func (e entry) summary() string {
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

// Shadowing reports the local-scope entry named server that hides dir's
// .mcp.json, or nil when there is none. A missing configuration file is not an
// error, because Claude Code may not be installed at all.
func Shadowing(dir, server string) (*Shadow, error) {
	path, err := configPath()
	if err != nil {
		return nil, err
	}

	// Claude Code files a project under its resolved path, so /tmp and
	// /private/tmp are one key and the unresolved one matches nothing.
	resolved, err := filepath.EvalSymlinks(dir)
	if err != nil {
		resolved = dir
	}

	raw, err := read(path)
	if err != nil || raw == nil {
		return nil, err
	}

	var doc struct {
		Projects map[string]struct {
			McpServers map[string]entry `json:"mcpServers"`
		} `json:"projects"`
	}
	if err := json.Unmarshal(raw, &doc); err != nil {
		return nil, fmt.Errorf("%s: %w", path, err)
	}

	found, ok := doc.Projects[resolved].McpServers[server]
	if !ok {
		return nil, nil
	}

	return &Shadow{Summary: found.summary(), Path: resolved}, nil
}

// Remove asks Claude Code to drop the local-scope entry named server for dir.
// Claude Code edits its own file, so nothing here races the writes it makes
// while it runs.
func Remove(ctx context.Context, dir, server string) error {
	binary, err := exec.LookPath("claude")
	if err != nil {
		return ErrNoClaude
	}

	cmd := exec.CommandContext(ctx, binary, "mcp", "remove", server, "-s", "local")
	cmd.Dir = dir
	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("%s: %w", strings.TrimSpace(string(out)), err)
	}

	return nil
}

// RemoveCommand is what somebody runs by hand to do what Remove does.
func RemoveCommand(server string) string {
	return "claude mcp remove " + server + " -s local"
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
