package cmd

import (
	"bytes"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

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
