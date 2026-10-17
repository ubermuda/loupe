package rules

import (
	"cmp"
	"errors"
	"fmt"
	"maps"
	"regexp"
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
	CapabilityAppPrompts   = "app-prompts"
	CapabilitySession      = "session"
	// CapabilityRunCommand and CapabilityAddEntry are not part of Capabilities.
	// The first is always on, and the second needs acceptEntries.
	CapabilityRunCommand = "run-command"
	CapabilityAddEntry   = "add-entry"
)

// SessionKind is the work kind that starts an interactive session from an
// inbox item. Its entry must be interactive.
const SessionKind = "session"

// workPlaceholders are the names a work entry can fill.
var workPlaceholders = append([]string{"cardId", "cardNumber", "projectId", "project", "kind", "ruleId", "workRequestId", "subjectType", "subjectId"}, contextPlaceholders...)

// cardPlaceholders have no value for a subject that is no card.
var cardPlaceholders = []string{"cardId", "cardNumber"}

// subjectCapabilityPrefix starts the capability of a subject type that is no
// card, so the server offers that work only to a bridge with an entry for it.
const subjectCapabilityPrefix = "subject-"

// contextPlaceholders fill from the context of the request. An empty value is
// a real state, such as a card with no pull request.
var contextPlaceholders = []string{"pullRequestNumber", "pullRequestUrl", "headSha", "reason", "documentId"}

// WorkEntry runs one kind of work request. Action is empty for a worker,
// ActionInteractive or ActionCommand, as for a rule.
type WorkEntry struct {
	Action      string `yaml:"action"`
	Prompt      string `yaml:"prompt"`
	Account     string `yaml:"account"`
	Permissions string `yaml:"permissions"`
	Model       string `yaml:"model"`
	// PermissionMode is an old key. A file with accounts refuses it.
	PermissionMode string        `yaml:"permissionMode"`
	Before         *BeforeConfig `yaml:"before"`
	WorkerPool     string        `yaml:"workerPool"`
	// Variants pick the model or the account, as the variants of an
	// experiment named after the kind. An entry that sets them sets no model.
	Variants []Variant `yaml:"variants"`
	// Metrics are the metric keys the Comparison tab of the experiment shows,
	// in this order. Only an entry with variants sets them.
	Metrics []string `yaml:"metrics"`
	Run     []string `yaml:"run"`
	Timeout string   `yaml:"timeout"`
	// Subject is the subject type the entry runs. The check fills card when
	// it is empty.
	Subject string `yaml:"subject"`
	// Description and Params belong to a command entry. The bridge reports
	// them, so a worker can name the entry and fill its parameters.
	Description string           `yaml:"description"`
	Params      map[string]Param `yaml:"params"`

	schema string
	// run holds the settings of an agent run, and an experiment holds those
	// of each variant.
	run            RunSettings
	experiment     *Experiment
	beforeTimeout  time.Duration
	commandTimeout time.Duration
}

// Param is one parameter of a command entry. A value must match the whole
// Pattern, which is RE2 syntax.
type Param struct {
	Pattern     string `yaml:"pattern"`
	Description string `yaml:"description"`
}

// paramRegexp wraps a pattern so it must match a whole value.
func paramRegexp(pattern string) (*regexp.Regexp, error) {
	return regexp.Compile(`^(?:` + pattern + `)$`)
}

// Match reports whether value matches the whole pattern. A pattern that does
// not compile matches nothing, and the check refuses such a pattern at load.
func (p Param) Match(value string) bool {
	re, err := paramRegexp(p.Pattern)

	return err == nil && re.MatchString(value)
}

// checkParams checks the parameters of a command entry.
func checkParams(params map[string]Param) []error {
	var errs []error
	for _, name := range slices.Sorted(maps.Keys(params)) {
		p := params[name]
		switch {
		case !paramName.MatchString(name):
			errs = append(errs, fmt.Errorf("params: name %q is not letters only, such as out", name))
		case slices.Contains(workPlaceholders, name):
			errs = append(errs, fmt.Errorf("params: %q is a built-in placeholder, so name the parameter differently", name))
		}
		if p.Pattern == "" {
			errs = append(errs, fmt.Errorf("params.%s: pattern is required", name))

			continue
		}
		if _, err := paramRegexp(p.Pattern); err != nil {
			errs = append(errs, fmt.Errorf("params.%s: pattern does not compile as RE2: %w", name, err))
		}
	}

	return errs
}

