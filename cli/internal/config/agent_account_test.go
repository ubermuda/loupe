package config

import (
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/zalando/go-keyring"
)

func TestSetAgentAccountWorksBeforeLogin(t *testing.T) {
	useTempConfigHome(t)

	if err := SetAgentAccount(&AgentAccount{Token: "ghp_x", Login: "bot", ID: 7}); err != nil {
		t.Fatalf("SetAgentAccount: %v", err)
	}
	got, err := LoadAgentAccount()
	if err != nil {
		t.Fatalf("LoadAgentAccount: %v", err)
	}
	if got == nil || *got != (AgentAccount{Token: "ghp_x", Login: "bot", ID: 7}) {
		t.Fatalf("LoadAgentAccount = %+v", got)
	}

	d, _ := Dir()
	info, err := os.Stat(filepath.Join(d, configFileName))
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Fatalf("config mode = %v, want 0600", info.Mode().Perm())
	}
}

func TestSetAgentAccountKeepsTheLogin(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)
	if err := Save(oauthLogin("access-1", "refresh-1", time.Now().Add(time.Hour))); err != nil {
		t.Fatal(err)
	}

	if err := SetAgentAccount(&AgentAccount{Token: "ghp_x", Login: "bot", ID: 7}); err != nil {
		t.Fatal(err)
	}
	got, err := Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if got.OAuth.RefreshToken != "refresh-1" || got.AgentAccount == nil || got.AgentAccount.Login != "bot" {
		t.Fatalf("Load = %+v", got)
	}

	if err := SetAgentAccount(nil); err != nil {
		t.Fatal(err)
	}
	if got, err := Load(); err != nil || got.AgentAccount != nil {
		t.Fatalf("after clear, Load = %+v, %v", got, err)
	}
}

// `loupe login` saves a config that knows nothing about the agent account,
// and must not drop it.
func TestSaveKeepsTheAgentAccount(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)
	if err := SetAgentAccount(&AgentAccount{Token: "ghp_x", Login: "bot", ID: 7}); err != nil {
		t.Fatal(err)
	}

	if err := Save(oauthLogin("access-1", "refresh-1", time.Now().Add(time.Hour))); err != nil {
		t.Fatal(err)
	}
	got, err := LoadAgentAccount()
	if err != nil || got == nil || got.Token != "ghp_x" {
		t.Fatalf("LoadAgentAccount = %+v, %v", got, err)
	}
}

func TestLoadAgentAccountWithNoFile(t *testing.T) {
	useTempConfigHome(t)

	if got, err := LoadAgentAccount(); err != nil || got != nil {
		t.Fatalf("LoadAgentAccount = %+v, %v", got, err)
	}
}
