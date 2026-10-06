package rules

import (
	"cmp"
	"errors"
	"fmt"
	"maps"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/event"
)

// WorkRulePrefix starts the rule name of a work match. The kind follows it.
const WorkRulePrefix = "work:"

// The capabilities a work map gives the bridge.
const (
	CapabilityWorkRequests = "work-requests"
	CapabilityInteractive  = "interactive"
)

// workPlaceholders are the names a work entry can fill.
var workPlaceholders = append([]string{"cardId", "cardNumber", "projectId", "project", "kind", "ruleId", "workRequestId"}, contextPlaceholders...)

// contextPlaceholders fill from the context of the request. An empty value is
// a real state, such as a card with no pull request.
var contextPlaceholders = []string{"pullRequestNumber", "pullRequestUrl", "headSha", "reason", "documentId"}

// WorkEntry runs one kind of work request. Action is empty for a worker,
// ActionInteractive or ActionCommand, as for a rule.
type WorkEntry struct {
	Action         string        `yaml:"action"`
	Prompt         string        `yaml:"prompt"`
	Model          string        `yaml:"model"`
	PermissionMode string        `yaml:"permissionMode"`
	Before         *BeforeConfig `yaml:"before"`
	WorkerPool     string        `yaml:"workerPool"`
	// Variants pick the model, as the variants of an experiment named after
	// the kind. An entry that sets them sets no model.
	Variants []Variant `yaml:"variants"`
	Run      []string  `yaml:"run"`
	Timeout  string    `yaml:"timeout"`

	schema         string
	experiment     *Experiment
	beforeTimeout  time.Duration
	commandTimeout time.Duration
}

// workRefusals lists, for each action, the fields its work entries refuse.
var workRefusals = map[string][]refusal{
	"": {
		{[]string{"run", "timeout"}, "%s belongs to action command, and this entry runs a worker"},
	},
	ActionInteractive: {
		{[]string{"before", "variants", "workerPool"}, "%s names worker behaviour, and action interactive launches no worker"},
		{[]string{"run", "timeout"}, "%s belongs to action command, and this entry launches an interactive session"},
	},
	ActionCommand: {
		{[]string{"before", "model", "permissionMode", "prompt", "variants", "workerPool"}, "%s names agent behaviour, and action command starts no agent"},
	},
}

// checkWork validates the entry of one kind, and fills its timeouts and its
// experiment. The caller checks the pool.
func checkWork(kind string, w *WorkEntry) error {
	var errs []error
	validKind := event.KindPattern.MatchString(kind)
	if !validKind {
		errs = append(errs, errors.New("a kind is 1 to 40 lowercase letters, digits and hyphens, and starts with a letter, such as implement"))
	}
	if table, ok := workRefusals[w.Action]; ok {
		errs = append(errs, refuse(table, map[string]bool{
			"before":         w.Before != nil,
			"model":          w.Model != "",
			"permissionMode": w.PermissionMode != "",
			"prompt":         w.Prompt != "",
			"run":            w.Run != nil,
			"timeout":        w.Timeout != "",
			"variants":       w.Variants != nil,
			"workerPool":     w.WorkerPool != "",
		})...)
	} else {
		errs = append(errs, fmt.Errorf("action %q is not %s or %s; leave it out for a worker", w.Action, ActionInteractive, ActionCommand))
	}
	if w.Action == ActionInteractive && goos == "windows" {
		errs = append(errs, fmt.Errorf("action %s needs a POSIX shell on macOS or Linux", ActionInteractive))
	}
	for _, err := range []error{checkWord("permissionMode", w.PermissionMode), checkWord("model", w.Model)} {
		if err != nil {
			errs = append(errs, err)
		}
	}

	if w.Action == ActionCommand {
		timeout, runErrs := checkRun("", w.Run, w.Timeout, DefaultCommandTimeout, MaxCommandTimeout, event.WorkRequestType, workPlaceholders)
		errs = append(errs, runErrs...)
		w.commandTimeout = timeout
	} else if strings.TrimSpace(w.Prompt) == "" {
		errs = append(errs, errors.New("prompt is required"))
	}
	errs = append(errs, checkPlaceholders("", w.Prompt, event.WorkRequestType, workPlaceholders)...)
	if w.Before != nil {
		timeout, beforeErrs := checkRun("before.", w.Before.Run, w.Before.Timeout, DefaultBeforeTimeout, MaxBeforeTimeout, event.WorkRequestType, workPlaceholders)
		errs = append(errs, beforeErrs...)
		w.beforeTimeout = timeout
	}

	if w.Variants != nil {
		if w.Model != "" {
			errs = append(errs, errors.New("model and variants are both set, and the variants name the model"))
		}
		// An invalid kind has its own error, and is no experiment name.
		if validKind {
			e := Experiment{Name: kind, Variants: w.Variants}
			if err := checkExperiment(e); err != nil {
				errs = append(errs, err)
			}
			w.experiment = e.clone()
		}
	}

	return errors.Join(errs...)
}

// MatchWork matches a work request against the work map. It checks the
// request first, so its prompt holds checked values only. An unmapped project
// skips as Unmapped. An unknown kind, an invalid request or dead work skips as
// NoRule. The server already checked the capability. A kind the map does not
// hold runs the app prompt of the request, when the set has appPrompts and the
// prompt is valid.
func (s *Set) MatchWork(w api.WorkRequest) Match {
	if event.CheckWorkRequest(&w) != nil {
		return Match{Skip: NoRule}
	}
	m := s.MatchKind(w)
	if _, mapped := s.work[w.Kind]; m.Skip == Run && !mapped && !validAppPrompt(w.Prompt) {
		return Match{Skip: NoRule, Project: m.Project}
	}

	return m
}