// The sizes the server takes in a heartbeat. An entry over them would make the
// server refuse the whole heartbeat.
const (
	maxReportText    = 500
	maxReportParams  = 20
	maxReportRun     = 50
	maxReportRunItem = 1000
)

// checkReportSizes refuses a command entry that the heartbeat cannot carry.
func checkReportSizes(w *WorkEntry) []error {
	var errs []error
	if len(w.Description) > maxReportText {
		errs = append(errs, fmt.Errorf("description is longer than %d characters", maxReportText))
	}
	if len(w.Params) > maxReportParams {
		errs = append(errs, fmt.Errorf("params has more than %d parameters", maxReportParams))
	}
	for name, p := range w.Params {
		if len(p.Pattern) > maxReportText || len(p.Description) > maxReportText || len(name) > 100 {
			errs = append(errs, fmt.Errorf("params.%s: name, pattern or description is too long, and the limit is %d characters", name, maxReportText))
		}
	}
	if len(w.Run) > maxReportRun {
		errs = append(errs, fmt.Errorf("run has more than %d elements", maxReportRun))
	}
	for _, item := range w.Run {
		if len(item) > maxReportRunItem {
			errs = append(errs, fmt.Errorf("a run element is longer than %d characters", maxReportRunItem))

			break
		}
	}

	return errs
}

// paramName is the shape of a placeholder name.
var paramName = regexp.MustCompile(`^[A-Za-z]+$`)

// runs are the settings of each agent run the entry can start, its variants
// included, and nil for a command entry.
func (w WorkEntry) runs() []RunSettings {
	if w.Action == ActionCommand {
		return nil
	}
	runs := []RunSettings{w.run}
	if w.experiment != nil {
		runs = append(runs, w.experiment.settings...)
	}

	return runs
}

// workRefusals lists, for each action, the fields its work entries refuse.
var workRefusals = map[string][]refusal{
	"": {
		{[]string{"run", "timeout"}, "%s belongs to action command, and this entry runs a worker"},
		{[]string{"params", "description"}, "%s belongs to action command, and this entry runs a worker"},
	},
	ActionInteractive: {
		{[]string{"before", "metrics", "variants", "workerPool"}, "%s names worker behaviour, and action interactive launches no worker"},
		{[]string{"run", "timeout"}, "%s belongs to action command, and this entry launches an interactive session"},
		{[]string{"params", "description"}, "%s belongs to action command, and this entry launches an interactive session"},
	},
	ActionCommand: {
		{[]string{"account", "before", "metrics", "model", "permissionMode", "permissions", "prompt", "variants", "workerPool"}, "%s names agent behaviour, and action command starts no agent"},
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
			"account":        w.Account != "",
			"permissions":    w.Permissions != "",
			"before":         w.Before != nil,
			"metrics":        w.Metrics != nil,
			"model":          w.Model != "",
			"permissionMode": w.PermissionMode != "",
			"prompt":         w.Prompt != "",
			"run":            w.Run != nil,
			"timeout":        w.Timeout != "",
			"variants":       w.Variants != nil,
			"params":         w.Params != nil,
			"description":    w.Description != "",
			"workerPool":     w.WorkerPool != "",
		})...)
	} else {
		errs = append(errs, fmt.Errorf("action %q is not %s or %s; leave it out for a worker", w.Action, ActionInteractive, ActionCommand))
	}
	if w.Action == ActionInteractive && goos == "windows" {
		errs = append(errs, fmt.Errorf("action %s needs a POSIX shell on macOS or Linux", ActionInteractive))
	}
	if kind == SessionKind && w.Action != ActionInteractive {
		errs = append(errs, fmt.Errorf("the %s entry needs action %s, because it opens a session from an inbox item", SessionKind, ActionInteractive))
	}
	if err := checkWord("model", w.Model); err != nil {
		errs = append(errs, err)
	}

	if w.Action == ActionCommand {
		errs = append(errs, checkParams(w.Params)...)
		errs = append(errs, checkReportSizes(w)...)
		allowed := slices.Concat(workPlaceholders, slices.Sorted(maps.Keys(w.Params)))
		timeout, runErrs := checkRun("", w.Run, w.Timeout, DefaultCommandTimeout, MaxCommandTimeout, event.WorkRequestType, allowed)
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
	errs = append(errs, checkSubject(w)...)

	if w.Action == "" && w.Metrics != nil && w.Variants == nil {
		errs = append(errs, errors.New("metrics needs variants, and only an experiment shows metrics"))
	}
	if w.Variants != nil {
		if w.Model != "" {
			errs = append(errs, errors.New("model and variants are both set, and the variants name the model"))
		}
		// An invalid kind has its own error, and is no experiment name.
		if validKind {
			e := Experiment{Name: kind, Variants: w.Variants, Metrics: w.Metrics}
			if err := checkExperiment(e); err != nil {
				errs = append(errs, err)
			}
			w.experiment = e.clone()
		}
	}

	return errors.Join(errs...)
}

