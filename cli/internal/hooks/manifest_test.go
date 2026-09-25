package hooks

import (
	"slices"
	"strings"
	"testing"
	"time"
)

const manifest = `name: amphetamine
os: [darwin]
timeout: 10
events:
  busy: [./hook, busy]
  idle: [./hook, idle]
settings:
  takeover:
    type: bool
    default: false
    description: End a session that the hook did not start.
  label:
    type: string
    description: A name.
`

func TestParseManifest(t *testing.T) {
	m, err := ParseManifest([]byte(manifest))
	if err != nil {
		t.Fatal(err)
	}

	if m.Name != "amphetamine" || !slices.Equal(m.OS, []string{"darwin"}) || m.Timeout != 10*time.Second {
		t.Fatalf("manifest = %+v", m)
	}
	if !slices.Equal(m.Events["busy"], []string{"./hook", "busy"}) || len(m.Events) != 2 {
		t.Fatalf("events = %v", m.Events)
	}
	if s := m.Settings["takeover"]; s.Type != "bool" || s.Default != "false" || s.Description == "" {
		t.Fatalf("takeover = %+v", s)
	}
	if s := m.Settings["label"]; s.Type != "string" || s.Default != "" {
		t.Fatalf("label = %+v", s)
	}
}

func TestParseManifestFillsTheTimeout(t *testing.T) {
	m, err := ParseManifest([]byte("name: x\nevents:\n  start: [./run]\n"))
	if err != nil {
		t.Fatal(err)
	}
	if m.Timeout != DefaultTimeout || DefaultTimeout != 30*time.Second || len(m.OS) != 0 {
		t.Fatalf("manifest = %+v", m)
	}
}

func TestParseManifestRefuses(t *testing.T) {
	for name, tc := range map[string]struct{ body, want string }{
		"empty":           {"", "the manifest is empty"},
		"no name":         {"events:\n  busy: [./hook]\n", "name is required"},
		"unknown key":     {"name: x\nevents:\n  busy: [./hook]\nurl: y\n", "field url not found"},
		"no events":       {"name: x\n", "events lists no event"},
		"unknown event":   {"name: x\nevents:\n  wake: [./hook]\n", `event "wake" is not start, stop, busy or idle`},
		"empty command":   {"name: x\nevents:\n  busy: []\n", "event busy: the command is empty"},
		"bare name":       {"name: x\nevents:\n  busy: [hook]\n", `event busy: the command "hook" does not start with ./`},
		"absolute":        {"name: x\nevents:\n  busy: [/bin/sh]\n", `event busy: the command "/bin/sh" does not start with ./`},
		"parent":          {"name: x\nevents:\n  busy: [./../hook]\n", `event busy: the command "./../hook" leaves the package`},
		"parent inside":   {"name: x\nevents:\n  busy: [./a/../../hook]\n", `leaves the package`},
		"dot":             {"name: x\nevents:\n  busy: [./]\n", `leaves the package`},
		"zero timeout":    {"name: x\ntimeout: 0\nevents:\n  busy: [./hook]\n", "timeout 0 is not between 1 and 120 seconds"},
		"long timeout":    {"name: x\ntimeout: 121\nevents:\n  busy: [./hook]\n", "timeout 121 is not between 1 and 120 seconds"},
		"setting name":    {"name: x\nevents:\n  busy: [./hook]\nsettings:\n  Take-Over:\n    type: string\n", `setting "Take-Over" is not a name such as take_over`},
		"setting type":    {"name: x\nevents:\n  busy: [./hook]\nsettings:\n  a:\n    type: int\n", `setting "a": type "int" is not bool or string`},
		"bool default":    {"name: x\nevents:\n  busy: [./hook]\nsettings:\n  a:\n    type: bool\n    default: yes please\n", `setting "a": default "yes please" is not true or false`},
		"no bool default": {"name: x\nevents:\n  busy: [./hook]\nsettings:\n  a:\n    type: bool\n", `setting "a": default "" is not true or false`},
		"setting key":     {"name: x\nevents:\n  busy: [./hook]\nsettings:\n  a:\n    type: bool\n    default: true\n    secret: true\n", "field secret not found"},
	} {
		t.Run(name, func(t *testing.T) {
			_, err := ParseManifest([]byte(tc.body))
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want %q", err, tc.want)
			}
		})
	}
}

func TestParseSpec(t *testing.T) {
	for in, want := range map[string]Spec{
		"ubermuda/loupe@v1.0.0":                   {Owner: "ubermuda", Repo: "loupe", Ref: "v1.0.0"},
		"ubermuda/loupe/hooks/amphetamine@abc123": {Owner: "ubermuda", Repo: "loupe", Path: "hooks/amphetamine", Ref: "abc123"},
		"acme/tool@feature/x":                     {Owner: "acme", Repo: "tool", Ref: "feature/x"},
	} {
		got, err := ParseSpec(in)
		if err != nil {
			t.Fatalf("%s: %v", in, err)
		}
		if got != want {
			t.Fatalf("%s: spec = %+v", in, got)
		}
	}
	if id := (Spec{Owner: "a", Repo: "b", Path: "c/d"}).ID(); id != "a/b/c/d" {
		t.Fatalf("ID = %q", id)
	}
	if pkg := (Spec{Owner: "a", Repo: "b", Path: "c/d"}).Package(); pkg != "a/b" {
		t.Fatalf("Package = %q", pkg)
	}
}

func TestParseSpecRefuses(t *testing.T) {
	for in, want := range map[string]string{
		"ubermuda/loupe":         "names no ref",
		"ubermuda/loupe@":        "ref is required",
		"ubermuda@v1":            `is not owner/repo`,
		"-bad/loupe@v1":          `is not owner/repo`,
		"ubermuda/loupe/../x@v1": "is not a clean relative path",
		"ubermuda/loupe/a//b@v1": "is not a clean relative path",
		"ubermuda/loupe/a/@v1":   "is not a clean relative path",
		"ubermuda/loupe@v 1":     "is not a git ref",
		"ubermuda/loupe@../../x": "is not a git ref",
	} {
		if _, err := ParseSpec(in); err == nil || !strings.Contains(err.Error(), want) {
			t.Fatalf("%s: err = %v, want %q", in, err, want)
		}
	}
}
