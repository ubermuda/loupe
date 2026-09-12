package cmd

import (
	"bytes"
	"runtime"
	"strings"
	"testing"
)

// injectBuildInfo sets the link-time variables for one test and restores them
// afterwards, which is what `-ldflags -X` does to a real binary.
func injectBuildInfo(t *testing.T, wantCommit, wantDirty string) {
	t.Helper()
	oldCommit, oldDirty := commit, dirty
	t.Cleanup(func() { commit, dirty = oldCommit, oldDirty })
	commit, dirty = wantCommit, wantDirty
}

// runRoot executes the root command, which is the only way to reach the
// --version flag. The root reads versionString() when it is built, so the
// injection has to happen first.
func runRoot(t *testing.T, args ...string) string {
	t.Helper()
	out := &bytes.Buffer{}
	root := newRootCmd()
	root.SetArgs(args)
	root.SetOut(out)
	root.SetErr(out)
	if err := root.Execute(); err != nil {
		t.Fatalf("args %v: %v", args, err)
	}

	return out.String()
}

// This test reads the package defaults on purpose, so it fails if a build that
// injects nothing stops saying so. Every other test restores them.
func TestVersionReportsUnknownWhenNothingWasInjected(t *testing.T) {
	out := runRoot(t, "version")
	if !strings.HasPrefix(out, "loupe unknown\n") {
		t.Fatalf("out = %q", out)
	}
	if strings.Contains(out, "dirty") {
		t.Fatalf("unset dirty must not print a marker: %q", out)
	}
}

func TestVersionReportsTheInjectedCommit(t *testing.T) {
	injectBuildInfo(t, "0f4a2c9b1d", "")

	out := runRoot(t, "version")
	if !strings.HasPrefix(out, "loupe 0f4a2c9b1d\n") {
		t.Fatalf("out = %q", out)
	}
}

func TestVersionMarksADirtyBuildOnlyWhenTheFlagIsSet(t *testing.T) {
	for _, tc := range []struct {
		dirty string
		want  bool
	}{
		{dirty: "true", want: true},
		{dirty: "", want: false},
		{dirty: "false", want: false},
	} {
		injectBuildInfo(t, "0f4a2c9b1d", tc.dirty)

		out := runRoot(t, "version")
		if got := strings.Contains(out, "loupe 0f4a2c9b1d (dirty)"); got != tc.want {
			t.Fatalf("dirty %q: marker = %v, want %v (out %q)", tc.dirty, got, tc.want, out)
		}
	}
}

func TestVersionReportsTheRuntimeAndPlatform(t *testing.T) {
	injectBuildInfo(t, "0f4a2c9b1d", "")

	want := runtime.Version() + " " + runtime.GOOS + "/" + runtime.GOARCH
	if out := runRoot(t, "version"); !strings.Contains(out, want) {
		t.Fatalf("out = %q, want it to contain %q", out, want)
	}
}

// The two paths reach the same string through different cobra machinery, so a
// change to either one alone shows up here.
func TestVersionFlagAgreesWithTheSubcommand(t *testing.T) {
	injectBuildInfo(t, "0f4a2c9b1d", "true")

	if flag, sub := runRoot(t, "--version"), runRoot(t, "version"); flag != sub {
		t.Fatalf("--version = %q, version = %q", flag, sub)
	}
}