// checkSubject checks the subject of the entry, and fills card when it is
// empty. A subject that is no card has no card placeholders, and its
// capability must fit the shape the server takes.
func checkSubject(w *WorkEntry) []error {
	w.Subject = cmp.Or(w.Subject, api.SubjectCard)
	if w.Subject == api.SubjectCard {
		return nil
	}
	if !event.KindPattern.MatchString(subjectCapabilityPrefix + w.Subject) {
		return []error{fmt.Errorf("subject %q is not 1 to 32 lowercase letters, digits and hyphens that start with a letter, such as analysis", w.Subject)}
	}
	var errs []error
	if w.Action == ActionInteractive {
		errs = append(errs, fmt.Errorf("action %s opens a session on a card, and this entry runs subject %s", ActionInteractive, w.Subject))
	}
	fields := map[string][]string{"prompt": {w.Prompt}, "run": w.Run}
	if w.Before != nil {
		fields["before.run"] = w.Before.Run
	}
	for _, field := range slices.Sorted(maps.Keys(fields)) {
		var used []string
		for _, text := range fields[field] {
			for _, name := range directive.Placeholders(text) {
				if slices.Contains(cardPlaceholders, name) && !slices.Contains(used, name) {
					used = append(used, name)
				}
			}
		}
		for _, name := range used {
			errs = append(errs, fmt.Errorf("%s uses {%s}, which names a card, and this entry runs subject %s", field, name, w.Subject))
		}
	}

	return errs
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
	if m.Skip == Run && s.RunCommandRefusal(w) != "" {
		return Match{Skip: NoRule, Project: m.Project}
	}
	if _, mapped := s.entry(w); m.Skip == Run && !mapped && !validAppPrompt(w.Prompt) {
		return Match{Skip: NoRule, Project: m.Project}
	}

	return m
}

// validAppPrompt reports whether an app prompt holds text and only the
// placeholders of a work entry.
func validAppPrompt(prompt string) bool {
	return strings.TrimSpace(prompt) != "" && len(checkPlaceholders("", prompt, event.WorkRequestType, workPlaceholders)) == 0
}

// entry is the work entry of the kind of w. With appPrompts, an entry about
// another subject type counts as no entry, so the app prompt runs.
func (s *Set) entry(w api.WorkRequest) (WorkEntry, bool) {
	entry, ok := s.work[w.Kind]
	if ok && s.appPrompts && entry.Subject != w.SubjectType && !runsCommand(w) {
		return WorkEntry{}, false
	}

	return entry, ok
}

