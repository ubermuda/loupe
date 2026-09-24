package update

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"path"
	"strings"
)

// Release is one entry of the GitHub REST releases list.
type Release struct {
	TagName    string  `json:"tag_name"`
	Draft      bool    `json:"draft"`
	Prerelease bool    `json:"prerelease"`
	Assets     []Asset `json:"assets"`
}

// Asset is one downloadable file of a release.
type Asset struct {
	Name string `json:"name"`
	URL  string `json:"browser_download_url"`
}

// Candidate is a release the running binary may update to.
type Candidate struct {
	Version   Version
	Tag       string
	Archive   Asset
	Checksums Asset
}

const checksumsName = "checksums.txt"

var (
	ErrNoChecksum       = errors.New("checksums.txt lists no checksum for the archive")
	ErrChecksumMismatch = errors.New("the archive does not match its checksum")
)

// AssetName is the archive name goreleaser gives a build. Its version has no
// leading v.
func AssetName(version Version, goos, goarch string) string {
	return fmt.Sprintf("loupe_%s_%s_%s.tar.gz", version, goos, goarch)
}

// Pick returns the highest published CLI release in the range that ships an
// archive for this platform and a checksums file. skip holds X.Y.Z strings.
// Tags other than vX.Y.Z belong to other parts of the repository.
func Pick(releases []Release, rangeStr, goos, goarch string, skip map[string]bool) (Candidate, bool) {
	var best Candidate
	found := false
	for _, r := range releases {
		if r.Draft || r.Prerelease {
			continue
		}
		v, ok := ParseVersion(r.TagName)
		if !ok || r.TagName != "v"+v.String() || v.Pre != "" {
			continue
		}
		if !Satisfies(r.TagName, rangeStr) || skip[v.String()] {
			continue
		}
		archive, okArchive := findAsset(r.Assets, AssetName(v, goos, goarch))
		checksums, okChecksums := findAsset(r.Assets, checksumsName)
		if !okArchive || !okChecksums {
			continue
		}
		if !found || Compare(v, best.Version) > 0 {
			best = Candidate{Version: v, Tag: r.TagName, Archive: archive, Checksums: checksums}
			found = true
		}
	}

	return best, found
}

func findAsset(assets []Asset, name string) (Asset, bool) {
	for _, a := range assets {
		if a.Name == name {
			return a, true
		}
	}

	return Asset{}, false
}

// ShouldInstall reports whether candidate should replace the running version.
// A running version outside the range is replaced even by a lower candidate.
// A development build, whose version does not parse, is never replaced.
func ShouldInstall(running string, candidate Version, rangeStr string) bool {
	current, ok := ParseVersion(running)
	if !ok {
		return false
	}

	return Compare(candidate, current) > 0 || !Satisfies(running, rangeStr)
}

// Verify checks archive against its line in a goreleaser checksums.txt.
func Verify(archive, checksums []byte, name string) error {
	for line := range strings.Lines(string(checksums)) {
		fields := strings.Fields(line)
		if len(fields) != 2 || fields[1] != name {
			continue
		}
		want, err := hex.DecodeString(fields[0])
		got := sha256.Sum256(archive)
		if err != nil || !bytes.Equal(want, got[:]) {
			return fmt.Errorf("%w: %s", ErrChecksumMismatch, name)
		}

		return nil
	}

	return fmt.Errorf("%w: %s", ErrNoChecksum, name)
}

// ExtractBinary returns the regular file named name from a tar.gz, at any depth.
func ExtractBinary(archive []byte, name string) ([]byte, error) {
	gz, err := gzip.NewReader(bytes.NewReader(archive))
	if err != nil {
		return nil, fmt.Errorf("open the archive: %w", err)
	}
	defer gz.Close()
	tr := tar.NewReader(gz)
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return nil, fmt.Errorf("the archive holds no %s binary", name)
		}
		if err != nil {
			return nil, fmt.Errorf("read the archive: %w", err)
		}
		if hdr.Typeflag == tar.TypeReg && path.Base(hdr.Name) == name {
			return io.ReadAll(tr)
		}
	}
}
