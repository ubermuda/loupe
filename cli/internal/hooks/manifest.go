package hooks

import (
	"bytes"
	"errors"
	"fmt"
	"io"
	"maps"
	"path"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"time"

	"go.yaml.in/yaml/v3"
)

// ManifestFile is the manifest at the root of a hook package.
const ManifestFile = "loupe-hook.yaml"

// DefaultTimeout bounds one hook run when the manifest sets no timeout.
const DefaultTimeout = 30 * time.Second

// MaxTimeout is the largest timeout a manifest can set.
const MaxTimeout = 120 * time.Second

// Events are the bridge events a hook can listen to, in the order they fire.
var Events = []string{"start", "stop", "busy", "idle"}

// The setting types a manifest can declare.
const (
	TypeBool   = "bool"
	TypeString = "string"
)

var settingNamePattern = regexp.MustCompile(`^[a-z][a-z0-9_]*$`)

// Manifest is a validated loupe-hook.yaml.
type Manifest struct {
	Name string
	// OS lists the GOOS values the package runs on. Empty means any.
	OS      []string
	Timeout time.Duration
	// Events maps an event to its command, run in the package directory.
	Events   map[string][]string
	Settings map[string]Setting
}

// Setting is one value an operator can set for a package.
type Setting struct {
	Type        string `yaml:"type"`
	Default     string `yaml:"default"`
	Description string `yaml:"description"`
}

type rawManifest struct {
	Name     string              `yaml:"name"`
	OS       []string            `yaml:"os"`
	Timeout  *int                `yaml:"timeout"`
	Events   map[string][]string `yaml:"events"`
	Settings map[string]Setting  `yaml:"settings"`
}

// ParseManifest validates a manifest. A key or an event the format does not
// define is an error.
func ParseManifest(data []byte) (Manifest, error) {
	var raw rawManifest
	dec := yaml.NewDecoder(bytes.NewReader(data))
	dec.KnownFields(true)
	if err := dec.Decode(&raw); errors.Is(err, io.EOF) {
		return Manifest{}, errors.New("the manifest is empty")
	} else if err != nil {
		return Manifest{}, fmt.Errorf("parse manifest: %w", err)
	}

	var errs []error
	if strings.TrimSpace(raw.Name) == "" {
		errs = append(errs, errors.New("name is required"))
	}
	timeout := DefaultTimeout
	if raw.Timeout != nil {
		if n := *raw.Timeout; n < 1 || n > int(MaxTimeout.Seconds()) {
			errs = append(errs, fmt.Errorf("timeout %d is not between 1 and %d seconds", n, int(MaxTimeout.Seconds())))
		} else {
			timeout = time.Duration(n) * time.Second
		}
	}
	if len(raw.Events) == 0 {
		errs = append(errs, errors.New("events lists no event"))
	}
	for _, name := range slices.Sorted(maps.Keys(raw.Events)) {
		if !slices.Contains(Events, name) {
			errs = append(errs, fmt.Errorf("event %q is not start, stop, busy or idle", name))

			continue
		}
		if err := checkCommand(raw.Events[name]); err != nil {
			errs = append(errs, fmt.Errorf("event %s: %w", name, err))
		}
	}
	for _, name := range slices.Sorted(maps.Keys(raw.Settings)) {
		if !settingNamePattern.MatchString(name) {
			errs = append(errs, fmt.Errorf("setting %q is not a name such as take_over", name))

			continue
		}
		s := raw.Settings[name]
		if s.Type != TypeBool && s.Type != TypeString {
			errs = append(errs, fmt.Errorf("setting %q: type %q is not bool or string", name, s.Type))

			continue
		}
		if err := checkValue(s, s.Default); err != nil {
			errs = append(errs, fmt.Errorf("setting %q: default %w", name, err))
		}
	}
	if len(errs) > 0 {
		return Manifest{}, errors.Join(errs...)
	}

	return Manifest{Name: raw.Name, OS: raw.OS, Timeout: timeout, Events: raw.Events, Settings: raw.Settings}, nil
}

// checkCommand refuses a command whose program is not a file of the package.
func checkCommand(cmd []string) error {
	if len(cmd) == 0 {
		return errors.New("the command is empty")
	}
	prog := cmd[0]
	if !strings.HasPrefix(prog, "./") {
		return fmt.Errorf("the command %q does not start with ./", prog)
	}
	clean := path.Clean(prog)
	if clean == "." || clean == ".." || strings.HasPrefix(clean, "../") || !filepath.IsLocal(filepath.FromSlash(clean)) {
		return fmt.Errorf("the command %q leaves the package", prog)
	}

	return nil
}

// checkValue refuses a value that does not fit the setting's type.
func checkValue(s Setting, v string) error {
	if s.Type == TypeBool && v != "true" && v != "false" {
		return fmt.Errorf("%q is not true or false", v)
	}

	return nil
}
