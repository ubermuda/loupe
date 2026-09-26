package update

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"testing"
)

func release(tag string, assets ...string) Release {
	r := Release{TagName: tag}
	for _, a := range assets {
		r.Assets = append(r.Assets, Asset{Name: a, URL: "https://example.test/" + tag + "/" + a})
	}

	return r
}

func full(tag, version string) Release {
	return release(tag, "loupe_"+version+"_linux_amd64.tar.gz", "checksums.txt")
}

func TestAssetName(t *testing.T) {
	v, _ := ParseVersion("v1.2.3")
	if got := AssetName(v, "darwin", "arm64"); got != "loupe_1.2.3_darwin_arm64.tar.gz" {
		t.Fatalf("AssetName = %q", got)
	}
}

func TestPickReturnsTheHighestUsableRelease(t *testing.T) {
	draft := full("cli/v1.9.0", "1.9.0")
	draft.Draft = true
	pre := full("cli/v1.8.0", "1.8.0")
	pre.Prerelease = true

	releases := []Release{
		full("cli/v1.2.0", "1.2.0"),
		full("cli/v1.5.0", "1.5.0"),
		full("cli/v1.3.0", "1.3.0"),
		draft,
		pre,
		release("cli/v1.7.0", "checksums.txt"),
		release("cli/v1.6.0", "loupe_1.6.0_linux_amd64.tar.gz"),
		full("cli/v1.6.5", "1.6.5"),
		full("cli/v2.0.0", "2.0.0"),
		full("cli/v1.9.7-rc.1", "1.9.7-rc.1"),
		release("cli/v1.9.6", "loupe_1.9.6_darwin_amd64.tar.gz", "checksums.txt"),
	}

	got, ok := Pick(releases, "^1.0", "linux", "amd64", map[string]bool{"1.6.5": true})
	if !ok {
		t.Fatal("Pick found nothing")
	}
	if got.Tag != "cli/v1.5.0" || got.Version != (Version{1, 5, 0, ""}) {
		t.Fatalf("Pick = %+v, want cli/v1.5.0", got)
	}
	if got.Archive.Name != "loupe_1.5.0_linux_amd64.tar.gz" || got.Archive.URL != "https://example.test/cli/v1.5.0/loupe_1.5.0_linux_amd64.tar.gz" {
		t.Fatalf("Archive = %+v", got.Archive)
	}
	if got.Checksums.Name != "checksums.txt" || got.Checksums.URL != "https://example.test/cli/v1.5.0/checksums.txt" {
		t.Fatalf("Checksums = %+v", got.Checksums)
	}
}

func TestPickSkipsTagsOfOtherVersionTracks(t *testing.T) {
	for _, tag := range []string{"v1.9.9", "1.9.9", "cli/1.9.9", "cli/vv1.9.9", "cli/v1.9", "app/v1.9.9", "xcli/v1.9.9"} {
		releases := []Release{full("cli/v1.2.0", "1.2.0"), full(tag, "1.9.9")}
		got, ok := Pick(releases, "^1.0", "linux", "amd64", nil)
		if !ok || got.Tag != "cli/v1.2.0" {
			t.Errorf("Pick with %q = %+v, %v, want cli/v1.2.0", tag, got, ok)
		}
	}
}

func TestPickFindsNothing(t *testing.T) {
	if _, ok := Pick([]Release{full("cli/v2.0.0", "2.0.0")}, "^1.0", "linux", "amd64", nil); ok {
		t.Fatal("Pick found a release outside the range")
	}
	if _, ok := Pick([]Release{full("v1.2.0", "1.2.0")}, "^1.0", "linux", "amd64", nil); ok {
		t.Fatal("Pick found a release with a plain vX.Y.Z tag")
	}
	if _, ok := Pick(nil, "^1.0", "linux", "amd64", nil); ok {
		t.Fatal("Pick found a release in an empty list")
	}
}

func TestShouldInstall(t *testing.T) {
	cases := []struct {
		running, candidate, rng string
		want                    bool
	}{
		{"5.0.0", "4.7.0", "^4.5", true},
		{"4.6.0", "4.6.0", "^4.5", false},
		{"4.6.0", "4.7.0", "^4.5", true},
		{"4.7.0", "4.6.0", "^4.5", false},
		{"v4.6.0", "4.7.0", "^4.5", true},
		{"dev", "4.7.0", "^4.5", false},
		{"a1b2c3d", "4.7.0", "^4.5", false},
		{"4.6.0-rc.1", "4.6.0", "^4.5", true},
	}
	for _, c := range cases {
		cand, _ := ParseVersion(c.candidate)
		if got := ShouldInstall(c.running, cand, c.rng); got != c.want {
			t.Errorf("ShouldInstall(%q, %s, %q) = %v, want %v", c.running, c.candidate, c.rng, got, c.want)
		}
	}
}

func sum(b []byte) string {
	s := sha256.Sum256(b)

	return hex.EncodeToString(s[:])
}

func TestVerify(t *testing.T) {
	archive := []byte("archive bytes")
	name := "loupe_1.2.3_linux_amd64.tar.gz"
	checksums := []byte(sum([]byte("other")) + "  loupe_1.2.3_darwin_arm64.tar.gz\n" +
		sum(archive) + "  " + name + "\n")

	if err := Verify(archive, checksums, name); err != nil {
		t.Fatalf("match: %v", err)
	}
	if err := Verify(archive, []byte(sum(archive)+"\t"+name+"\r\n"), name); err != nil {
		t.Fatalf("tab separator: %v", err)
	}
	if err := Verify([]byte("tampered"), checksums, name); !errors.Is(err, ErrChecksumMismatch) {
		t.Fatalf("mismatch: err = %v", err)
	}
	if err := Verify(archive, checksums, "loupe_1.2.3_linux_arm64.tar.gz"); !errors.Is(err, ErrNoChecksum) {
		t.Fatalf("missing line: err = %v", err)
	}
	if err := Verify(archive, []byte("not-hex  "+name+"\n"), name); !errors.Is(err, ErrChecksumMismatch) {
		t.Fatalf("garbled line: err = %v", err)
	}
}

func tarGz(t *testing.T, files map[string]string) []byte {
	t.Helper()
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	for name, body := range files {
		if err := tw.WriteHeader(&tar.Header{Name: name, Mode: 0o755, Size: int64(len(body)), Typeflag: tar.TypeReg}); err != nil {
			t.Fatal(err)
		}
		if _, err := tw.Write([]byte(body)); err != nil {
			t.Fatal(err)
		}
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	if err := gz.Close(); err != nil {
		t.Fatal(err)
	}

	return buf.Bytes()
}

func TestExtractBinary(t *testing.T) {
	archive := tarGz(t, map[string]string{"README.md": "readme", "LICENSE": "mit", "loupe": "binary"})
	got, err := ExtractBinary(archive, "loupe")
	if err != nil || string(got) != "binary" {
		t.Fatalf("ExtractBinary = %q, %v", got, err)
	}

	nested := tarGz(t, map[string]string{"loupe_1.2.3/loupe": "nested"})
	if got, err := ExtractBinary(nested, "loupe"); err != nil || string(got) != "nested" {
		t.Fatalf("nested = %q, %v", got, err)
	}

	if _, err := ExtractBinary(tarGz(t, map[string]string{"README.md": "x"}), "loupe"); err == nil {
		t.Fatal("ExtractBinary found a binary that is not there")
	}
	if _, err := ExtractBinary([]byte("not gzip"), "loupe"); err == nil {
		t.Fatal("ExtractBinary read a corrupt archive")
	}
}
