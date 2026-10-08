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
const Example = `accounts:
  claude:
    harness: claude-code

defaults:
  account: claude

projects:
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

// HarnessClaudeCode is the harness of an account that runs Claude Code.
const HarnessClaudeCode = "claude-code"

// HarnessCodex is the harness of an account that runs Codex.
const HarnessCodex = "codex"

// CodexModes are the sandbox modes `codex exec -s` takes.
var CodexModes = []string{"read-only", "workspace-write", "danger-full-access"}

// profilePattern matches the profile of a Codex account, which names a file
// in the Codex home folder.
var profilePattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$`)

// The permission levels a rule names. A harness maps each to a mode of its own.
const (
	PermissionsReadOnly  = "read-only"
	PermissionsWorkspace = "workspace"
	PermissionsFull      = "full"
)

// claudeCodeModes maps each permission level to a Claude Code permission mode.
var claudeCodeModes = map[string]string{
	PermissionsReadOnly:  "plan",
	PermissionsWorkspace: "auto",
	PermissionsFull:      "bypassPermissions",
}

// codexModes maps each permission level to a Codex sandbox mode.
var codexModes = map[string]string{
	PermissionsReadOnly:  "read-only",
	PermissionsWorkspace: "workspace-write",
	PermissionsFull:      "danger-full-access",
}

// levelModes is the map from a permission level to a mode of the harness.
func levelModes(harness string) map[string]string {
	if harness == HarnessCodex {
		return codexModes
	}

	return claudeCodeModes
}

// MaxAccounts is the most accounts a file declares, because the heartbeat
// reports each one and the server takes 50 rows.
const MaxAccounts = 50

// agentsOffNoAccounts is why a file with no accounts block runs no agent.
const agentsOffNoAccounts = "rules.yaml has no accounts block"

// File is the rule file as written.
type File struct {
	// EnvFile is read at the start of each agent run, before the account's.
	EnvFile  string             `yaml:"envFile"`
	Accounts map[string]Account `yaml:"accounts"`
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
	// AppPrompts runs the prompt a request carries for a kind that Work does
	// not hold. It is off when the key is absent.
	AppPrompts *bool `yaml:"appPrompts"`
	// Name is the host name when absent, and a blank value opts out.
	Name *string `yaml:"name"`
	// Collect is on when the key is absent. Off, it also stops the host
	// samples.
	Collect *bool `yaml:"collect"`
}

// WorkerPool is one named share of maxWorkers.
type WorkerPool struct {
	Size *int `yaml:"size"`
}

// FileDefaults fill a rule's empty fields before the bridge flags do. A reload
// reads them again, and the flags stay fixed for the process.
type FileDefaults struct {
	Account     string `yaml:"account"`
	Permissions string `yaml:"permissions"`
	// Model and PermissionMode are old keys. A file with accounts refuses
	// them, and a file without loads with its agents off.
	Model          string `yaml:"model"`
	PermissionMode string `yaml:"permissionMode"`
}

// Account is one named agent account as written.
type Account struct {
	Harness   string `yaml:"harness"`
	ConfigDir string `yaml:"configDir"`
	// CodexHome is the Codex home folder, and Profile names a
	// <profile>.config.toml file in it. Only a codex account takes them.
	CodexHome string `yaml:"codexHome"`
	Profile   string `yaml:"profile"`
	Model     string `yaml:"model"`
	// PermissionMode is a mode of the harness, and a rule's level beats it.
	PermissionMode string `yaml:"permissionMode"`
	EnvFile        string `yaml:"envFile"`
}

// RunSettings are what an agent run takes from its account, its entry and the
// defaults. EnvFiles lists the global file before the account's.
type RunSettings struct {
	Account string
	Harness string
	// ConfigDir is the config folder of Claude Code, or the home folder of
	// Codex.
	ConfigDir string
	// Profile is the Codex profile, and "" for any other harness.
	Profile        string
	Model          string
	PermissionMode string
	// Permissions is the level of the entry, and "" when it names none.
	Permissions string
	EnvFiles    []string
}

