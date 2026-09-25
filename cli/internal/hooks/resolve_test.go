package hooks

import (
	"os"
	"path/filepath"
	"runtime"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/rules"
)

const sha = "0123456789abcdef0123456789abcdef01234567"

// install writes a manifest where Resolve looks for the entry's package.
func install(t *testing.T, root string, e rules.HookEntry, body string) {
	t.Helper()
	dir := PackageDir(root, e.ID(), e.SHA)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, ManifestFile), []byte(body), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestTheDirectories(t *testing.T) {
	root := t.TempDir()
	if got, want := PackageDir(root, "ubermuda/loupe/hooks/amphetamine", sha), filepath.Join(root, "hooks", "packages", "ubermuda", "loupe", "hooks", "amphetamine", sha); got != want {
		t.Fatalf("PackageDir = %s, want %s", got, want)
	}
	if got, want := StateDir(root, "acme/tool"), filepath.Join(root, "hooks", "state", "acme", "tool"); got != want {
		t.Fatalf("StateDir = %s, want %s", got, want)
	}
}

func TestResolve(t *testing.T) {
	root := t.TempDir()
	amph := rules.HookEntry{Package: "ubermuda/loupe", Path: "hooks/amphetamine", Ref: "v1", SHA: sha, Settings: map[string]string{"takeover": "true"}}
	tool := rules.HookEntry{Package: "acme/tool", Ref: "main", SHA: sha}
	install(t, root, amph, manifest)
	install(t, root, tool, "name: tool\nevents:\n  start: [./run, a b]\n")

	got, err := Resolve(root, []rules.HookEntry{amph, tool}, "darwin")
	if err != nil {
		t.Fatal(err)
	}

	if len(got) != 2 {
		t.Fatalf("hooks = %+v", got)
	}
	h := got[0]
	if h.ID != "ubermuda/loupe/hooks/amphetamine" || h.Ref != "v1" || h.SHA != sha || h.Timeout != 10*time.Second {
		t.Fatalf("hook = %+v", h)
	}
	if h.Dir != PackageDir(root, h.ID, sha) || h.StateDir != StateDir(root, h.ID) {
		t.Fatalf("dirs = %s, %s", h.Dir, h.StateDir)
	}
	if !slices.Equal(h.Events["idle"], []string{"./hook", "idle"}) {
		t.Fatalf("events = %v", h.Events)
	}
	if h.Settings["takeover"] != "true" || h.Settings["label"] != "" || len(h.Settings) != 2 {
		t.Fatalf("settings = %v", h.Settings)
	}
	if got[1].ID != "acme/tool" || got[1].Timeout != DefaultTimeout || len(got[1].Settings) != 0 {
		t.Fatalf("second hook = %+v", got[1])
	}
	if _, err := os.Stat(h.StateDir); !os.IsNotExist(err) {
		t.Fatalf("Resolve made the state dir: %v", err)
	}
}

// Loupe keeps one row per package and event, and 100 rows at most, so a list
// past that is refused rather than shown in part.
func TestResolveRefusesMoreEventsThanLoupeShows(t *testing.T) {
	root := t.TempDir()
	body := "name: x\nevents:\n  start: [./run]\n  stop: [./run]\n  busy: [./run]\n  idle: [./run]\n"
	var entries []rules.HookEntry
	for i := range 26 {
		e := rules.HookEntry{Package: "acme/tool" + strings.Repeat("x", i), Ref: "v1", SHA: sha}
		install(t, root, e, body)
		entries = append(entries, e)
	}

	if _, err := Resolve(root, entries[:25], "linux"); err != nil {
		t.Fatalf("100 events: %v", err)
	}
	if _, err := Resolve(root, entries, "linux"); err == nil || !strings.Contains(err.Error(), "104 events") {
		t.Fatalf("104 events: err = %v", err)
	}
}

func TestResolveFillsTheDefaults(t *testing.T) {
	root := t.TempDir()
	e := rules.HookEntry{Package: "ubermuda/loupe", Path: "hooks/amphetamine", Ref: "v1", SHA: sha}
	install(t, root, e, manifest)

	got, err := Resolve(root, []rules.HookEntry{e}, "darwin")
	if err != nil {
		t.Fatal(err)
	}
	if got[0].Settings["takeover"] != "false" {
		t.Fatalf("settings = %v", got[0].Settings)
	}
}

func TestResolveRefuses(t *testing.T) {
	root := t.TempDir()
	ok := rules.HookEntry{Package: "ubermuda/loupe", Path: "hooks/amphetamine", Ref: "v1", SHA: sha}
	install(t, root, ok, manifest)
	broken := rules.HookEntry{Package: "acme/broken", Ref: "v1", SHA: sha}
	install(t, root, broken, "name: x\n")
	bare := rules.HookEntry{Package: "acme/bare", Ref: "v1", SHA: sha}
	if err := os.MkdirAll(PackageDir(root, bare.ID(), sha), 0o755); err != nil {
		t.Fatal(err)
	}

	with := func(settings map[string]string) rules.HookEntry {
		e := ok
		e.Settings = settings

		return e
	}
	for name, tc := range map[string]struct {
		entry rules.HookEntry
		goos  string
		want  string
	}{
		"missing":      {rules.HookEntry{Package: "acme/none", Ref: "v1", SHA: sha}, "darwin", "hook acme/none: the package is not installed"},
		"no manifest":  {bare, "darwin", "hook acme/bare: the package is not installed"},
		"bad manifest": {broken, "darwin", "hook acme/broken: events lists no event"},
		"other os":     {ok, "linux", "hook ubermuda/loupe/hooks/amphetamine: the package runs on darwin, and this machine runs linux"},
		"unknown":      {with(map[string]string{"speed": "1"}), "darwin", `hook ubermuda/loupe/hooks/amphetamine: setting "speed" is not in the manifest, which has label, takeover`},
		"wrong type":   {with(map[string]string{"takeover": "yes"}), "darwin", `hook ubermuda/loupe/hooks/amphetamine: setting "takeover": "yes" is not true or false`},
	} {
		t.Run(name, func(t *testing.T) {
			_, err := Resolve(root, []rules.HookEntry{tc.entry}, tc.goos)
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want %q", err, tc.want)
			}
		})
	}
}

func TestResolveJoinsTheErrors(t *testing.T) {
	root := t.TempDir()
	_, err := Resolve(root, []rules.HookEntry{
		{Package: "acme/one", Ref: "v1", SHA: sha},
		{Package: "acme/two", Ref: "v1", SHA: sha},
	}, "darwin")
	if err == nil || !strings.Contains(err.Error(), "acme/one") || !strings.Contains(err.Error(), "acme/two") {
		t.Fatalf("err = %v", err)
	}
}

func TestMakeStateDir(t *testing.T) {
	h := Hook{StateDir: filepath.Join(t.TempDir(), "hooks", "state", "acme", "tool")}
	if err := h.MakeStateDir(); err != nil {
		t.Fatal(err)
	}
	info, err := os.Stat(h.StateDir)
	if err != nil || !info.IsDir() {
		t.Fatalf("stat = %v, %v", info, err)
	}
	if runtime.GOOS != "windows" && info.Mode().Perm() != 0o700 {
		t.Fatalf("mode = %v", info.Mode())
	}
}
