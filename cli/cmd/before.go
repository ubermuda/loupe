package cmd

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync/atomic"
	"time"

	"github.com/ubermuda/loupe/cli/internal/config"
)

// beforeShell runs the argv after $0 and records its exit code in the file
// that $0 names. The argv stays as given, so no shell parses it.
const beforeShell = `"$@"; echo $? > "$0"`

// beforeSpec is one before command to run: its argv, the project dir it runs
// in, and the run whose directory holds its files.
type beforeSpec struct {
	argv  []string
	dir   string
	runID string
}

// beforeResult is one finished before command. err is set when the command
// never ran. dir is the folder to start claude in, and reason says why the
// command failed when its exit code does not. runDir holds its files.
type beforeResult struct {
	exitCode int
	killed   bool
	timedOut bool
	err      error
	dir      string
	output   string
	reason   string
	runDir   string
}

// failure says why the before command failed, and "" when it succeeded.
func (b beforeResult) failure() string {
	switch {
	case b.err != nil:
		return "the before command did not start: " + b.err.Error()
	case b.timedOut:
		return "the before command ran past its timeout, so the bridge killed it"
	case b.killed:
		return "the bridge stopped the before command"
	case b.exitCode != 0:
		return fmt.Sprintf("the before command exited with code %d", b.exitCode)
	}

	return b.reason
}

// beforeRecord is before.json, what the bridge knows about a before command
// it started. It has its own name, so an image that reads run.json never takes
// the command for a worker.
type beforeRecord struct {
	PID       int       `json:"pid"`
	StartedAt time.Time `json:"startedAt"`
	StartTime string    `json:"startTime,omitempty"`
	Argv      []string  `json:"argv"`
	Dir       string    `json:"dir"`
	Deadline  time.Time `json:"deadline,omitzero"`
}

func writeBeforeRecord(dir string, rec beforeRecord) error {
	b, err := json.Marshal(rec)
	if err != nil {
		return err
	}
	if err := os.WriteFile(filepath.Join(dir, "before.json"), b, 0o600); err != nil {
		return fmt.Errorf("write before record: %w", err)
	}

	return nil
}

func readBeforeRecord(dir string) (beforeRecord, error) {
	var rec beforeRecord
	b, err := os.ReadFile(filepath.Join(dir, "before.json"))
	if err != nil {
		return rec, fmt.Errorf("read before record: %w", err)
	}
	if err := json.Unmarshal(b, &rec); err != nil {
		return rec, fmt.Errorf("parse before record: %w", err)
	}

	return rec, nil
}

// runBefore runs the before command in the project dir, in its own process
// group and with the bridge's environment, and waits for it. Its output and
// its exit code go to files in the run directory, as a worker's do, so another
// image can adopt it. The end of ctx kills the group.
func runBefore(ctx context.Context, spec beforeSpec, onStart func(workerProc)) beforeResult {
	runs, err := config.RunsDir()
	if err != nil {
		return beforeResult{err: err}
	}
	if spec.runID == "" {
		spec.runID = config.NewUUID()
	}
	dir := filepath.Join(runs, spec.runID)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return beforeResult{err: fmt.Errorf("create run directory: %w", err)}
	}
	res := beforeResult{runDir: dir}
	stdout, err := createOutput(dir, "before.stdout")
	if err != nil {
		res.err = err

		return res
	}
	defer stdout.Close()
	stderr, err := createOutput(dir, "before.stderr")
	if err != nil {
		res.err = err

		return res
	}
	defer stderr.Close()

	cmd := exec.CommandContext(ctx, "/bin/sh", append([]string{"-c", beforeShell, filepath.Join(dir, "before.exit")}, spec.argv...)...)
	cmd.Dir = spec.dir
	cmd.Env = os.Environ()
	cmd.Stdout, cmd.Stderr = stdout, stderr
	cmd.WaitDelay = waitDelay
	setProcessGroup(cmd)
	var cancelled atomic.Bool
	kill := cmd.Cancel
	cmd.Cancel = func() error {
		err := kill()
		cancelled.Store(err == nil)

		return err
	}
	if err := cmd.Start(); err != nil {
		res.err = err

		return res
	}
	deadline, _ := ctx.Deadline()
	rec := beforeRecord{
		PID: cmd.Process.Pid, StartedAt: time.Now(), StartTime: processStart(cmd.Process.Pid),
		Argv: spec.argv, Dir: spec.dir, Deadline: deadline,
	}
	// A command with no record cannot outlive this bridge, so it does not run.
	if err := writeBeforeRecord(dir, rec); err != nil {
		_ = cmd.Cancel()
		_ = cmd.Wait()
		res.err = err

		return res
	}
	if onStart != nil {
		onStart(workerProc{pid: cmd.Process.Pid, dir: dir})
	}
	killed := cmd.Wait() != nil && cancelled.Load()

	return beforeOutcome(dir, spec.dir, killed, killed && errors.Is(ctx.Err(), context.DeadlineExceeded))
}