// validAppPrompt reports whether an app prompt holds text and only the
// placeholders of a work entry.
func validAppPrompt(prompt string) bool {
	return strings.TrimSpace(prompt) != "" && len(checkPlaceholders("", prompt, event.WorkRequestType, workPlaceholders)) == 0
}

// MatchKind matches the run of a kind of work against the work map, as a
// person's command names that run. It reads the project, the card, the kind
// and the ids of w, and checks none of them, so the caller checks them first.
// A kind the map does not hold runs as an app prompt when the set has
// appPrompts. A continued run carries no prompt and needs none.
func (s *Set) MatchKind(w api.WorkRequest) Match {
	slug, ok := s.slugs[w.ProjectID]
	if !ok {
		return Match{Skip: Unmapped}
	}
	entry, ok := s.work[w.Kind]
	s.mu.RLock()
	dead := s.deadWork[slug] != ""
	s.mu.RUnlock()
	if (!ok && !s.appPrompts) || dead {
		return Match{Skip: NoRule, Project: slug}
	}

	v := workValues(w, slug)
	if !ok {
		// A nil map of fields always builds.
		schema, _ := resultSchema(nil)

		return Match{
			Skip:           Run,
			Rule:           WorkRulePrefix + w.Kind,
			Project:        slug,
			Dir:            s.dirs[slug],
			PermissionMode: s.defaults.PermissionMode,
			Model:          s.defaults.Model,
			Schema:         schema,
			Prompt:         directive.Render(w.Prompt, v),
			Pool:           DefaultPool,
		}
	}
	m := Match{
		Skip:           Run,
		Rule:           WorkRulePrefix + w.Kind,
		Action:         entry.Action,
		Project:        slug,
		Dir:            s.dirs[slug],
		PermissionMode: entry.PermissionMode,
		Model:          entry.Model,
		Schema:         entry.schema,
	}
	switch entry.Action {
	case "":
		m.Prompt = directive.Render(entry.Prompt, v)
		m.Pool = cmp.Or(entry.WorkerPool, DefaultPool)
		if entry.experiment != nil {
			m.Experiment = entry.experiment.clone()
		}
		if entry.Before != nil {
			m.Before = &Before{Argv: renderArgv(entry.Before.Run, v), Timeout: entry.beforeTimeout}
		}
	case ActionInteractive:
		m.Prompt = directive.RenderPlain(entry.Prompt, v)
	case ActionCommand:
		m.Command = &Command{Argv: renderArgv(entry.Run, v), Timeout: entry.commandTimeout}
	}

	return m
}

// workValues are the values a work entry fills its placeholders with. A
// context value the request lacks fills as empty.
func workValues(w api.WorkRequest, slug string) map[string]string {
	pullRequestNumber := ""
	if w.Context.PullRequestNumber > 0 {
		pullRequestNumber = strconv.Itoa(w.Context.PullRequestNumber)
	}

	return map[string]string{
		"cardId":            w.CardID,
		"cardNumber":        strconv.Itoa(w.CardNumber),
		"projectId":         w.ProjectID,
		"project":           slug,
		"kind":              w.Kind,
		"ruleId":            w.RuleID,
		"workRequestId":     w.WorkRequestID,
		"pullRequestNumber": pullRequestNumber,
		"pullRequestUrl":    w.Context.PullRequestURL,
		"headSha":           w.Context.HeadSHA,
		"reason":            w.Context.Reason,
		"documentId":        w.Context.DocumentID,
	}
}

// WorkGaps lists the placeholders of the command of the kind that w leaves
// empty. A run of a migrated rule names no work request, so its rerun would
// run a command that differs from the first. A context placeholder never
// counts, because the command carries the context and an empty value is real.
func (s *Set) WorkGaps(w api.WorkRequest) []string {
	entry, ok := s.work[w.Kind]
	if !ok {
		return nil
	}
	v := workValues(w, s.slugs[w.ProjectID])
	var gaps []string
	for _, arg := range entry.Run {
		for _, p := range directive.Placeholders(arg) {
			if v[p] == "" && !slices.Contains(contextPlaceholders, p) && !slices.Contains(gaps, p) {
				gaps = append(gaps, p)
			}
		}
	}

	return gaps
}

// WorkKinds lists the kinds of the work map, in name order.
func (s *Set) WorkKinds() []string {
	return slices.Sorted(maps.Keys(s.work))
}

// WorkEntry is the entry of a kind, with its defaults filled.
func (s *Set) WorkEntry(kind string) (WorkEntry, bool) {
	w, ok := s.work[kind]

	return w, ok
}

// HasWork reports whether the set has a work map.
func (s *Set) HasWork() bool {
	return len(s.work) > 0
}

// WorkDead is the reason the work of a mapped project died, and "" while it
// lives.
func (s *Set) WorkDead(slug string) string {
	s.mu.RLock()
	defer s.mu.RUnlock()

	return s.deadWork[slug]
}

// Capabilities lists what the work map lets the bridge claim: work-requests
// for any entry, and interactive too for an interactive entry. It is nil for
// a set with no work.
func (s *Set) Capabilities() []string {
	if len(s.work) == 0 {
		return nil
	}
	out := []string{CapabilityWorkRequests}
	for _, kind := range slices.Sorted(maps.Keys(s.work)) {
		if s.work[kind].Action == ActionInteractive {
			return append(out, CapabilityInteractive)
		}
	}

	return out
}
