package rules

import (
	"path/filepath"
	"reflect"
	"strings"
	"testing"
)

const codexAccount = "accounts:\n  cdx:\n    harness: codex\n    codexHome: ~/.codex-b\n    profile: openrouter\n    model: openrouter/free\n"

const codexDefaults = "defaults:\n  account: cdx\n"

// A codex account hands its home folder and profile to the run, and each
// permission level maps to a Codex sandbox mode.
func TestParseResolvesACodexAccount(t *testing.T) {
	home := t.TempDir()
	t.Setenv("HOME", home)
	body := codexAccount + "  claude:\n    harness: claude-code\n    model: opus\n" + codexDefaults + oneWork +
		"  ro:\n    prompt: x\n    permissions: read-only\n" +
		"  ws:\n    prompt: x\n    permissions: workspace\n" +
		"  full:\n    prompt: x\n    permissions: full\n" +
		"  other:\n    prompt: x\n    account: claude\n    permissions: full\n"
	text, _ := file(t, body)
	s, err := Parse([]byte(text), Defaults{PermissionMode: "dontAsk", Model: "haiku"})
	if err != nil {
		t.Fatal(err)
	}

	for kind, mode := range map[string]string{"ro": "read-only", "ws": "workspace-write", "full": "danger-full-access"} {
		want := RunSettings{
			Account: "cdx", Harness: HarnessCodex, ConfigDir: filepath.Join(home, ".codex-b"), Profile: "openrouter",
			Model: "openrouter/free", PermissionMode: mode, Permissions: map[string]string{"ro": PermissionsReadOnly, "ws": PermissionsWorkspace, "full": PermissionsFull}[kind],
		}
		if got := entry(t, s, kind).run; !reflect.DeepEqual(got, want) {
			t.Fatalf("%s run = %+v, want %+v", kind, got, want)
		}
	}
	// The bridge flags name Claude Code values, so a codex run takes none.
	if got := entry(t, s, "implement").run; got.PermissionMode != "" || got.Model != "openrouter/free" {
		t.Fatalf("implement run = %+v", got)
	}
	if got := entry(t, s, "other").run; got.Harness != HarnessClaudeCode || got.PermissionMode != "bypassPermissions" || got.Profile != "" {
		t.Fatalf("other run = %+v", got)
	}
}

func TestAMatchCarriesTheProfileOfACodexRun(t *testing.T) {
	t.Setenv("HOME", t.TempDir())
	s := parse(t, codexAccount+codexDefaults+oneWork)
	m := Match{}.withRun(entry(t, s, "implement").run)
	if m.Profile != "openrouter" || m.Run().Profile != "openrouter" {
		t.Fatalf("match = %+v", m)
	}
}

func TestACodexAccountPermissionModeIsASandboxMode(t *testing.T) {
	t.Setenv("HOME", t.TempDir())
	s := parse(t, codexAccount+"    permissionMode: read-only\n"+codexDefaults+"  permissions: full\n"+oneWork+"  keep:\n    prompt: x\n    permissions: workspace\n")
	// The level of an entry beats the mode of the account, and the account's
	// mode beats the defaults level.
	if got := entry(t, s, "keep").run.PermissionMode; got != "workspace-write" {
		t.Fatalf("keep = %q", got)
	}
	if got := entry(t, s, "implement").run.PermissionMode; got != "read-only" {
		t.Fatalf("implement = %q", got)
	}
	// Claude's list of modes does not cover a sandbox mode.
	if got := s.UnknownPermissionModes(); len(got) != 0 {
		t.Fatalf("UnknownPermissionModes = %v", got)
	}
}

// An interactive entry on a codex account loads, and runs with the sandbox mode
// of its own level alone.
func TestParseAcceptsAnInteractiveEntryOnACodexAccount(t *testing.T) {
	t.Setenv("HOME", t.TempDir())
	body := codexAccount + codexDefaults + "projects:\n  loupe:\n    dir: {dir}\nlaunch:\n  command: ['{script}']\nwork:\n  pair:\n    action: interactive\n    prompt: x\n"
	text, _ := file(t, body)
	s, err := Parse([]byte(text), Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	if got := entry(t, s, "pair").run; got.Harness != HarnessCodex || got.Account != "cdx" {
		t.Fatalf("pair run = %+v", got)
	}
}

func TestParseRefusesAnInvalidCodexAccount(t *testing.T) {
	codex := func(fields string) string {
		return "accounts:\n  cdx:\n    harness: codex\n" + fields + codexDefaults + oneWork
	}
	claude := func(fields string) string {
		return "accounts:\n  claude:\n    harness: claude-code\n" + fields + claudeDefaults + oneWork
	}
	for name, tc := range map[string]struct {
		body string
		want string
	}{
		"configDir on codex":       {codex("    configDir: /tmp/x\n"), "line 4: accounts.cdx.configDir: a codex account takes codexHome, and configDir is for claude-code"},
		"codexHome on claude":      {claude("    codexHome: /tmp/x\n"), "line 4: accounts.claude.codexHome: only a codex account takes codexHome"},
		"profile on claude":        {claude("    profile: x\n"), "line 4: accounts.claude.profile: only a codex account takes profile"},
		"a relative codexHome":     {codex("    codexHome: codex-a\n"), "line 4: accounts.cdx.codexHome codex-a is not an absolute path"},
		"a path as profile":        {codex("    profile: ../x\n"), `line 4: accounts.cdx.profile "../x" is not 1 to 64 letters`},
		"a profile with a space":   {codex("    profile: 'a b'\n"), `accounts.cdx.profile "a b" is not 1 to 64 letters`},
		"a claude mode":            {codex("    permissionMode: plan\n"), `line 4: accounts.cdx.permissionMode "plan" is not read-only, workspace-write, danger-full-access`},
		"an unknown harness named": {"accounts:\n  a:\n    harness: gemini\n" + codexDefaults + oneWork, "this CLI accepts claude-code and codex"},
	} {
		t.Run(name, func(t *testing.T) {
			text, _ := file(t, tc.body)
			_, err := Parse([]byte(text), Defaults{})
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want it to contain %q", err, tc.want)
			}
		})
	}
}
