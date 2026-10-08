package cmd

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	harn "github.com/ubermuda/loupe/cli/internal/harness"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// loggedInClaude puts on PATH a claude that is logged in only for a config
// folder that holds a file named in, and lists no plugin.
func loggedInClaude(t *testing.T) {
	t.Helper()
	dir := t.TempDir()
	body := "#!/bin/sh\ncase \"$1\" in\nauth) [ -f \"$CLAUDE_CONFIG_DIR/in\" ] ;;\nplugin) echo '[]' ;;\nesac\n"
	if err := os.WriteFile(filepath.Join(dir, "claude"), []byte(body), 0o700); err != nil {
		t.Fatal(err)
	}
	t.Setenv("PATH", dir+string(os.PathListSeparator)+os.Getenv("PATH"))
	t.Setenv("CLAUDE_CONFIG_DIR", t.TempDir())
	t.Setenv("HOME", t.TempDir())
}

// readyProject is a project folder that declares the loupe MCP server and
// holds the Loupe skills.
func readyProject(t *testing.T) string {
	t.Helper()
	dir := t.TempDir()
	if err := os.MkdirAll(filepath.Join(dir, ".claude", "skills", "loupe-board"), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, ".mcp.json"), []byte(`{"mcpServers":{"loupe":{"command":"loupe","args":["mcp"]}}}`), 0o600); err != nil {
		t.Fatal(err)
	}

	return dir
}

// accountSet has three accounts: broken reads an env file that is not there,
// out is logged out, and in is ready.
func accountSet(t *testing.T) (*rules.Set, string) {
	t.Helper()
	in, out := t.TempDir(), t.TempDir()
	if err := os.WriteFile(filepath.Join(in, "in"), nil, 0o600); err != nil {
		t.Fatal(err)
	}
	missing := filepath.Join(t.TempDir(), "secret.env")
	body := "accounts:\n" +
		"  broken:\n    harness: claude-code\n    envFile: " + missing + "\n" +
		"  out:\n    harness: claude-code\n    configDir: " + out + "\n" +
		"  in:\n    harness: claude-code\n    configDir: " + in + "\n" +
		"defaults:\n  account: in\nprojects:\n  loupe:\n    dir: " + readyProject(t) + "\nwork:\n" +
		"  plan:\n    prompt: go\n  fix:\n    prompt: go\n    account: broken\n  review:\n    prompt: go\n    account: out\n"
	set, err := rules.Parse([]byte(body), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}

	return set, missing
}

func TestCheckAccountsChecksEachUsedAccount(t *testing.T) {
	loggedInClaude(t)
	set, missing := accountSet(t)

	got := checkAccounts(context.Background(), set)
	if names := []string{got[0].name, got[1].name, got[2].name}; len(got) != 3 || !slices.Equal(names, []string{"broken", "in", "out"}) {
		t.Fatalf("checkAccounts = %+v", got)
	}
	if got[0].reason() != "env file does not read" || !strings.Contains(got[0].detail(), missing) {
		t.Fatalf("broken = %+v", got[0])
	}
	if got[1].reason() != "" || got[1].harness != "claude-code" {
		t.Fatalf("in = %+v", got[1])
	}
	if got[2].reason() != "not logged in" || !strings.Contains(got[2].detail(), "claude auth login") {
		t.Fatalf("out = %+v", got[2])
	}
	if off := accountsOff(got); len(off) != 2 || off["broken"] == "" || off["out"] == "" {
		t.Fatalf("accountsOff = %v", off)
	}
}

// The heartbeat carries a row for each used account, and no row names a path,
// so neither a folder nor an env file leaves the machine.
func TestTheHeartbeatReportsEachAccountWithNoPath(t *testing.T) {
	loggedInClaude(t)
	set, _ := accountSet(t)
	if hb := heartbeatBody(set, ""); hb.Accounts != nil {
		t.Fatalf("an unchecked set reports accounts: %+v", hb.Accounts)
	}

	set.SetAccountProblems(accountsOff(checkAccounts(context.Background(), set)))
	hb := heartbeatBody(set, "")
	want := []api.AccountReport{
		{Name: "broken", Harness: "claude-code", State: api.AccountFailing, Reason: "env file does not read"},
		{Name: "in", Harness: "claude-code", State: api.AccountReady},
		{Name: "out", Harness: "claude-code", State: api.AccountFailing, Reason: "not logged in"},
	}
	if !slices.Equal(hb.Accounts, want) {
		t.Fatalf("Accounts = %+v, want %+v", hb.Accounts, want)
	}
	b, err := json.Marshal(hb.Accounts)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(b), "/") {
		t.Fatalf("the account rows name a path: %s", b)
	}
	if !strings.Contains(string(b), `{"name":"in","harness":"claude-code","state":"ready"}`) {
		t.Fatalf("a ready row = %s", b)
	}
}

