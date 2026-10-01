package installscript

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

func formula(t *testing.T, checksums string) (string, error) {
	t.Helper()
	if _, err := exec.LookPath("sh"); err != nil {
		t.Skip("sh is not available")
	}
	script, err := filepath.Abs("../scripts/homebrew-formula.sh")
	if err != nil {
		t.Fatal(err)
	}
	sums := filepath.Join(t.TempDir(), "checksums.txt")
	if err := os.WriteFile(sums, []byte(checksums), 0o644); err != nil {
		t.Fatal(err)
	}
	out, err := exec.Command("sh", script, "1.4.2", sums).Output()

	return string(out), err
}

func TestFormulaNamesEveryArchiveAndItsSum(t *testing.T) {
	var checksums strings.Builder
	sums := map[string]string{}
	for i, p := range platforms {
		sum := strings.Repeat(fmt.Sprint(i+1), 64)
		sums[p] = sum
		fmt.Fprintf(&checksums, "%s  loupe_1.4.2_%s.tar.gz\n", sum, p)
	}
	fmt.Fprintf(&checksums, "%s  loupe_1.4.2_windows_amd64.zip\n", strings.Repeat("9", 64))

	out, err := formula(t, checksums.String())
	if err != nil {
		t.Fatalf("the script failed: %v", err)
	}
	assertContains(t, out, "class Loupe < Formula", `version "1.4.2"`, `license "AGPL-3.0-or-later"`, `bin.install "loupe"`)
	for _, p := range platforms {
		url := "https://github.com/ubermuda/loupe/releases/download/cli/v1.4.2/loupe_1.4.2_" + p + ".tar.gz"
		assertContains(t, out, `url "`+url+`"`+"\n      sha256 \""+sums[p]+`"`)
	}

	ruby, err := exec.LookPath("ruby")
	if err != nil {
		t.Log("ruby is not available, so the syntax check is skipped")

		return
	}
	rb := filepath.Join(t.TempDir(), "loupe.rb")
	if err := os.WriteFile(rb, []byte(out), 0o644); err != nil {
		t.Fatal(err)
	}
	if msg, err := exec.Command(ruby, "-c", rb).CombinedOutput(); err != nil {
		t.Errorf("the formula is not valid Ruby: %v\n%s", err, msg)
	}
}

func TestFormulaRefusesAMissingArchive(t *testing.T) {
	checksums := strings.Repeat("1", 64) + "  loupe_1.4.2_darwin_arm64.tar.gz\n"
	out, err := formula(t, checksums)
	if err == nil {
		t.Fatalf("the script accepted a checksums.txt with one archive:\n%s", out)
	}
}

// publishable runs the tap version check. tap is the version the tap holds,
// and an empty tap means that the tap has no formula yet.
func publishable(t *testing.T, tap, formulaBody, next string) (string, int) {
	t.Helper()
	if _, err := exec.LookPath("sh"); err != nil {
		t.Skip("sh is not available")
	}
	script, err := filepath.Abs("../scripts/homebrew-publishable.sh")
	if err != nil {
		t.Fatal(err)
	}
	rb := filepath.Join(t.TempDir(), "loupe.rb")
	if tap != "" {
		formulaBody = "class Loupe < Formula\n  version \"" + tap + "\"\nend\n"
	}
	if formulaBody != "" {
		if err := os.WriteFile(rb, []byte(formulaBody), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	out, err := exec.Command("sh", script, next, rb).CombinedOutput()
	var exitErr *exec.ExitError
	if errors.As(err, &exitErr) {
		return string(out), exitErr.ExitCode()
	}
	if err != nil {
		t.Fatal(err)
	}

	return string(out), 0
}

func TestPublishableComparesWithTheTapVersion(t *testing.T) {
	cases := []struct {
		tap, next string
		exit      int
		says      string
	}{
		{"", "1.4.2", 0, ""},
		{"1.4.1", "1.4.2", 0, ""},
		{"1.9.3", "1.10.0", 0, ""},
		{"1.4.2", "1.4.2", 3, "::notice::"},
		{"1.10.0", "1.9.9", 3, "::notice::"},
		{"2.0.0", "1.9.9", 3, "::notice::"},
		{"1.9.9", "2.0.0", 3, "::warning::"},
	}
	for _, c := range cases {
		t.Run(c.tap+"_to_"+c.next, func(t *testing.T) {
			out, exit := publishable(t, c.tap, "", c.next)
			if exit != c.exit {
				t.Errorf("exit %d, want %d:\n%s", exit, c.exit, out)
			}
			if !strings.Contains(out, c.says) {
				t.Errorf("output lacks %q:\n%s", c.says, out)
			}
		})
	}
}

func TestPublishableReadsTheVersionOfAGeneratedFormula(t *testing.T) {
	var checksums strings.Builder
	for _, p := range platforms {
		fmt.Fprintf(&checksums, "%s  loupe_1.4.2_%s.tar.gz\n", strings.Repeat("1", 64), p)
	}
	generated, err := formula(t, checksums.String())
	if err != nil {
		t.Fatal(err)
	}
	if out, exit := publishable(t, "", generated, "1.4.2"); exit != 3 {
		t.Errorf("exit %d, want 3:\n%s", exit, out)
	}
	if out, exit := publishable(t, "", generated, "1.4.3"); exit != 0 {
		t.Errorf("exit %d, want 0:\n%s", exit, out)
	}
}

func TestPublishableRefusesAFormulaWithNoVersion(t *testing.T) {
	out, exit := publishable(t, "", "class Loupe < Formula\nend\n", "1.4.2")
	if exit != 1 {
		t.Errorf("exit %d, want 1:\n%s", exit, out)
	}
}
