package cmd

import (
	"bytes"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/zalando/go-keyring"
)

func useLoginConfigHome(t *testing.T) {
	t.Helper()
	keyring.MockInit()
	dir := t.TempDir()
	t.Setenv("XDG_CONFIG_HOME", dir)
	t.Setenv("HOME", dir)
	t.Setenv("LOUPE_TOKEN", "")
}

func TestLoginWithNoTokenRunsTheDeviceFlow(t *testing.T) {
	useLoginConfigHome(t)
	var apiAuth string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		switch r.URL.Path {
		case "/oauth/device-authorization":
			fmt.Fprint(w, `{"device_code":"dev-1","user_code":"BCDFGHJK","verification_uri":"https://loupe.test/oauth/device","verification_uri_complete":"https://loupe.test/oauth/device?user_code=BCDFGHJK","expires_in":600,"interval":1}`)
		case "/oauth/token":
			fmt.Fprint(w, `{"token_type":"Bearer","access_token":"eyJ.access","refresh_token":"refresh-1","expires_in":3600}`)
		case "/api/projects":
			apiAuth = r.Header.Get("Authorization")
			fmt.Fprint(w, `{"sites":[]}`)
		default:
			t.Errorf("unexpected path %s", r.URL.Path)
		}
	}))
	t.Cleanup(server.Close)

	var out bytes.Buffer
	cmd := newLoginCmd()
	cmd.SetOut(&out)
	cmd.SetArgs([]string{"--url", server.URL + "/"})
	if err := cmd.Execute(); err != nil {
		t.Fatalf("login: %v", err)
	}

	printed := out.String()
	if !strings.Contains(printed, "https://loupe.test/oauth/device?user_code=BCDFGHJK") || !strings.Contains(printed, "BCDF-GHJK") {
		t.Fatalf("login printed %q, want the page and the code", printed)
	}
	for _, secret := range []string{"eyJ.access", "refresh-1", "dev-1"} {
		if strings.Contains(printed, secret) {
			t.Fatalf("login printed the secret %q", secret)
		}
	}
	if apiAuth != "Bearer eyJ.access" {
		t.Fatalf("the check call sent %q", apiAuth)
	}

	stored, err := config.Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if stored.BaseURL != server.URL || stored.OAuth == nil || stored.OAuth.RefreshToken != "refresh-1" {
		t.Fatalf("stored %+v", stored)
	}
}

func TestLoginWithATokenKeepsTheStaticPath(t *testing.T) {
	useLoginConfigHome(t)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/projects" {
			t.Errorf("unexpected path %s: a token login must not start the device flow", r.URL.Path)
		}
		fmt.Fprint(w, `{"sites":[]}`)
	}))
	t.Cleanup(server.Close)
	t.Setenv("LOUPE_TOKEN", "sk-static")

	cmd := newLoginCmd()
	cmd.SetOut(&bytes.Buffer{})
	cmd.SetArgs([]string{"--url", server.URL})
	if err := cmd.Execute(); err != nil {
		t.Fatalf("login: %v", err)
	}

	stored, err := config.Load()
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if stored.Token != "sk-static" || stored.OAuth != nil {
		t.Fatalf("stored %+v", stored)
	}
}

func TestLoginDefaultsToTheHostedInstance(t *testing.T) {
	useLoginConfigHome(t)

	cmd := newLoginCmd()
	flag := cmd.Flags().Lookup("url")
	if flag == nil {
		t.Fatal("login has no --url flag")
	}
	// Empty, so the precedence below can tell "not given" from a value.
	if flag.DefValue != "" {
		t.Fatalf("--url default: got %q, want the empty string", flag.DefValue)
	}
	if !strings.Contains(flag.Usage, DefaultBaseURL) {
		t.Fatalf("--url help must name the default, got %q", flag.Usage)
	}
	if DefaultBaseURL != "https://loupe.ac" {
		t.Fatalf("DefaultBaseURL: got %q, want the hosted instance", DefaultBaseURL)
	}
}

func TestTheBaseUrlPrefersTheFlagThenTheEnvThenTheHostedInstance(t *testing.T) {
	for name, c := range map[string]struct {
		flag, env, want string
	}{
		"the flag wins":              {flag: "https://flag.example", env: "https://env.example", want: "https://flag.example"},
		"the env when no flag":       {env: "https://env.example", want: "https://env.example"},
		"the default when neither":   {want: DefaultBaseURL},
		"a blank flag is not a URL":  {flag: "   ", env: "https://env.example", want: "https://env.example"},
		"a blank env is not one out": {want: DefaultBaseURL, env: "  "},
		"a trailing slash goes":      {flag: "https://flag.example/", want: "https://flag.example"},
	} {
		t.Run(name, func(t *testing.T) {
			if got := resolveBaseURL(c.flag, c.env); got != c.want {
				t.Fatalf("resolveBaseURL(%q, %q): got %q, want %q", c.flag, c.env, got, c.want)
			}
		})
	}
}