// adoptBeforeProc waits for the before command another image of the bridge
// started in dir, until the deadline that image set, and reads how it ended.
func adoptBeforeProc(ctx context.Context, dir string) beforeResult {
	if dir == "" {
		return beforeResult{reason: "the bridge handed the before command over with no run directory"}
	}
	rec, err := readBeforeRecord(dir)
	if err == nil && rec.PID <= 0 {
		err = errors.New("the before record names no process")
	}
	if err != nil {
		return beforeResult{runDir: dir, reason: "the bridge lost the before command across a handover: " + err.Error()}
	}
	wait := ctx
	if !rec.Deadline.IsZero() {
		var cancel context.CancelFunc
		wait, cancel = context.WithDeadline(ctx, rec.Deadline)
		defer cancel()
	}
	killed := awaitProcess(wait, rec.PID, rec.StartTime)

	return beforeOutcome(dir, rec.Dir, killed, killed && ctx.Err() == nil && errors.Is(wait.Err(), context.DeadlineExceeded))
}

// beforeOutcome reads how the before command in dir ended. On success, the
// last non-empty line of stdout names the folder, and the rest of stdout
// follows stderr in the output. On failure, all of stdout does.
func beforeOutcome(dir, project string, killed, timedOut bool) beforeResult {
	res := beforeResult{runDir: dir, killed: killed, timedOut: timedOut}
	stdout, stdoutErr := readTail(filepath.Join(dir, "before.stdout"), maxStdout)
	stderr, stderrErr := readCapped(filepath.Join(dir, "before.stderr"), maxOutput)
	code, codeErr := readExitStatus(filepath.Join(dir, "before.exit"))
	res.exitCode = code
	if codeErr != nil {
		res.exitCode = -1
	}
	rest := stdout
	if res.exitCode == 0 && !killed {
		var folder string
		folder, rest = lastLine(stdout)
		res.dir, res.reason = resolveFolder(folder, project)
	}

	out := &capWriter{limit: maxOutput}
	parts := []string{strings.TrimRight(stderr.buf.String(), "\n"), strings.Trim(rest, "\n")}
	if readErr := errors.Join(stdoutErr, stderrErr); readErr != nil {
		parts = append(parts, readErr.Error())
	}
	if codeErr != nil && !killed {
		parts = append(parts, "(no exit status: "+codeErr.Error()+")")
	}
	for _, part := range parts {
		if part == "" {
			continue
		}
		if out.buf.Len() > 0 {
			_, _ = out.Write([]byte("\n"))
		}
		_, _ = out.Write([]byte(part))
	}
	out.dropped = out.dropped || stderr.dropped
	res.output = out.text()

	return res
}

// readTail reads the last limit bytes of the file at path.
func readTail(path string, limit int64) (string, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", fmt.Errorf("read before output: %w", err)
	}
	defer f.Close()
	if info, err := f.Stat(); err == nil && info.Size() > limit {
		if _, err := f.Seek(info.Size()-limit, io.SeekStart); err != nil {
			return "", fmt.Errorf("read before output: %w", err)
		}
	}
	b, err := io.ReadAll(f)
	if err != nil {
		return "", fmt.Errorf("read before output: %w", err)
	}

	return string(b), nil
}

// lastLine splits stdout into its last non-empty line, trimmed, and what
// comes before that line.
func lastLine(stdout string) (string, string) {
	lines := strings.Split(stdout, "\n")
	for i := len(lines) - 1; i >= 0; i-- {
		if line := strings.TrimSpace(lines[i]); line != "" {
			return line, strings.Join(lines[:i], "\n")
		}
	}

	return "", ""
}

// resolveFolder is the folder a before command printed, against the project
// dir. No folder keeps the project dir. A folder that is not an existing
// directory returns the reason the run fails.
func resolveFolder(folder, project string) (string, string) {
	if folder == "" {
		return project, ""
	}
	path := folder
	if !filepath.IsAbs(path) {
		path = filepath.Join(project, path)
	}
	if info, err := os.Stat(path); err != nil || !info.IsDir() {
		return "", fmt.Sprintf("the before command printed %q, which is not a directory", folder)
	}

	return path, ""
}
