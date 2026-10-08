package rules

import (
	"bytes"
	"cmp"
	"errors"
	"fmt"
	"maps"
	"os"
	"path/filepath"
	"reflect"
	"slices"
	"strings"

	"github.com/ubermuda/loupe/cli/internal/update"
	"go.yaml.in/yaml/v3"
)

// migratedAccount is the account the migration declares.
const migratedAccount = "claude"

// entryLevels maps the permission mode of an old work entry to the level that
// runs it. acceptEdits has no level of its own, and workspace is the nearest.
var entryLevels = map[string]string{
	"plan":              PermissionsReadOnly,
	"acceptEdits":       PermissionsWorkspace,
	"auto":              PermissionsWorkspace,
	"bypassPermissions": PermissionsFull,
}

// movedDefaults are the old defaults keys that move into the account.
var movedDefaults = []string{"model", "permissionMode"}

// ErrMigrationRefused marks a rule file that line edits cannot give an
// accounts block.
var ErrMigrationRefused = errors.New("the accounts block cannot be added by line edits alone")

// lineEdit replaces the bytes from start to end with text.
type lineEdit struct {
	start, end int
	text       string
}

// MigrateAccounts gives a rule file with no accounts block the account claude,
// which takes defaults.model and defaults.permissionMode. Each entry's
// permissionMode becomes a level. Only those lines change, and the write
// happens only when the file then decodes as expected. block is what an
// operator adds by hand when the migration fails.
func MigrateAccounts(path string) (changed bool, block string, err error) {
	data, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return false, "", nil
	}
	if err != nil {
		return false, "", fmt.Errorf("read rule file: %w", err)
	}
	old, err := decodeMap(data)
	if err != nil {
		return false, accountsBlock(nil), err
	}
	if _, ok := old["accounts"]; ok || old == nil {
		return false, "", nil
	}
	defaults, _ := old["defaults"].(map[string]any)
	block = accountsBlock(defaults)
	want, err := migratedMap(old)
	if err != nil {
		return false, block, err
	}
	edits, err := accountsEdits(data)
	if err != nil {
		return false, block, err
	}
	next := applyEdits(data, edits)
	if got, err := decodeMap(next); err != nil || !reflect.DeepEqual(got, want) {
		return false, block, fmt.Errorf("%w: the edited file would not hold the same rules", ErrMigrationRefused)
	}
	real, err := filepath.EvalSymlinks(path)
	if err != nil {
		return false, block, err
	}
	info, err := os.Stat(real)
	if err != nil {
		return false, block, err
	}
	if err := update.WriteFile(real, next, info.Mode().Perm()); err != nil {
		return false, block, err
	}

	return true, block, nil
}

// migratedMap is the decoded content the migrated file must hold.
func migratedMap(old map[string]any) (map[string]any, error) {
	want := maps.Clone(old)
	account := map[string]any{"harness": HarnessClaudeCode}
	defaults := map[string]any{}
	switch d := old["defaults"].(type) {
	case nil:
	case map[string]any:
		defaults = maps.Clone(d)
	default:
		return nil, fmt.Errorf("%w: defaults is not a mapping", ErrMigrationRefused)
	}
	for _, key := range movedDefaults {
		if v, ok := defaults[key]; ok {
			if v != nil {
				account[key] = v
			}
			delete(defaults, key)
		}
	}
	defaults["account"] = migratedAccount
	want["accounts"] = map[string]any{migratedAccount: account}
	want["defaults"] = defaults

	work, ok := old["work"].(map[string]any)
	if !ok {
		return want, nil
	}
	work = maps.Clone(work)
	var errs []error
	for _, kind := range slices.Sorted(maps.Keys(work)) {
		entry, ok := work[kind].(map[string]any)
		if !ok {
			continue
		}
		mode, ok := entry["permissionMode"]
		if !ok {
			continue
		}
		entry = maps.Clone(entry)
		delete(entry, "permissionMode")
		if mode != nil {
			name, _ := mode.(string)
			level, ok := entryLevels[name]
			if !ok {
				errs = append(errs, fmt.Errorf("work %q: permissionMode %v has no permission level; set permissions: %s, %s or %s by hand", kind, mode, PermissionsReadOnly, PermissionsWorkspace, PermissionsFull))

				continue
			}
			entry["permissions"] = level
		}
		work[kind] = entry
	}
	want["work"] = work

	return want, errors.Join(errs...)
}