func (r RunSettings) clone() RunSettings {
	r.EnvFiles = slices.Clone(r.EnvFiles)

	return r
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
	// noCollect is the inverse of collect, so a zero Set collects.
	noCollect  bool
	maxWorkers int
	name       string
	// pools maps each pool name to its size, DefaultPool included.
	pools map[string]int
	// work has the defaults of each worker entry filled.
	work map[string]WorkEntry
	// appPrompts runs the app prompt of a kind that work does not hold, with
	// appRun.
	appPrompts bool
	appRun     RunSettings
	// defaults are the bridge flags, and fileDefaults the file's defaults.
	defaults     Defaults
	fileDefaults FileDefaults
	// accounts have their paths expanded.
	accounts map[string]Account
	envFile  string
	// agentsOff is why no worker, interactive entry or app prompt runs, and
	// "" while they run.
	agentsOff string

	// deadWork maps a project slug to the reason its work died. The bridge
	// reads and writes it on the stream goroutine alone. mu guards it for any
	// other caller.
	mu       sync.RWMutex
	deadWork map[string]string
	// accountsOff maps each account that failed its check to the reason. It
	// is nil until a check ran, and mu guards it.
	accountsOff map[string]string
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
	f, root, err := decodeFile(data)
	if err != nil {
		return nil, err
	}

	s := &Set{dirs: map[string]string{}, work: map[string]WorkEntry{}, autoUpdate: f.AutoUpdate != nil && *f.AutoUpdate, autoUpdateSet: f.AutoUpdate != nil, noCollect: f.Collect != nil && !*f.Collect}
	s.appPrompts, s.defaults, s.fileDefaults = f.AppPrompts != nil && *f.AppPrompts, defaults, f.Defaults
	if keyNode(root, "accounts") == nil {
		s.agentsOff = agentsOffNoAccounts
	}
	var errs []error
	s.accounts, errs = checkAccounts(f.Accounts, root)
	declared := slices.Sorted(maps.Keys(f.Accounts))
	if f.EnvFile != "" {
		path, err := checkPath("envFile", f.EnvFile)
		if err != nil {
			errs = append(errs, fmt.Errorf("%s%w", lineOf(root, "envFile"), err))
		}
		s.envFile = path
	}
	switch {
	case f.Defaults.Account != "":
		errs = append(errs, checkAccountName(lineOf(root, "defaults", "account"), "defaults.account", f.Defaults.Account, declared)...)
	case s.agentsOff == "":
		errs = append(errs, fmt.Errorf("%sdefaults.account is required, and names one of accounts: %s", lineOf(root, "accounts"), declaredNames(declared)))
	}
	errs = append(errs, checkLevel(lineOf(root, "defaults", "permissions"), "defaults.permissions", f.Defaults.Permissions)...)
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
	if len(f.Work) == 0 && !s.appPrompts {
		errs = append(errs, errors.New("the rule file has no work, and no appPrompts: true"))
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
		errs = append(errs, s.checkWorkAccounts(kind, &w, declared, root)...)
		if w.Action == "" {
			if err := checkPool(w.WorkerPool, f.WorkerPools, pools, known, s.maxWorkers); err != nil {
				errs = append(errs, fmt.Errorf("work %q: %w", kind, err))
			}
			// A nil map of fields always builds.
			w.schema, _ = resultSchema(nil)
		}
		s.work[kind] = w
	}
	if s.appPrompts {
		if err := checkPool("", f.WorkerPools, pools, known, s.maxWorkers); err != nil {
			errs = append(errs, fmt.Errorf("appPrompts: %w", err))
		}
		s.appRun = s.resolve("", "", "", false)
	}
	interactive := slices.ContainsFunc(slices.Collect(maps.Values(f.Work)), func(w WorkEntry) bool { return w.Action == ActionInteractive })
	launch, err := checkLaunch(f.Launch, interactive)
	if err != nil {
		errs = append(errs, err)
	}
	s.launch = launch
	errs = append(errs, checkHooks(f.Hooks)...)
	s.hooks = f.Hooks
	if len(f.Work) == 0 && !s.appPrompts {
		return nil, withExample(errors.Join(errs...))
	}
	if len(errs) > 0 {
		return nil, errors.Join(errs...)
	}

	return s, nil
}