// MatchKind matches the run of a kind of work against the work map, as a
// person's command names that run. It reads the project, the subject, the
// kind and the ids of w, and checks none of them, so the caller checks them
// first. A request whose subject type differs from the entry's skips as
// NoRule, or runs as an app prompt when the set has appPrompts. A kind the
// map does not hold runs as an app prompt when the set has appPrompts. A
// continued run carries no prompt and needs none.
func (s *Set) MatchKind(w api.WorkRequest) Match {
	slug, ok := s.slugs[w.ProjectID]
	if !ok {
		return Match{Skip: Unmapped}
	}
	entry, ok := s.entry(w)
	runCommand := runsCommand(w)
	if runCommand && (!ok || entry.Action != ActionCommand) {
		return Match{Skip: NoRule, Project: slug}
	}
	runs := entry.runs()
	if !ok {
		runs = []RunSettings{s.appRun}
	}
	s.mu.RLock()
	dead := s.deadWork[slug] != ""
	off := s.offLocked(runs)
	s.mu.RUnlock()
	off = off || s.agentsOff != "" && (!ok || entry.Action != ActionCommand)
	if (!ok && !s.appPrompts) || dead || off || (ok && entry.Subject != w.SubjectType && !runCommand) {
		return Match{Skip: NoRule, Project: slug}
	}

	v := workValues(w, slug)
	if runCommand {
		for name := range entry.Params {
			if value, given := w.Params[name]; given {
				v[name] = value
			}
		}
	}
	if !ok {
		// A nil map of fields always builds.
		schema, _ := resultSchema(nil)

		m := Match{
			Skip:    Run,
			Rule:    WorkRulePrefix + w.Kind,
			Project: slug,
			Dir:     s.dirs[slug],
			Schema:  schema,
			Prompt:  renderWork(w, w.Prompt, v),
			Pool:    DefaultPool,
		}.withRun(s.appRun)
		m.Model, m.Effort = cmp.Or(w.Model, m.Model), w.Effort

		return m
	}
	m := Match{
		Skip:    Run,
		Rule:    WorkRulePrefix + w.Kind,
		Action:  entry.Action,
		Project: slug,
		Dir:     s.dirs[slug],
		Schema:  entry.schema,
	}
	if entry.Action != ActionCommand {
		m = m.withRun(entry.run)
		m.Model, m.Effort = cmp.Or(w.Model, m.Model), w.Effort
	}
	switch entry.Action {
	case "":
		m.Prompt = renderWork(w, entry.Prompt, v)
		m.Pool = cmp.Or(entry.WorkerPool, DefaultPool)
		// A model the request names is no draw, so the run joins no experiment.
		if entry.experiment != nil && w.Model == "" {
			m.Experiment, m.Model = entry.experiment.clone(), ""
		}
		if entry.Before != nil {
			m.Before = &Before{Argv: renderArgv(entry.Before.Run, v), Timeout: entry.beforeTimeout}
		}
	case ActionInteractive:
		m.Prompt = directive.RenderPlain(entry.Prompt, v)
		if w.Context.InboxActionID != "" && w.Context.InboxItemID != "" {
			m.Prompt += "\n\n" + directive.InboxNote(w.Context.InboxItemNumber, w.Context.InboxItemID)
		}
		if w.Kind == SessionKind && strings.TrimSpace(w.Prompt) != "" {
			m.Prompt += "\n\n" + directive.SuggestedPrompt(w.Prompt)
		}
	case ActionCommand:
		m.Command = &Command{Argv: renderArgv(entry.Run, v), Timeout: entry.commandTimeout}
	}

	return m
}

// renderWork renders the prompt of a run. A request that an inbox action sent
// gets the line that names the item, before the footer.
func renderWork(w api.WorkRequest, template string, v map[string]string) string {
	if w.InboxActionID != "" {
		return directive.RenderInboxAction(template, v, directive.InboxAction{ItemID: w.InboxItemID, ItemNumber: w.InboxItemNumber, ActionID: w.InboxActionID})
	}

	return directive.Render(template, v)
}

