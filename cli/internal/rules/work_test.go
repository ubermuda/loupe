package rules

import (
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/event"
)

const workRequestID = "0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90"

const workFile = claudeAccount + claudeDefaults + `
projects:
  loupe:
    dir: {dir}
launch:
  command: [osascript, -e, 'run {script}']
workerPools:
  quick:
    size: 1
work:
  implement:
    prompt: Implement {cardNumber} {cardId} {project} {projectId} {kind} {ruleId} {workRequestId}.
    workerPool: quick
    permissions: read-only
    before:
      run: [prep, '{cardNumber}', '{workRequestId}']
  pair:
    action: interactive
    prompt: Pair on {cardNumber} in {project}.
    model: opus
  test:
    action: command
    run: [make, test, 'CARD={cardNumber}', '{kind}']
    timeout: 5m
  split:
    prompt: Split {cardNumber}.
    variants:
      - {name: a, weight: 1, model: opus}
      - {name: b, weight: 3, model: sonnet}
    metrics: [cost, merge-rate]
`

func workRequest(kind string) api.WorkRequest {
	return api.WorkRequest{
		Type:          event.WorkRequestType,
		ProjectID:     projectID,
		Subject:       api.WorkRequestSubject{Type: "work-request", ID: workRequestID},
		WorkRequestID: workRequestID,
		Kind:          kind,
		State:         api.WorkRequestOpen,
		SubjectType:   api.SubjectCard,
		SubjectID:     cardID,
		CardNumber:    87,
		RuleID:        "impl.rule",
		CreatedAt:     time.Date(2026, 10, 1, 12, 30, 0, 0, time.UTC),
	}
}