// checkAccounts validates each account, and returns them with their paths
// expanded. No path has to exist yet.
func checkAccounts(accounts map[string]Account, root *yaml.Node) (map[string]Account, []error) {
	out := map[string]Account{}
	var errs []error
	if len(accounts) > MaxAccounts {
		errs = append(errs, fmt.Errorf("%saccounts: at most %d accounts, got %d", lineOf(root, "accounts"), MaxAccounts, len(accounts)))
	}
	for _, name := range slices.Sorted(maps.Keys(accounts)) {
		a := accounts[name]
		at := func(field string) string { return lineOf(root, "accounts", name, field) }
		if !poolNamePattern.MatchString(name) {
			errs = append(errs, fmt.Errorf("%saccounts.%s: an account name is 1 to 40 lowercase letters, digits and hyphens, and starts with a letter, such as claude", lineOf(root, "accounts", name), name))
		}
		switch a.Harness {
		case HarnessClaudeCode:
		case "":
			errs = append(errs, fmt.Errorf("%saccounts.%s: harness is required, such as %s", lineOf(root, "accounts", name), name, HarnessClaudeCode))
		case HarnessCodex:
		default:
			errs = append(errs, fmt.Errorf("%saccounts.%s.harness %q is not a harness; this CLI accepts %s and %s", at("harness"), name, a.Harness, HarnessClaudeCode, HarnessCodex))
		}
		errs = append(errs, checkHarnessKeys(a, name, at)...)
		for _, field := range []struct {
			key   string
			value *string
		}{{"configDir", &a.ConfigDir}, {"codexHome", &a.CodexHome}, {"envFile", &a.EnvFile}} {
			if *field.value == "" {
				continue
			}
			path, err := checkPath(field.key, *field.value)
			if err != nil {
				errs = append(errs, fmt.Errorf("%saccounts.%s.%w", at(field.key), name, err))
			}
			*field.value = path
		}
		for _, field := range [][2]string{{"model", a.Model}, {"permissionMode", a.PermissionMode}} {
			if err := checkWord(field[0], field[1]); err != nil {
				errs = append(errs, fmt.Errorf("%saccounts.%s.%w", at(field[0]), name, err))
			}
		}
		if a.Harness == HarnessCodex && a.PermissionMode != "" && !slices.Contains(CodexModes, a.PermissionMode) {
			errs = append(errs, fmt.Errorf("%saccounts.%s.permissionMode %q is not %s", at("permissionMode"), name, a.PermissionMode, strings.Join(CodexModes, ", ")))
		}
		out[name] = a
	}

	return out, errs
}

// checkHarnessKeys refuses an account key that belongs to the other harness,
// and a profile that is no file name.
func checkHarnessKeys(a Account, name string, at func(string) string) []error {
	var errs []error
	switch a.Harness {
	case HarnessCodex:
		if a.ConfigDir != "" {
			errs = append(errs, fmt.Errorf("%saccounts.%s.configDir: a codex account takes codexHome, and configDir is for claude-code", at("configDir"), name))
		}
		if a.Profile != "" && !profilePattern.MatchString(a.Profile) {
			errs = append(errs, fmt.Errorf("%saccounts.%s.profile %q is not 1 to 64 letters, digits, dots, underscores and hyphens, and starts with a letter or digit", at("profile"), name, a.Profile))
		}
	case HarnessClaudeCode:
		for _, field := range [][2]string{{"codexHome", a.CodexHome}, {"profile", a.Profile}} {
			if field[1] != "" {
				errs = append(errs, fmt.Errorf("%saccounts.%s.%s: only a codex account takes %s", at(field[0]), name, field[0], field[0]))
			}
		}
	}

	return errs
}

// checkPath expands ~ in a path, and refuses a path that is not absolute.
func checkPath(field, path string) (string, error) {
	expanded, err := expandHome(path)
	if err != nil {
		return "", fmt.Errorf("%s: %w", field, err)
	}
	if !filepath.IsAbs(expanded) {
		return "", fmt.Errorf("%s %s is not an absolute path", field, path)
	}

	return expanded, nil
}

