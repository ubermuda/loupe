package cmd

import (
	"bytes"
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"sync/atomic"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/envfile"
	harn "github.com/ubermuda/loupe/cli/internal/harness"
	"github.com/ubermuda/loupe/cli/internal/rules"
	"github.com/ubermuda/loupe/cli/internal/stream"
	"github.com/ubermuda/loupe/cli/internal/transcript"
)

// waitDelay bounds the wait after the context kills the worker's process
// group.
const waitDelay = 5 * time.Second

// maxOutput caps the worker output a failure report carries.
const maxOutput = 4000

// maxStdout bounds the stdout the bridge reads from a before command.
const maxStdout = 1 << 20

// sessionEnv gives `loupe mcp` the run id of a worker, so the server can name
// the run that moves a card.
const sessionEnv = "LOUPE_SESSION_ID"

// workerResult is one finished worker. err is set when the process never ran,
// which is a different fault from a process that ran and failed. hasResult
// says whether stdout held a valid structured result, and killed that the
// bridge's context ended the process. reason is the result's reason when it is
// a string. fields holds the result's keys other than status, summary and reason.
type workerResult struct {
	exitCode  int
	output    string
	hasResult bool
	status    string
	reason    string
	fields    map[string]any
	killed    bool
	err       error
	// dir is the run directory, which the router removes once it reported.
	dir string
	// before says the rule's before command failed, and resumeGone that the
	// folder of a resume is gone. Either way claude never ran and the run
	// never resumes.
	before     bool
	resumeGone bool
	// command says the process was the command of a command rule, which
	// succeeds on exit code 0 and never resumes.
	command bool
	// timedOut says the command ran past its timeout, so the bridge killed it.
	timedOut bool
	// reported is the modelUsage claude printed, which counts the whole session.
	// usage is what this process spent, and nil when unknown.
	reported transcript.Usage
	usage    *api.Usage
	// streamed says the harness read the calls of the run, and calls and
	// timing are what the run held.
	streamed bool
	calls    []stream.Call
	timing   stream.Timing
	// peakContextTokens is the largest input context of the main session,
	// and nil when unknown.
	peakContextTokens *int64
}

// workerProc is a started worker: its shell's pid, which leads its process
// group, and its run directory.
type workerProc struct {
	pid int
	dir string
}

// workerSpec is one claude process to run. An empty permissionMode, model or
// schema passes no flag. sessionID is the id claude runs the session under, a
// new one or, with resume, the one it continues.
type workerSpec struct {
	dir            string
	permissionMode string
	model          string
	effort         string
	schema         string
	sessionID      string
	resume         bool
	prompt         string
	// runID names the run directory. rule and key only go into run.json.
	runID string
	rule  string
	key   string
	// before is the command that runs ahead of claude and prints its dir, or
	// nil.
	before *rules.Before
	// command is the command of a command rule, which runs in place of
	// the harness, or nil.
	command *rules.Command
	// account names the account of the run. harnessName and configDir pick
	// its harness, and envFiles are read into env at the start of the run.
	account     string
	harnessName string
	configDir   string
	profile     string
	envFiles    []string
	env         []string
	// runDir is the run directory, which startWorker sets.
	runDir string
	// agent is the GitHub user that the worker pushes as, or nil.
	agent *config.AgentAccount
}

// workerOps is the process surface the router drives. Tests replace run so the
// routing and the in-flight bookkeeping need no claude binary, and sessionID so
// a test knows the id each worker gets.
type workerOps struct {
	// run calls onStart once the process exists. A process that never starts
	// never calls it, so the run never reads as running.
	run func(ctx context.Context, spec workerSpec, onStart func(workerProc)) workerResult
	// adopt waits for a worker a former image started in a run directory. A
	// nil one is adoptWorker.
	adopt     func(ctx context.Context, dir string) workerResult
	sessionID func() string
	// before runs a rule's before command, and calls onStart once it exists.
	// adoptBefore waits for one a former image started. A nil one is
	// runBefore or adoptBeforeProc.
	before      func(ctx context.Context, spec procSpec, onStart func(workerProc)) procResult
	adoptBefore func(ctx context.Context, dir string) procResult
	// command runs the command of a command rule, and adoptCommand waits for
	// one a former image started. A nil one is runRuleCommand or
	// adoptRuleCommand.
	command      func(ctx context.Context, spec procSpec, onStart func(workerProc)) procResult
	adoptCommand func(ctx context.Context, dir string) procResult
}