func TestParseRefusesAnInvalidWorkEntry(t *testing.T) {
	entry := func(kind, fields string) string {
		return "projects:\n  loupe:\n    dir: {dir}\nlaunch:\n  command: ['{script}']\nworkerPools:\n  quick: {size: 1}\n" +
			"work:\n  " + kind + ":\n    " + strings.ReplaceAll(strings.TrimSpace(fields), "\n", "\n    ") + "\n"
	}
	interactive := "action: interactive\nprompt: x\n"
	command := "action: command\nrun: [make]\n"
	variants := "variants:\n  - {name: a, weight: 1, model: opus}\n"

	for name, tc := range map[string]struct {
		body string
		want string
	}{
		"kind with a capital":          {entry("Implement", "prompt: x"), `work "Implement": a kind is 1 to 40 lowercase letters`},
		"kind with a digit first":      {entry("1st", "prompt: x"), `work "1st": a kind is`},
		"kind too long":                {entry(strings.Repeat("a", 41), "prompt: x"), "a kind is"},
		"unknown action":               {entry("x", "action: launch\nprompt: x"), `work "x": action "launch" is not interactive or command`},
		"unknown field":                {entry("x", "prompt: x\nmaxChain: 2"), "field maxChain not found"},
		"allowUntrusted":               {entry("x", "prompt: x\nallowUntrusted: true"), "field allowUntrusted not found"},
		"worker without prompt":        {entry("x", "model: opus"), `work "x": prompt is required`},
		"worker with a blank prompt":   {entry("x", "prompt: '  '"), "prompt is required"},
		"interactive without prompt":   {entry("x", "action: interactive"), "prompt is required"},
		"worker with run":              {entry("x", "prompt: x\nrun: [make]"), "run belongs to action command, and this entry runs a worker"},
		"worker with timeout":          {entry("x", "prompt: x\ntimeout: 1m"), "timeout belongs to action command"},
		"interactive with before":      {entry("x", interactive+"before:\n  run: [x]"), "before names worker behaviour, and action interactive launches no worker"},
		"interactive with variants":    {entry("x", interactive+variants), "variants names worker behaviour"},
		"interactive with workerPool":  {entry("x", interactive+"workerPool: quick"), "workerPool names worker behaviour"},
		"interactive with metrics":     {entry("x", interactive+"metrics: [cost]"), "metrics names worker behaviour"},
		"interactive with run":         {entry("x", interactive+"run: [x]"), "run belongs to action command, and this entry launches an interactive session"},
		"command with prompt":          {entry("x", command+"prompt: x"), "prompt names agent behaviour, and action command starts no agent"},
		"command with model":           {entry("x", command+"model: opus"), "model names agent behaviour"},
		"command with permissions":     {entry("x", command+"permissions: full"), "permissions names agent behaviour"},
		"command with account":         {entry("x", command+"account: claude"), "account names agent behaviour"},
		"command with before":          {entry("x", command+"before:\n  run: [x]"), "before names agent behaviour"},
		"command with variants":        {entry("x", command+variants), "variants names agent behaviour"},
		"command with workerPool":      {entry("x", command+"workerPool: quick"), "workerPool names agent behaviour"},
		"command with metrics":         {entry("x", command+"metrics: [cost]"), "metrics names agent behaviour"},
		"metrics without variants":     {entry("x", "prompt: x\nmetrics: [cost]"), "metrics needs variants"},
		"empty metrics, no variants":   {entry("x", "prompt: x\nmetrics: []"), "metrics needs variants"},
		"bad metric key":               {entry("x", "prompt: x\nmetrics: [Cost]\n"+variants), `metric "Cost": a metric key is 1 to 64`},
		"metric key too long":          {entry("x", "prompt: x\nmetrics: [c"+strings.Repeat("o", 64)+"]\n"+variants), "a metric key is 1 to 64"},
		"repeated metric":              {entry("x", "prompt: x\nmetrics: [cost, cost]\n"+variants), `metric "cost" is listed twice`},
		"command without run":          {entry("x", "action: command"), "run is required"},
		"command timeout too long":     {entry("x", command+"timeout: 2h"), "the most it takes is 1h0m0s"},
		"command timeout not duration": {entry("x", command+"timeout: soon"), `timeout "soon" is not a duration`},
		"unknown placeholder":          {entry("x", "prompt: 'Card {title}'"), `work "x": unknown placeholder {title}`},
		"an old event placeholder":     {entry("x", "prompt: 'Moved to {to}'"), "unknown placeholder {to}"},
		"unknown run placeholder":      {entry("x", "action: command\nrun: [make, '{title}']"), "run: unknown placeholder {title}"},
		"unknown before placeholder":   {entry("x", "prompt: x\nbefore:\n  run: [prep, '{title}']"), "before.run: unknown placeholder {title}"},
		"before without run":           {entry("x", "prompt: x\nbefore:\n  timeout: 1m"), "before.run is required"},
		"model with a space":           {entry("x", "prompt: x\nmodel: 'claude opus'"), `model "claude opus" holds whitespace`},
		"variants and model":           {entry("x", "prompt: x\nmodel: opus\n"+variants), "model and variants are both set"},
		"empty variants":               {entry("x", "prompt: x\nvariants: []"), "it has no variants"},
		"variant weight":               {entry("x", "prompt: x\nvariants:\n  - {name: a, weight: 0, model: opus}"), `variant "a": weight must be at least 1`},
		"variant name":                 {entry("x", "prompt: x\nvariants:\n  - {name: A, weight: 1, model: opus}"), "a name is 1 to 64"},
		"variant without model":        {entry("x", "prompt: x\nvariants:\n  - {name: a, weight: 1}"), "model or account is required"},
		"variant model too long":       {entry("x", "prompt: x\nvariants:\n  - {name: a, weight: 1, model: "+strings.Repeat("m", 101)+"}"), "the server takes at most 100"},
		"two variants of one name":     {entry("x", "prompt: x\nvariants:\n  - {name: a, weight: 1, model: opus}\n  - {name: a, weight: 1, model: sonnet}"), `two variants are named "a"`},
		"undeclared pool":              {entry("x", "prompt: x\nworkerPool: slow"), `work "x": workerPool "slow" is not in workerPools, which declares default, quick`},
		"subject with a capital":       {entry("x", "subject: Analysis\nprompt: x"), `work "x": subject "Analysis" is not 1 to 32`},
		"subject too long":             {entry("x", "subject: "+strings.Repeat("a", 33)+"\nprompt: x"), "is not 1 to 32"},
		"cardId off card":              {entry("x", "subject: analysis\nprompt: 'Do {cardId}'"), "prompt uses {cardId}, which names a card, and this entry runs subject analysis"},
		"cardNumber in run off card":   {entry("x", "subject: analysis\naction: command\nrun: [make, '{cardNumber}']"), "run uses {cardNumber}, which names a card"},
		"cardId in before off card":    {entry("x", "subject: analysis\nprompt: x\nbefore:\n  run: [prep, '{cardId}']"), "before.run uses {cardId}, which names a card"},
		"interactive off card":         {entry("x", "subject: analysis\n"+interactive), "action interactive opens a session on a card, and this entry runs subject analysis"},
	} {
		t.Run(name, func(t *testing.T) {
			text, _ := file(t, tc.body)
			_, err := Parse([]byte(text), Defaults{})
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want it to contain %q", err, tc.want)
			}
		})
	}
}