// workValues are the values a work entry fills its placeholders with. A
// context value the request lacks fills as empty, and so does a card value
// of a subject that is no card.
func workValues(w api.WorkRequest, slug string) map[string]string {
	pullRequestNumber := ""
	if w.Context.PullRequestNumber > 0 {
		pullRequestNumber = strconv.Itoa(w.Context.PullRequestNumber)
	}
	cardID, cardNumber := "", ""
	if w.OnCard() {
		cardID, cardNumber = w.SubjectID, strconv.Itoa(w.CardNumber)
	}

	return map[string]string{
		"cardId":            cardID,
		"cardNumber":        cardNumber,
		"subjectType":       w.SubjectType,
		"subjectId":         w.SubjectID,
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

// The reasons a bridge refuses a run-command request.
const (
	RefuseEntryMissing       = "entry-missing"
	RefuseEntryChanged       = "entry-changed"
	RefuseParamInvalid       = "param-invalid"
	RefuseWorktreeMissing    = "worktree-missing"
	RefusePlaceholderMissing = "placeholder-missing"
)

// The reasons a bridge refuses an add-entry request.
const (
	RefuseEntryInvalid = "entry-invalid"
	RefuseEntryExists  = "entry-exists"
	RefuseNotAccepted  = "not-accepted"
	RefuseWriteFailed  = "write-failed"
	RefuseFileChanged  = "file-changed"
	RefuseReloadFailed = "reload-failed"
)

// runsCommand reports whether w asks for the run of a command entry by name,
// whatever its subject is.
func runsCommand(w api.WorkRequest) bool {
	return w.Capability == CapabilityRunCommand
}

// RunCommandRefusal is the reason code for which the bridge refuses a
// run-command request, and "" when the rules accept it or when it is no
// valid run-command request of a mapped project. The worktree is no part of
// it, because the rules cannot read it. The entry must exist and be a command
// entry. Its run list must be the one the owner confirmed, when the request
// carries that list. The request must hold a value for each parameter, each value must
// match the whole pattern, and the request must hold no other parameter. Each
// placeholder of the argv must have a value.
func (s *Set) RunCommandRefusal(w api.WorkRequest) string {
	if !runsCommand(w) || event.CheckWorkRequest(&w) != nil {
		return ""
	}
	slug, mapped := s.slugs[w.ProjectID]
	if !mapped {
		return ""
	}
	entry, ok := s.work[w.Kind]
	if !ok || entry.Action != ActionCommand {
		return RefuseEntryMissing
	}
	if w.Run != nil && !slices.Equal(w.Run, entry.Run) {
		return RefuseEntryChanged
	}
	for name := range w.Params {
		if _, declared := entry.Params[name]; !declared {
			return RefuseParamInvalid
		}
	}
	for name, p := range entry.Params {
		if value, given := w.Params[name]; !given || !p.Match(value) {
			return RefuseParamInvalid
		}
	}
	v := workValues(w, slug)
	for _, arg := range entry.Run {
		for _, name := range directive.Placeholders(arg) {
			if _, param := entry.Params[name]; !param && v[name] == "" && !slices.Contains(contextPlaceholders, name) {
				return RefusePlaceholderMissing
			}
		}
	}

	return ""
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

// CommandEntry is a command entry as the bridge reports it.
type CommandEntry struct {
	Name  string
	Entry WorkEntry
}

// CommandEntries lists the command entries of the work map, in name order.
func (s *Set) CommandEntries() []CommandEntry {
	var out []CommandEntry
	for _, kind := range s.WorkKinds() {
		if e := s.work[kind]; e.Action == ActionCommand {
			out = append(out, CommandEntry{Name: kind, Entry: e})
		}
	}

	return out
}

// WorkKinds lists the kinds of the work map, in name order.
func (s *Set) WorkKinds() []string {
	return slices.Sorted(maps.Keys(s.work))
}

// WorkKindsOn lists the kinds of the work map that the bridge can run now, in
// name order: the kinds whose agent and account are on. A kind of another
// subject than card reads <subject>:<kind>, because the server needs the
// subject to match a request. It is never nil, so a bridge with no usable kind
// reports an empty list.
func (s *Set) WorkKindsOn() []string {
	s.mu.RLock()
	defer s.mu.RUnlock()

	out := []string{}
	for _, kind := range slices.Sorted(maps.Keys(s.work)) {
		entry := s.work[kind]
		if s.agentsOff != "" && entry.Action != ActionCommand || s.offLocked(entry.runs()) {
			continue
		}
		if entry.Subject != api.SubjectCard {
			kind = entry.Subject + ":" + kind
		}
		out = append(out, kind)
	}

	return out
}

// WorkEntry is the entry of a kind, with its defaults filled.
func (s *Set) WorkEntry(kind string) (WorkEntry, bool) {
	w, ok := s.work[kind]

	return w, ok
}

// HasWork reports whether the set claims work requests: it has a work map, or
// it runs app prompts.
func (s *Set) HasWork() bool {
	return len(s.work) > 0 || s.appPrompts
}

// WorkDead is the reason the work of a mapped project died, and "" while it
// lives.
func (s *Set) WorkDead(slug string) string {
	s.mu.RLock()
	defer s.mu.RUnlock()

	return s.deadWork[slug]
}

// Capabilities lists what the work map lets the bridge claim: work-requests
// for any entry or for app prompts, interactive too for an interactive entry,
// session for the session entry,
// subject- with the subject type for each entry whose subject is no card, and
// app-prompts when the set runs app prompts. An entry whose agent or account
// is off adds neither. It is nil for a set with no work.
func (s *Set) Capabilities() []string {
	if !s.HasWork() {
		return nil
	}
	out := []string{CapabilityWorkRequests}
	interactive, session := false, false
	var subjects []string
	s.mu.RLock()
	defer s.mu.RUnlock()
	for kind, entry := range s.work {
		if s.agentsOff != "" && entry.Action != ActionCommand || s.offLocked(entry.runs()) {
			continue
		}
		interactive = interactive || entry.Action == ActionInteractive
		session = session || kind == SessionKind
		if entry.Subject != api.SubjectCard && !slices.Contains(subjects, entry.Subject) {
			subjects = append(subjects, entry.Subject)
		}
	}
	if interactive {
		out = append(out, CapabilityInteractive)
	}
	if session {
		out = append(out, CapabilitySession)
	}
	slices.Sort(subjects)
	for _, subject := range subjects {
		out = append(out, subjectCapabilityPrefix+subject)
	}
	if s.appPrompts && s.agentsOff == "" && !s.offLocked([]RunSettings{s.appRun}) {
		out = append(out, CapabilityAppPrompts)
	}

	return out
}
