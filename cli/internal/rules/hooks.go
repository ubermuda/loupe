package rules

import (
	"bytes"
	"errors"
	"fmt"
	"maps"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"strings"
	"unicode"

	"go.yaml.in/yaml/v3"
)

// HookEntry is one installed hook package in the rule file.
type HookEntry struct {
	// Package is the GitHub repository, as owner/repo.
	Package string `yaml:"package"`
	// Path is the package directory inside the repository. Empty is its root.
	Path string `yaml:"path,omitempty"`
	// Ref is the tag, branch or sha the operator installed, as a label.
	Ref string `yaml:"ref"`
	// SHA is the commit the bridge runs, whatever Ref names today.
	SHA      string            `yaml:"sha"`
	Settings map[string]string `yaml:"settings,omitempty"`
}

// ID names the package as owner/repo, or owner/repo/path.
func (e HookEntry) ID() string {
	if e.Path == "" {
		return e.Package
	}

	return e.Package + "/" + e.Path
}

// The characters GitHub allows in an owner and in a repository name.
var (
	ownerPattern = regexp.MustCompile(`^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$`)
	repoPattern  = regexp.MustCompile(`^[A-Za-z0-9._-]+$`)
	shaPattern   = regexp.MustCompile(`^[0-9a-f]{40}$`)
)

// CheckRepo refuses an owner or a repository name that GitHub would not take,
// or that is not safe as a directory name.
func CheckRepo(owner, repo string) error {
	if !ownerPattern.MatchString(owner) || !repoPattern.MatchString(repo) || repo == "." || repo == ".." {
		return fmt.Errorf("package %q is not owner/repo", owner+"/"+repo)
	}

	return nil
}

// CheckPackage refuses a package that is not owner/repo.
func CheckPackage(pkg string) error {
	owner, repo, ok := strings.Cut(pkg, "/")
	if !ok || strings.Contains(repo, "/") {
		return fmt.Errorf("package %q is not owner/repo", pkg)
	}

	return CheckRepo(owner, repo)
}

// CheckPath refuses a package path that is not clean and relative, because
// the bridge builds directories from it. Empty is the repository root.
func CheckPath(p string) error {
	if p == "" {
		return nil
	}
	if path.Clean(p) != p || strings.HasPrefix(p, "/") || !filepath.IsLocal(filepath.FromSlash(p)) {
		return fmt.Errorf("path %q is not a clean relative path", p)
	}
	for seg := range strings.SplitSeq(p, "/") {
		if seg == ".." || strings.ContainsAny(seg, `\:`) {
			return fmt.Errorf("path %q is not a clean relative path", p)
		}
	}

	return nil
}

