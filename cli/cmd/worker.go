package cmd

import (
	"bytes"
	"context"
	"errors"
	"os/exec"
	"strings"
	"time"

	"github.com/ubermuda/loupe/cli/internal/config"
)

// waitDelay bounds the wait after the context kills claude. A grandchild that
// still holds the output pipe would otherwise block Wait for good.
const waitDelay = 5 * time.Second

// maxOutput caps the worker output a failure report carries.
const maxOutput = 4000

// workerResult is one finished worker. err is set when the process never ran,
// which is a different fault from a process that ran and failed.
type workerResult struct {
	exitCode int
	output   string
	err      error
}

// workerSpec is one claude process to run. An empty permissionMode or model
// passes no flag. sessionID is the id claude runs the session under.
type workerSpec struct {
	dir            string
	permissionMode string
	model          string
	sessionID      string
	prompt         string
}

// workerOps is the process surface the router drives. Tests replace run so the
// routing and the in-flight bookkeeping need no claude binary, and sessionID so
// a test knows the id each worker gets.
type workerOps struct {
	run       func(ctx context.Context, spec workerSpec) workerResult
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

	return append(args, "-p", "--session-id", spec.sessionID, "--", spec.prompt)
}

// runWorker runs `claude -p --session-id <id> -- <prompt>` in the spec's dir
// and waits for it.
func runWorker(ctx context.Context, spec workerSpec) workerResult {
	args := workerArgs(spec)

	// One writer for both streams, so os/exec drains them through one pipe and
	// nothing races. The cap bounds the memory a chatty worker holds.
	captured := &capWriter{limit: maxOutput}

	cmd := exec.CommandContext(ctx, "claude", args...)
	cmd.Dir = spec.dir
	cmd.Stdout, cmd.Stderr = captured, captured
	cmd.WaitDelay = waitDelay
	setProcessGroup(cmd)

	err := cmd.Run()
	res := workerResult{output: captured.text()}

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

func (w *capWriter) text() string {
	s := strings.TrimRight(w.buf.String(), "\n")
	if w.dropped {
		s += "… (truncated)"
	}

	return s
}
