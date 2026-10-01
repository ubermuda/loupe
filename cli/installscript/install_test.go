package installscript

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
)

// fakeLoupe records its arguments and answers `update auto` like the real one.
const fakeLoupe = `#!/bin/sh
printf '%s\n' "$*" >> "$LOUPE_FAKE_ARGS"
if [ "$1 $2" = "update auto" ]; then
  echo "Automatic updates: $3"
fi
`

// The releases page holds a GitHub prerelease, a draft, a server tag, a newer
// major, a pre-release suffix, a body that looks like JSON, and both pretty and
// compact objects. Only numeric sort of the published 1.x tags picks 1.10.0.
const releasesJSON = `[
  {
    "url": "https://api.github.com/repos/ubermuda/loupe/releases/11",
    "tag_name": "cli/v1.11.0",
    "name": "loupe CLI v1.11.0",
    "draft": false,
    "prerelease": true,
    "author": {"login": "bot", "type": "Bot", "site_admin": false},
    "assets": [{"name": "checksums.txt", "uploader": {"login": "bot"}, "state": "uploaded"}],
    "body": "Fixes {a}, [b] and \"tag_name\": \"cli/v1.99.0\", \"draft\": false, \"prerelease\": false}"
  },
  {"url":"x","tag_name":"cli/v1.12.0","draft":true,"prerelease":false,"author":{"login":"bot"},"assets":[]},
  {"tag_name": "v1.20.0", "draft": false, "prerelease": false},
  {"tag_name": "cli/v2.0.0", "draft": false, "prerelease": false},
  {"tag_name": "cli/v1.11.0-rc1", "draft": false, "prerelease": false},
  {"tag_name":"cli/v1.10.0","draft":false,"prerelease":false},
  {"tag_name": "cli/v1.3.0", "draft": false, "prerelease": false},
  {"prerelease": false, "draft": false, "tag_name": "cli/v1.9.2"}
]`

var platforms = []string{"darwin_amd64", "darwin_arm64", "linux_amd64", "linux_arm64"}