func TestParseAcceptsEmptyMetricsWithVariants(t *testing.T) {
	body := "projects:\n  loupe:\n    dir: {dir}\nwork:\n  x:\n    prompt: x\n    metrics: []\n" +
		"    variants:\n      - {name: a, weight: 1, model: opus}\n"
	text, _ := file(t, body)
	if _, err := Parse([]byte(text), Defaults{}); err != nil {
		t.Fatalf("err = %v", err)
	}
}

func TestParseRefusesTooManyMetrics(t *testing.T) {
	keys := make([]string, MaxMetrics+1)
	for i := range keys {
		keys[i] = "m" + strings.Repeat("a", i+1)
	}
	body := "projects:\n  loupe:\n    dir: {dir}\nwork:\n  x:\n    prompt: x\n    metrics: [" + strings.Join(keys, ", ") +
		"]\n    variants:\n      - {name: a, weight: 1, model: opus}\n"
	text, _ := file(t, body)
	if _, err := Parse([]byte(text), Defaults{}); err == nil || !strings.Contains(err.Error(), "it has 17 metrics, and the server takes at most 16") {
		t.Fatalf("err = %v", err)
	}
}

func TestParseRefusesTooManyVariants(t *testing.T) {
	var b strings.Builder
	b.WriteString("projects:\n  loupe:\n    dir: {dir}\nwork:\n  x:\n    prompt: x\n    variants:\n")
	for i := range MaxVariants + 1 {
		b.WriteString("      - {name: v" + strings.Repeat("a", i+1) + ", weight: 1, model: opus}\n")
	}
	text, _ := file(t, b.String())
	if _, err := Parse([]byte(text), Defaults{}); err == nil || !strings.Contains(err.Error(), "it has 33 variants") {
		t.Fatalf("err = %v", err)
	}
}

func TestParseRefusesAnInteractiveEntryWithNoLaunch(t *testing.T) {
	text, _ := file(t, "projects:\n  loupe:\n    dir: {dir}\nwork:\n  pair:\n    action: interactive\n    prompt: x\n")
	if _, err := Parse([]byte(text), Defaults{}); err == nil || !strings.Contains(err.Error(), "launch.command is required") {
		t.Fatalf("err = %v", err)
	}
}

func TestParseRefusesAnInteractiveEntryOnWindows(t *testing.T) {
	old := goos
	goos = "windows"
	t.Cleanup(func() { goos = old })
	text, _ := file(t, "projects:\n  loupe:\n    dir: {dir}\nlaunch:\n  command: ['{script}']\nwork:\n  pair:\n    action: interactive\n    prompt: x\n")
	if _, err := Parse([]byte(text), Defaults{}); err == nil || !strings.Contains(err.Error(), "needs a POSIX shell") {
		t.Fatalf("err = %v", err)
	}
}

