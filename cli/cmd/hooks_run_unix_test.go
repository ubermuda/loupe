//go:build unix

package cmd

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/config"
)

func TestHooksRunRunsOneHookWithTheBridgeEnvironment(t *testing.T) {
	_, path := hooksEnv(t)
	gh := newFakeGitHub(t)
	gh.script = "#!/bin/sh\necho \"event=$LOUPE_HOOK_EVENT package=$LOUPE_HOOK_PACKAGE arg=$1\"\n" +
		"echo \"loud=$LOUPE_HOOK_SETTING_LOUD bridge=$LOUPE_BRIDGE_ID\"\necho oops >&2\n"
	installTool(t, path)
	if _, err := runHooks(t, "", "set", "acme/tool", "loud=true", "--rules", path); err != nil {
		t.Fatal(err)
	}
	dir, err := config.Dir()
	if err != nil {
		t.Fatal(err)
	}
	id := "0b7c2f7e-3c2a-4f6b-9a51-6f0f3c1d2e4a"
	if err := os.WriteFile(filepath.Join(dir, "config.json"), []byte(`{"bridgeId":"`+id+`"}`), 0o600); err != nil {
		t.Fatal(err)
	}

	out, err := runHooks(t, "", "run", "acme/tool", "start", "--rules", path)
	if err != nil {
		t.Fatalf("err = %v\n%s", err, out)
	}
	for _, want := range []string{"event=start package=acme/tool arg=start", "loud=true bridge=" + id, "oops", "The start hook of acme/tool ran."} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
}

func TestHooksRunReportsAFailure(t *testing.T) {
	_, path := hooksEnv(t)
	gh := newFakeGitHub(t)
	gh.script = "#!/bin/sh\necho denied\nexit 3\n"
	installTool(t, path)

	out, err := runHooks(t, "", "run", "acme/tool", "start", "--rules", path)
	if err == nil || !strings.Contains(err.Error(), "status 3") {
		t.Fatalf("err = %v", err)
	}
	if !strings.Contains(out, "denied") {
		t.Fatalf("output = %s", out)
	}
}

func TestHooksRunRefusesAnEventThePackageLacks(t *testing.T) {
	_, path := hooksEnv(t)
	newFakeGitHub(t)
	installTool(t, path)

	_, err := runHooks(t, "", "run", "acme/tool", "busy", "--rules", path)
	if err == nil || !strings.Contains(err.Error(), "start, idle") {
		t.Fatalf("err = %v", err)
	}
	if _, err := runHooks(t, "", "run", "acme/other", "start", "--rules", path); err == nil || !strings.Contains(err.Error(), "is not installed") {
		t.Fatalf("err = %v", err)
	}
}
