package cmd

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/hooks"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

var (
	shaOne = strings.Repeat("1", 40)
	shaTwo = strings.Repeat("2", 40)
)

const toolManifest = `name: tool
events:
  start: [./hook.sh, start]
  idle: [./hook.sh, idle, --quiet]
settings:
  loud:
    type: bool
    default: "false"
    description: Print more.
  label:
    type: string
    default: hi
    description: A label for the log.
`

// fakeHookRepo serves the ref and the tarball endpoints of acme/tool.
type fakeHookRepo struct {
	mu        sync.Mutex
	refs      map[string]string
	manifests map[string]string
	script    string
	downloads int
}

func newFakeHookRepo(t *testing.T) *fakeHookRepo {
	t.Helper()
	gh := &fakeHookRepo{
		refs:      map[string]string{"v1": shaOne},
		manifests: map[string]string{shaOne: toolManifest},
		script:    "#!/bin/sh\necho ran\n",
	}
	srv := httptest.NewServer(http.HandlerFunc(gh.serve))
	t.Cleanup(srv.Close)
	old := hookFetcher
	hookFetcher = &hooks.Fetcher{APIBase: srv.URL, CodeloadBase: srv.URL, Client: srv.Client()}
	t.Cleanup(func() { hookFetcher = old })

	return gh
}

func (gh *fakeHookRepo) serve(w http.ResponseWriter, r *http.Request) {
	gh.mu.Lock()
	defer gh.mu.Unlock()

	path := strings.ToLower(r.URL.Path)
	if ref, ok := strings.CutPrefix(path, "/repos/acme/tool/commits/"); ok {
		sha, found := gh.refs[ref]
		if !found {
			http.NotFound(w, r)

			return
		}
		_, _ = w.Write([]byte(sha))

		return
	}
	if sha, ok := strings.CutPrefix(path, "/acme/tool/tar.gz/"); ok {
		manifest, found := gh.manifests[sha]
		if !found {
			http.NotFound(w, r)

			return
		}
		gh.downloads++
		_, _ = w.Write(toolTarball(manifest, gh.script))

		return
	}
	http.NotFound(w, r)
}

func (gh *fakeHookRepo) downloaded() int {
	gh.mu.Lock()
	defer gh.mu.Unlock()

	return gh.downloads
}

func toolTarball(manifest, script string) []byte {
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	files := []struct {
		name, body string
		mode       int64
	}{
		{"tool-1111111/loupe-hook.yaml", manifest, 0o644},
		{"tool-1111111/hook.sh", script, 0o755},
	}
	_ = tw.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: "tool-1111111/", Mode: 0o755})
	for _, f := range files {
		_ = tw.WriteHeader(&tar.Header{Typeflag: tar.TypeReg, Name: f.name, Mode: f.mode, Size: int64(len(f.body))})
		_, _ = tw.Write([]byte(f.body))
	}
	_ = tw.Close()
	_ = gz.Close()

	return buf.Bytes()
}

// hooksEnv points the config directory at a temp dir, and writes a rule file
// that starts with a comment.
func hooksEnv(t *testing.T) (root, rulesPath string) {
	t.Helper()
	home := t.TempDir()
	t.Setenv("XDG_CONFIG_HOME", home)
	t.Setenv("HOME", home)
	root, err := config.Dir()
	if err != nil {
		t.Fatal(err)
	}
	rulesPath = writeRules(t, "loupe")
	data := "# keep this comment\n" + fileText(t, rulesPath)
	if err := os.WriteFile(rulesPath, []byte(data), 0o600); err != nil {
		t.Fatal(err)
	}

	return root, rulesPath
}

func fileText(t *testing.T, path string) string {
	t.Helper()
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}

	return string(data)
}

func runHooks(t *testing.T, stdin string, args ...string) (string, error) {
	t.Helper()
	cmd := newBridgeHooksCmd()
	cmd.SetArgs(args)
	var out bytes.Buffer
	cmd.SetOut(&out)
	cmd.SetErr(&out)
	cmd.SetIn(strings.NewReader(stdin))
	cmd.SilenceUsage, cmd.SilenceErrors = true, true
	err := cmd.Execute()

	return out.String(), err
}

func installedHooks(t *testing.T, path string) []rules.HookEntry {
	t.Helper()
	set, err := rules.Load(path, rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}

	return set.Hooks()
}

func installTool(t *testing.T, rulesPath string) {
	t.Helper()
	if out, err := runHooks(t, "", "install", "acme/tool@v1", "--yes", "--rules", rulesPath); err != nil {
		t.Fatalf("install: %v\n%s", err, out)
	}
}

