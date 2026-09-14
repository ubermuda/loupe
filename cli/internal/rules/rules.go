// Package rules loads the bridge's rule file, checks it against the board, and
// picks the rule an event triggers.
package rules

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"maps"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"sync"
	"unicode"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/event"
	"go.yaml.in/yaml/v3"
)

// FileName is the rule file the bridge reads from the config directory.
const FileName = "rules.yaml"

// DefaultMaxChain bounds the agent-triggered runs in a row one rule starts for
// one card.
const DefaultMaxChain = 3

// Example is the file the bridge prints when it finds none.
const Example = `projects:
  my-app:
    dir: ~/Code/my-app

rules:
  - name: plan
    on: board.card_moved
    project: my-app
    to: next
    prompt: |
      Card {cardNumber} in Loupe project {projectId} moved to {to}.
      Read it with the card_get MCP tool, passing cardId {cardId}.
      If its column is no longer {to}, stop and do nothing.
      Otherwise write an implementation plan into the card body
      with card_update, and stop.
`

// eventTypePattern is the shape of an event type, such as board.card_moved. It
// cannot catch a misspelt type, but it catches a value that is not a type.
var eventTypePattern = regexp.MustCompile(`^[a-z0-9_]+(\.[a-z0-9_]+)+$`)

// PermissionModes are the values claude 2.1.270 takes for --permission-mode.
// Its help omits default, and it still accepts it. A later claude can add a
// mode, so a value outside the list is logged at start rather than refused.
var PermissionModes = []string{"acceptEdits", "auto", "bypassPermissions", "default", "dontAsk", "manual", "plan"}

// Placeholder names, and the ones each kind of event can fill.
var (
	cardMovedPlaceholders = []string{"cardId", "cardNumber", "projectId", "project", "from", "to"}
	genericPlaceholders   = []string{"projectId", "project"}
)

// File is the rule file as written.
type File struct {
	Projects map[string]Project `yaml:"projects"`
	Rules    []Rule             `yaml:"rules"`
}

// Project maps a project slug to the directory its workers run in.
type Project struct {
	Dir string `yaml:"dir"`
}

// Rule starts an agent when an event matches it.
type Rule struct {
	Name           string `yaml:"name"`
	On             string `yaml:"on"`
	Project        string `yaml:"project"`
	To             string `yaml:"to"`
	From           string `yaml:"from"`
	Prompt         string `yaml:"prompt"`
	PermissionMode string `yaml:"permissionMode"`
	Model          string `yaml:"model"`
	MaxChain       *int   `yaml:"maxChain"`
	AllowUntrusted bool   `yaml:"allowUntrusted"`
}

// Defaults fill a rule's fields that the file leaves empty.
type Defaults struct {
	PermissionMode string
	Model          string
}

// Check refuses a malformed default, before any rule is read.
func (d Defaults) Check() error {
	return errors.Join(
		checkWord("--permission-mode", d.PermissionMode),
		checkWord("--model", d.Model),
	)
}

// checkWord refuses a permission mode or model that holds whitespace. Empty
// passes no flag. claude owns both lists, and either can grow.
func checkWord(field, value string) error {
	if strings.ContainsFunc(value, unicode.IsSpace) {
		return fmt.Errorf("%s %q holds whitespace, and claude takes a single word", field, value)
	}

	return nil
}

// Set is a loaded, validated rule file.
type Set struct {
	rules []Rule
	dirs  map[string]string
	// slugs maps a project id to its slug. Check fills it, so an unchecked
	// set matches nothing.
	slugs map[string]string
}

// ErrMissing marks a rule file that does not exist.
var ErrMissing = errors.New("the file does not exist")

// ErrEmpty marks a rule file that holds no YAML document.
var ErrEmpty = errors.New("the file is empty")

// Load reads and validates the rule file at path. The caller names the path in
// the error.
func Load(path string, defaults Defaults) (*Set, error) {
	data, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return nil, withExample(ErrMissing)
	}
	if err != nil {
		return nil, fmt.Errorf("read rule file: %w", err)
	}

	return Parse(data, defaults)
}

func withExample(err error) error {
	return fmt.Errorf("%w, and the bridge needs rules to know what to run. An example:\n\n%s", err, Example)
}