// checkAccountName refuses a name that accounts does not declare. at is the
// line prefix.
func checkAccountName(at, field, name string, declared []string) []error {
	if name == "" || slices.Contains(declared, name) {
		return nil
	}

	return []error{fmt.Errorf("%s%s %q is not in accounts, which declares %s", at, field, name, declaredNames(declared))}
}

func declaredNames(declared []string) string {
	if len(declared) == 0 {
		return "none"
	}

	return strings.Join(declared, ", ")
}

// checkLevel refuses a permission level outside the three. Empty sets none.
func checkLevel(at, field, level string) []error {
	if level == "" {
		return nil
	}
	if _, ok := claudeCodeModes[level]; !ok {
		return []error{fmt.Errorf("%s%s %q is not %s, %s or %s", at, field, level, PermissionsReadOnly, PermissionsWorkspace, PermissionsFull)}
	}

	return nil
}

// checkWorkAccounts checks the account and the level of an entry and of its
// variants, and fills the run settings of each.
func (s *Set) checkWorkAccounts(kind string, w *WorkEntry, declared []string, root *yaml.Node) []error {
	at := func(path ...any) string { return lineOf(root, append([]any{"work", kind}, path...)...) }
	prefix := fmt.Sprintf("work %q: ", kind)
	errs := checkAccountName(at("account"), prefix+"account", w.Account, declared)
	errs = append(errs, checkLevel(at("permissions"), prefix+"permissions", w.Permissions)...)
	for i, v := range w.Variants {
		vprefix := fmt.Sprintf("%svariant %q: ", prefix, v.Name)
		errs = append(errs, checkAccountName(at("variants", i, "account"), vprefix+"account", v.Account, declared)...)
		errs = append(errs, checkLevel(at("variants", i, "permissions"), vprefix+"permissions", v.Permissions)...)
	}
	switch w.Action {
	case "":
		w.run = s.resolve(w.Account, w.Model, w.Permissions, false)
		if w.experiment != nil {
			w.experiment.settings = make([]RunSettings, len(w.experiment.Variants))
			for i, v := range w.experiment.Variants {
				w.experiment.settings[i] = s.resolve(cmp.Or(v.Account, w.Account), v.Model, cmp.Or(v.Permissions, w.Permissions), false)
			}
		}
	case ActionInteractive:
		w.run = s.resolve(w.Account, w.Model, w.Permissions, true)
	}

	return errs
}

// resolve gives the settings of an agent run. An interactive session takes its
// mode from its own level alone, so it never runs with more rights than it names.
func (s *Set) resolve(account, model, level string, interactive bool) RunSettings {
	name := cmp.Or(account, s.fileDefaults.Account)
	a := s.accounts[name]
	r := RunSettings{Account: name, Harness: a.Harness, ConfigDir: a.ConfigDir, Model: cmp.Or(model, a.Model, s.defaults.Model), Permissions: level}
	modes, flagMode := levelModes(a.Harness), s.defaults.PermissionMode
	if a.Harness == HarnessCodex {
		// The bridge flags name Claude Code values, which Codex would refuse.
		r.ConfigDir, r.Profile, r.Model, flagMode = a.CodexHome, a.Profile, cmp.Or(model, a.Model), ""
	}
	r.PermissionMode = modes[level]
	if !interactive {
		r.PermissionMode = cmp.Or(r.PermissionMode, a.PermissionMode, modes[s.fileDefaults.Permissions], flagMode)
	}
	for _, path := range []string{s.envFile, a.EnvFile} {
		if path != "" {
			r.EnvFiles = append(r.EnvFiles, path)
		}
	}

	return r
}

// Account gives the settings of a worker run on the named account, with the
// permissions level of its entry. ok is false when the set has no such account.
func (s *Set) Account(name, level string) (RunSettings, bool) {
	if _, ok := s.accounts[name]; !ok {
		return RunSettings{}, false
	}

	return s.resolve(name, "", level, false), true
}

