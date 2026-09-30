package rules

import (
	"bytes"
	"errors"
	"fmt"
	"io"
	"maps"
	"os"
	"path/filepath"
	"reflect"
	"slices"

	"github.com/ubermuda/loupe/cli/internal/update"
	"go.yaml.in/yaml/v3"
)

// autoUpdateKey is the top-level key that turns automatic updates on.
const autoUpdateKey = "autoUpdate"

// ErrAppendRefused marks a rule file where a new last line would not add the
// autoUpdate key alone.
var ErrAppendRefused = errors.New("a new last line would change what the rule file means")

// AutoUpdateLine is the line that sets the key to on.
func AutoUpdateLine(on bool) string {
	return fmt.Sprintf("%s: %t", autoUpdateKey, on)
}

// ReadAutoUpdate reads the autoUpdate key of the rule file at path. present is
// false when the file or the key is absent.
func ReadAutoUpdate(path string) (on, present bool, err error) {
	data, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return false, false, nil
	}
	if err != nil {
		return false, false, fmt.Errorf("read rule file: %w", err)
	}
	m, err := decodeMap(data)
	if err != nil {
		return false, false, err
	}

	return autoUpdateOf(m)
}

// SetAutoUpdate adds the autoUpdate key to the rule file at path, and creates
// the file when it is absent. A file that holds the key keeps it, and kept is
// its value. The key goes on a new last line, so comments and layout stay. The
// write happens only when the file then decodes to its old content plus the
// key.
func SetAutoUpdate(path string, on bool) (kept *bool, err error) {
	line := AutoUpdateLine(on) + "\n"
	data, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return nil, createFile(path, line)
	}
	if err != nil {
		return nil, fmt.Errorf("read rule file: %w", err)
	}
	old, err := decodeMap(data)
	if err != nil {
		return nil, err
	}
	value, present, err := autoUpdateOf(old)
	if err != nil {
		return nil, err
	}
	if present {
		return &value, nil
	}

	next := slices.Clone(data)
	if len(next) > 0 && next[len(next)-1] != '\n' {
		next = append(next, '\n')
	}
	next = append(next, line...)
	want := maps.Clone(old)
	if want == nil {
		want = map[string]any{}
	}
	want[autoUpdateKey] = on
	if got, err := decodeMap(next); err != nil || !reflect.DeepEqual(got, want) {
		return nil, fmt.Errorf("%w; add the line %q at the top level by hand", ErrAppendRefused, AutoUpdateLine(on))
	}
	real, err := filepath.EvalSymlinks(path)
	if err != nil {
		return nil, err
	}
	info, err := os.Stat(real)
	if err != nil {
		return nil, err
	}

	return nil, update.WriteFile(real, next, info.Mode().Perm())
}

// createFile writes a new rule file that holds line. It fails when a file
// appears at path in the meantime.
func createFile(path, line string) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return fmt.Errorf("create config dir: %w", err)
	}
	f, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
	if err != nil {
		return err
	}
	_, err = f.WriteString(line)

	return errors.Join(err, f.Close())
}

// autoUpdateOf reads the key from a decoded file. An empty value is false, as
// Parse reads it.
func autoUpdateOf(m map[string]any) (on, present bool, err error) {
	v, present := m[autoUpdateKey]
	if !present || v == nil {
		return false, present, nil
	}
	on, ok := v.(bool)
	if !ok {
		return false, true, fmt.Errorf("parse rule file: autoUpdate %v is not true or false", v)
	}

	return on, true, nil
}

// decodeMap decodes the one document that holds content, as decodeFile does,
// into a generic map. It is nil for a file with no content.
func decodeMap(data []byte) (map[string]any, error) {
	var out map[string]any
	found := false
	docs := yaml.NewDecoder(bytes.NewReader(data))
	for {
		var doc any
		if err := docs.Decode(&doc); errors.Is(err, io.EOF) {
			break
		} else if err != nil {
			return nil, fmt.Errorf("parse rule file: %w", err)
		}
		if doc == nil {
			continue
		}
		if found {
			return nil, errors.New("parse rule file: it holds a second YAML document after ---, and the bridge reads one")
		}
		found = true
		m, ok := doc.(map[string]any)
		if !ok {
			return nil, errors.New("parse rule file: the top level is not a mapping of keys")
		}
		out = m
	}

	return out, nil
}
