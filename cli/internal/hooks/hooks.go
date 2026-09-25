// Package hooks installs hook packages from GitHub and loads the ones the rule
// file lists.
package hooks

import (
	"errors"
	"fmt"
	"maps"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// Spec is a package the operator names on the command line, such as
// owner/repo/path@ref.
type Spec struct {
	Owner string
	Repo  string
	// Path is the package directory inside the repository. Empty is its root.
	Path string
	Ref  string
}

// ParseSpec reads owner/repo[/path]@ref. A name holds no @, so the first one
// starts the ref.
func ParseSpec(s string) (Spec, error) {
	name, ref, ok := strings.Cut(s, "@")
	if !ok {
		return Spec{}, fmt.Errorf("package %q names no ref; add @ and a tag, a branch or a sha", s)
	}
	parts := strings.SplitN(name, "/", 3)
	if len(parts) < 2 {
		return Spec{}, fmt.Errorf("package %q is not owner/repo", name)
	}
	spec := Spec{Owner: parts[0], Repo: parts[1], Ref: ref}
	if len(parts) == 3 {
		spec.Path = parts[2]
		if spec.Path == "" {
			return Spec{}, fmt.Errorf("path %q is not a clean relative path", "/")
		}
	}
	if err := errors.Join(rules.CheckRepo(spec.Owner, spec.Repo), rules.CheckPath(spec.Path), rules.CheckRef(spec.Ref)); err != nil {
		return Spec{}, err
	}

	return spec, nil
}

// Package is the repository, as owner/repo.
func (s Spec) Package() string {
	return s.Owner + "/" + s.Repo
}

// ID names the package as owner/repo, or owner/repo/path.
func (s Spec) ID() string {
	return rules.HookEntry{Package: s.Package(), Path: s.Path}.ID()
}

// PackageDir is where the commit sha of a package lives under the config
// directory root.
func PackageDir(root, id, sha string) string {
	return filepath.Join(root, "hooks", "packages", filepath.FromSlash(id), sha)
}

// StateDir is the directory a package keeps across its updates.
func StateDir(root, id string) string {
	return filepath.Join(root, "hooks", "state", filepath.FromSlash(id))
}

// Hook is an installed package, ready to run.
type Hook struct {
	ID       string
	Ref      string
	SHA      string
	Dir      string
	StateDir string
	Timeout  time.Duration
	Events   map[string][]string
	// Settings holds every setting of the manifest, with the defaults filled.
	Settings map[string]string
}

// MakeStateDir creates the state directory, readable by the operator alone.
func (h Hook) MakeStateDir() error {
	return os.MkdirAll(h.StateDir, 0o700)
}

// Resolve loads the manifest of each entry from root and checks the entry's
// settings against it. goos is the system the bridge runs on.
func Resolve(root string, entries []rules.HookEntry, goos string) ([]Hook, error) {
	var out []Hook
	var errs []error
	for _, e := range entries {
		h, err := resolve(root, e, goos)
		if err != nil {
			errs = append(errs, fmt.Errorf("hook %s: %w", e.ID(), err))

			continue
		}
		out = append(out, h)
	}
	if len(errs) > 0 {
		return nil, errors.Join(errs...)
	}
	rows := 0
	for _, h := range out {
		rows += len(h.Events)
	}
	if rows > api.MaxHookRows {
		return nil, fmt.Errorf("the hooks define %d events in all, and Loupe shows at most %d; remove a package", rows, api.MaxHookRows)
	}

	return out, nil
}

func resolve(root string, e rules.HookEntry, goos string) (Hook, error) {
	if err := errors.Join(rules.CheckPackage(e.Package), rules.CheckPath(e.Path), rules.CheckSHA(e.SHA)); err != nil {
		return Hook{}, err
	}
	dir := PackageDir(root, e.ID(), e.SHA)
	m, err := LoadManifest(dir)
	if errors.Is(err, os.ErrNotExist) {
		return Hook{}, fmt.Errorf("the package is not installed in %s; install it again", dir)
	}
	if err != nil {
		return Hook{}, err
	}
	if len(m.OS) > 0 && !slices.Contains(m.OS, goos) {
		return Hook{}, fmt.Errorf("the package runs on %s, and this machine runs %s", strings.Join(m.OS, ", "), goos)
	}

	settings := map[string]string{}
	for name, s := range m.Settings {
		settings[name] = s.Default
	}
	var errs []error
	for _, name := range slices.Sorted(maps.Keys(e.Settings)) {
		s, ok := m.Settings[name]
		if !ok {
			errs = append(errs, fmt.Errorf("setting %q is not in the manifest, which has %s", name, settingNames(m)))

			continue
		}
		if err := s.Check(e.Settings[name]); err != nil {
			errs = append(errs, fmt.Errorf("setting %q: %w", name, err))

			continue
		}
		settings[name] = e.Settings[name]
	}
	if len(errs) > 0 {
		return Hook{}, errors.Join(errs...)
	}

	return Hook{
		ID:       e.ID(),
		Ref:      e.Ref,
		SHA:      e.SHA,
		Dir:      dir,
		StateDir: StateDir(root, e.ID()),
		Timeout:  m.Timeout,
		Events:   m.Events,
		Settings: settings,
	}, nil
}

func settingNames(m Manifest) string {
	if len(m.Settings) == 0 {
		return "none"
	}

	return strings.Join(slices.Sorted(maps.Keys(m.Settings)), ", ")
}