// AgentsOff is why the set runs no worker, interactive entry or app prompt,
// and "" while it runs them. Command entries run either way.
func (s *Set) AgentsOff() string {
	return s.agentsOff
}

// UsedAccounts names, in order, each account that a worker entry, an
// interactive entry, one of their variants or an app prompt runs on.
func (s *Set) UsedAccounts() []string {
	var out []string
	runs := []RunSettings{}
	for _, w := range s.work {
		runs = append(runs, w.runs()...)
	}
	if s.appPrompts {
		runs = append(runs, s.appRun)
	}
	for _, r := range runs {
		if r.Account != "" && !slices.Contains(out, r.Account) {
			out = append(out, r.Account)
		}
	}
	slices.Sort(out)

	return out
}

// SetAccountProblems turns off each entry that runs on an account of m, which
// maps the account to the reason its check failed. A nil m turns none off.
func (s *Set) SetAccountProblems(m map[string]string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.accountsOff = maps.Clone(m)
	if s.accountsOff == nil {
		s.accountsOff = map[string]string{}
	}
}

// AccountsOff maps each account that failed its check to the reason. It is
// nil until SetAccountProblems ran.
func (s *Set) AccountsOff() map[string]string {
	s.mu.RLock()
	defer s.mu.RUnlock()

	return maps.Clone(s.accountsOff)
}

// offLocked reports whether one of runs takes an account that failed its
// check. The caller holds mu.
func (s *Set) offLocked(runs []RunSettings) bool {
	return slices.ContainsFunc(runs, func(r RunSettings) bool { return s.accountsOff[r.Account] != "" })
}

// keyNode is the key node at path in the document root, or nil. A string
// step names a mapping key, and an int step a sequence index.
func keyNode(root *yaml.Node, path ...any) *yaml.Node {
	n, key := root, (*yaml.Node)(nil)
	for _, step := range path {
		if n == nil {
			return nil
		}
		key = nil
		switch step := step.(type) {
		case string:
			if n.Kind != yaml.MappingNode {
				return nil
			}
			for i := 0; i+1 < len(n.Content); i += 2 {
				if n.Content[i].Value == step {
					key = n.Content[i]
					n = n.Content[i+1]

					break
				}
			}
		case int:
			if n.Kind == yaml.SequenceNode && step < len(n.Content) {
				key, n = n.Content[step], n.Content[step]
			}
		}
		if key == nil {
			return nil
		}
	}

	return key
}

// lineOf is the "line N: " prefix of the key at path, or "" when the file
// holds no such key.
func lineOf(root *yaml.Node, path ...any) string {
	if key := keyNode(root, path...); key != nil {
		return fmt.Sprintf("line %d: ", key.Line)
	}

	return ""
}