// Parse validates a rule file's contents. A field the format does not define is
// an error, so a misspelt key fails at start instead of being ignored. The
// caller checks defaults with Defaults.Check.
func Parse(data []byte, defaults Defaults) (*Set, error) {
	f, err := decodeFile(data)
	if err != nil {
		return nil, err
	}

	s := &Set{dirs: map[string]string{}}
	var errs []error
	if len(f.Projects) == 0 {
		errs = append(errs, errors.New("the rule file maps no projects"))
	}
	for _, slug := range slices.Sorted(maps.Keys(f.Projects)) {
		dir, err := checkProject(slug, f.Projects[slug])
		if err != nil {
			errs = append(errs, err)

			continue
		}
		s.dirs[slug] = dir
	}
	if len(f.Rules) == 0 {
		errs = append(errs, errors.New("the rule file has no rules"))
	}

	names := map[string]bool{}
	for i, r := range f.Rules {
		if r.Name == "" {
			r.Name = strconv.Itoa(i + 1)
		}
		if names[r.Name] {
			errs = append(errs, fmt.Errorf("rule %q: another rule has the same name", r.Name))
		}
		names[r.Name] = true

		if err := checkRule(r, f.Projects); err != nil {
			errs = append(errs, fmt.Errorf("rule %q: %w", r.Name, err))
		}
		if r.MaxChain == nil {
			n := DefaultMaxChain
			r.MaxChain = &n
		}
		if r.PermissionMode == "" {
			r.PermissionMode = defaults.PermissionMode
		}
		if r.Model == "" {
			r.Model = defaults.Model
		}
		s.rules = append(s.rules, r)
	}

	if len(errs) > 0 {
		return nil, errors.Join(errs...)
	}

	return s, nil
}

// decodeFile decodes the one document that holds content. An empty document,
// such as a bare --- before or after it, holds nothing and is skipped.
func decodeFile(data []byte) (File, error) {
	var f File
	docs := yaml.NewDecoder(bytes.NewReader(data))
	content := -1
	for i := 0; ; i++ {
		var doc any
		if err := docs.Decode(&doc); errors.Is(err, io.EOF) {
			break
		} else if err != nil {
			return f, fmt.Errorf("parse rule file: %w", err)
		}
		if doc == nil {
			continue
		}
		if content >= 0 {
			return f, errors.New("parse rule file: it holds a second YAML document after ---, and the bridge reads one")
		}
		content = i
	}
	if content < 0 {
		return f, withExample(ErrEmpty)
	}

	// A second pass, because KnownFields applies to a decoder and not to a node.
	dec := yaml.NewDecoder(bytes.NewReader(data))
	dec.KnownFields(true)
	for range content + 1 {
		if err := dec.Decode(&f); err != nil {
			return f, fmt.Errorf("parse rule file: %w", err)
		}
	}

	return f, nil
}

func checkProject(slug string, p Project) (string, error) {
	if !event.SlugPattern.MatchString(slug) {
		return "", fmt.Errorf("project %q: a project key is a slug, such as my-app", slug)
	}
	if p.Dir == "" {
		return "", fmt.Errorf("project %q: dir is required", slug)
	}
	dir, err := expandHome(p.Dir)
	if err != nil {
		return "", fmt.Errorf("project %q: %w", slug, err)
	}
	if !filepath.IsAbs(dir) {
		return "", fmt.Errorf("project %q: dir %s is not an absolute path", slug, p.Dir)
	}
	info, err := os.Stat(dir)
	if err != nil {
		return "", fmt.Errorf("project %q: dir %w", slug, err)
	}
	if !info.IsDir() {
		return "", fmt.Errorf("project %q: dir %s is not a directory", slug, dir)
	}

	return dir, nil
}

func expandHome(dir string) (string, error) {
	if dir != "~" && !strings.HasPrefix(dir, "~/") {
		return dir, nil
	}
	home, err := os.UserHomeDir()
	if err != nil {
		return "", fmt.Errorf("expand ~ in dir: %w", err)
	}

	return filepath.Join(home, strings.TrimPrefix(dir, "~")), nil
}