func defaultWorkerOps() workerOps {
	return workerOps{
		run: runWorker, adopt: adoptWorker, sessionID: config.NewUUID, before: runBefore, adoptBefore: adoptBeforeProc,
		command: runRuleCommand, adoptCommand: adoptRuleCommand,
	}
}

// workerEnv is the environment the bridge gives a worker before its harness
// adds to it. The session id replaces an inherited one. An agent account
// replaces the inherited GitHub token and git identity.
func workerEnv(environ []string, sessionID string, agent *config.AgentAccount) []string {
	env := make([]string, 0, len(environ)+12)
	gitConfigs := 0
	for _, e := range environ {
		if strings.HasPrefix(e, sessionEnv+"=") {
			continue
		}
		if agent != nil {
			if n, ok := strings.CutPrefix(e, "GIT_CONFIG_COUNT="); ok {
				gitConfigs, _ = strconv.Atoi(n)
				gitConfigs = max(gitConfigs, 0)

				continue
			}
			if slices.ContainsFunc(agentEnvOverrides, func(name string) bool { return strings.HasPrefix(e, name+"=") }) {
				continue
			}
		}
		env = append(env, e)
	}
	env = append(env, sessionEnv+"="+sessionID)
	if agent == nil {
		return env
	}

	email := strconv.FormatInt(agent.ID, 10) + "+" + agent.Login + "@users.noreply.github.com"
	// The empty helper drops every helper the owner's git config names for
	// github.com, so only the agent's token answers.
	helper := `!f() { test "$1" = get && echo username=` + agent.Login + ` && echo "password=$GH_TOKEN"; }; f`
	n := strconv.Itoa

	return append(env,
		"GH_TOKEN="+agent.Token,
		"GIT_AUTHOR_NAME="+agent.Login, "GIT_COMMITTER_NAME="+agent.Login,
		"GIT_AUTHOR_EMAIL="+email, "GIT_COMMITTER_EMAIL="+email,
		"GIT_CONFIG_KEY_"+n(gitConfigs)+"="+githubHelperKey, "GIT_CONFIG_VALUE_"+n(gitConfigs)+"=",
		"GIT_CONFIG_KEY_"+n(gitConfigs+1)+"="+githubHelperKey, "GIT_CONFIG_VALUE_"+n(gitConfigs+1)+"="+helper,
		// An SSH remote would push with the machine's key, so it goes through HTTPS.
		"GIT_CONFIG_KEY_"+n(gitConfigs+2)+"="+githubInsteadOfKey, "GIT_CONFIG_VALUE_"+n(gitConfigs+2)+"=git@github.com:",
		"GIT_CONFIG_KEY_"+n(gitConfigs+3)+"="+githubInsteadOfKey, "GIT_CONFIG_VALUE_"+n(gitConfigs+3)+"=ssh://git@github.com/",
		"GIT_CONFIG_COUNT="+n(gitConfigs+4),
	)
}

// githubHelperKey is the git config key of the credential helpers for
// github.com.
const githubHelperKey = "credential.https://github.com.helper"

// githubInsteadOfKey rewrites a GitHub SSH remote to its HTTPS form.
const githubInsteadOfKey = "url.https://github.com/.insteadOf"

// agentEnvOverrides are the inherited variables an agent account replaces.
var agentEnvOverrides = []string{
	"GH_TOKEN", "GITHUB_TOKEN",
	"GIT_AUTHOR_NAME", "GIT_AUTHOR_EMAIL", "GIT_COMMITTER_NAME", "GIT_COMMITTER_EMAIL",
}

// workerShell runs the program and argv after $0 and records the program's
// exit code in "$0.exit". The prompt stays one argv element, so no shell
// parses it.
const workerShell = `"$@"; echo $? > "$0.exit"`

