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
	"unicode/utf8"

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
var eventTypePattern = regexp.MustCompile(`^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$`)

// The limits the rule health endpoint puts on a report. The bridge refuses a
// rule file past them at start, or every report of that project gets a 422.
const (
	MaxNameLength      = 100
	MaxOnLength        = 100
	MaxSlugLength      = 2000
	MaxRulesPerProject = 200
)

// phpTrimSet is the set PHP's trim strips by default.
const phpTrimSet = " \t\n\r\x00\x0b"

// PermissionModes are the values claude 2.1.270 takes for --permission-mode.
// Its help omits default, and it still accepts it. A later claude can add a
// mode, so a value outside the list is logged at start rather than refused.
var PermissionModes = []string{"acceptEdits", "auto", "bypassPermissions", "default", "dontAsk", "manual", "plan"}

// Placeholder names, and the ones each kind of event can fill.
var (
	cardMovedPlaceholders       = []string{"cardId", "cardNumber", "projectId", "project", "from", "to"}
	askClosedPlaceholders       = []string{"askId", "sessionId", "cardNumber", "projectId", "project"}
	reviewSubmittedPlaceholders = []string{"cardId", "cardNumber", "column", "documentId", "verdict", "projectId", "project"}
	genericPlaceholders         = []string{"projectId", "project"}
)

// UnknownCard is what {cardNumber} renders for a resume whose card neither the
// server nor the bridge knows.
const UnknownCard = "unknown"

// File is the rule file as written.
type File struct {
	Defaults FileDefaults       `yaml:"defaults"`
	Projects map[string]Project `yaml:"projects"`
	Rules    []Rule             `yaml:"rules"`
}

// FileDefaults fill a rule's empty fields before the bridge flags do. A reload
// reads them again, and the flags stay fixed for the process.
type FileDefaults struct {
	PermissionMode string `yaml:"permissionMode"`
	Model          string `yaml:"model"`
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
	// Verdict limits a document.review_submitted rule to one verdict. Empty
	// matches either.
	Verdict string `yaml:"verdict"`
	// Resume runs claude --resume on the session an inbox ask names, instead
	// of a new session.
	Resume bool `yaml:"resume"`
	// Card limits a board.card_moved or document.review_submitted rule by the
	// state of its card. Nil matches any card.
	Card *CardCondition `yaml:"card"`
}

// CardCondition names the card state a rule needs. A nil field matches either
// value.
type CardCondition struct {
	InteractiveRun *bool `yaml:"interactiveRun"`
}

