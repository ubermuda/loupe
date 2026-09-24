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

	"github.com/ubermuda/loupe/cli/internal/config"
)

// waitDelay bounds the wait after the context kills the worker's process
// group.
const waitDelay = 5 * time.Second

// maxOutput caps the worker output a failure report carries.
const maxOutput = 4000

// resultPrefix starts the line a worker ends its final reply with.
const resultPrefix = "STAGE RESULT:"

// ceilingEnv lifts claude -p's background wait ceiling, which otherwise ends a
// worker mid-task and exits 0.
const ceilingEnv = "CLAUDE_CODE_PRINT_BG_WAIT_CEILING_MS"

// workerResult is one finished worker. err is set when the process never ran,
// which is a different fault from a process that ran and failed. hasResult
// says whether the output held a result line, and killed that the bridge's
// context ended the process.
type workerResult struct {
	exitCode  int
	output    string
	hasResult bool
	killed    bool
	err       error
}

// workerSpec is one claude process to run. An empty permissionMode or model
// passes no flag. sessionID is the id claude runs the session under, a new one
// or, with resume, the one it continues.
type workerSpec struct {
	dir            string
	permissionMode string
	model          string
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
	run       func(ctx context.Context, spec workerSpec, onStart func()) workerResult
	sessionID func() string
}

func defaultWorkerOps() workerOps {
	return workerOps{run: runWorker, sessionID: config.NewUUID}
}

// workerArgs builds claude's argv. The prompt is an argv element, so no shell
// reads it. It follows --, because claude reads a prompt that starts with - as
// an option.
func workerArgs(spec workerSpec) []string {
	args := make([]string, 0, 9)
	if spec.permissionMode != "" {
		args = append(args, "--permission-mode", spec.permissionMode)
	}
	if spec.model != "" {
		args = append(args, "--model", spec.model)
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
	PID            int       `json:"pid"`
	StartedAt      time.Time `json:"startedAt"`
	RunID          string    `json:"runId"`
	Rule           string    `json:"rule,omitempty"`
	Key            string    `json:"key,omitempty"`
	Dir            string    `json:"dir"`
	PermissionMode string    `json:"permissionMode,omitempty"`
	Model          string    `json:"model,omitempty"`
	SessionID      string    `json:"sessionId"`
	Resume         bool      `json:"resume,omitempty"`
	Prompt         string    `json:"prompt"`
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
// code to files in its run directory, so it holds no pipe to the bridge.
func runWorker(ctx context.Context, spec workerSpec, onStart func()) workerResult {
	// The shell always starts, so a claude it cannot run must fail here to read
	// as a run that never started.
	if _, err := exec.LookPath("claude"); err != nil {
		return workerResult{err: err}
	}
	if spec.runID == "" {
		spec.runID = config.NewUUID()
	}
	runs, err := config.RunsDir()
	if err != nil {
		return workerResult{err: err}
	}
	dir := filepath.Join(runs, spec.runID)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return workerResult{err: fmt.Errorf("create run directory: %w", err)}
	}
	defer os.RemoveAll(dir)

	outPath := filepath.Join(dir, "output")
	out, err := os.OpenFile(outPath, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return workerResult{err: fmt.Errorf("create worker output: %w", err)}
	}
	defer out.Close()

	status := filepath.Join(dir, "status")
	cmd := exec.CommandContext(ctx, "/bin/sh", append([]string{"-c", workerShell, status}, workerArgs(spec)...)...)
	cmd.Dir = spec.dir
	cmd.Env = workerEnv(os.Environ())
	cmd.Stdout, cmd.Stderr = out, out
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

	if err := cmd.Start(); err != nil {
		return workerResult{err: err}
	}
	rec := runRecord{
		PID: cmd.Process.Pid, StartedAt: time.Now(), RunID: spec.runID, Rule: spec.rule, Key: spec.key,
		Dir: spec.dir, PermissionMode: spec.permissionMode, Model: spec.model,
		SessionID: spec.sessionID, Resume: spec.resume, Prompt: spec.prompt,
	}
	// A worker with no record cannot outlive this bridge, so it does not run.
	if err := writeRunRecord(dir, rec); err != nil {
		_ = cmd.Cancel()
		_ = cmd.Wait()

		return workerResult{err: err}
	}
	if onStart != nil {
		onStart()
	}
	waitErr := cmd.Wait()

	res := workerResult{killed: waitErr != nil && cancelled.Load()}
	res.output, res.hasResult = readWorkerOutput(outPath)

	var exitErr *exec.ExitError
	if waitErr != nil && !errors.As(waitErr, &exitErr) {
		res.err = waitErr

		return res
	}

	// A kill leaves no status, and -1 is the code os/exec gives a signalled
	// process.
	code, err := readExitStatus(status + ".exit")
	res.exitCode = code
	if err != nil {
		res.exitCode = -1
	}
	if err != nil && !res.killed {
		res.output = strings.TrimLeft(res.output+"\n(no exit status: "+err.Error()+")", "\n")
	}

	return res
}

// readWorkerOutput gives the first maxOutput bytes of the worker's output, and
// whether any line of the whole output is a result line.
func readWorkerOutput(path string) (string, bool) {
	captured := &capWriter{limit: maxOutput}
	scanner := &resultScanner{}

	f, err := os.Open(path)
	if err != nil {
		return "read worker output: " + err.Error(), false
	}
	defer f.Close()

	if _, err := io.Copy(io.MultiWriter(captured, scanner), f); err != nil {
		return captured.text() + "\nread worker output: " + err.Error(), scanner.matched
	}

	return captured.text(), scanner.matched
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

// resultScanner records whether any line of the stream starts with
// resultPrefix. It holds no bytes, so a prefix split across writes still
// matches.
type resultScanner struct {
	midLine bool
	seen    int
	matched bool
}

func (s *resultScanner) Write(p []byte) (int, error) {
	for _, b := range p {
		if s.matched {
			break
		}
		switch {
		case b == '\n':
			s.midLine, s.seen = false, 0
		case s.midLine:
		case b == resultPrefix[s.seen]:
			s.seen++
			s.matched = s.seen == len(resultPrefix)
		default:
			s.midLine, s.seen = true, 0
		}
	}

	return len(p), nil
}

func (w *capWriter) text() string {
	s := strings.TrimRight(w.buf.String(), "\n")
	if w.dropped {
		s += "… (truncated)"
	}

	return s
}