// A checked set that uses no account sends an empty list, which clears the
// rows the server holds.
func TestACheckedSetOfCommandsSendsNoAccountRows(t *testing.T) {
	body := "accounts:\n  claude:\n    harness: claude-code\ndefaults:\n  account: claude\nprojects:\n  loupe:\n    dir: " + t.TempDir() +
		"\nwork:\n  test:\n    action: command\n    run: [make]\n"
	set, err := rules.Parse([]byte(body), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	set.SetAccountProblems(accountsOff(checkAccounts(context.Background(), set)))
	b, err := json.Marshal(heartbeatBody(set, ""))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(b), `"accounts":[]`) {
		t.Fatalf("heartbeat body = %s", b)
	}
}

func TestAReloadTurnsOffAFailingAccount(t *testing.T) {
	h := newHarness(t)
	client := &fakeHeartbeats{}
	hh := startHeartbeater(t, client, time.Minute)
	h.router.heartbeat = hh.h
	src := h.source(defaultRules)
	src.checkAccounts = func(_ context.Context, set *rules.Set) []accountResult {
		return []accountResult{{name: "claude", harness: "claude-code", problems: []harn.Problem{{Reason: "not logged in", Detail: "run `claude auth login`"}}}}
	}

	res := h.router.reload(context.Background(), src)
	if !res.OK || res.AccountsOff["claude"] != "not logged in" {
		t.Fatalf("result = %+v", res)
	}
	line := h.only(t, "account_failed")
	for key, want := range map[string]string{"account": "claude", "harness": "claude-code", "reason": "not logged in", "detail": "run `claude auth login`"} {
		if got := str(t, line, key); got != want {
			t.Fatalf("account_failed %s = %q, want %q", key, got, want)
		}
	}
	if m := h.router.rules().MatchWork(workRequest(1, 7, "plan", api.WorkRequestOpen)); m.Skip != rules.NoRule {
		t.Fatalf("match on a failing account = %+v", m)
	}
	// sent waits for the n-th heartbeat, and gives its account rows.
	sent := func(n int) []api.AccountReport {
		t.Helper()
		var rows []api.AccountReport
		eventually(t, "the heartbeat of the swap", func() bool {
			client.mu.Lock()
			defer client.mu.Unlock()
			if len(client.sent) < n {
				return false
			}
			rows = client.sent[n-1].Accounts

			return true
		})

		return rows
	}
	if rows := sent(1); rows != nil {
		t.Fatalf("the heartbeat before the reload has account rows %+v", rows)
	}
	if rows, want := sent(2), []api.AccountReport{{Name: "claude", Harness: "claude-code", State: api.AccountFailing, Reason: "not logged in"}}; !slices.Equal(rows, want) {
		t.Fatalf("the heartbeat of the swap has rows %+v, want %+v", rows, want)
	}

	// A later reload that finds the account ready sends the ready row.
	src.checkAccounts = func(context.Context, *rules.Set) []accountResult {
		return []accountResult{{name: "claude", harness: "claude-code"}}
	}
	if res := h.router.reload(context.Background(), src); !res.OK || res.AccountsOff != nil {
		t.Fatalf("result = %+v", res)
	}
	if rows, want := sent(3), []api.AccountReport{{Name: "claude", Harness: "claude-code", State: api.AccountReady}}; !slices.Equal(rows, want) {
		t.Fatalf("the heartbeat of the second swap has rows %+v, want %+v", rows, want)
	}
}

func TestALongReasonFitsTheServerLimit(t *testing.T) {
	long := strings.Repeat("loupe MCP server not declared for project p; ", 10)
	got := cutReason(long)
	if n := len([]rune(got)); n != maxAccountReason {
		t.Fatalf("cutReason gave %d characters, want %d", n, maxAccountReason)
	}
	if short := "not logged in"; cutReason(short) != short {
		t.Fatalf("cutReason changed a short reason to %q", cutReason(short))
	}
}

func TestTheHeartbeatSendsNoMoreAccountRowsThanTheServerTakes(t *testing.T) {
	var accounts, work strings.Builder
	for i := range maxAccountRows + 1 {
		fmt.Fprintf(&accounts, "  a%d:\n    harness: claude-code\n", i)
		fmt.Fprintf(&work, "  w%d:\n    prompt: go\n    account: a%d\n", i, i)
	}
	body := "accounts:\n" + accounts.String() + "defaults:\n  account: a0\nprojects:\n  loupe:\n    dir: " + t.TempDir() + "\nwork:\n" + work.String()
	set, err := rules.Parse([]byte(body), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	set.SetAccountProblems(map[string]string{})

	if got := len(accountReports(set)); got != maxAccountRows {
		t.Fatalf("accountReports gave %d rows, want %d", got, maxAccountRows)
	}
}
