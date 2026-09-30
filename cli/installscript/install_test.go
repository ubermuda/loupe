package installscript

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

// fakeLoupe records its arguments and answers `update auto` like the real one.
const fakeLoupe = `#!/bin/sh
printf '%s\n' "$*" >> "$LOUPE_FAKE_ARGS"
if [ "$1 $2" = "update auto" ]; then
  echo "Automatic updates: $3"
fi
`

// The releases list mixes a server tag, a newer major, a pre-release and a
// compact JSON entry, so only numeric sort within major 1 picks 1.10.0.
const releasesJSON = `[
  {"tag_name": "v1.20.0"},
  {"tag_name": "cli/v2.0.0"},
  {"tag_name": "cli/v1.11.0-rc1"},
  {"tag_name":"cli/v1.10.0"},
  {"tag_name": "cli/v1.3.0"},
  {"tag_name": "cli/v1.9.2"}
]`

var platforms = []string{"darwin_amd64", "darwin_arm64", "linux_amd64", "linux_arm64"}

func archive(t *testing.T) []byte {
	t.Helper()
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	if err := tw.WriteHeader(&tar.Header{Name: "loupe", Mode: 0o755, Size: int64(len(fakeLoupe))}); err != nil {
		t.Fatal(err)
	}
	if _, err := tw.Write([]byte(fakeLoupe)); err != nil {
		t.Fatal(err)
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	if err := gz.Close(); err != nil {
		t.Fatal(err)
	}

	return buf.Bytes()
}

// release serves the releases list and, for every version, the four archives
// and a checksums.txt. A corrupt server lists a wrong sum for each archive.
func release(t *testing.T, corrupt bool) *httptest.Server {
	t.Helper()
	body := archive(t)
	sum := sha256.Sum256(body)
	hexSum := hex.EncodeToString(sum[:])
	if corrupt {
		hexSum = strings.Repeat("0", 64)
	}

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/repos/ubermuda/loupe/releases" {
			_, _ = w.Write([]byte(releasesJSON))

			return
		}
		prefix := "/ubermuda/loupe/releases/download/cli/v"
		rest, ok := strings.CutPrefix(r.URL.Path, prefix)
		version, file, found := strings.Cut(rest, "/")
		if !ok || !found {
			http.NotFound(w, r)

			return
		}
		if file == "checksums.txt" {
			for _, p := range platforms {
				fmt.Fprintf(w, "%s  loupe_%s_%s.tar.gz\n", hexSum, version, p)
			}

			return
		}
		for _, p := range platforms {
			if file == fmt.Sprintf("loupe_%s_%s.tar.gz", version, p) {
				_, _ = w.Write(body)

				return
			}
		}
		http.NotFound(w, r)
	}))
	t.Cleanup(srv.Close)

	return srv
}

type result struct {
	home   string
	out    string
	err    error
	called string
}

type setup struct {
	corrupt    bool
	brewFound  bool
	script     string
	onlyOnPath bool
	rules      string
	piped      bool
}

func scriptPath(t *testing.T) string {
	t.Helper()
	p, err := filepath.Abs("../install.sh")
	if err != nil {
		t.Fatal(err)
	}

	return p
}

func run(t *testing.T, s setup, args ...string) result {
	t.Helper()
	if _, err := exec.LookPath("sh"); err != nil {
		t.Skip("sh is not available")
	}
	srv := release(t, s.corrupt)
	home := t.TempDir()

	// A fake brew comes first, so a real Homebrew on the machine never answers.
	fakeBin := filepath.Join(home, "fakebin")
	if err := os.MkdirAll(fakeBin, 0o755); err != nil {
		t.Fatal(err)
	}
	brewExit := "1"
	if s.brewFound {
		brewExit = "0"
	}
	if err := os.WriteFile(filepath.Join(fakeBin, "brew"), []byte("#!/bin/sh\nexit "+brewExit+"\n"), 0o755); err != nil {
		t.Fatal(err)
	}

	if s.rules != "" {
		if err := os.WriteFile(filepath.Join(home, "rules.yaml"), []byte(s.rules), 0o644); err != nil {
			t.Fatal(err)
		}
	}

	path := fakeBin + string(os.PathListSeparator) + os.Getenv("PATH")
	if !s.onlyOnPath {
		path = filepath.Join(home, ".local", "bin") + string(os.PathListSeparator) + path
	}

	script := s.script
	if script == "" {
		script = scriptPath(t)
	}
	cmd := exec.Command("sh", append([]string{script}, args...)...)
	if s.piped {
		f, err := os.Open(script)
		if err != nil {
			t.Fatal(err)
		}
		defer f.Close()
		cmd = exec.Command("sh", append([]string{"-s", "--"}, args...)...)
		cmd.Stdin = f
	}
	cmd.Dir = home
	cmd.Env = []string{
		"HOME=" + home,
		"PATH=" + path,
		"LOUPE_GITHUB_API=" + srv.URL,
		"LOUPE_GITHUB_DOWNLOAD=" + srv.URL,
		"LOUPE_INSTALL_NO_TTY=1",
		"LOUPE_RULES_FILE=" + filepath.Join(home, "rules.yaml"),
		"LOUPE_FAKE_ARGS=" + filepath.Join(home, "args.txt"),
	}
	out, err := cmd.CombinedOutput()
	called, _ := os.ReadFile(filepath.Join(home, "args.txt"))

	return result{home: home, out: string(out), err: err, called: string(called)}
}