// runRecord is run.json, what the bridge knows about a worker it started.
type runRecord struct {
	PID       int       `json:"pid"`
	StartedAt time.Time `json:"startedAt"`
	// LaunchedAt precedes the start, so a transcript count from it misses no
	// entry the process wrote at once.
	LaunchedAt time.Time `json:"launchedAt,omitzero"`
	// StartTime is the OS's start time of PID, so an adopter tells a reused pid
	// apart. It is empty when the OS did not say.
	StartTime      string `json:"startTime,omitempty"`
	RunID          string `json:"runId"`
	Rule           string `json:"rule,omitempty"`
	Key            string `json:"key,omitempty"`
	Dir            string `json:"dir"`
	PermissionMode string `json:"permissionMode,omitempty"`
	Model          string `json:"model,omitempty"`
	Effort         string `json:"effort,omitempty"`
	SessionID      string `json:"sessionId"`
	Resume         bool   `json:"resume,omitempty"`
	Prompt         string `json:"prompt"`
	// Harness names the harness of the worker, and "" is the default one.
	// Account is "" in the record of an older image.
	Harness   string `json:"harness,omitempty"`
	Account   string `json:"account,omitempty"`
	ConfigDir string `json:"configDir,omitempty"`
	Profile   string `json:"profile,omitempty"`
	// Baseline is the session's usage before a resume started. A resume with
	// no baseline could not read it.
	Baseline *transcript.Usage `json:"baseline,omitempty"`
}

func writeRunRecord(dir string, rec runRecord) error {
	b, err := json.Marshal(rec)
	if err != nil {
		return err
	}
	if err := os.WriteFile(filepath.Join(dir, "run.json"), b, 0o600); err != nil {
		return fmt.Errorf("write run record: %w", err)
	}

	return nil
}

func readRunRecord(dir string) (runRecord, error) {
	var rec runRecord
	b, err := os.ReadFile(filepath.Join(dir, "run.json"))
	if err != nil {
		return rec, fmt.Errorf("read run record: %w", err)
	}
	if err := json.Unmarshal(b, &rec); err != nil {
		return rec, fmt.Errorf("parse run record: %w", err)
	}

	return rec, nil
}

// runWorker runs `claude -p --session-id <id> -- <prompt>`, or `--resume <id>`,
// in the spec's dir and waits for it. The worker writes its output and its exit
// code to files in its run directory, so it holds no pipe to the bridge. The
// directory stays for the caller to remove once it reported the run.
func runWorker(ctx context.Context, spec workerSpec, onStart func(workerProc)) workerResult {
	cmd, dir, cancelled, err := startWorker(ctx, spec)
	if err != nil {
		return workerResult{err: err, dir: dir}
	}
	if onStart != nil {
		onStart(workerProc{pid: cmd.Process.Pid, dir: dir})
	}
	waitErr := cmd.Wait()
	killed := waitErr != nil && cancelled.Load()
	if exitErr := (*exec.ExitError)(nil); errors.As(waitErr, &exitErr) {
		waitErr = nil
	}

	return workerOutcome(dir, killed, waitErr)
}

// adoptWorker waits for the worker another image of the bridge started in dir,
// and reads how it ended as runWorker does. A run with no readable record ran
// and cannot be followed, so it reads as a failure that says why.
func adoptWorker(ctx context.Context, dir string) workerResult {
	if dir == "" {
		return workerResult{exitCode: -1, output: "the bridge handed this run over with no run directory", dir: dir}
	}
	rec, err := readRunRecord(dir)
	if err == nil && rec.PID <= 0 {
		err = errors.New("the run record names no process")
	}
	if err != nil {
		return workerResult{exitCode: -1, output: "the bridge lost this run across a handover: " + err.Error(), dir: dir}
	}

	return workerOutcome(dir, awaitProcess(ctx, rec.PID, rec.StartTime), nil)
}

