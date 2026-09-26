package cmd

import (
	"bytes"
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
	"github.com/ubermuda/loupe/cli/internal/rules"
	"github.com/ubermuda/loupe/cli/internal/transcript"
)

// waitDelay bounds the wait after the context kills the worker's process
// group.
const waitDelay = 5 * time.Second

// maxOutput caps the worker output a failure report carries.
const maxOutput = 4000

// maxStdout bounds the JSON document the bridge reads from claude's stdout.
const maxStdout = 1 << 20

// ceilingEnv lifts claude -p's background wait ceiling, which otherwise ends a
// worker mid-task and exits 0.
const ceilingEnv = "CLAUDE_CODE_PRINT_BG_WAIT_CEILING_MS"

// workerResult is one finished worker. err is set when the process never ran,
// which is a different fault from a process that ran and failed. hasResult
// says whether stdout held a valid structured result, and killed that the
// bridge's context ended the process. fields holds the result's keys other
// than status and summary.
type workerResult struct {
	exitCode  int
	output    string
	hasResult bool
	status    string
	fields    map[string]any
	killed    bool
	err       error
	// dir is the run directory, which the router removes once it reported.
	dir string
	// reported is the modelUsage claude printed, which counts the whole session.
	// usage is what this process spent, and nil when unknown.
	reported transcript.Usage
	usage    *api.Usage
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
	schema         string
	sessionID      string
	resume         bool
	prompt         string
	// runID names the run directory. rule and key only go into run.json.
	runID string
	rule  string
	key   string
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
}

func defaultWorkerOps() workerOps {
	return workerOps{run: runWorker, adopt: adoptWorker, sessionID: config.NewUUID}
}

// workerArgs builds claude's argv. The prompt is an argv element, so no shell
// reads it. It follows --, because claude reads a prompt that starts with - as
// an option.
func workerArgs(spec workerSpec) []string {
	args := make([]string, 0, 13)
	if spec.permissionMode != "" {
		args = append(args, "--permission-mode", spec.permissionMode)
	}
	if spec.model != "" {
		args = append(args, "--model", spec.model)
	}
	args = append(args, "--output-format", "json")
	if spec.schema != "" {
		args = append(args, "--json-schema", spec.schema)
	}

	session := "--session-id"
	if spec.resume {
		session = "--resume"
	}

	return append(args, "-p", session, spec.sessionID, "--", spec.prompt)
}

// workerEnv is claude's environment. A ceiling the operator set, empty
// included, stays as set.
func workerEnv(environ []string) []string {
	for _, e := range environ {
		if strings.HasPrefix(e, ceilingEnv+"=") {
			return environ
		}
	}

	return append(slices.Clip(environ), ceilingEnv+"=0")
}

// workerShell runs claude on the argv after $0 and records claude's exit code
// in "$0.exit". The prompt stays one argv element, so no shell parses it.
const workerShell = `claude "$@"; echo $? > "$0.exit"`

// runRecord is run.json, what the bridge knows about a worker it started.
type runRecord struct {
	PID       int       `json:"pid"`
	StartedAt time.Time `json:"startedAt"`
	// StartTime is the OS's start time of PID, so an adopter tells a reused pid
	// apart. It is empty when the OS did not say.
	StartTime      string `json:"startTime,omitempty"`
	RunID          string `json:"runId"`
	Rule           string `json:"rule,omitempty"`
	Key            string `json:"key,omitempty"`
	Dir            string `json:"dir"`
	PermissionMode string `json:"permissionMode,omitempty"`
	Model          string `json:"model,omitempty"`
	SessionID      string `json:"sessionId"`
	Resume         bool   `json:"resume,omitempty"`
	Prompt         string `json:"prompt"`
	// Baseline is the session's usage before a resume started. A resume with
	// no baseline could not read it. BaselineIncomplete says messages came after
	// the cost-state line the baseline holds.
	Baseline           *transcript.Usage `json:"baseline,omitempty"`
	BaselineIncomplete bool              `json:"baselineIncomplete,omitempty"`
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
	// The shell always starts, so a claude it cannot run must fail here to read
	// as a run that never started.
	if _, err := exec.LookPath("claude"); err != nil {
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
	cmd := exec.CommandContext(ctx, "/bin/sh", append([]string{"-c", workerShell, status}, workerArgs(spec)...)...)
	cmd.Dir = spec.dir
	cmd.Env = workerEnv(os.Environ())
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
	var incomplete bool
	if spec.resume {
		baseline, incomplete = sessionBaseline(spec.sessionID)
	}
	if err := cmd.Start(); err != nil {
		return nil, dir, nil, err
	}
	rec := runRecord{
		PID: cmd.Process.Pid, StartedAt: time.Now(), StartTime: processStart(cmd.Process.Pid),
		RunID: spec.runID, Rule: spec.rule, Key: spec.key,
		Dir: spec.dir, PermissionMode: spec.permissionMode, Model: spec.model,
		SessionID: spec.sessionID, Resume: spec.resume, Prompt: spec.prompt,
		Baseline: baseline, BaselineIncomplete: incomplete,
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
	stdout, stdoutErr := readCapped(filepath.Join(dir, "stdout"), maxStdout)
	stderr, stderrErr := readCapped(filepath.Join(dir, "stderr"), maxOutput)
	res := decodeWorkerOutput(stdout.buf.Bytes(), stdout.dropped, stderr.text())
	if readErr := errors.Join(stdoutErr, stderrErr); readErr != nil {
		res.output = strings.TrimLeft(res.output+"\n"+readErr.Error(), "\n")
	}
	res.killed, res.dir = killed, dir
	if rec, err := readRunRecord(dir); err == nil {
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

// readExitStatus reads the exit code the worker shell recorded for claude.
func readExitStatus(path string) (int, error) {
	b, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return 0, errors.New("the worker shell ended before it recorded one")
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

// decodeWorkerOutput reads the one JSON document claude --output-format json
// prints. A cut-off or undecodable stdout holds no result. The output is the
// first non-empty of the summary, claude's result text, stderr and the raw
// stdout that did not decode.
func decodeWorkerOutput(stdout []byte, overflow bool, stderr string) workerResult {
	var doc struct {
		StructuredOutput json.RawMessage `json:"structured_output"`
		Result           string          `json:"result"`
		IsError          bool            `json:"is_error"`
		ModelUsage       json.RawMessage `json:"modelUsage"`
	}
	var res workerResult
	var summary, raw string
	decoded := !overflow && json.Unmarshal(stdout, &doc) == nil
	if !decoded {
		raw = string(stdout)
	}
	if decoded {
		if len(doc.ModelUsage) > 0 && string(doc.ModelUsage) != "null" {
			res.reported, _ = transcript.DecodeModelUsage(doc.ModelUsage)
		}
		var fields map[string]any
		_ = json.Unmarshal(doc.StructuredOutput, &fields)
		status, _ := fields["status"].(string)
		s, isString := fields["summary"].(string)
		if isString && slices.Contains(rules.ResultStatuses, status) {
			delete(fields, "status")
			delete(fields, "summary")
			res.hasResult, res.status, res.fields, summary = true, status, fields, s
		}
	}

	out := &capWriter{limit: maxOutput}
	for _, text := range []string{summary, doc.Result, stderr, raw} {
		if text != "" {
			_, _ = out.Write([]byte(text))
			out.dropped = out.dropped || (text == raw && overflow)

			break
		}
	}
	res.output = out.text()

	return res
}

func (w *capWriter) text() string {
	s := strings.TrimRight(w.buf.String(), "\n")
	if w.dropped {
		s += "… (truncated)"
	}

	return s
}
