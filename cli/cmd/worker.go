package cmd

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"os"
	"os/exec"
	"slices"
	"strings"
	"sync/atomic"
	"time"

	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// waitDelay bounds the wait after the context kills claude. A grandchild that
// still holds the output pipe would otherwise block Wait for good.
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

// runWorker runs `claude -p --session-id <id> -- <prompt>`, or `--resume <id>`,
// in the spec's dir and waits for it.
func runWorker(ctx context.Context, spec workerSpec, onStart func()) workerResult {
	args := workerArgs(spec)

	// Each stream has its own writer, so os/exec drains each on its own
	// goroutine and no writer is shared.
	stdout := &capWriter{limit: maxStdout}
	stderr := &capWriter{limit: maxOutput}

	cmd := exec.CommandContext(ctx, "claude", args...)
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

	if err := cmd.Start(); err != nil {
		return workerResult{output: stderr.text(), err: err}
	}
	if onStart != nil {
		onStart()
	}
	err := cmd.Wait()
	res := decodeWorkerOutput(stdout.buf.Bytes(), stdout.dropped, stderr.text())
	res.killed = err != nil && cancelled.Load()

	var exitErr *exec.ExitError
	switch {
	case err == nil:
	case errors.As(err, &exitErr):
		res.exitCode = exitErr.ExitCode()
	default:
		res.err = err
	}

	return res
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
// first non-empty of the summary, claude's result text and stderr.
func decodeWorkerOutput(stdout []byte, overflow bool, stderr string) workerResult {
	var doc struct {
		StructuredOutput json.RawMessage `json:"structured_output"`
		Result           string          `json:"result"`
		IsError          bool            `json:"is_error"`
	}
	var res workerResult
	var summary string
	if !overflow && json.Unmarshal(stdout, &doc) == nil {
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
	for _, text := range []string{summary, doc.Result, stderr} {
		if text != "" {
			_, _ = out.Write([]byte(text))

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
