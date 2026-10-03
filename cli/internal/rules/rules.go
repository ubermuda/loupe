// Package rules loads the bridge's rule file, checks it against the board, and
// picks the rule an event triggers.
package rules

import (
	"bytes"
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"maps"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"slices"
	"strings"
	"sync"
	"time"
	"unicode"
	"unicode/utf8"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/event"
	"go.yaml.in/yaml/v3"
)

// FileName is the rule file the bridge reads from the config directory.
const FileName = "rules.yaml"

// DefaultMaxWorkers bounds the workers the bridge runs at once when the file
// sets no maxWorkers.
const DefaultMaxWorkers = 3

// DefaultPool is the pool of a worker rule that names none. It holds the slots
// the named pools leave.
const DefaultPool = "default"

// poolNamePattern is the shape of a worker pool name.
var poolNamePattern = regexp.MustCompile(`^[a-z][a-z0-9-]{0,39}$`)

// ActionInteractive is the rule action that opens an interactive claude session
// in a terminal, instead of a worker.
const ActionInteractive = "interactive"

// ActionCommand is the rule action that runs a command for the card, instead
// of a worker.
const ActionCommand = "command"

// DefaultLaunchTimeout bounds the launch command when the file sets no timeout.
const DefaultLaunchTimeout = 10 * time.Second

// DefaultBeforeTimeout bounds a before command when its rule sets no timeout.
// MaxBeforeTimeout is the largest timeout a rule can set.
const (
	DefaultBeforeTimeout = 15 * time.Minute
	MaxBeforeTimeout     = 60 * time.Minute
)

// DefaultCommandTimeout bounds the command of a command rule that sets no
// timeout. MaxCommandTimeout is the largest timeout a rule can set.
const (
	DefaultCommandTimeout = 10 * time.Minute
	MaxCommandTimeout     = 60 * time.Minute
)

// launchPlaceholders are the names launch.command can hold.
var launchPlaceholders = []string{"script", "dir", "sessionId", "cardNumber", "project"}

// MaxBridgeNameLength is the longest bridge name the server takes, trimmed.
const MaxBridgeNameLength = 40

// hostname names the machine for a file that sets no name. Tests replace it.
var hostname = os.Hostname

// goos is the OS Parse checks an interactive rule against. Tests replace it.
var goos = runtime.GOOS

// Example is the file the bridge prints when it finds none.
const Example = `projects:
  my-app:
    dir: ~/Code/my-app

work:
  implement:
    prompt: |
      Loupe asks for {kind} work on card {cardNumber} of project {projectId}.
      Read it with the card_get MCP tool, passing cardId {cardId}, and do it.
`

// PermissionModes are the values claude 2.1.270 takes for --permission-mode.
// Its help omits default, and it still accepts it. A later claude can add a
// mode, so a value outside the list is logged at start rather than refused.
var PermissionModes = []string{"acceptEdits", "auto", "bypassPermissions", "default", "dontAsk", "manual", "plan"}

// File is the rule file as written.
type File struct {
	Defaults FileDefaults       `yaml:"defaults"`
	Projects map[string]Project `yaml:"projects"`
	Hooks    []HookEntry        `yaml:"hooks"`
	Launch   LaunchConfig       `yaml:"launch"`
	// AutoUpdate is off when the key is absent.
	AutoUpdate  *bool                 `yaml:"autoUpdate"`
	MaxWorkers  *int                  `yaml:"maxWorkers"`
	WorkerPools map[string]WorkerPool `yaml:"workerPools"`
	// Work maps each work request kind the bridge claims to what it runs.
	Work map[string]WorkEntry `yaml:"work"`
	// Name is the host name when absent, and a blank value opts out.
	Name *string `yaml:"name"`
}

// WorkerPool is one named share of maxWorkers.
type WorkerPool struct {
	Size *int `yaml:"size"`
}

// FileDefaults fill a rule's empty fields before the bridge flags do. A reload
// reads them again, and the flags stay fixed for the process.
type FileDefaults struct {
	PermissionMode string `yaml:"permissionMode"`
	Model          string `yaml:"model"`
}

// LaunchConfig is the launch block as written.
type LaunchConfig struct {
	Command []string `yaml:"command"`
	Timeout string   `yaml:"timeout"`
}

// Launch is the command that opens a terminal for an interactive rule. No shell
// reads Command, so a value needs no quoting.
type Launch struct {
	Command []string
	Timeout time.Duration
}

// LaunchValues fill the placeholders of one launch.
type LaunchValues struct {
	Script     string
	Dir        string
	SessionID  string
	CardNumber string
	Project    string
}