func TestParseRefusesAnEmptyDefaultPoolForAWorkerEntry(t *testing.T) {
	text, _ := file(t, "projects:\n  loupe:\n    dir: {dir}\nmaxWorkers: 1\nworkerPools:\n  quick: {size: 1}\nwork:\n  x:\n    prompt: x\n")
	if _, err := Parse([]byte(text), Defaults{}); err == nil || !strings.Contains(err.Error(), `work "x": the default pool has no slot`) {
		t.Fatalf("err = %v", err)
	}
}

func TestMatchWorkRunsAWorker(t *testing.T) {
	text, dir := file(t, workFile)
	s, err := Parse([]byte(text), Defaults{PermissionMode: "acceptEdits", Model: "sonnet"})
	if err != nil {
		t.Fatal(err)
	}
	checkLoupe(t, s)

	m := s.MatchWork(workRequest("implement"))
	if m.Skip != Run || m.Rule != "work:implement" || m.Action != "" || m.Project != "loupe" || m.Dir != dir || m.Pool != "quick" ||
		m.PermissionMode != "plan" || m.Model != "sonnet" || m.Account != "claude" || m.Harness != HarnessClaudeCode ||
		m.Schema == "" || m.Experiment != nil || m.Command != nil {
		t.Fatalf("match = %+v", m)
	}
	want := directive.Render("Implement 87 "+cardID+" loupe "+projectID+" implement impl.rule "+workRequestID+".", nil)
	if m.Prompt != want {
		t.Fatalf("prompt = %q, want %q", m.Prompt, want)
	}
	if m.Before == nil || !slices.Equal(m.Before.Argv, []string{"prep", "87", workRequestID}) || m.Before.Timeout != DefaultBeforeTimeout {
		t.Fatalf("before = %+v", m.Before)
	}
}

func TestMatchWorkLaunchesAnInteractiveSession(t *testing.T) {
	s := checked(t, workFile)

	m := s.MatchWork(workRequest("pair"))
	if m.Skip != Run || m.Rule != "work:pair" || m.Action != ActionInteractive || m.Pool != "" || m.Model != "opus" || m.Schema != "" ||
		m.Prompt != "Pair on 87 in loupe." || m.Before != nil || m.Command != nil {
		t.Fatalf("match = %+v", m)
	}
}

func TestMatchWorkRunsACommand(t *testing.T) {
	s := checked(t, workFile)

	m := s.MatchWork(workRequest("test"))
	if m.Skip != Run || m.Rule != "work:test" || m.Action != ActionCommand || m.Pool != "" || m.Prompt != "" || m.Schema != "" || m.Command == nil {
		t.Fatalf("match = %+v", m)
	}
	if !slices.Equal(m.Command.Argv, []string{"make", "test", "CARD=87", "test"}) || m.Command.Timeout != 5*time.Minute {
		t.Fatalf("command = %+v", m.Command)
	}
}

// A teardown is a command entry like any other: the app requests it when a card
// reaches a terminal column.
func TestMatchWorkRunsATeardownCommand(t *testing.T) {
	s := checked(t, workFile+`
  teardown:
    action: command
    run: [just, worktree-down, 'card-{cardNumber}']
`)

	m := s.MatchWork(workRequest("teardown"))
	if m.Skip != Run || m.Rule != "work:teardown" || m.Action != ActionCommand || m.Prompt != "" || m.Command == nil {
		t.Fatalf("match = %+v", m)
	}
	if !slices.Equal(m.Command.Argv, []string{"just", "worktree-down", "card-87"}) || m.Command.Timeout != DefaultCommandTimeout {
		t.Fatalf("command = %+v", m.Command)
	}
}