func archive(t *testing.T, loupe string) []byte {
	t.Helper()
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	if err := tw.WriteHeader(&tar.Header{Name: "loupe", Mode: 0o755, Size: int64(len(loupe))}); err != nil {
		t.Fatal(err)
	}
	if _, err := tw.Write([]byte(loupe)); err != nil {
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
// and a checksums.txt. A corrupt server lists a wrong sum for each archive. A
// decoy server lists wrong sums for names that share a prefix or a suffix.
// Page N of the list is pages[N-1], or releasesJSON for page 1 when pages is
// empty. A page past the end is an empty list. requested records each page.
func release(t *testing.T, s setup, requested *[]string) *httptest.Server {
	t.Helper()
	loupe := s.loupe
	if loupe == "" {
		loupe = fakeLoupe
	}
	body := archive(t, loupe)
	corrupt, decoy, pages := s.corrupt, s.decoy, s.pages
	sum := sha256.Sum256(body)
	hexSum := hex.EncodeToString(sum[:])
	if corrupt {
		hexSum = strings.Repeat("0", 64)
	}

	if len(pages) == 0 {
		pages = []string{releasesJSON}
	}
	var mu sync.Mutex
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/repos/ubermuda/loupe/releases" {
			page := r.URL.Query().Get("page")
			mu.Lock()
			*requested = append(*requested, page)
			mu.Unlock()
			n, err := strconv.Atoi(page)
			if err != nil || n < 1 || n > len(pages) {
				_, _ = w.Write([]byte("[]"))

				return
			}
			_, _ = w.Write([]byte(pages[n-1]))

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
				if decoy {
					fmt.Fprintf(w, "%s  loupe_%s_%s.tar.gz.sbom\n", strings.Repeat("0", 64), version, p)
					fmt.Fprintf(w, "%s  old-loupe_%s_%s.tar.gz\n", strings.Repeat("1", 64), version, p)
				}
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
	pages  []string
}

type setup struct {
	corrupt    bool
	brewFound  bool
	script     string
	onlyOnPath bool
	rules      string
	piped      bool
	decoy      bool
	noRulesEnv bool
	pages      []string
	// loupe replaces the binary in the release archive.
	loupe string
	// tty holds the answers of a terminal session, one per line.
	tty string
	// fakes holds extra commands, by name, that shadow the real ones.
	fakes map[string]string
	// prepare runs before the script and returns directories to put first on PATH.
	prepare func(home string) []string
}

// pathWithoutLoupe drops each PATH directory that holds a loupe, so a real
// install on the machine never becomes the target of a test.
func pathWithoutLoupe() string {
	var keep []string
	for _, d := range filepath.SplitList(os.Getenv("PATH")) {
		if _, err := os.Stat(filepath.Join(d, "loupe")); err == nil {
			continue
		}
		keep = append(keep, d)
	}

	return strings.Join(keep, string(os.PathListSeparator))
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
	var requested []string
	srv := release(t, s, &requested)
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
	for name, body := range s.fakes {
		if err := os.WriteFile(filepath.Join(fakeBin, name), []byte(body), 0o755); err != nil {
			t.Fatal(err)
		}
	}

	if s.rules != "" {
		if err := os.WriteFile(filepath.Join(home, "rules.yaml"), []byte(s.rules), 0o644); err != nil {
			t.Fatal(err)
		}
	}

	path := fakeBin + string(os.PathListSeparator) + pathWithoutLoupe()
	if !s.onlyOnPath {
		path = filepath.Join(home, ".local", "bin") + string(os.PathListSeparator) + path
	}
	if s.prepare != nil {
		for _, d := range s.prepare(home) {
			path = d + string(os.PathListSeparator) + path
		}
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
		"LOUPE_FAKE_ARGS=" + filepath.Join(home, "args.txt"),
	}
	if s.tty == "" {
		cmd.Env = append(cmd.Env, "LOUPE_INSTALL_NO_TTY=1")
	} else {
		answers := filepath.Join(home, "answers")
		if err := os.WriteFile(answers, []byte(s.tty), 0o644); err != nil {
			t.Fatal(err)
		}
		cmd.Env = append(cmd.Env, "LOUPE_INSTALL_TTY="+answers)
	}
	if !s.noRulesEnv {
		cmd.Env = append(cmd.Env, "LOUPE_RULES_FILE="+filepath.Join(home, "rules.yaml"))
	}
	out, err := cmd.CombinedOutput()
	called, _ := os.ReadFile(filepath.Join(home, "args.txt"))

	srv.Close()

	return result{home: home, out: string(out), err: err, called: string(called), pages: requested}
}

func assertContains(t *testing.T, got string, wants ...string) {
	t.Helper()
	for _, want := range wants {
		if !strings.Contains(got, want) {
			t.Errorf("output lacks %q:\n%s", want, got)
		}
	}
}

func assertCalled(t *testing.T, r result, want string) {
	t.Helper()
	if got := strings.TrimSpace(r.called); got != want {
		t.Errorf("loupe was called with %q, want %q", got, want)
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
	assertCalled(t, r, "update auto off --keep --rules "+filepath.Join(r.home, "rules.yaml"))
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
	assertCalled(t, r, "update auto on --rules "+filepath.Join(r.home, "rules.yaml"))
}

func TestInstallKeepsAnExistingAutoUpdateKey(t *testing.T) {
	r := run(t, setup{rules: "autoUpdate: on\n"})
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	assertCalled(t, r, "update auto off --keep --rules "+filepath.Join(r.home, "rules.yaml"))
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

func TestInstallWithoutARulesOverrideLetsLoupePickTheFile(t *testing.T) {
	r := run(t, setup{noRulesEnv: true})
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	assertCalled(t, r, "update auto off --keep")
}

func TestInstallIgnoresChecksumsOfSimilarNames(t *testing.T) {
	r := run(t, setup{decoy: true})
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	assertInstalled(t, filepath.Join(r.home, ".local", "bin", "loupe"))
}

func TestInstallRemovesTheTempFileOnFailure(t *testing.T) {
	r := run(t, setup{fakes: map[string]string{"chmod": "#!/bin/sh\nexit 1\n"}})
	if r.err == nil {
		t.Fatalf("install succeeded with a failing chmod:\n%s", r.out)
	}
	assertNotInstalled(t, r.home)
	left, _ := filepath.Glob(filepath.Join(r.home, ".local", "bin", ".loupe.tmp.*"))
	if len(left) > 0 {
		t.Errorf("the temp file stays behind: %v", left)
	}
}

// existing puts a loupe at dir, so the script finds it with command -v.
func existing(t *testing.T, dir string) {
	t.Helper()
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "loupe"), []byte("#!/bin/sh\n"), 0o755); err != nil {
		t.Fatal(err)
	}
}

func TestInstallReplacesTheLoupeOnPath(t *testing.T) {
	var dir string
	r := run(t, setup{prepare: func(home string) []string {
		dir = filepath.Join(home, "tools", "bin")
		existing(t, dir)

		return []string{dir}
	}})
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	assertContains(t, r.out, filepath.Join(dir, "loupe"))
	assertNotInstalled(t, r.home)
	if b, _ := os.ReadFile(filepath.Join(dir, "loupe")); string(b) != fakeLoupe {
		t.Errorf("the loupe on PATH was not replaced")
	}
}

func TestInstallFollowsASymlinkOnPath(t *testing.T) {
	var real string
	r := run(t, setup{prepare: func(home string) []string {
		real = filepath.Join(home, "opt", "loupe")
		existing(t, real)
		link := filepath.Join(home, "links")
		if err := os.MkdirAll(link, 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.Symlink(filepath.Join(real, "loupe"), filepath.Join(link, "loupe")); err != nil {
			t.Fatal(err)
		}

		return []string{link}
	}})
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	if b, _ := os.ReadFile(filepath.Join(real, "loupe")); string(b) != fakeLoupe {
		t.Errorf("the symlink target was not replaced:\n%s", r.out)
	}
}

func TestInstallAsksOnATerminal(t *testing.T) {
	r := run(t, setup{onlyOnPath: true, tty: "~/custom\ny\n"})
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	assertInstalled(t, filepath.Join(r.home, "custom", "loupe"))
	assertContains(t, r.out, "Install directory", "Turn on automatic updates?")
	assertCalled(t, r, "update auto on --keep --rules "+filepath.Join(r.home, "rules.yaml"))
}

func TestInstallTreatsACommentAnswerAsEmpty(t *testing.T) {
	r := run(t, setup{onlyOnPath: true, tty: "# a pasted comment\n#y\n"})
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	assertInstalled(t, filepath.Join(r.home, ".local", "bin", "loupe"))
	assertCalled(t, r, "update auto off --keep --rules "+filepath.Join(r.home, "rules.yaml"))
}

func TestInstallSkipsPrereleasesAndDrafts(t *testing.T) {
	r := run(t, setup{})
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	assertContains(t, r.out, "Installed loupe 1.10.0")
	for _, v := range []string{"1.11.0", "1.12.0", "1.99.0"} {
		if strings.Contains(r.out, v) {
			t.Errorf("the script chose %s:\n%s", v, r.out)
		}
	}
}

func TestInstallReadsTheNextPages(t *testing.T) {
	serverOnly := `[{"tag_name": "v1.20.0", "draft": false, "prerelease": false}]`
	cli := `[{"tag_name": "cli/v1.5.0", "draft": false, "prerelease": false}]`
	r := run(t, setup{pages: []string{serverOnly, cli}})
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	assertContains(t, r.out, "Installed loupe 1.5.0")
	if got := strings.Join(r.pages, ","); got != "1,2,3" {
		t.Errorf("the script read pages %s, want 1,2,3 and a stop at the empty page", got)
	}
}

func TestInstallReadsTenPagesAtMost(t *testing.T) {
	var pages []string
	for range 12 {
		pages = append(pages, `[{"tag_name": "v1.20.0", "draft": false, "prerelease": false}]`)
	}
	pages[9] = `[{"tag_name": "cli/v1.6.0", "draft": false, "prerelease": false}]`
	pages[10] = `[{"tag_name": "cli/v1.7.0", "draft": false, "prerelease": false}]`
	r := run(t, setup{pages: pages})
	if r.err != nil {
		t.Fatalf("install failed: %v\n%s", r.err, r.out)
	}
	assertContains(t, r.out, "Installed loupe 1.6.0")
	if len(r.pages) != 10 {
		t.Errorf("the script read %d pages, want 10", len(r.pages))
	}
}

func TestInstallFailsWhenAutoUpdateCannotBeTurnedOn(t *testing.T) {
	failing := "#!/bin/sh\nprintf '%s\\n' \"$*\" >> \"$LOUPE_FAKE_ARGS\"\necho 'error: cannot write the rule file' >&2\nexit 1\n"
	r := run(t, setup{loupe: failing}, "--auto-update")
	var exitErr *exec.ExitError
	if !errors.As(r.err, &exitErr) || exitErr.ExitCode() != 2 {
		t.Fatalf("want exit status 2, got %v:\n%s", r.err, r.out)
	}
	bin := filepath.Join(r.home, ".local", "bin", "loupe")
	assertInstalled(t, bin)
	assertContains(t, r.out, "Installed loupe 1.10.0", "cannot write the rule file", "Auto-update: unknown",
		"automatic updates are NOT on", "loupe update auto on", "loupe login --url")
}
