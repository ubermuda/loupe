package hooks

import (
	"archive/tar"
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path"
	"path/filepath"
	"strings"
	"time"

	"github.com/ubermuda/loupe/cli/internal/rules"
)

// The limits Fetch puts on one archive.
const (
	MaxArchiveBytes   = 50 << 20
	MaxArchiveEntries = 10000
)

// Fetcher downloads hook packages from GitHub. Its zero value uses GitHub.
type Fetcher struct {
	APIBase      string
	CodeloadBase string
	Client       *http.Client

	// Zero means MaxArchiveBytes and MaxArchiveEntries.
	maxBytes   int64
	maxEntries int
}

func (f *Fetcher) apiBase() string {
	if f.APIBase == "" {
		return "https://api.github.com"
	}

	return strings.TrimSuffix(f.APIBase, "/")
}

func (f *Fetcher) codeloadBase() string {
	if f.CodeloadBase == "" {
		return "https://codeload.github.com"
	}

	return strings.TrimSuffix(f.CodeloadBase, "/")
}

func (f *Fetcher) client() *http.Client {
	if f.Client == nil {
		return &http.Client{Timeout: 2 * time.Minute}
	}

	return f.Client
}

// ResolveSHA asks GitHub for the commit a tag, a branch or a sha names today.
func (f *Fetcher) ResolveSHA(ctx context.Context, owner, repo, ref string) (string, error) {
	if err := errors.Join(rules.CheckRepo(owner, repo), rules.CheckRef(ref)); err != nil {
		return "", err
	}
	segments := strings.Split(ref, "/")
	for i, s := range segments {
		segments[i] = url.PathEscape(s)
	}
	u := fmt.Sprintf("%s/repos/%s/%s/commits/%s", f.apiBase(), owner, repo, strings.Join(segments, "/"))
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u, nil)
	if err != nil {
		return "", fmt.Errorf("resolve ref: %w", err)
	}
	req.Header.Set("Accept", "application/vnd.github.sha")
	resp, err := f.client().Do(req)
	if err != nil {
		return "", fmt.Errorf("resolve ref: %w", err)
	}
	defer func() { _ = resp.Body.Close() }()
	// GitHub answers 422 for a ref that looks like a sha and names no commit.
	if resp.StatusCode == http.StatusNotFound || resp.StatusCode == http.StatusUnprocessableEntity {
		return "", fmt.Errorf("%s/%s has no ref %q, or the repository is private", owner, repo, ref)
	}
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("resolve ref: GitHub answered %s", resp.Status)
	}
	body, err := io.ReadAll(io.LimitReader(resp.Body, 256))
	if err != nil {
		return "", fmt.Errorf("resolve ref: %w", err)
	}
	sha := strings.TrimSpace(string(body))
	if err := rules.CheckSHA(sha); err != nil {
		return "", fmt.Errorf("resolve ref: GitHub answered with a %w", err)
	}

	return sha, nil
}

// Fetch downloads the commit sha of the package and extracts the package
// directory into dest, which it replaces. The package must hold a manifest.
func (f *Fetcher) Fetch(ctx context.Context, spec Spec, sha, dest string) error {
	if err := errors.Join(rules.CheckRepo(spec.Owner, spec.Repo), rules.CheckPath(spec.Path), rules.CheckSHA(sha)); err != nil {
		return err
	}
	u := fmt.Sprintf("%s/%s/%s/tar.gz/%s", f.codeloadBase(), spec.Owner, spec.Repo, sha)
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u, nil)
	if err != nil {
		return fmt.Errorf("download %s: %w", spec.Package(), err)
	}
	resp, err := f.client().Do(req)
	if err != nil {
		return fmt.Errorf("download %s: %w", spec.Package(), err)
	}
	defer func() { _ = resp.Body.Close() }()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("download %s: GitHub answered %s", spec.Package(), resp.Status)
	}

	parent := filepath.Dir(dest)
	if err := os.MkdirAll(parent, 0o755); err != nil {
		return err
	}
	tmp, err := os.MkdirTemp(parent, "."+filepath.Base(dest)+".tmp-*")
	if err != nil {
		return err
	}
	done := false
	defer func() {
		if !done {
			_ = os.RemoveAll(tmp)
		}
	}()

	x := extractor{dir: tmp, path: spec.Path, maxBytes: f.maxBytes, maxEntries: f.maxEntries, links: map[string]bool{}, files: map[string]bool{}}
	if x.maxBytes == 0 {
		x.maxBytes = MaxArchiveBytes
	}
	if x.maxEntries == 0 {
		x.maxEntries = MaxArchiveEntries
	}
	if err := x.extract(resp.Body); err != nil {
		return fmt.Errorf("extract %s: %w", spec.ID(), err)
	}
	if info, err := os.Lstat(filepath.Join(tmp, ManifestFile)); err != nil || !info.Mode().IsRegular() {
		return fmt.Errorf("%s has no %s at commit %s", spec.ID(), ManifestFile, sha)
	}
	if err := os.Chmod(tmp, 0o755); err != nil {
		return err
	}
	if err := replace(tmp, dest); err != nil {
		return err
	}
	done = true

	return nil
}

// replace moves dir to dest, and puts an existing dest back when the move fails.
func replace(dir, dest string) error {
	if _, err := os.Lstat(dest); errors.Is(err, os.ErrNotExist) {
		return os.Rename(dir, dest)
	}
	old := dir + ".old"
	if err := os.Rename(dest, old); err != nil {
		return err
	}
	if err := os.Rename(dir, dest); err != nil {
		_ = os.Rename(old, dest)

		return err
	}

	return os.RemoveAll(old)
}

