// Package envfile reads the KEY=VALUE environment files that an account of
// the bridge names. An error names the file and the line, and never a value.
package envfile

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
)

var keyPattern = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*$`)

// Read gives the KEY=VALUE pairs of the file at path, in file order. A line
// can start with export, and one pair of matching quotes around a value goes.
// Nothing expands.
func Read(path string) ([]string, error) {
	b, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("read the environment file %s: %w", path, err)
	}

	var pairs []string
	for i, line := range strings.Split(string(b), "\n") {
		n := i + 1
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		line = strings.TrimSpace(strings.TrimPrefix(line, "export "))
		key, value, ok := strings.Cut(line, "=")
		if !ok {
			return nil, fmt.Errorf("%s:%d: the line is no KEY=VALUE pair", path, n)
		}
		key = strings.TrimSpace(key)
		if !keyPattern.MatchString(key) {
			return nil, fmt.Errorf("%s:%d: a key is letters, digits and underscores, and starts with no digit", path, n)
		}
		pairs = Overlay(pairs, []string{key + "=" + unquote(strings.TrimSpace(value))})
	}

	return pairs, nil
}

func unquote(v string) string {
	if len(v) >= 2 && (v[0] == '"' || v[0] == '\'') && v[len(v)-1] == v[0] {
		return v[1 : len(v)-1]
	}

	return v
}

// Overlay is environ with each key of pairs replaced by its value in pairs.
func Overlay(environ, pairs []string) []string {
	keys := make(map[string]bool, len(pairs))
	for _, p := range pairs {
		k, _, _ := strings.Cut(p, "=")
		keys[k] = true
	}
	out := slices.DeleteFunc(slices.Clone(environ), func(e string) bool {
		k, _, _ := strings.Cut(e, "=")

		return keys[k]
	})

	return append(out, pairs...)
}

// LookPath finds program on the PATH of env, which a worker shell in dir
// searches, and on the PATH of the bridge when env sets none. A relative entry
// counts from dir, and an empty dir skips it.
func LookPath(program string, env []string, dir string) (string, error) {
	path, ok := "", false
	for _, kv := range env {
		if v, found := strings.CutPrefix(kv, "PATH="); found {
			path, ok = v, true
		}
	}
	if !ok {
		return exec.LookPath(program)
	}
	for _, entry := range filepath.SplitList(path) {
		if !filepath.IsAbs(entry) {
			if dir == "" {
				continue
			}
			entry = filepath.Join(dir, entry)
		}
		candidate := filepath.Join(entry, program)
		if info, err := os.Stat(candidate); err == nil && info.Mode().IsRegular() && info.Mode()&0o111 != 0 {
			return candidate, nil
		}
	}

	return "", fmt.Errorf("%s not found in PATH %s", program, path)
}