// decodeFile decodes the one document that holds content. An empty document,
// such as a bare --- before or after it, holds nothing and is skipped.
func decodeFile(data []byte) (File, *yaml.Node, error) {
	var f File
	var root *yaml.Node
	docs := yaml.NewDecoder(bytes.NewReader(data))
	content := -1
	for i := 0; ; i++ {
		var node yaml.Node
		if err := docs.Decode(&node); errors.Is(err, io.EOF) {
			break
		} else if err != nil {
			return f, nil, fmt.Errorf("parse rule file: %w", err)
		}
		var doc any
		if err := node.Decode(&doc); err != nil {
			return f, nil, fmt.Errorf("parse rule file: %w", err)
		}
		if doc == nil {
			continue
		}
		if content >= 0 {
			return f, nil, errors.New("parse rule file: it holds a second YAML document after ---, and the bridge reads one")
		}
		if err := refuseOldFormat(doc); err != nil {
			return f, nil, err
		}
		content, root = i, node.Content[0]
	}
	if content < 0 {
		return f, nil, withExample(ErrEmpty)
	}

	// A second pass, because KnownFields applies to a decoder and not to a node.
	dec := yaml.NewDecoder(bytes.NewReader(data))
	dec.KnownFields(true)
	for range content + 1 {
		if err := dec.Decode(&f); err != nil {
			return f, nil, fmt.Errorf("parse rule file: %w", err)
		}
	}

	return f, root, nil
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
	// A file with no accounts block loads with its agents off, old keys and all.
	if _, ok := top["accounts"]; !ok {
		return errors.Join(errs...)
	}
	if defaults, ok := top["defaults"].(map[string]any); ok {
		if _, ok := defaults["model"]; ok {
			errs = append(errs, errors.New("parse rule file: defaults.model is gone; move its value into the model of the account that defaults.account names"))
		}
		if _, ok := defaults["permissionMode"]; ok {
			errs = append(errs, errors.New("parse rule file: defaults.permissionMode is gone; move its value into the permissionMode of the account that defaults.account names"))
		}
	}
	work, _ := top["work"].(map[string]any)
	for _, kind := range slices.Sorted(maps.Keys(work)) {
		if entry, ok := work[kind].(map[string]any); ok {
			if _, ok := entry["permissionMode"]; ok {
				errs = append(errs, fmt.Errorf("parse rule file: work %q: permissionMode is gone; use permissions: %s, %s or %s instead", kind, PermissionsReadOnly, PermissionsWorkspace, PermissionsFull))
			}
		}
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

// Collect reports whether the bridge sends the tool calls and the timing of
// each worker run, and the host samples.
func (s *Set) Collect() bool {
	return !s.noCollect
}

// AppPrompts reports whether the bridge runs the app prompt of a kind its work
// map does not hold.
func (s *Set) AppPrompts() bool {
	return s.appPrompts
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

// UnknownPermissionModes lists the modes the accounts pass, in account order,
// then the --permission-mode flag, that are not in PermissionModes. The bridge
// warns about them, and claude has the last word.
func (s *Set) UnknownPermissionModes() []string {
	var out []string
	modes := []string{}
	for _, name := range slices.Sorted(maps.Keys(s.accounts)) {
		if s.accounts[name].Harness != HarnessCodex {
			modes = append(modes, s.accounts[name].PermissionMode)
		}
	}
	for _, mode := range append(modes, s.defaults.PermissionMode) {
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
	// Permissions is the level of the entry, and "" when it names none.
	Permissions string
	Model       string
	// Effort is the claude --effort level the request asks for, or "".
	Effort string
	// Account, Harness, ConfigDir, Profile and EnvFiles come from the account the run
	// takes. They are empty for a command entry.
	Account   string
	Harness   string
	ConfigDir string
	Profile   string
	EnvFiles  []string
	Prompt    string
	// Schema is the compact JSON Schema claude's final reply must match.
	Schema string
	// Pool is the worker pool the run takes a slot from. It is empty for an
	// interactive entry.
	Pool string
	// Experiment is the experiment of the entry, with its variants in file
	// order, or nil. Model is empty when it is set, and ApplyVariant fills it.
	Experiment *Experiment
	// Before is the command that runs ahead of claude, or nil.
	Before *Before
	// Command is the command of a command entry, and nil for any other entry.
	Command *Command
}

// ApplyVariant gives the match the settings of a variant of its experiment.
func (m Match) ApplyVariant(v Variant) Match {
	if m.Experiment == nil {
		return m
	}

	return m.withRun(m.Experiment.Settings(v))
}

func (m Match) withRun(r RunSettings) Match {
	r = r.clone()
	m.Account, m.Harness, m.ConfigDir, m.Profile, m.Model, m.PermissionMode, m.EnvFiles = r.Account, r.Harness, r.ConfigDir, r.Profile, r.Model, r.PermissionMode, r.EnvFiles
	m.Permissions = r.Permissions

	return m
}

// Run is the settings of an agent run that the match resolved.
func (m Match) Run() RunSettings {
	return RunSettings{
		Account: m.Account, Harness: m.Harness, ConfigDir: m.ConfigDir, Profile: m.Profile, Model: m.Model,
		PermissionMode: m.PermissionMode, Permissions: m.Permissions, EnvFiles: slices.Clone(m.EnvFiles),
	}
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