func TestHooksInstallShowsThePackageAndARefusalChangesNothing(t *testing.T) {
	root, path := hooksEnv(t)
	newFakeHookRepo(t)
	before := fileText(t, path)

	out, err := runHooks(t, "n\n", "install", "acme/tool@v1", "--rules", path)
	if err == nil || !strings.Contains(err.Error(), "not installed") {
		t.Fatalf("err = %v", err)
	}
	for _, want := range []string{
		"acme/tool", "v1", shaOne, "30 seconds",
		`start: ["./hook.sh" "start"]`, `idle: ["./hook.sh" "idle" "--quiet"]`,
		"loud (bool, default false): Print more.", "label (string, default hi): A label for the log.",
		"no sandbox", "Install? [y/N]",
	} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
	if got := fileText(t, path); got != before {
		t.Fatalf("rule file changed:\n%s", got)
	}
	if _, err := os.Stat(hooks.PackageDir(root, "acme/tool", shaOne)); !os.IsNotExist(err) {
		t.Fatalf("package dir stays after a refusal: %v", err)
	}
}

func TestHooksInstallAddsTheEntryAndKeepsComments(t *testing.T) {
	root, path := hooksEnv(t)
	newFakeHookRepo(t)

	out, err := runHooks(t, "yes\n", "install", "acme/tool@v1", "--rules", path)
	if err != nil {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if !strings.Contains(out, "Run `loupe bridge reload` to apply it to a running bridge.") {
		t.Fatalf("output = %s", out)
	}
	got := installedHooks(t, path)
	if len(got) != 1 || got[0].Package != "acme/tool" || got[0].Ref != "v1" || got[0].SHA != shaOne || len(got[0].Settings) != 0 {
		t.Fatalf("hooks = %+v", got)
	}
	if !strings.Contains(fileText(t, path), "# keep this comment") {
		t.Fatal("the comment is gone")
	}
	if _, err := os.Stat(filepath.Join(hooks.PackageDir(root, "acme/tool", shaOne), "hook.sh")); err != nil {
		t.Fatal(err)
	}
}

func TestHooksInstallSkipsTheDownloadOfAnInstalledCommit(t *testing.T) {
	_, path := hooksEnv(t)
	gh := newFakeHookRepo(t)
	installTool(t, path)
	installTool(t, path)

	if n := gh.downloaded(); n != 1 {
		t.Fatalf("downloads = %d", n)
	}
}

func TestHooksInstallUpdatesTheCommitAndKeepsTheSettings(t *testing.T) {
	root, path := hooksEnv(t)
	gh := newFakeHookRepo(t)
	installTool(t, path)
	if _, err := runHooks(t, "", "set", "acme/tool", "loud=true", "--rules", path); err != nil {
		t.Fatal(err)
	}
	if _, err := runHooks(t, "", "set", "acme/tool", "label=x", "--rules", path); err != nil {
		t.Fatal(err)
	}
	gh.mu.Lock()
	gh.refs["v2"] = shaTwo
	gh.manifests[shaTwo] = strings.Replace(toolManifest, "  label:\n    type: string\n    default: hi\n    description: A label for the log.\n", "", 1)
	gh.mu.Unlock()

	out, err := runHooks(t, "", "install", "ACME/tool@v2", "--yes", "--rules", path)
	if err != nil {
		t.Fatalf("err = %v\n%s", err, out)
	}
	if !strings.Contains(out, "take their values: label.") {
		t.Fatalf("output does not name the dropped setting:\n%s", out)
	}
	got := installedHooks(t, path)
	if len(got) != 1 || got[0].Package != "acme/tool" || got[0].Ref != "v2" || got[0].SHA != shaTwo {
		t.Fatalf("hooks = %+v", got)
	}
	if len(got[0].Settings) != 1 || got[0].Settings["loud"] != "true" {
		t.Fatalf("settings = %v", got[0].Settings)
	}
	if _, err := os.Stat(hooks.PackageDir(root, "acme/tool", shaTwo)); err != nil {
		t.Fatal(err)
	}
}

func TestHooksInstallRefusesAnotherSystem(t *testing.T) {
	_, path := hooksEnv(t)
	gh := newFakeHookRepo(t)
	gh.manifests[shaOne] = "os: [plan9]\n" + toolManifest
	before := fileText(t, path)

	_, err := runHooks(t, "", "install", "acme/tool@v1", "--yes", "--rules", path)
	if err == nil || !strings.Contains(err.Error(), "plan9") {
		t.Fatalf("err = %v", err)
	}
	if got := fileText(t, path); got != before {
		t.Fatalf("rule file changed:\n%s", got)
	}
}

// An install that takes the list past the rows Loupe shows is refused before
// it writes, so the bridge can still load the file.
func TestHooksInstallRefusesMoreEventsThanLoupeShows(t *testing.T) {
	root, path := hooksEnv(t)
	newFakeHookRepo(t)
	manifest := "name: x\nevents:\n  start: [./run]\n  stop: [./run]\n  busy: [./run]\n  idle: [./run]\n"
	err := rules.EditHooks(path, func(list []rules.HookEntry) ([]rules.HookEntry, error) {
		for i := range 25 {
			e := rules.HookEntry{Package: "acme/other" + strings.Repeat("x", i), Ref: "v1", SHA: shaOne}
			dir := hooks.PackageDir(root, e.ID(), e.SHA)
			if err := os.MkdirAll(dir, 0o755); err != nil {
				return nil, err
			}
			if err := os.WriteFile(filepath.Join(dir, hooks.ManifestFile), []byte(manifest), 0o644); err != nil {
				return nil, err
			}
			list = append(list, e)
		}

		return list, nil
	})
	if err != nil {
		t.Fatal(err)
	}
	before := fileText(t, path)

	_, err = runHooks(t, "", "install", "acme/tool@v1", "--yes", "--rules", path)
	if err == nil || !strings.Contains(err.Error(), "Loupe shows at most 100") {
		t.Fatalf("err = %v", err)
	}
	if got := fileText(t, path); got != before {
		t.Fatalf("rule file changed:\n%s", got)
	}
}

func TestHooksInstallRefusesAnUnknownRef(t *testing.T) {
	_, path := hooksEnv(t)
	newFakeHookRepo(t)

	_, err := runHooks(t, "", "install", "acme/tool@nope", "--yes", "--rules", path)
	if err == nil || !strings.Contains(err.Error(), `no ref "nope"`) {
		t.Fatalf("err = %v", err)
	}
}

func TestHooksList(t *testing.T) {
	_, path := hooksEnv(t)
	newFakeHookRepo(t)

	out, err := runHooks(t, "", "list", "--rules", path)
	if err != nil || !strings.Contains(out, "No hook is installed.") {
		t.Fatalf("out = %q, err = %v", out, err)
	}

	installTool(t, path)
	if _, err := runHooks(t, "", "set", "acme/tool", "loud=true", "--rules", path); err != nil {
		t.Fatal(err)
	}
	out, err = runHooks(t, "", "list", "--rules", path)
	if err != nil {
		t.Fatal(err)
	}
	want := "acme/tool  v1  1111111  label=hi loud=true\n"
	if out != want {
		t.Fatalf("out = %q, want %q", out, want)
	}
}

func TestHooksRemove(t *testing.T) {
	root, path := hooksEnv(t)
	newFakeHookRepo(t)
	installTool(t, path)
	state := hooks.StateDir(root, "acme/tool")
	if err := os.MkdirAll(state, 0o700); err != nil {
		t.Fatal(err)
	}

	out, err := runHooks(t, "", "remove", "acme/tool", "--rules", path)
	if err != nil || !strings.Contains(out, "loupe bridge reload") {
		t.Fatalf("out = %q, err = %v", out, err)
	}
	if got := installedHooks(t, path); len(got) != 0 {
		t.Fatalf("hooks = %+v", got)
	}
	if !strings.Contains(fileText(t, path), "# keep this comment") {
		t.Fatal("the comment is gone")
	}
	if _, err := os.Stat(state); err != nil {
		t.Fatalf("state dir: %v", err)
	}

	if _, err := runHooks(t, "", "remove", "acme/tool", "--rules", path); err == nil || !strings.Contains(err.Error(), "is not installed") {
		t.Fatalf("err = %v", err)
	}
	if _, err := runHooks(t, "", "remove", "acme/tool@v1", "--rules", path); err == nil || !strings.Contains(err.Error(), "without a ref") {
		t.Fatalf("err = %v", err)
	}
}

func TestHooksSet(t *testing.T) {
	_, path := hooksEnv(t)
	newFakeHookRepo(t)
	installTool(t, path)

	out, err := runHooks(t, "", "set", "acme/tool", "label=a=b", "--rules", path)
	if err != nil || !strings.Contains(out, "loupe bridge reload") {
		t.Fatalf("out = %q, err = %v", out, err)
	}
	if got := installedHooks(t, path)[0].Settings; got["label"] != "a=b" {
		t.Fatalf("settings = %v", got)
	}

	before := fileText(t, path)
	for name, c := range map[string]struct{ args []string }{
		"unknown name":  {[]string{"acme/tool", "colour=red"}},
		"bad bool":      {[]string{"acme/tool", "loud=yes"}},
		"no equals":     {[]string{"acme/tool", "loud"}},
		"not installed": {[]string{"acme/other", "loud=true"}},
	} {
		if _, err := runHooks(t, "", append(append([]string{"set"}, c.args...), "--rules", path)...); err == nil {
			t.Errorf("%s: no error", name)
		}
	}
	if got := fileText(t, path); got != before {
		t.Fatalf("rule file changed:\n%s", got)
	}

	_, err = runHooks(t, "", "set", "acme/tool", "colour=red", "--rules", path)
	if err == nil || !strings.Contains(err.Error(), "label, loud") {
		t.Fatalf("err = %v", err)
	}
	_, err = runHooks(t, "", "set", "acme/tool", "loud=yes", "--rules", path)
	if err == nil || !strings.Contains(err.Error(), "not true or false") {
		t.Fatalf("err = %v", err)
	}
}