func TestMatchWorkCarriesTheVariantsAsAnExperiment(t *testing.T) {
	text, _ := file(t, workFile)
	s, err := Parse([]byte(text), Defaults{Model: "haiku"})
	if err != nil {
		t.Fatal(err)
	}
	checkLoupe(t, s)

	m := s.MatchWork(workRequest("split"))
	if m.Skip != Run || m.Model != "" || m.Pool != DefaultPool || m.Experiment == nil || m.Experiment.Name != "split" {
		t.Fatalf("match = %+v", m)
	}
	if got := m.ApplyVariant(m.Experiment.Variants[1]); got.Model != "sonnet" || got.Account != "claude" || got.PermissionMode != "" {
		t.Fatalf("ApplyVariant = %+v", got)
	}
	want := []Variant{{Name: "a", Weight: 1, Model: "opus"}, {Name: "b", Weight: 3, Model: "sonnet"}}
	if !slices.Equal(m.Experiment.Variants, want) {
		t.Fatalf("variants = %+v", m.Experiment.Variants)
	}
	if !slices.Equal(m.Experiment.Metrics, []string{"cost", "merge-rate"}) {
		t.Fatalf("metrics = %v", m.Experiment.Metrics)
	}
	m.Experiment.Variants[0].Model = "changed"
	m.Experiment.Metrics[0] = "changed"
	again := s.MatchWork(workRequest("split")).Experiment
	if again.Variants[0].Model != "opus" || again.Metrics[0] != "cost" {
		t.Fatal("a caller changed the set's experiment")
	}
}

func TestMatchWorkTakesTheModelAndTheEffortOfTheRequest(t *testing.T) {
	s := checked(t, workFile)

	for _, kind := range []string{"implement", "pair"} {
		w := workRequest(kind)
		w.Model, w.Effort = "claude-opus-4-1", "xhigh"
		if m := s.MatchWork(w); m.Skip != Run || m.Model != "claude-opus-4-1" || m.Effort != "xhigh" {
			t.Fatalf("%s: match = %+v", kind, m)
		}
	}
	if m := s.MatchWork(workRequest("pair")); m.Model != "opus" || m.Effort != "" {
		t.Fatalf("no request model: match = %+v", m)
	}
}

// A model the request names is no draw, so the run joins no experiment.
func TestARequestModelRunsOutsideTheExperiment(t *testing.T) {
	s := checked(t, workFile)
	w := workRequest("split")
	w.Model = "haiku"

	if m := s.MatchWork(w); m.Skip != Run || m.Model != "haiku" || m.Experiment != nil {
		t.Fatalf("match = %+v", m)
	}
	if m := s.MatchWork(workRequest("split")); m.Experiment == nil || m.Model != "" {
		t.Fatalf("no request model: match = %+v", m)
	}
}

func TestMatchWorkSkipsWhatItCannotRun(t *testing.T) {
	s := checked(t, workFile)
	other := workRequest("implement")
	other.ProjectID = "0192f3a1-4b2c-7d3e-8f10-ffffffffffff"

	if m := s.MatchWork(workRequest("deploy")); m.Skip != NoRule || m.Project != "loupe" {
		t.Fatalf("unknown kind: match = %+v", m)
	}
	if m := s.MatchWork(other); m.Skip != Unmapped {
		t.Fatalf("unknown project: match = %+v", m)
	}
	if m := parse(t, workFile).MatchWork(workRequest("implement")); m.Skip != Unmapped {
		t.Fatalf("unchecked set: match = %+v", m)
	}
}

func TestAGoneProjectKillsItsWork(t *testing.T) {
	s := checked(t, workFile)

	if !s.HasWork() || s.WorkDead("loupe") != "" {
		t.Fatalf("has work = %v, dead = %q before the kill", s.HasWork(), s.WorkDead("loupe"))
	}
	if !s.KillProjectWork("loupe", api.ReasonProjectGone) || s.KillProjectWork("loupe", api.ReasonProjectGone) {
		t.Fatal("KillProjectWork reports a death once")
	}
	if m := s.MatchWork(workRequest("implement")); m.Skip != NoRule {
		t.Fatalf("match = %+v", m)
	}
	if got := s.WorkDead("loupe"); got != api.ReasonProjectGone {
		t.Fatalf("dead = %q", got)
	}
}