func assertContains(t *testing.T, got string, wants ...string) {
	t.Helper()
	for _, want := range wants {
		if !strings.Contains(got, want) {
			t.Errorf("output lacks %q:\n%s", want, got)
		}
	}
}

func assertInstalled(t *testing.T, bin string) {
	t.Helper()
	info, err := os.Stat(bin)
	if err != nil {
		t.Fatalf("no binary at %s: %v", bin, err)
	}
	if info.Mode().Perm() != 0o755 {
		t.Errorf("binary mode is %v, want 0755", info.Mode().Perm())
	}
}

func assertNotInstalled(t *testing.T, home string) {
	t.Helper()
	if _, err := os.Stat(filepath.Join(home, ".local", "bin", "loupe")); err == nil {
		t.Errorf("a binary was installed")
	}
}

func TestInstallPicksTheHighestReleaseOfTheMajor(t *testing.T) {
	r := run(t, setup{})
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	bin := filepath.Join(r.home, ".local", "bin", "loupe")
	assertInstalled(t, bin)
	assertContains(t, r.out, "1.10.0", bin, "Auto-update: off", "loupe update auto on", "loupe login --url <your Loupe URL>")
	if strings.Contains(r.out, "export PATH") {
		t.Errorf("the output asks to change PATH, but the directory is on PATH:\n%s", r.out)
	}
	if strings.TrimSpace(r.called) != "update auto off --keep" {
		t.Errorf("loupe was called with %q, want update auto off --keep", r.called)
	}
}

func TestInstallReadsTheLinesTheServerInserts(t *testing.T) {
	src, err := os.ReadFile(scriptPath(t))
	if err != nil {
		t.Fatal(err)
	}
	first, rest, _ := strings.Cut(string(src), "\n")
	served := first + "\nLOUPE_URL='https://loupe.example'\nLOUPE_CLI_MAJOR='2'\n" + rest
	script := filepath.Join(t.TempDir(), "install.sh")
	if err := os.WriteFile(script, []byte(served), 0o644); err != nil {
		t.Fatal(err)
	}

	r := run(t, setup{script: script})
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	assertContains(t, r.out, "2.0.0", "loupe login --url https://loupe.example")
}

func TestInstallFromAPipe(t *testing.T) {
	dir := filepath.Join(t.TempDir(), "bin")
	r := run(t, setup{piped: true}, "--install-dir", dir)
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	assertInstalled(t, filepath.Join(dir, "loupe"))
	assertContains(t, r.out, "1.10.0", "loupe login --url")
}

func TestInstallWithAutoUpdateTurnsItOn(t *testing.T) {
	r := run(t, setup{}, "--auto-update")
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	assertContains(t, r.out, "Auto-update: on")
	if strings.TrimSpace(r.called) != "update auto on" {
		t.Errorf("loupe was called with %q, want update auto on", r.called)
	}
}

func TestInstallKeepsAnExistingAutoUpdateKey(t *testing.T) {
	r := run(t, setup{rules: "autoUpdate: on\n"})
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	if strings.TrimSpace(r.called) != "update auto off --keep" {
		t.Errorf("loupe was called with %q, want update auto off --keep", r.called)
	}
}

func TestInstallRefusesAChecksumMismatch(t *testing.T) {
	r := run(t, setup{corrupt: true})
	if r.err == nil {
		t.Fatalf("install succeeded with a wrong checksum:\n%s", r.out)
	}
	assertContains(t, r.out, "checksum")
	assertNotInstalled(t, r.home)
}

func TestInstallIntoAnExplicitDirectory(t *testing.T) {
	dir := filepath.Join(t.TempDir(), "tools", "bin")
	r := run(t, setup{}, "--install-dir", dir)
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	assertInstalled(t, filepath.Join(dir, "loupe"))
	assertNotInstalled(t, r.home)
	assertContains(t, r.out, `export PATH="`+dir+`:$PATH"`)
}

func TestInstallOffPathFallsBackToLocalBin(t *testing.T) {
	r := run(t, setup{onlyOnPath: true})
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	dir := filepath.Join(r.home, ".local", "bin")
	assertInstalled(t, filepath.Join(dir, "loupe"))
	assertContains(t, r.out, `export PATH="`+dir+`:$PATH"`)
}

func TestInstallLeavesAHomebrewInstallAlone(t *testing.T) {
	r := run(t, setup{brewFound: true})
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	assertContains(t, r.out, "brew upgrade loupe")
	assertNotInstalled(t, r.home)
}

func TestInstallAPinnedVersion(t *testing.T) {
	r := run(t, setup{}, "--version", "1.3.0")
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	assertContains(t, r.out, "1.3.0")
}

func TestInstallRefusesAMalformedVersion(t *testing.T) {
	r := run(t, setup{}, "--version", "1.3")
	if r.err == nil {
		t.Fatalf("install accepted version 1.3:\n%s", r.out)
	}
	assertNotInstalled(t, r.home)
}