type extractor struct {
	dir        string
	path       string
	maxBytes   int64
	maxEntries int

	top     string
	bytes   int64
	entries int
	// links and files hold the names extracted so far, relative to dir.
	links map[string]bool
	files map[string]bool
}

func (x *extractor) extract(r io.Reader) error {
	gz, err := gzip.NewReader(r)
	if err != nil {
		return err
	}
	root, err := os.OpenRoot(x.dir)
	if err != nil {
		return err
	}
	defer func() { _ = root.Close() }()

	tr := tar.NewReader(gz)
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return err
		}
		// codeload starts with a pax global header that names no file.
		if h.Typeflag != tar.TypeReg && h.Typeflag != tar.TypeDir && h.Typeflag != tar.TypeSymlink && h.Typeflag != tar.TypeLink {
			continue
		}
		x.entries++
		if x.entries > x.maxEntries {
			return fmt.Errorf("the archive has more than %d entries", x.maxEntries)
		}
		x.bytes += h.Size
		if x.bytes > x.maxBytes {
			return fmt.Errorf("the archive is larger than %d bytes", x.maxBytes)
		}
		rel, keep, err := x.name(h.Name)
		if err != nil {
			return err
		}
		if !keep {
			continue
		}
		if err := x.entry(root, tr, h, rel); err != nil {
			return err
		}
	}

	return x.checkLinks()
}

// name maps an archive name to a name relative to the package directory. It
// strips the one top directory, which codeload names repo-shortsha, and the
// package path. keep is false for a name outside the package.
func (x *extractor) name(raw string) (string, bool, error) {
	clean := strings.TrimSuffix(raw, "/")
	bad := clean == "" || strings.HasPrefix(raw, "/")
	for seg := range strings.SplitSeq(clean, "/") {
		if seg == "" || seg == "." || seg == ".." {
			bad = true
		}
	}
	if bad {
		return "", false, fmt.Errorf("entry %q is not a clean relative name", raw)
	}
	top, rest, _ := strings.Cut(clean, "/")
	if x.top == "" {
		x.top = top
	} else if top != x.top {
		return "", false, fmt.Errorf("entry %q: the archive has more than one top directory", raw)
	}
	if x.path != "" {
		if rest != x.path && !strings.HasPrefix(rest, x.path+"/") {
			return "", false, nil
		}
		rest = strings.TrimPrefix(strings.TrimPrefix(rest, x.path), "/")
	}
	if rest == "" {
		return "", true, nil
	}
	if !filepath.IsLocal(filepath.FromSlash(rest)) {
		return "", false, fmt.Errorf("entry %q is not a clean relative name", raw)
	}

	return rest, true, nil
}

func (x *extractor) entry(root *os.Root, r io.Reader, h *tar.Header, rel string) error {
	if rel == "" {
		if h.Typeflag == tar.TypeDir {
			return nil
		}

		return fmt.Errorf("entry %q is not a directory", h.Name)
	}
	if err := x.checkNotThroughLink(rel); err != nil {
		return err
	}
	name := filepath.FromSlash(rel)
	if parent := path.Dir(rel); parent != "." {
		if err := root.MkdirAll(filepath.FromSlash(parent), 0o755); err != nil {
			return err
		}
	}

	switch h.Typeflag {
	case tar.TypeDir:
		if err := root.MkdirAll(name, 0o755); err != nil {
			return err
		}

		return root.Chmod(name, 0o755)
	case tar.TypeSymlink:
		target := h.Linkname
		if target == "" || path.IsAbs(target) || filepath.IsAbs(target) || !filepath.IsLocal(filepath.FromSlash(path.Join(path.Dir(rel), target))) {
			return fmt.Errorf("link %q to %q leaves the package", h.Name, target)
		}
		x.links[rel] = true

		return root.Symlink(target, name)
	case tar.TypeLink:
		target, keep, err := x.name(h.Linkname)
		if err != nil || !keep || !x.files[target] {
			return fmt.Errorf("link %q to %q leaves the package", h.Name, h.Linkname)
		}
		x.files[rel] = true

		return root.Link(filepath.FromSlash(target), name)
	}

	mode := os.FileMode(0o644)
	if h.Mode&0o111 != 0 {
		mode = 0o755
	}
	file, err := root.OpenFile(name, os.O_WRONLY|os.O_CREATE|os.O_EXCL, mode)
	if err != nil {
		return err
	}
	if _, err := io.CopyN(file, r, h.Size); err != nil {
		_ = file.Close()

		return err
	}
	if err := file.Close(); err != nil {
		return err
	}
	x.files[rel] = true

	return root.Chmod(name, mode)
}

// checkNotThroughLink refuses a name that is, or sits under, an extracted
// link, so no write lands where a link points.
func (x *extractor) checkNotThroughLink(rel string) error {
	prefix := ""
	for seg := range strings.SplitSeq(rel, "/") {
		prefix = path.Join(prefix, seg)
		if x.links[prefix] {
			return fmt.Errorf("entry %q goes through a link", rel)
		}
	}

	return nil
}

// checkLinks resolves each link on disk. A link can pass the check by name and
// still leave, as l to m/.. does when m links to the package root.
func (x *extractor) checkLinks() error {
	base, err := filepath.EvalSymlinks(x.dir)
	if err != nil {
		return err
	}
	for rel := range x.links {
		resolved, err := filepath.EvalSymlinks(filepath.Join(x.dir, filepath.FromSlash(rel)))
		if err != nil {
			return fmt.Errorf("link %q leads nowhere in the package", rel)
		}
		inside, err := filepath.Rel(base, resolved)
		if err != nil || (inside != "." && !filepath.IsLocal(inside)) {
			return fmt.Errorf("link %q leaves the package", rel)
		}
	}

	return nil
}