// An entry runs the subject type it names, card by default, and skips a
// request about another subject.
func TestMatchWorkRunsTheSubjectOfTheEntry(t *testing.T) {
	s := checked(t, claudeAccount+claudeDefaults+"projects:\n  loupe:\n    dir: {dir}\nwork:\n  implement:\n    prompt: Implement {cardNumber}.\n"+
		"  analyse:\n    subject: analysis\n    action: command\n    run: [analyse, '{subjectType}', '{subjectId}', '{project}']\n")
	analysis := workRequest("analyse")
	analysis.SubjectType, analysis.SubjectID, analysis.CardNumber = "analysis", "0199a0e2-aaaa-7c5e-9f2a-3b1c6d7e8f90", 0

	m := s.MatchWork(analysis)
	if m.Skip != Run || m.Command == nil || !slices.Equal(m.Command.Argv, []string{"analyse", "analysis", "0199a0e2-aaaa-7c5e-9f2a-3b1c6d7e8f90", "loupe"}) {
		t.Fatalf("match = %+v", m)
	}
	onCard := workRequest("analyse")
	if m := s.MatchWork(onCard); m.Skip != NoRule {
		t.Fatalf("a card request matched the analysis entry: %+v", m)
	}
	analysis.Kind = "implement"
	if m := s.MatchWork(analysis); m.Skip != NoRule {
		t.Fatalf("an analysis request matched the card entry: %+v", m)
	}
}

// A card subject fills the card placeholders and the subject ones alike.
func TestMatchWorkFillsTheSubjectOfACard(t *testing.T) {
	s := checked(t, claudeAccount+claudeDefaults+"projects:\n  loupe:\n    dir: {dir}\nwork:\n  implement:\n    prompt: '{cardId} {cardNumber} {subjectType} {subjectId}'\n")
	m := s.MatchWork(workRequest("implement"))
	if want := cardID + " 87 card " + cardID; !strings.HasPrefix(m.Prompt, want) {
		t.Fatalf("prompt = %q, want it to start with %q", m.Prompt, want)
	}
}

