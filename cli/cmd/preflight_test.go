package cmd

import (
	"bytes"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/zalando/go-keyring"

	"github.com/ubermuda/loupe/cli/internal/config"
)

// preflightHome logs in against a fake Loupe in a config directory short
// enough for a socket path.
func preflightHome(t *testing.T) *fakeLoupe {
	t.Helper()
	keyring.MockInit()
	shortConfigHome(t)
	fake := &fakeLoupe{}
	server := httptest.NewServer(http.HandlerFunc(fake.serve))
	t.Cleanup(server.Close)
	if err := config.Save(testLogin(server.URL)); err != nil {
		t.Fatal(err)
	}

	return fake
}

func runPreflight(t *testing.T, args ...string) error {
	t.Helper()
	cmd := newBridgeCmd()
	cmd.SetArgs(append([]string{"preflight"}, args...))
	cmd.SetOut(&bytes.Buffer{})
	cmd.SetErr(&bytes.Buffer{})
	cmd.SilenceUsage, cmd.SilenceErrors = true, true

	return cmd.Execute()
}

// A binary passes its preflight when it reads the rule file and the handover
// file, and reaches the server. It takes no lock and opens no socket, because
// the running bridge holds both.
func TestPreflightPassesWithGoodRules(t *testing.T) {
	fake := preflightHome(t)
	path := writeRules(t, "loupe")
	lock, err := lockBridge(path, "sock")
	if err != nil {
		t.Fatal(err)
	}
	defer lock.Close()
	probe := filepath.Join(t.TempDir(), "handover.json")
	if err := writeHandover(probe, handoverState{Format: handoverFormat}); err != nil {
		t.Fatal(err)
	}

	if err := runPreflight(t, "--rules", path, "--handover", probe); err != nil {
		t.Fatal(err)
	}
	fake.mu.Lock()
	defer fake.mu.Unlock()
	if fake.eventsCalls != 1 {
		t.Fatalf("GET /api/events ran %d times", fake.eventsCalls)
	}
}

func TestPreflightFailsOnBadRules(t *testing.T) {
	preflightHome(t)
	path := filepath.Join(t.TempDir(), "rules.yaml")
	if err := os.WriteFile(path, []byte("projects: {}\nrules: []\nsite: loupe\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	if err := runPreflight(t, "--rules", path); err == nil {
		t.Fatal("preflight passed a bad rule file")
	}
}

func TestPreflightFailsOnAHandoverFormatItCannotRead(t *testing.T) {
	preflightHome(t)
	probe := filepath.Join(t.TempDir(), "handover.json")
	if err := os.WriteFile(probe, []byte(`{"format":99}`), 0o600); err != nil {
		t.Fatal(err)
	}

	err := runPreflight(t, "--rules", writeRules(t, "loupe"), "--handover", probe)
	if err == nil || !strings.Contains(err.Error(), "format 99") {
		t.Fatalf("err = %v", err)
	}
}

// A mapped project the server no longer lists fails the preflight, as it
// fails the start.
func TestPreflightFailsWhenTheServerDoesNotListAProject(t *testing.T) {
	preflightHome(t)

	err := runPreflight(t, "--rules", writeRules(t, "loupe", "unknown"))
	if err == nil {
		t.Fatal("preflight passed a project the server does not know")
	}
}

func TestPreflightIsHidden(t *testing.T) {
	if cmd := newBridgePreflightCmd(); !cmd.Hidden {
		t.Fatal("preflight is listed in the help")
	}
}