// startWorker starts the worker shell and writes its run record. It returns the
// run directory whenever it made one, so a failed start leaves nothing behind.
func startWorker(ctx context.Context, spec workerSpec) (*exec.Cmd, string, *atomic.Bool, error) {
	// The shell always starts, so a program it cannot run must fail here to
	// read as a run that never started.
	h := spec.adapter()
	if _, err := envfile.LookPath(h.Program(), envfile.Overlay(os.Environ(), spec.env), spec.dir); err != nil {
		return nil, "", nil, err
	}
	if spec.runID == "" {
		spec.runID = config.NewUUID()
	}
	runs, err := config.RunsDir()
	if err != nil {
		return nil, "", nil, err
	}
	dir := filepath.Join(runs, spec.runID)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, "", nil, fmt.Errorf("create run directory: %w", err)
	}
	spec.runDir = dir

	// Each stream has its own file, so stdout holds claude's JSON alone. The
	// shell holds its own copies once it starts.
	stdout, err := createOutput(dir, "stdout")
	if err != nil {
		return nil, dir, nil, err
	}
	defer stdout.Close()
	stderr, err := createOutput(dir, "stderr")
	if err != nil {
		return nil, dir, nil, err
	}
	defer stderr.Close()

	status := filepath.Join(dir, "status")
	command := spec.harnessCommand(workerEnv(envfile.Overlay(os.Environ(), spec.env), spec.sessionID, spec.agent))
	for path, content := range command.Files {
		if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
			return nil, dir, nil, fmt.Errorf("write worker file: %w", err)
		}
	}
	cmd := exec.CommandContext(ctx, "/bin/sh", append([]string{"-c", workerShell, status, h.Program()}, command.Args...)...)
	cmd.Dir = spec.dir
	cmd.Env = command.Env
	cmd.Stdout, cmd.Stderr = stdout, stderr
	cmd.WaitDelay = waitDelay
	setProcessGroup(cmd)

	// The context can end while claude exits on its own, so only a kill that
	// reached a live process marks the worker as killed.
	var cancelled atomic.Bool
	kill := cmd.Cancel
	cmd.Cancel = func() error {
		err := kill()
		cancelled.Store(err == nil)

		return err
	}

	// claude adds to the session's totals as it runs, so the baseline is read
	// before it starts.
	var baseline *transcript.Usage
	if spec.resume {
		baseline = sessionBaseline(h, spec.sessionID)
	}
	launched := time.Now()
	if err := cmd.Start(); err != nil {
		return nil, dir, nil, err
	}
	rec := runRecord{
		PID: cmd.Process.Pid, StartedAt: time.Now(), LaunchedAt: launched, StartTime: processStart(cmd.Process.Pid),
		RunID: spec.runID, Rule: spec.rule, Key: spec.key,
		Dir: spec.dir, PermissionMode: spec.permissionMode, Model: spec.model, Effort: spec.effort,
		SessionID: spec.sessionID, Resume: spec.resume, Prompt: spec.prompt,
		Harness: h.Name(), Account: spec.account, ConfigDir: spec.configDir, Profile: spec.profile, Baseline: baseline,
	}
	// A worker with no record cannot outlive this bridge, so it does not run.
	if err := writeRunRecord(dir, rec); err != nil {
		_ = cmd.Cancel()
		_ = cmd.Wait()

		return nil, dir, nil, err
	}

	return cmd, dir, &cancelled, nil
}

func createOutput(dir, name string) (*os.File, error) {
	f, err := os.OpenFile(filepath.Join(dir, name), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return nil, fmt.Errorf("create worker %s: %w", name, err)
	}

	return f, nil
}

// workerOutcome reads how the worker in dir ended. waitErr is a fault of the
// wait itself, and not the exit of a process that ran.
func workerOutcome(dir string, killed bool, waitErr error) workerResult {
	head, headErr := readCapped(filepath.Join(dir, "stdout"), maxOutput)
	stderr, stderrErr := readCapped(filepath.Join(dir, "stderr"), maxOutput)
	rec, recErr := readRunRecord(dir)
	since := rec.LaunchedAt
	if since.IsZero() {
		since = rec.StartedAt
	}
	doc := recordHarness(rec).ReadRun(dir, harn.RunInfo{SessionID: rec.SessionID, Model: rec.Model, Since: since})
	res := decodeWorkerOutput(doc, head.buf.Bytes(), head.dropped, stderr.text())
	res.streamed, res.calls, res.timing, res.peakContextTokens = doc.CallsRead, doc.Calls, doc.Timing, doc.PeakContextTokens
	if readErr := errors.Join(cmp.Or(doc.ReadErr, headErr), stderrErr); readErr != nil {
		res.output = strings.TrimLeft(res.output+"\n"+readErr.Error(), "\n")
	}
	res.killed, res.dir = killed, dir
	if recErr == nil {
		res.usage = workerUsage(rec, res.reported)
	}
	if waitErr != nil {
		res.err = waitErr

		return res
	}

	// A kill leaves no status, and -1 is the code os/exec gives a signalled
	// process.
	code, err := readExitStatus(filepath.Join(dir, "status.exit"))
	res.exitCode = code
	if err != nil {
		res.exitCode = -1
	}
	if err != nil && !res.killed {
		res.output = strings.TrimLeft(res.output+"\n(no exit status: "+err.Error()+")", "\n")
	}

	return res
}