// Defaults come from the bridge flags. They fill a rule's fields that neither
// the rule nor the file's defaults set.
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

	// dead maps a rule name to the reason it died. The bridge reads and writes
	// it on the stream goroutine alone. mu guards it for any other caller.
	mu   sync.RWMutex
	dead map[string]string
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
	for _, err := range []error{
		checkWord("defaults.permissionMode", f.Defaults.PermissionMode),
		checkWord("defaults.model", f.Defaults.Model),
	} {
		if err != nil {
			errs = append(errs, err)
		}
	}
	if f.Defaults.PermissionMode != "" {
		defaults.PermissionMode = f.Defaults.PermissionMode
	}
	if f.Defaults.Model != "" {
		defaults.Model = f.Defaults.Model
	}
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
	perProject := map[string]int{}
	for i, r := range f.Rules {
		if r.Name == "" {
			r.Name = strconv.Itoa(i + 1)
		}
		// The server trims a name with PHP's trim and counts code points, so
		// two names that differ in spaces alone would share one row.
		if trimmed := strings.Trim(r.Name, phpTrimSet); trimmed == "" {
			errs = append(errs, fmt.Errorf("rule %d: name %q is blank", i+1, r.Name))
		} else if n := utf8.RuneCountInString(trimmed); n > MaxNameLength {
			errs = append(errs, fmt.Errorf("rule %d: name is %d characters, and the server takes at most %d", i+1, n, MaxNameLength))
		} else {
			r.Name = trimmed
		}
		perProject[r.Project]++
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
	for _, slug := range slices.Sorted(maps.Keys(perProject)) {
		if perProject[slug] > MaxRulesPerProject {
			errs = append(errs, fmt.Errorf("project %q has %d rules, and the server takes at most %d in one report", slug, perProject[slug], MaxRulesPerProject))
		}
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
	case len(r.On) > MaxOnLength:
		errs = append(errs, fmt.Errorf("on is %d characters, and the server takes at most %d", len(r.On), MaxOnLength))
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
		for _, col := range [][2]string{{"to", r.To}, {"from", r.From}} {
			if len(col[1]) > MaxSlugLength {
				errs = append(errs, fmt.Errorf("%s is %d characters, and the server takes a column slug of at most %d", col[0], len(col[1]), MaxSlugLength))
			}
		}
		if r.From != "" && r.From == r.To {
			errs = append(errs, errors.New("from and to name one column, and a move inside one column never fires"))
		}
	} else {
		if r.To != "" || r.From != "" {
			errs = append(errs, fmt.Errorf("to and from apply to board.card_moved only, and this rule is on %s", r.On))
		}
	}
	if r.On == event.AskClosedType {
		allowed = askClosedPlaceholders
		if !r.Resume {
			errs = append(errs, errors.New("inbox.ask_closed needs resume: true, because resuming the session that asked is the one action it takes"))
		}
	} else if r.Resume {
		errs = append(errs, fmt.Errorf("resume applies to inbox.ask_closed only, and this rule is on %s", r.On))
	}
	if r.On == event.ReviewSubmittedType {
		allowed = reviewSubmittedPlaceholders
		if r.Verdict != "" && r.Verdict != event.VerdictApproved && r.Verdict != event.VerdictChangesRequested {
			errs = append(errs, fmt.Errorf("verdict %q is not %s or %s", r.Verdict, event.VerdictApproved, event.VerdictChangesRequested))
		}
	} else if r.Verdict != "" {
		errs = append(errs, fmt.Errorf("verdict applies to document.review_submitted only, and this rule is on %s", r.On))
	}
	if r.Card != nil && r.On != event.CardMovedType && r.On != event.ReviewSubmittedType {
		errs = append(errs, fmt.Errorf("card applies to board.card_moved and document.review_submitted only, and this rule is on %s", r.On))
	}

	if strings.TrimSpace(r.Prompt) == "" {
		errs = append(errs, errors.New("prompt is required"))
	}
	for _, name := range directive.Placeholders(r.Prompt) {
		switch {
		case slices.Contains(allowed, name):
		case slices.Contains(cardMovedPlaceholders, name), slices.Contains(askClosedPlaceholders, name), slices.Contains(reviewSubmittedPlaceholders, name):
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

// Dir is the directory of a mapped slug, and "" for a slug the set does not map.
func (s *Set) Dir(slug string) string {
	return s.dirs[slug]
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
	Resume         bool
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

	s.mu.RLock()
	defer s.mu.RUnlock()
	for _, r := range s.rules {
		if !s.triggers(r, slug, e) {
			continue
		}
		if e.Actor == event.ActorReviewer && !r.AllowUntrusted {
			return Match{Skip: Untrusted, Rule: r.Name, Project: slug}
		}

		return s.run(r, slug, e)
	}

	return Match{Skip: NoRule, Project: slug}
}

// MatchRule matches the event against the named rule alone. It fails when the
// set has no live rule of that name, or when the rule would not run the event.
// A reload keeps a queued event this way, under the rule that accepted it.
func (s *Set) MatchRule(e event.Event, name string) (Match, bool) {
	slug, ok := s.slugs[e.ProjectID]
	if !ok {
		return Match{}, false
	}

	s.mu.RLock()
	defer s.mu.RUnlock()
	for _, r := range s.rules {
		if r.Name != name {
			continue
		}
		if !s.triggers(r, slug, e) || (e.Actor == event.ActorReviewer && !r.AllowUntrusted) {
			return Match{}, false
		}

		return s.run(r, slug, e), true
	}

	return Match{}, false
}

// triggers reports whether a live rule names the event, whatever its actor.
// The caller holds mu.
func (s *Set) triggers(r Rule, slug string, e event.Event) bool {
	if r.On != e.Type || r.Project != slug || s.dead[r.Name] != "" {
		return false
	}
	if r.Card != nil && r.Card.InteractiveRun != nil && *r.Card.InteractiveRun != e.Card.InteractiveRun {
		return false
	}
	// A verdict with no stage card has nothing for a card agent to act on.
	if e.Type == event.ReviewSubmittedType {
		return e.CardID != "" && (r.Verdict == "" || e.Verdict == r.Verdict)
	}
	// Entered, not sits in: a card dragged to a new rank inside one column
	// submits a move with that column on both sides.
	return e.Type != event.CardMovedType || (e.ToStatus == r.To && e.FromStatus != e.ToStatus && (r.From == "" || e.FromStatus == r.From))
}

// run is the match of a rule that starts a worker for the event.
func (s *Set) run(r Rule, slug string, e event.Event) Match {
	render := directive.Render
	if r.Resume {
		render = directive.RenderResume
	}

	return Match{
		Skip:           Run,
		Rule:           r.Name,
		Project:        slug,
		Dir:            s.dirs[slug],
		PermissionMode: r.PermissionMode,
		Model:          r.Model,
		MaxChain:       *r.MaxChain,
		Prompt:         render(r.Prompt, values(e, slug)),
		Resume:         r.Resume,
	}
}

// Dead names a rule that an event killed.
type Dead struct {
	Rule    string
	Project string
	Reason  string
}

// Kill marks dead every live rule that names the slug the event takes away,
// and returns them in file order. A dead rule matches nothing until a reload
// or a restart reads the file again, and its check refuses the stale slug.
func (s *Set) Kill(e event.Event) []Dead {
	slug, ok := s.slugs[e.ProjectID]
	if !ok {
		return nil
	}
	var reason, column string
	switch e.Type {
	case event.ColumnRenamedType:
		reason, column = api.ReasonColumnRenamed, e.FromSlug
	case event.ColumnDeletedType:
		reason, column = api.ReasonColumnDeleted, e.Slug
	case event.ProjectRenamedType:
		reason = api.ReasonProjectRenamed
	default:
		return nil
	}

	return s.kill(slug, column, reason)
}

// KillProject marks dead every live rule of a mapped project, and returns them
// in file order.
func (s *Set) KillProject(slug, reason string) []Dead {
	return s.kill(slug, "", reason)
}

// kill marks dead the live rules of a project, or with a column only the rules
// whose to or from names it.
func (s *Set) kill(slug, column, reason string) []Dead {
	s.mu.Lock()
	defer s.mu.Unlock()
	var out []Dead
	for _, r := range s.rules {
		if r.Project != slug || s.dead[r.Name] != "" {
			continue
		}
		if column != "" && r.To != column && r.From != column {
			continue
		}
		if s.dead == nil {
			s.dead = map[string]string{}
		}
		s.dead[r.Name] = reason
		out = append(out, Dead{Rule: r.Name, Project: slug, Reason: reason})
	}

	return out
}

// Live reports whether the named rule has not died.
func (s *Set) Live(rule string) bool {
	s.mu.RLock()
	defer s.mu.RUnlock()

	return s.dead[rule] == ""
}

// Health lists every rule of a mapped project with its state, in file order.
func (s *Set) Health(slug string) []api.RuleHealth {
	s.mu.RLock()
	defer s.mu.RUnlock()
	out := []api.RuleHealth{}
	for _, r := range s.rules {
		if r.Project != slug {
			continue
		}
		h := api.RuleHealth{Name: r.Name, On: r.On, Columns: []string{}, State: api.RuleLive}
		for _, col := range []string{r.To, r.From} {
			if col != "" {
				h.Columns = append(h.Columns, col)
			}
		}
		if reason := s.dead[r.Name]; reason != "" {
			h.State, h.Reason = api.RuleDead, &reason
		}
		out = append(out, h)
	}

	return out
}

// values fills placeholders from fields Parse validated and from the slug the
// rule file maps. Nothing a person wrote on the board is among them.
func values(e event.Event, slug string) map[string]string {
	v := map[string]string{"projectId": e.ProjectID, "project": slug}
	switch e.Type {
	case event.CardMovedType:
		v["cardId"] = e.Subject.ID
		v["cardNumber"] = strconv.Itoa(e.CardNumber)
		v["from"] = e.FromStatus
		v["to"] = e.ToStatus
	case event.AskClosedType:
		v["askId"] = e.Subject.ID
		v["sessionId"] = e.SessionID
		v["cardNumber"] = UnknownCard
		if e.CardNumber > 0 {
			v["cardNumber"] = strconv.Itoa(e.CardNumber)
		}
	case event.ReviewSubmittedType:
		v["cardId"] = e.CardID
		v["cardNumber"] = strconv.Itoa(e.CardNumber)
		v["column"] = e.Column
		v["documentId"] = e.Subject.ID
		v["verdict"] = e.Verdict
	}

	return v
}
