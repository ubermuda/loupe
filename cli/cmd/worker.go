package cmd

import (
	"context"
	"errors"
	"os/exec"
	"strings"
	"time"
)

// waitDelay bounds the wait after the context kills claude. A grandchild that
// still holds the output pipe would otherwise block Wait for good.
const waitDelay = 5 * time.Second

// maxOutput caps the captured output a failure report carries.
const maxOutput = 4000

// workerResult is one finished worker. err is set when the process never ran,
// which is a different fault from a process that ran and failed.
type workerResult struct {
	exitCode int
	output   string
	err      error
}

// workerOps is the process surface the router drives. Tests replace run so the
// routing and the in-flight bookkeeping need no claude binary.
type workerOps struct {
	run func(ctx context.Context, dir, permissionMode, prompt string) workerResult
}

func defaultWorkerOps() workerOps {
	return workerOps{run: runWorker}
}

// runWorker runs `claude -p <prompt>` in dir and waits for it.
//
// The prompt is an argv element, so no shell reads it and no quoting applies.
func runWorker(ctx context.Context, dir, permissionMode, prompt string) workerResult {
	args := make([]string, 0, 4)
	if permissionMode != "" {
		args = append(args, "--permission-mode", permissionMode)
	}
	args = append(args, "-p", prompt)

	cmd := exec.CommandContext(ctx, "claude", args...)
	cmd.Dir = dir
	cmd.WaitDelay = waitDelay

	out, err := cmd.CombinedOutput()
	res := workerResult{output: truncate(string(out))}

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

func truncate(s string) string {
	s = strings.TrimRight(s, "\n")
	if len(s) <= maxOutput {
		return s
	}

	return s[:maxOutput] + "… (truncated)"
}