// readCapped reads the file at path through a capWriter of limit bytes.
func readCapped(path string, limit int) (*capWriter, error) {
	w := &capWriter{limit: limit}
	f, err := os.Open(path)
	if err != nil {
		return w, fmt.Errorf("read worker output: %w", err)
	}
	defer f.Close()
	if _, err := io.Copy(w, f); err != nil {
		return w, fmt.Errorf("read worker output: %w", err)
	}

	return w, nil
}

// errNoExitFile says the wrapper shell ended before it wrote its exit file.
var errNoExitFile = errors.New("the worker shell ended before it recorded one")

// readExitStatus reads the exit code the worker shell recorded for claude.
func readExitStatus(path string) (int, error) {
	b, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return 0, errNoExitFile
	}
	if err != nil {
		return 0, err
	}
	code, err := strconv.Atoi(strings.TrimSpace(string(b)))
	if err != nil {
		return 0, fmt.Errorf("unreadable status %q", bytes.TrimSpace(b))
	}

	return code, nil
}

// capWriter keeps the first limit bytes written to it and counts the rest as
// dropped. It never reports a short write, so the process keeps running after
// the cap is reached.
type capWriter struct {
	limit   int
	buf     bytes.Buffer
	dropped bool
}

func (w *capWriter) Write(p []byte) (int, error) {
	room := w.limit - w.buf.Len()
	switch {
	case room <= 0:
		w.dropped = w.dropped || len(p) > 0
	case len(p) > room:
		w.buf.Write(p[:room])
		w.dropped = true
	default:
		w.buf.Write(p)
	}

	return len(p), nil
}

// decodeWorkerOutput reads the result the harness decoded from the worker's
// output. raw is the start of stdout, and cut says stdout goes on past it. The
// output is the first non-empty of the summary, the harness's result text,
// stderr and the plain lines of raw when no result line decodes.
func decodeWorkerOutput(doc harn.Output, raw []byte, cut bool, stderr string) workerResult {
	var res workerResult
	var summary, rawText string
	if !doc.Decoded {
		rawText = plainLines(raw)
	}
	if doc.Decoded {
		res.reported = doc.Usage
		var fields map[string]any
		_ = json.Unmarshal(doc.StructuredOutput, &fields)
		status, _ := fields["status"].(string)
		s, isString := fields["summary"].(string)
		if isString && slices.Contains(rules.ResultStatuses, status) {
			res.reason, _ = fields["reason"].(string)
			delete(fields, "status")
			delete(fields, "summary")
			delete(fields, "reason")
			res.hasResult, res.status, res.fields, summary = true, status, fields, s
		}
	}

	out := &capWriter{limit: maxOutput}
	for _, text := range []string{summary, doc.Result, stderr, rawText} {
		if text != "" {
			_, _ = out.Write([]byte(text))
			out.dropped = out.dropped || (text == rawText && cut)

			break
		}
	}
	res.output = out.text()

	return res
}

// plainLines drops each line of raw that starts with "{". A stream line can
// hold tool input, and a cut last line no longer parses, so the shape decides.
func plainLines(raw []byte) string {
	var kept []string
	for line := range strings.SplitSeq(string(raw), "\n") {
		if !strings.HasPrefix(strings.TrimSpace(line), "{") {
			kept = append(kept, line)
		}
	}

	return strings.TrimSpace(strings.Join(kept, "\n"))
}

func (w *capWriter) text() string {
	s := strings.TrimRight(w.buf.String(), "\n")
	if w.dropped {
		s += "… (truncated)"
	}

	return s
}