func checkRule(r Rule, projects map[string]Project) error {
	var errs []error
	switch {
	case r.On == "":
		errs = append(errs, errors.New("on is required"))
	case !eventTypePattern.MatchString(r.On):
		errs = append(errs, fmt.Errorf("on %q is not an event type, such as board.card_moved", r.On))
	}
	if r.Project == "" {
		errs = append(errs, errors.New("project is required"))
	} else if _, ok := projects[r.Project]; !ok && len(projects) > 0 {
		errs = append(errs, fmt.Errorf("project %q is not in projects, which maps %s", r.Project, strings.Join(slices.Sorted(maps.Keys(projects)), ", ")))
	} else if !ok {
		errs = append(errs, fmt.Errorf("project %q is not in projects", r.Project))
	}
	if err := checkWord("permissionMode", r.PermissionMode); err != nil {
		errs = append(errs, err)
	}
	if err := checkWord("model", r.Model); err != nil {
		errs = append(errs, err)
	}

	allowed := genericPlaceholders
	if r.On == event.CardMovedType {
		allowed = cardMovedPlaceholders
		switch {
		case r.To == "":
			errs = append(errs, errors.New("to is required for board.card_moved"))
		case !event.SlugPattern.MatchString(r.To):
			errs = append(errs, fmt.Errorf("to %q is not a column slug", r.To))
		}
		if r.From != "" && !event.SlugPattern.MatchString(r.From) {
			errs = append(errs, fmt.Errorf("from %q is not a column slug", r.From))
		}
		if r.From != "" && r.From == r.To {
			errs = append(errs, errors.New("from and to name one column, and a move inside one column never fires"))
		}
	} else {
		if r.To != "" || r.From != "" {
			errs = append(errs, fmt.Errorf("to and from apply to board.card_moved only, and this rule is on %s", r.On))
		}
	}

	if strings.TrimSpace(r.Prompt) == "" {
		errs = append(errs, errors.New("prompt is required"))
	}
	for _, name := range directive.Placeholders(r.Prompt) {
		switch {
		case slices.Contains(allowed, name):
		case slices.Contains(cardMovedPlaceholders, name):
			errs = append(errs, fmt.Errorf("placeholder {%s} has no value for %s events; this type fills %s", name, r.On, braces(allowed)))
		default:
			errs = append(errs, fmt.Errorf("unknown placeholder {%s}; this type fills %s", name, braces(allowed)))
		}
	}
	if r.MaxChain != nil && *r.MaxChain < 1 {
		errs = append(errs, fmt.Errorf("maxChain must be at least 1, got %d", *r.MaxChain))
	}

	return errors.Join(errs...)
}

func braces(names []string) string {
	out := make([]string, len(names))
	for i, n := range names {
		out[i] = "{" + n + "}"
	}

	return strings.Join(out, " ")
}

// Projects lists the mapped project slugs in order.
func (s *Set) Projects() []string {
	return slices.Sorted(maps.Keys(s.dirs))
}

// Rules lists the rules in file order, with their defaults filled.
func (s *Set) Rules() []Rule {
	return slices.Clone(s.rules)
}

// ProjectID is the id Check resolved for a mapped slug.
func (s *Set) ProjectID(slug string) string {
	for id, sl := range s.slugs {
		if sl == slug {
			return id
		}
	}

	return ""
}

// UnknownPermissionModes lists, in order, the modes the rules pass that are not
// in PermissionModes. The bridge warns about them, and claude has the last word.
func (s *Set) UnknownPermissionModes() []string {
	var out []string
	for _, r := range s.rules {
		if r.PermissionMode != "" && !slices.Contains(PermissionModes, r.PermissionMode) && !slices.Contains(out, r.PermissionMode) {
			out = append(out, r.PermissionMode)
		}
	}

	return out
}

// ExtraTypes names the event types the rules use that the event parser does
// not know the fields of.
func (s *Set) ExtraTypes() map[string]bool {
	out := map[string]bool{}
	for _, r := range s.rules {
		if r.On != event.CardMovedType {
			out[r.On] = true
		}
	}

	return out
}

// ColumnSource reads a project's columns, and the caller's projects, from the
// server.
type ColumnSource interface {
	Columns(ctx context.Context, handle string) (api.ProjectColumns, error)
	Sites(ctx context.Context) ([]api.Site, error)
}

