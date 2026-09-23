package cmd

import (
	"bytes"
	"context"
	"errors"
	"io"
	"os"
	"os/exec"
	"slices"
	"strings"
	"sync/atomic"
	"time"

	"github.com/ubermuda/loupe/cli/internal/config"
)

// waitDelay bounds the wait after the context kills claude. A grandchild that
// still holds the output pipe would otherwise block Wait for good.
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

// runWorker runs `claude -p --session-id <id> -- <prompt>`, or `--resume <id>`,
// in the spec's dir and waits for it.
func runWorker(ctx context.Context, spec workerSpec, onStart func()) workerResult {
	args := workerArgs(spec)

	// One writer value for both streams, so os/exec drains them through one pipe
	// and nothing races. The cap bounds the memory a chatty worker holds, and the
	// scanner reads past the cap.
	captured := &capWriter{limit: maxOutput}
	scanner := &resultScanner{}
	w := io.MultiWriter(captured, scanner)

	cmd := exec.CommandContext(ctx, "claude", args...)
	cmd.Dir = spec.dir
	cmd.Env = workerEnv(os.Environ())
	cmd.Stdout, cmd.Stderr = w, w
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
		return workerResult{output: captured.text(), err: err}
	}
	if onStart != nil {
		onStart()
	}
	err := cmd.Wait()
	res := workerResult{
		output:    captured.text(),
		hasResult: scanner.matched,
		killed:    err != nil && cancelled.Load(),
	}

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
