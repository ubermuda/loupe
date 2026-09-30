package installscript

import (
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