func TestCapabilities(t *testing.T) {
	for name, tc := range map[string]struct {
		body string
		want []string
	}{
		"workers only":     {claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: {dir}\nwork:\n  x:\n    prompt: x\n", []string{"work-requests"}},
		"with interactive": {workFile, []string{"work-requests", "interactive"}},
		"app prompts only": {claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: {dir}\nappPrompts: true\n", []string{"work-requests"}},
		"with subjects": {claudeAccount + claudeDefaults + "projects:\n  loupe:\n    dir: {dir}\nwork:\n  x:\n    subject: review\n    prompt: x\n" +
			"  y:\n    subject: analysis\n    prompt: x\n  z:\n    subject: analysis\n    prompt: x\n  w:\n    subject: card\n    prompt: x\n",
			[]string{"work-requests", "subject-analysis", "subject-review"}},
	} {
		t.Run(name, func(t *testing.T) {
			if got := parse(t, tc.body).Capabilities(); !slices.Equal(got, tc.want) {
				t.Fatalf("Capabilities = %v, want %v", got, tc.want)
			}
		})
	}
}

// A project rename stops the work of the old slug, and any other event stops
// nothing.
func TestARenamedProjectKillsItsWork(t *testing.T) {
	s := checked(t, workFile)
	renamed := event.Event{Type: event.ProjectRenamedType, ProjectID: projectID, FromSlug: "loupe", ToSlug: "loupe-2"}

	if slug, reason := s.KillWork(event.Event{Type: event.CardHeldType, ProjectID: projectID}); slug != "" || reason != "" {
		t.Fatalf("a hold killed %q for %q", slug, reason)
	}
	if slug, reason := s.KillWork(renamed); slug != "loupe" || reason != api.ReasonProjectRenamed {
		t.Fatalf("KillWork = %q, %q", slug, reason)
	}
	if m := s.MatchWork(workRequest("implement")); m.Skip != NoRule {
		t.Fatalf("match = %+v", m)
	}
	if slug, _ := s.KillWork(renamed); slug != "" {
		t.Fatal("a second rename killed the work again")
	}
}

// checkLoupe resolves the loupe project of a set parsed with its own defaults.
func checkLoupe(t *testing.T, s *Set) {
	t.Helper()
	if err := s.Check(t.Context(), columnsServer(t, map[string]string{"loupe": loupeColumns})); err != nil {
		t.Fatal(err)
	}
}

const contextFile = claudeAccount + claudeDefaults + `
projects:
  loupe:
    dir: {dir}
work:
  fix:
    prompt: Fix pull request {pullRequestNumber} at {pullRequestUrl} on {headSha} for {reason}.
    before:
      run: [prep, '{cardNumber}', '{cardId}', '{pullRequestNumber}']
  revise:
    prompt: Revise document {documentId}.
  sync:
    action: command
    run: [sync, '{pullRequestNumber}', '{headSha}', '{reason}', '{documentId}', '{pullRequestUrl}']
`

func TestMatchWorkFillsTheContext(t *testing.T) {
	s := checked(t, contextFile)
	w := workRequest("fix")
	w.Context = api.WorkRequestContext{
		PullRequestNumber: 42, PullRequestURL: "https://github.com/acme/widgets/pull/42", HeadSHA: "abc1234",
		Reason: "checks-failed", DocumentID: "01a10beb-ba65-736b-8626-a6e3fa59dfc5",
	}

	m := s.MatchWork(w)
	want := directive.Render("Fix pull request 42 at https://github.com/acme/widgets/pull/42 on abc1234 for checks-failed.", nil)
	if m.Skip != Run || m.Prompt != want {
		t.Fatalf("prompt = %q, want %q", m.Prompt, want)
	}
	if m.Before == nil || !slices.Equal(m.Before.Argv, []string{"prep", "87", cardID, "42"}) {
		t.Fatalf("before = %+v", m.Before)
	}

	w.Kind = "revise"
	if m := s.MatchWork(w); m.Prompt != directive.Render("Revise document 01a10beb-ba65-736b-8626-a6e3fa59dfc5.", nil) {
		t.Fatalf("prompt = %q", m.Prompt)
	}

	w.Kind = "sync"
	m = s.MatchWork(w)
	if m.Command == nil || !slices.Equal(m.Command.Argv, []string{
		"sync", "42", "abc1234", "checks-failed", "01a10beb-ba65-736b-8626-a6e3fa59dfc5", "https://github.com/acme/widgets/pull/42",
	}) {
		t.Fatalf("command = %+v", m.Command)
	}
}

// A request with no context, as an older server sends it, fills each context
// placeholder as empty, so bridge-before.sh reads no pull request.
func TestMatchWorkFillsAnAbsentContextAsEmpty(t *testing.T) {
	s := checked(t, contextFile)

	m := s.MatchWork(workRequest("fix"))
	if m.Skip != Run || m.Prompt != directive.Render("Fix pull request  at  on  for .", nil) {
		t.Fatalf("prompt = %q", m.Prompt)
	}
	if m.Before == nil || !slices.Equal(m.Before.Argv, []string{"prep", "87", cardID, ""}) {
		t.Fatalf("before = %+v", m.Before)
	}

	w := workRequest("sync")
	m = s.MatchWork(w)
	if m.Command == nil || !slices.Equal(m.Command.Argv, []string{"sync", "", "", "", "", ""}) {
		t.Fatalf("command = %+v", m.Command)
	}
}

// An empty context value is a real state, such as a card with no pull
// request, so a rerun never lacks one.
func TestWorkGapsLeavesOutTheContextPlaceholders(t *testing.T) {
	s := checked(t, contextFile)

	if gaps := s.WorkGaps(workRequest("sync")); gaps != nil {
		t.Fatalf("gaps = %v", gaps)
	}
	w := workRequest("sync")
	w.WorkRequestID = ""
	if gaps := s.WorkGaps(w); gaps != nil {
		t.Fatalf("gaps = %v, want none, because the sync command names no work request", gaps)
	}
}

// A bridge that opts in runs the app prompt of a kind its work map does not
// hold, with the defaults of the file and the flags. A local entry wins.
func TestMatchWorkRunsTheAppPromptOfAnUnmappedKind(t *testing.T) {
	withPrompt := func(kind, prompt string) api.WorkRequest {
		w := workRequest(kind)
		w.Prompt = prompt
		w.Context.Reason = "conflict"

		return w
	}
	for name, tc := range map[string]struct {
		body   string
		w      api.WorkRequest
		skip   Skip
		prompt string
	}{
		"an unmapped kind runs the prompt": {"appPrompts: true\n" + workFile, withPrompt("review", "Review {cardNumber} in {project} for {reason}."), Run, "Review 87 in loupe for conflict."},
		"a local entry wins":               {"appPrompts: true\n" + workFile, withPrompt("implement", "Ignore {cardNumber}."), Run, "Implement 87 " + cardID + " loupe " + projectID + " implement impl.rule " + workRequestID + "."},
		"no opt-in":                        {workFile, withPrompt("review", "Review {cardNumber}."), NoRule, ""},
		"an opt-out":                       {"appPrompts: false\n" + workFile, withPrompt("review", "Review {cardNumber}."), NoRule, ""},
		"no prompt":                        {"appPrompts: true\n" + workFile, withPrompt("review", ""), NoRule, ""},
		"a blank prompt":                   {"appPrompts: true\n" + workFile, withPrompt("review", " \n\t"), NoRule, ""},
		"an unknown placeholder":           {"appPrompts: true\n" + workFile, withPrompt("review", "Review {cardNumber} on {branch}."), NoRule, ""},
	} {
		t.Run(name, func(t *testing.T) {
			text, dir := file(t, strings.Replace(tc.body, "harness: claude-code\n", "harness: claude-code\n    model: opus\n", 1))
			s, err := Parse([]byte(text), Defaults{PermissionMode: "acceptEdits", Model: "sonnet"})
			if err != nil {
				t.Fatal(err)
			}
			checkLoupe(t, s)

			m := s.MatchWork(tc.w)
			if m.Skip != tc.skip {
				t.Fatalf("match = %+v", m)
			}
			if tc.skip != Run {
				return
			}
			if want := directive.Render(tc.prompt, nil); m.Prompt != want {
				t.Fatalf("prompt = %q, want %q", m.Prompt, want)
			}
			if tc.w.Kind != "review" {
				return
			}
			if m.Rule != "work:review" || m.Action != "" || m.Project != "loupe" || m.Dir != dir || m.Pool != DefaultPool ||
				m.PermissionMode != "acceptEdits" || m.Model != "opus" || m.Schema == "" || m.Experiment != nil || m.Before != nil || m.Command != nil {
				t.Fatalf("match = %+v", m)
			}
		})
	}
}

// Dead work runs no app prompt either.
func TestDeadWorkRunsNoAppPrompt(t *testing.T) {
	s := checked(t, "appPrompts: true\n"+workFile)
	s.KillProjectWork("loupe", api.ReasonProjectGone)
	w := workRequest("review")
	w.Prompt = "Review {cardNumber}."

	if m := s.MatchWork(w); m.Skip != NoRule {
		t.Fatalf("match = %+v", m)
	}
}

// A person's resume names the kind and carries no prompt, and still finds the
// worker settings of an app prompt.
func TestMatchKindContinuesTheRunOfAnAppPrompt(t *testing.T) {
	if m := checked(t, "appPrompts: true\n"+workFile).MatchKind(workRequest("review")); m.Skip != Run || m.Action != "" || m.Pool != DefaultPool || m.Rule != "work:review" {
		t.Fatalf("match = %+v", m)
	}
	if m := checked(t, workFile).MatchKind(workRequest("review")); m.Skip != NoRule {
		t.Fatalf("no opt-in: match = %+v", m)
	}
}