// accountsEdits finds the lines to change. The accounts block goes above
// defaults, or at the end of a file with no defaults.
func accountsEdits(data []byte) ([]lineEdit, error) {
	refuse := func(reason string) error { return fmt.Errorf("%w: %s", ErrMigrationRefused, reason) }
	root := contentRoot(data)
	if root == nil || root.Kind != yaml.MappingNode || root.Style&yaml.FlowStyle != 0 {
		return nil, refuse("the top level is not a block mapping")
	}
	var edits []lineEdit
	account := "accounts:\n  " + migratedAccount + ":\n    harness: " + HarnessClaudeCode + "\n"
	key, value := pair(root, "defaults")
	switch {
	case key == nil:
		text := account + "defaults:\n  account: " + migratedAccount + "\n"
		if len(data) > 0 && data[len(data)-1] != '\n' {
			text = "\n" + text
		}
		edits = append(edits, lineEdit{len(data), len(data), text})
	case key.Column != 1:
		return nil, refuse("the top-level keys are indented")
	case value.Kind == yaml.ScalarNode && value.Tag == "!!null":
		edits = append(edits, lineEdit{lineStart(data, key.Line+1), lineStart(data, key.Line+1), "  account: " + migratedAccount + "\n"})
	case value.Kind != yaml.MappingNode || value.Style&yaml.FlowStyle != 0 || len(value.Content) == 0:
		return nil, refuse("defaults is not a block mapping")
	default:
		indent := strings.Repeat(" ", value.Content[0].Column-1)
		at := lineStart(data, key.Line+1)
		edits = append(edits, lineEdit{at, at, indent + "account: " + migratedAccount + "\n"})
		for _, name := range movedDefaults {
			k, v := pair(value, name)
			if k == nil {
				continue
			}
			if !onKeyLine(k, v) {
				return nil, refuse("defaults." + name + " is not one plain value on its own line")
			}
			if v.Tag != "!!null" {
				start, end, ok := tokenAt(data, v.Line, v.Column)
				if !ok {
					return nil, refuse("defaults." + name + " has no value to move")
				}
				account += "    " + name + ": " + string(data[start:end]) + "\n"
			}
			edits = append(edits, lineEdit{lineStart(data, k.Line), lineStart(data, k.Line+1), ""})
		}
		at = lineStart(data, key.Line)
		edits = append(edits, lineEdit{at, at, account})
	}

	_, work := pair(root, "work")
	if work == nil || work.Kind != yaml.MappingNode {
		return edits, nil
	}
	for i := 0; i+1 < len(work.Content); i += 2 {
		kind, entry := work.Content[i].Value, work.Content[i+1]
		k, v := pair(entry, "permissionMode")
		if k == nil {
			continue
		}
		if entry.Style&yaml.FlowStyle != 0 || !onKeyLine(k, v) {
			return nil, refuse(fmt.Sprintf("the permissionMode of work %q is not one plain value on its own line", kind))
		}
		if v.Tag == "!!null" {
			edits = append(edits, lineEdit{lineStart(data, k.Line), lineStart(data, k.Line+1), ""})

			continue
		}
		start, _, okKey := tokenAt(data, k.Line, k.Column)
		_, end, okValue := tokenAt(data, v.Line, v.Column)
		if !okKey || !okValue {
			return nil, refuse(fmt.Sprintf("the permissionMode of work %q cannot be found on its line", kind))
		}
		edits = append(edits, lineEdit{start, end, "permissions: " + entryLevels[v.Value]})
	}

	return edits, nil
}

// onKeyLine reports whether a value is one plain or quoted scalar on the line
// of its key, so removing or rewriting that line moves it whole.
func onKeyLine(k, v *yaml.Node) bool {
	return v.Kind == yaml.ScalarNode && k.Anchor == "" && v.Anchor == "" && v.Line == k.Line &&
		v.Style&(yaml.LiteralStyle|yaml.FoldedStyle|yaml.TaggedStyle) == 0
}

// pair is the key and value nodes of key in a mapping node, or nil.
func pair(n *yaml.Node, key string) (*yaml.Node, *yaml.Node) {
	if n == nil || n.Kind != yaml.MappingNode {
		return nil, nil
	}
	for i := 0; i+1 < len(n.Content); i += 2 {
		if n.Content[i].Value == key {
			return n.Content[i], n.Content[i+1]
		}
	}

	return nil, nil
}

// contentRoot is the root node of the first document that holds content.
func contentRoot(data []byte) *yaml.Node {
	docs := yaml.NewDecoder(bytes.NewReader(data))
	for {
		var doc yaml.Node
		if err := docs.Decode(&doc); err != nil {
			return nil
		}
		if len(doc.Content) > 0 && doc.Content[0].Tag != "!!null" {
			return doc.Content[0]
		}
	}
}

// lineStart is the byte offset of a 1-based line, or the file length past
// the last line.
func lineStart(data []byte, line int) int {
	at := 0
	for range line - 1 {
		i := slices.Index(data[at:], '\n')
		if i < 0 {
			return len(data)
		}
		at += i + 1
	}

	return at
}

// applyEdits applies edits that do not overlap. An insertion goes before a
// removal that starts at the same byte.
func applyEdits(data []byte, edits []lineEdit) []byte {
	slices.SortStableFunc(edits, func(a, b lineEdit) int {
		return cmp.Or(cmp.Compare(a.start, b.start), cmp.Compare(a.end, b.end))
	})
	var out []byte
	at := 0
	for _, e := range edits {
		out = append(out, data[at:e.start]...)
		out = append(out, e.text...)
		at = e.end
	}

	return append(out, data[at:]...)
}

// accountsBlock is what an operator adds to rules.yaml by hand.
func accountsBlock(defaults map[string]any) string {
	var b strings.Builder
	b.WriteString("accounts:\n  " + migratedAccount + ":\n    harness: " + HarnessClaudeCode + "\n")
	for _, key := range movedDefaults {
		if v := defaults[key]; v != nil {
			fmt.Fprintf(&b, "    %s: %v\n", key, v)
		}
	}
	b.WriteString("defaults:\n  account: " + migratedAccount + "\n")
	b.WriteString("# Remove defaults.model and defaults.permissionMode. In each work entry, replace permissionMode with permissions:" +
		" plan is read-only, acceptEdits and auto are workspace, bypassPermissions is full.\n")

	return b.String()
}