// Argv is the command with each placeholder replaced. A value is never read
// again for a placeholder.
func (l Launch) Argv(v LaunchValues) []string {
	r := strings.NewReplacer(
		"{script}", v.Script,
		"{dir}", v.Dir,
		"{sessionId}", v.SessionID,
		"{cardNumber}", v.CardNumber,
		"{project}", v.Project,
	)
	out := make([]string, len(l.Command))
	for i, arg := range l.Command {
		out[i] = r.Replace(arg)
	}

	return out
}

// Project maps a project slug to the directory its workers run in.
type Project struct {
	Dir string `yaml:"dir"`
}

// BeforeConfig is the before block as written. No shell reads Run.
type BeforeConfig struct {
	Run     []string `yaml:"run"`
	Timeout string   `yaml:"timeout"`
}

// Before is the before command of one match, with its placeholders filled.
type Before struct {
	Argv    []string
	Timeout time.Duration
}

// Command is the command of one match of a command rule, with its
// placeholders filled.
type Command struct {
	Argv    []string
	Timeout time.Duration
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
	dirs  map[string]string
	hooks []HookEntry
	// launch has its default timeout filled.
	launch Launch
	// slugs maps a project id to its slug. Check fills it, so an unchecked
	// set matches nothing.
	slugs map[string]string

	autoUpdate    bool
	autoUpdateSet bool
	maxWorkers    int
	name          string
	// pools maps each pool name to its size, DefaultPool included.
	pools map[string]int
	// work has the defaults of each worker entry filled.
	work map[string]WorkEntry

	// deadWork maps a project slug to the reason its work died. The bridge
	// reads and writes it on the stream goroutine alone. mu guards it for any
	// other caller.
	mu       sync.RWMutex
	deadWork map[string]string
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

	s := &Set{dirs: map[string]string{}, work: map[string]WorkEntry{}, autoUpdate: f.AutoUpdate != nil && *f.AutoUpdate, autoUpdateSet: f.AutoUpdate != nil}
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
	if len(f.Work) == 0 {
		errs = append(errs, errors.New("the rule file has no work"))
	}
	name, err := bridgeName(f.Name)
	if err != nil {
		errs = append(errs, err)
	}
	s.name = name
	s.maxWorkers = DefaultMaxWorkers
	if f.MaxWorkers != nil {
		s.maxWorkers = *f.MaxWorkers
	}
	pools, poolErrs := checkPools(s.maxWorkers, f.WorkerPools)
	errs = append(errs, poolErrs...)
	s.pools = pools
	known := slices.AppendSeq([]string{DefaultPool}, maps.Keys(pools))
	slices.Sort(known)
	known = slices.Compact(known)
	for _, kind := range slices.Sorted(maps.Keys(f.Work)) {
		w := f.Work[kind]
		if err := checkWork(kind, &w); err != nil {
			errs = append(errs, fmt.Errorf("work %q: %w", kind, err))
		}
		if w.Action == "" {
			if err := checkPool(w.WorkerPool, f.WorkerPools, pools, known, s.maxWorkers); err != nil {
				errs = append(errs, fmt.Errorf("work %q: %w", kind, err))
			}
			// A nil map of fields always builds.
			w.schema, _ = resultSchema(nil)
			if w.PermissionMode == "" {
				w.PermissionMode = defaults.PermissionMode
			}
			if w.Model == "" && w.Variants == nil {
				w.Model = defaults.Model
			}
		}
		s.work[kind] = w
	}
	interactive := slices.ContainsFunc(slices.Collect(maps.Values(f.Work)), func(w WorkEntry) bool { return w.Action == ActionInteractive })
	launch, err := checkLaunch(f.Launch, interactive)
	if err != nil {
		errs = append(errs, err)
	}
	s.launch = launch
	errs = append(errs, checkHooks(f.Hooks)...)
	s.hooks = f.Hooks
	if len(f.Work) == 0 {
		return nil, withExample(errors.Join(errs...))
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
		if err := refuseOldFormat(doc); err != nil {
			return f, err
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

// refuseOldFormat names the work map for a file that still lists rules or
// experiments. Loupe now decides when work runs, and the bridge only runs it.
func refuseOldFormat(doc any) error {
	top, ok := doc.(map[string]any)
	if !ok {
		return nil
	}
	var errs []error
	if _, ok := top["rules"]; ok {
		errs = append(errs, errors.New("parse rule file: the rules list is gone, because Loupe now decides when work runs; map each kind of work request under work instead, such as work: {implement: {prompt: ...}}, and drop on, to, from, when, maxChain, resume and maxResumes"))
	}
	if _, ok := top["experiments"]; ok {
		errs = append(errs, errors.New("parse rule file: the experiments list is gone; give a work entry its variants instead, and the experiment takes the name of its kind"))
	}

	return errors.Join(errs...)
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

// checkLaunch validates the launch block and fills its default timeout. An
// interactive rule needs a command.
func checkLaunch(c LaunchConfig, interactive bool) (Launch, error) {
	l := Launch{Command: c.Command, Timeout: DefaultLaunchTimeout}
	var errs []error
	if c.Timeout != "" {
		d, err := time.ParseDuration(c.Timeout)
		switch {
		case err != nil:
			errs = append(errs, fmt.Errorf("launch.timeout %q is not a duration, such as 10s", c.Timeout))
		case d <= 0:
			errs = append(errs, fmt.Errorf("launch.timeout must be positive, got %s", c.Timeout))
		default:
			l.Timeout = d
		}
	}
	if len(c.Command) == 0 {
		if interactive {
			errs = append(errs, fmt.Errorf("launch.command is required for action: %s", ActionInteractive))
		}

		return l, errors.Join(errs...)
	}
	script := false
	for _, arg := range c.Command {
		for _, name := range directive.Placeholders(arg) {
			if !slices.Contains(launchPlaceholders, name) {
				errs = append(errs, fmt.Errorf("launch.command: unknown placeholder {%s}; it fills %s", name, braces(launchPlaceholders)))
			}
			script = script || name == "script"
		}
	}
	if !script {
		errs = append(errs, errors.New("no element of launch.command holds {script}, so the terminal cannot run the session"))
	}

	return l, errors.Join(errs...)
}

// bridgeName refuses a set name the server would refuse, and derives an absent
// one from the first label of the host name.
func bridgeName(set *string) (string, error) {
	if set != nil {
		name := strings.TrimSpace(*set)
		if n := utf8.RuneCountInString(name); n > MaxBridgeNameLength {
			return "", fmt.Errorf("name is %d characters, and the server takes at most %d", n, MaxBridgeNameLength)
		}
		if strings.ContainsFunc(name, unicode.IsControl) {
			return "", errors.New("name holds a control character, and the server refuses it")
		}

		return name, nil
	}
	host, err := hostname()
	if err != nil {
		return "", nil
	}
	label, _, _ := strings.Cut(host, ".")
	label = strings.TrimSpace(strings.Map(func(r rune) rune {
		if unicode.IsControl(r) {
			return -1
		}

		return r
	}, label))
	if runes := []rune(label); len(runes) > MaxBridgeNameLength {
		label = strings.TrimSpace(string(runes[:MaxBridgeNameLength]))
	}

	return label, nil
}

// checkPools sizes each declared pool and gives DefaultPool the rest of the
// budget. It leaves DefaultPool out when the budget or the sizes are invalid.
func checkPools(budget int, declared map[string]WorkerPool) (map[string]int, []error) {
	pools := map[string]int{}
	var errs []error
	if budget < 1 {
		errs = append(errs, fmt.Errorf("maxWorkers must be at least 1, got %d", budget))
	}
	// sum never passes budget, so it cannot overflow.
	sum, over := 0, false
	for _, name := range slices.Sorted(maps.Keys(declared)) {
		size := declared[name].Size
		switch {
		case name == DefaultPool:
			errs = append(errs, fmt.Errorf("workerPools.%s: the name %s is reserved for the rules that name no pool", name, DefaultPool))
		case !poolNamePattern.MatchString(name):
			errs = append(errs, fmt.Errorf("workerPools.%s: a pool name is 1 to 40 lowercase letters, digits and hyphens, and starts with a letter, such as quick", name))
		case size == nil:
			errs = append(errs, fmt.Errorf("workerPools.%s: size is required", name))
		case *size < 1:
			errs = append(errs, fmt.Errorf("workerPools.%s: size must be at least 1, got %d", name, *size))
		case budget >= 1 && *size > budget:
			errs = append(errs, fmt.Errorf("workerPools.%s: size %d is more than maxWorkers, which is %d", name, *size, budget))
		default:
			pools[name] = *size
			if *size > budget-sum {
				over = true
			} else {
				sum += *size
			}
		}
	}
	if budget >= 1 && over {
		errs = append(errs, fmt.Errorf("the worker pools take more than the %d slots of maxWorkers", budget))
	}
	if len(errs) == 0 {
		pools[DefaultPool] = budget - sum
	}

	return pools, errs
}

// checkPool refuses a pool that workerPools does not declare, and the default
// pool when it has no slot. A declared pool with an invalid size has its own
// error already.
func checkPool(name string, declared map[string]WorkerPool, pools map[string]int, known []string, maxWorkers int) error {
	pool := cmp.Or(name, DefaultPool)
	if _, ok := declared[pool]; !ok && pool != DefaultPool {
		return fmt.Errorf("workerPool %q is not in workerPools, which declares %s", pool, strings.Join(known, ", "))
	}
	if size, ok := pools[DefaultPool]; ok && size == 0 && pool == DefaultPool {
		return fmt.Errorf("the default pool has no slot, because workerPools take all %d of maxWorkers; give it a workerPool or raise maxWorkers", maxWorkers)
	}

	return nil
}

// refusal is a set of fields a rule of one action must not set. why takes the
// name of the field.
type refusal struct {
	fields []string
	why    string
}

// refuse names each field of set that a refusal of table lists.
func refuse(table []refusal, set map[string]bool) []error {
	var errs []error
	for _, rf := range table {
		for _, name := range rf.fields {
			if set[name] {
				errs = append(errs, fmt.Errorf(rf.why, name))
			}
		}
	}

	return errs
}

// checkPlaceholders refuses a placeholder of template that the rule's type
// does not fill. prefix names the field in each error.
func checkPlaceholders(prefix, template, on string, allowed []string) []error {
	var errs []error
	for _, name := range directive.Placeholders(template) {
		switch {
		case slices.Contains(allowed, name):
		default:
			errs = append(errs, fmt.Errorf("%sunknown placeholder {%s}; this type fills %s", prefix, name, braces(allowed)))
		}
	}

	return errs
}

// checkRun validates the argv and the timeout of a command, and returns the
// timeout. prefix names the block in each error. The command takes the
// placeholders of the prompt, because both read one event.
func checkRun(prefix string, run []string, timeoutText string, timeout, most time.Duration, on string, allowed []string) (time.Duration, []error) {
	var errs []error
	if timeoutText != "" {
		d, err := time.ParseDuration(timeoutText)
		switch {
		case err != nil:
			errs = append(errs, fmt.Errorf("%stimeout %q is not a duration, such as 10m", prefix, timeoutText))
		case d <= 0:
			errs = append(errs, fmt.Errorf("%stimeout must be positive, got %s", prefix, timeoutText))
		case d > most:
			errs = append(errs, fmt.Errorf("%stimeout is %s, and the most it takes is %s", prefix, timeoutText, most))
		default:
			timeout = d
		}
	}
	if len(run) == 0 || strings.TrimSpace(run[0]) == "" {
		errs = append(errs, fmt.Errorf("%srun is required, and its first element names the program", prefix))
	}
	for _, arg := range run {
		errs = append(errs, checkPlaceholders(prefix+"run: ", arg, on, allowed)...)
	}

	return timeout, errs
}

func braces(names []string) string {
	out := make([]string, len(names))
	for i, n := range names {
		out[i] = "{" + n + "}"
	}

	return strings.Join(out, " ")
}

// AutoUpdate reports whether the bridge may update the CLI on its own.
func (s *Set) AutoUpdate() bool {
	return s.autoUpdate
}

// AutoUpdateSet reports whether the file holds a value for autoUpdate.
func (s *Set) AutoUpdateSet() bool {
	return s.autoUpdateSet
}

// Name is the bridge name the heartbeat sends. Empty clears the stored name.
func (s *Set) Name() string {
	return s.name
}

// MaxWorkers is the number of workers the bridge runs at once.
func (s *Set) MaxWorkers() int {
	return s.maxWorkers
}

// Pools maps each worker pool to its size. DefaultPool is always present, and
// its size can be 0.
func (s *Set) Pools() map[string]int {
	return maps.Clone(s.pools)
}

// ResultStatuses are the values of a worker result's status.
var ResultStatuses = []string{"finished", "blocked", "unfinished", "waiting"}

// resultFieldPattern is the shape of a result field name.
var resultFieldPattern = regexp.MustCompile(`^[A-Za-z][A-Za-z0-9_]*$`)

// resultSchema builds the JSON Schema of a worker's final reply. The core
// reason and the extra fields are optional. claude checks each fragment, and
// the bridge does not.
func resultSchema(fields map[string]any) (string, error) {
	props := map[string]any{
		"status":  map[string]any{"type": "string", "enum": ResultStatuses},
		"summary": map[string]any{"type": "string"},
		"reason":  map[string]any{"type": "string"},
	}
	var errs []error
	for _, name := range slices.Sorted(maps.Keys(fields)) {
		fragment, isMap := fields[name].(map[string]any)
		switch {
		case name == "status" || name == "summary" || name == "reason":
			errs = append(errs, fmt.Errorf("resultFields: %q is a core field, and every result has it", name))
		case !resultFieldPattern.MatchString(name):
			errs = append(errs, fmt.Errorf("resultFields: %q is not a field name, such as prUrl", name))
		case !isMap:
			errs = append(errs, fmt.Errorf("resultFields.%s is not a mapping, such as {type: string}", name))
		default:
			if _, err := json.Marshal(fragment); err != nil {
				errs = append(errs, fmt.Errorf("resultFields.%s is not valid JSON: %w", name, err))
			}
			props[name] = fragment
		}
	}
	if len(errs) > 0 {
		return "", errors.Join(errs...)
	}

	schema, err := json.Marshal(map[string]any{
		"type":       "object",
		"properties": props,
		"required":   []string{"status", "summary"},
	})
	if err != nil {
		return "", fmt.Errorf("resultFields: %w", err)
	}

	return string(schema), nil
}

// Projects lists the mapped project slugs in order.
func (s *Set) Projects() []string {
	return slices.Sorted(maps.Keys(s.dirs))
}

// Dir is the directory of a mapped slug, and "" for a slug the set does not map.
func (s *Set) Dir(slug string) string {
	return s.dirs[slug]
}

// Launch is the launch command and its timeout.
func (s *Set) Launch() Launch {
	return Launch{Command: slices.Clone(s.launch.Command), Timeout: s.launch.Timeout}
}

// HasInteractive reports whether a work entry has action interactive.
func (s *Set) HasInteractive() bool {
	return slices.ContainsFunc(slices.Collect(maps.Values(s.work)), func(w WorkEntry) bool { return w.Action == ActionInteractive })
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

// UnknownPermissionModes lists, in kind order, the modes the work entries pass
// that are not in PermissionModes. The bridge warns about them, and claude has
// the last word.
func (s *Set) UnknownPermissionModes() []string {
	var out []string
	for _, kind := range slices.Sorted(maps.Keys(s.work)) {
		mode := s.work[kind].PermissionMode
		if mode != "" && !slices.Contains(PermissionModes, mode) && !slices.Contains(out, mode) {
			out = append(out, mode)
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
	// Run means a work entry matched and the request starts a run.
	Run Skip = iota
	// NoRule means no work entry runs the request.
	NoRule
	// Unmapped means the request belongs to a project the file does not map.
	Unmapped
)

// Match is the outcome of matching one work request. Rule names the run as
// work:<kind>.
type Match struct {
	Skip           Skip
	Rule           string
	Action         string
	Project        string
	Dir            string
	PermissionMode string
	Model          string
	Prompt         string
	// Schema is the compact JSON Schema claude's final reply must match.
	Schema string
	// Pool is the worker pool the run takes a slot from. It is empty for an
	// interactive entry.
	Pool string
	// Experiment is the experiment of the entry, with its variants in file
	// order, or nil. Model is empty when it is set.
	Experiment *Experiment
	// Before is the command that runs ahead of claude, or nil.
	Before *Before
	// Command is the command of a command entry, and nil for any other entry.
	Command *Command
}

// KillWork marks dead the work of the project that a project.renamed event
// renames, and returns its slug and the reason. Dead work matches nothing
// until a reload or a restart reads the file again. Any other event kills
// nothing and returns two empty strings.
func (s *Set) KillWork(e event.Event) (string, string) {
	slug, ok := s.slugs[e.ProjectID]
	if !ok || e.Type != event.ProjectRenamedType {
		return "", ""
	}
	if !s.KillProjectWork(slug, api.ReasonProjectRenamed) {
		return "", ""
	}

	return slug, api.ReasonProjectRenamed
}

// KillProjectWork marks dead the work of a mapped project, and reports
// whether it lived until now.
func (s *Set) KillProjectWork(slug, reason string) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.deadWork[slug] != "" {
		return false
	}
	if s.deadWork == nil {
		s.deadWork = map[string]string{}
	}
	s.deadWork[slug] = reason

	return true
}

// renderArgv fills each element of a command on its own, so a value never
// splits in two.
func renderArgv(run []string, v map[string]string) []string {
	out := make([]string, len(run))
	for i, arg := range run {
		out[i] = directive.RenderArgument(arg, v)
	}

	return out
}