// CheckRef refuses a ref that git would not take as a tag, a branch or a sha.
func CheckRef(ref string) error {
	if ref == "" {
		return errors.New("ref is required")
	}
	bad := strings.ContainsFunc(ref, func(r rune) bool { return unicode.IsSpace(r) || unicode.IsControl(r) }) ||
		strings.ContainsAny(ref, `~^:?*[\`) ||
		strings.Contains(ref, "..") || strings.Contains(ref, "//") || strings.Contains(ref, "@{") ||
		strings.HasPrefix(ref, "/") || strings.HasSuffix(ref, "/") || strings.HasSuffix(ref, ".") ||
		strings.HasPrefix(ref, "-")
	if bad {
		return fmt.Errorf("ref %q is not a git ref", ref)
	}

	return nil
}

// CheckSHA refuses a value that is not a full commit id.
func CheckSHA(sha string) error {
	if !shaPattern.MatchString(sha) {
		return fmt.Errorf("sha %q is not 40 lowercase hex characters", sha)
	}

	return nil
}

func checkHooks(entries []HookEntry) []error {
	var errs []error
	seen := map[string]bool{}
	for i, e := range entries {
		if err := errors.Join(CheckPackage(e.Package), CheckPath(e.Path), CheckRef(e.Ref), CheckSHA(e.SHA)); err != nil {
			errs = append(errs, fmt.Errorf("hook %d: %w", i+1, err))

			continue
		}
		// GitHub names ignore case, and so does the default macOS file system.
		key := strings.ToLower(e.ID())
		if seen[key] {
			errs = append(errs, fmt.Errorf("hook %q: another hook installs the same package", e.ID()))
		}
		seen[key] = true
	}

	return errs
}

// Hooks lists the installed hook packages in file order.
func (s *Set) Hooks() []HookEntry {
	out := make([]HookEntry, len(s.hooks))
	for i, e := range s.hooks {
		e.Settings = maps.Clone(e.Settings)
		out[i] = e
	}

	return out
}

// EditHooks rewrites the hooks list of the rule file at path, and leaves every
// other node, comments included, as it was. The file stays unchanged when edit
// fails or when the result does not parse.
func EditHooks(path string, edit func([]HookEntry) ([]HookEntry, error)) error {
	data, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return fmt.Errorf("read rule file: %w", ErrMissing)
	}
	if err != nil {
		return fmt.Errorf("read rule file: %w", err)
	}
	if _, err := Parse(data, Defaults{}); err != nil {
		return err
	}

	var doc yaml.Node
	if err := yaml.Unmarshal(data, &doc); err != nil {
		return fmt.Errorf("parse rule file: %w", err)
	}
	if doc.Kind != yaml.DocumentNode || len(doc.Content) != 1 || doc.Content[0].Kind != yaml.MappingNode {
		return errors.New("parse rule file: the first YAML document is not a mapping, so the bridge cannot edit it")
	}
	top := doc.Content[0]

	var hooks []HookEntry
	at := -1
	for i := 0; i+1 < len(top.Content); i += 2 {
		if top.Content[i].Value == "hooks" {
			at = i
			if err := top.Content[i+1].Decode(&hooks); err != nil {
				return fmt.Errorf("parse rule file: %w", err)
			}
		}
	}

	hooks, err = edit(hooks)
	if err != nil {
		return err
	}

	switch {
	case len(hooks) == 0 && at >= 0:
		top.Content = append(top.Content[:at], top.Content[at+2:]...)
	case len(hooks) > 0:
		var value yaml.Node
		if err := value.Encode(hooks); err != nil {
			return fmt.Errorf("encode hooks: %w", err)
		}
		if at >= 0 {
			top.Content[at+1] = &value
		} else {
			top.Content = append(top.Content, &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!str", Value: "hooks"}, &value)
		}
	}

	var out bytes.Buffer
	enc := yaml.NewEncoder(&out)
	enc.SetIndent(2)
	if err := enc.Encode(&doc); err != nil {
		return fmt.Errorf("encode rule file: %w", err)
	}
	if err := enc.Close(); err != nil {
		return fmt.Errorf("encode rule file: %w", err)
	}
	if _, err := Parse(out.Bytes(), Defaults{}); err != nil {
		return err
	}

	return writeAtomic(path, out.Bytes())
}

// writeAtomic replaces the file at path in one rename, with its old mode.
func writeAtomic(path string, data []byte) error {
	info, err := os.Stat(path)
	if err != nil {
		return fmt.Errorf("write rule file: %w", err)
	}
	tmp, err := os.CreateTemp(filepath.Dir(path), ".rules-*")
	if err != nil {
		return fmt.Errorf("write rule file: %w", err)
	}
	done := false
	defer func() {
		if !done {
			_ = tmp.Close()
			_ = os.Remove(tmp.Name())
		}
	}()
	if _, err := tmp.Write(data); err != nil {
		return fmt.Errorf("write rule file: %w", err)
	}
	if err := tmp.Sync(); err != nil {
		return fmt.Errorf("write rule file: %w", err)
	}
	if err := tmp.Close(); err != nil {
		return fmt.Errorf("write rule file: %w", err)
	}
	if err := os.Chmod(tmp.Name(), info.Mode().Perm()); err != nil {
		return fmt.Errorf("write rule file: %w", err)
	}
	if err := os.Rename(tmp.Name(), path); err != nil {
		return fmt.Errorf("write rule file: %w", err)
	}
	done = true

	return nil
}