// Check reads each mapped project's columns and refuses a project or a column
// the server does not know. It lists the valid slugs, so the fix is a copy.
func (s *Set) Check(ctx context.Context, src ColumnSource) error {
	slugs := map[string]string{}
	var errs []error
	known := sync.OnceValue(func() string { return knownSlugs(ctx, src) })
	for _, slug := range s.Projects() {
		pc, err := src.Columns(ctx, slug)
		switch {
		case errors.Is(err, api.ErrProjectNotFound):
			errs = append(errs, fmt.Errorf("project %q: no project of yours has this slug%s", slug, known()))
		case errors.Is(err, api.ErrBoardDisabled):
			errs = append(errs, fmt.Errorf("project %q: the board is switched off on this Loupe instance, so no card event can reach the bridge", slug))
		case errors.Is(err, api.ErrEndpointMissing):
			errs = append(errs, fmt.Errorf("project %q: the server is too old for this bridge version: it has no GET /api/projects/{handle}/board/columns endpoint, so upgrade Loupe first", slug))
		case errors.Is(err, api.ErrProjectAmbiguous):
			errs = append(errs, fmt.Errorf("project %q: this handle names more than one of your projects: %w", slug, err))
		case err != nil:
			errs = append(errs, fmt.Errorf("project %q: %w", slug, err))
		}
		if err != nil {
			continue
		}
		// A project with no slug yet sends none, and the server resolved the
		// key as its id.
		if pc.Project.Slug != "" && pc.Project.Slug != slug {
			errs = append(errs, fmt.Errorf("project %q: the server resolves it to the project with slug %q; use that slug", slug, pc.Project.Slug))

			continue
		}
		if !event.IsID(pc.Project.ID) {
			errs = append(errs, fmt.Errorf("project %q: the server returned an invalid id %q", slug, pc.Project.ID))

			continue
		}
		id := strings.ToLower(pc.Project.ID)
		if other, ok := slugs[id]; ok {
			errs = append(errs, fmt.Errorf("project %q: the server resolves it to the same project as %q; map each project once", slug, other))

			continue
		}
		slugs[id] = slug

		valid := make([]string, len(pc.Columns))
		for i, c := range pc.Columns {
			valid[i] = c.Slug
		}
		for _, r := range s.rules {
			if r.Project != slug || r.On != event.CardMovedType {
				continue
			}
			for _, col := range [][2]string{{"to", r.To}, {"from", r.From}} {
				if col[1] != "" && !slices.Contains(valid, col[1]) {
					errs = append(errs, fmt.Errorf("rule %q: %s %q is not a column of project %q; its columns are %s", r.Name, col[0], col[1], slug, strings.Join(valid, ", ")))
				}
			}
		}
	}
	if len(errs) > 0 {
		return errors.Join(errs...)
	}
	s.slugs = slugs

	return nil
}

// knownSlugs names the caller's project slugs for an error message. A failed
// request leaves the message as it was, because the refusal matters more.
func knownSlugs(ctx context.Context, src ColumnSource) string {
	sites, err := src.Sites(ctx)
	if err != nil {
		return ""
	}
	var known []string
	for _, site := range sites {
		if site.Slug != "" {
			known = append(known, site.Slug)
		}
	}
	if len(known) == 0 {
		return "; you have no project with a slug"
	}
	slices.Sort(known)

	return "; your projects are " + strings.Join(known, ", ")
}

// Skip says why an event starts no worker.
type Skip int

const (
	// Run means a rule matched and the event starts a worker.
	Run Skip = iota
	// NoRule means no rule matches the event.
	NoRule
	// Unmapped means the event belongs to a project the file does not map.
	Unmapped
	// Untrusted means the matching rule does not accept a reviewer's event.
	Untrusted
)

// Match is the outcome of matching one event.
type Match struct {
	Skip           Skip
	Rule           string
	Project        string
	Dir            string
	PermissionMode string
	Model          string
	MaxChain       int
	Prompt         string
}

// Match picks the first rule, in file order, that the event triggers.
//
// A reviewer's event stops at the first matching rule when that rule does not
// allow it. A later rule never catches it, so the order of the file stays the
// whole answer to which rule runs.
func (s *Set) Match(e event.Event) Match {
	slug, ok := s.slugs[e.ProjectID]
	if !ok {
		return Match{Skip: Unmapped}
	}

	for _, r := range s.rules {
		if r.On != e.Type || r.Project != slug {
			continue
		}
		// Entered, not sits in: a card dragged to a new rank inside one column
		// submits a move with that column on both sides.
		if e.Type == event.CardMovedType && (e.ToStatus != r.To || e.FromStatus == e.ToStatus || (r.From != "" && e.FromStatus != r.From)) {
			continue
		}
		if e.Actor == event.ActorReviewer && !r.AllowUntrusted {
			return Match{Skip: Untrusted, Rule: r.Name, Project: slug}
		}

		return Match{
			Skip:           Run,
			Rule:           r.Name,
			Project:        slug,
			Dir:            s.dirs[slug],
			PermissionMode: r.PermissionMode,
			Model:          r.Model,
			MaxChain:       *r.MaxChain,
			Prompt:         directive.Render(r.Prompt, values(e, slug)),
		}
	}

	return Match{Skip: NoRule, Project: slug}
}

// values fills placeholders from fields Parse validated and from the slug the
// rule file maps. Nothing a person wrote on the board is among them.
func values(e event.Event, slug string) map[string]string {
	v := map[string]string{"projectId": e.ProjectID, "project": slug}
	if e.Type == event.CardMovedType {
		v["cardId"] = e.Subject.ID
		v["cardNumber"] = strconv.Itoa(e.CardNumber)
		v["from"] = e.FromStatus
		v["to"] = e.ToStatus
	}

	return v
}
