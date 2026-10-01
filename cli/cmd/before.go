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
	"slices"
	"strings"
	"sync/atomic"
	"time"
	"unicode/utf8"

	"github.com/ubermuda/loupe/cli/internal/config"
)

// beforeShell runs the argv after $0 and records its exit code in the file
// that $0 names. The argv stays as given, so no shell parses it.
const beforeShell = `"$@"; echo $? > "$0"`

// procKind is a command the bridge runs for a run, other than claude. Its
// files in the run directory start with name, so an image that reads run.json
// never takes it for a worker. label names it in a reason. A kind with folder
// set prints the folder to start claude in.
type procKind struct {
	name   string
	label  string
	folder bool
}

var (
	beforeProc  = procKind{name: "before", label: "before command", folder: true}
	commandProc = procKind{name: "command", label: "command"}
)

// procSpec is one command to run: its argv, the project dir it runs in, and
// the run whose directory holds its files.
type procSpec struct {
	argv  []string
	dir   string
	runID string
}

// procResult is one finished command. err is set when the command never ran.
// dir is the folder a before command printed, and reason says why the command
// failed when its exit code does not. runDir holds its files.
type procResult struct {
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
func (b procResult) failure() string {
	return b.failureOf(beforeProc)
}

// failureOf says why the command of kind k failed, and "" when it succeeded.
func (b procResult) failureOf(k procKind) string {
	switch {
	case b.err != nil:
		return "the " + k.label + " did not start: " + b.err.Error()
	case b.timedOut:
		return "the " + k.label + " ran past its timeout, so the bridge killed it"
	case b.killed:
		return "the bridge shut down while the " + k.label + " ran"
	case b.exitCode != 0:
		return fmt.Sprintf("the %s exited with code %d", k.label, b.exitCode)
	}

	return b.reason
}

// procRecord is <name>.json, what the bridge knows about a command it started.
type procRecord struct {
	PID       int       `json:"pid"`
	StartedAt time.Time `json:"startedAt"`
	StartTime string    `json:"startTime,omitempty"`
	Argv      []string  `json:"argv"`
	Dir       string    `json:"dir"`
	Deadline  time.Time `json:"deadline,omitzero"`
}

func writeProcRecord(k procKind, dir string, rec procRecord) error {
	b, err := json.Marshal(rec)
	if err != nil {
		return err
	}
	if err := os.WriteFile(filepath.Join(dir, k.name+".json"), b, 0o600); err != nil {
		return fmt.Errorf("write %s record: %w", k.name, err)
	}

	return nil
}

func readProcRecord(k procKind, dir string) (procRecord, error) {
	var rec procRecord
	b, err := os.ReadFile(filepath.Join(dir, k.name+".json"))
	if err != nil {
		return rec, fmt.Errorf("read %s record: %w", k.name, err)
	}
	if err := json.Unmarshal(b, &rec); err != nil {
		return rec, fmt.Errorf("parse %s record: %w", k.name, err)
	}

	return rec, nil
}

// runBefore runs the before command of a worker rule and reads the folder it
// prints.
func runBefore(ctx context.Context, spec procSpec, onStart func(workerProc)) procResult {
	return runProc(ctx, beforeProc, spec, onStart)
}

// adoptBeforeProc waits for the before command another image started in dir.
func adoptBeforeProc(ctx context.Context, dir string) procResult {
	return adoptProc(ctx, beforeProc, dir)
}

// beforeOutcome reads how the before command in dir ended.
func beforeOutcome(dir, project string, killed, timedOut bool) procResult {
	return procOutcome(beforeProc, dir, project, killed, timedOut)
}

// runProc runs the command in the project dir, in its own process group and
// with the bridge's environment, and waits for it. Its output and its exit
// code go to files in the run directory, as a worker's do, so another image
// can adopt it. The end of ctx kills the group.
func runProc(ctx context.Context, k procKind, spec procSpec, onStart func(workerProc)) procResult {
	runs, err := config.RunsDir()
	if err != nil {
		return procResult{err: err}
	}
	if spec.runID == "" {
		spec.runID = config.NewUUID()
	}
	dir := filepath.Join(runs, spec.runID)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return procResult{err: fmt.Errorf("create run directory: %w", err)}
	}
	res := procResult{runDir: dir}
	stdout, err := createOutput(dir, k.name+".stdout")
	if err != nil {
		res.err = err

		return res
	}
	defer stdout.Close()
	stderr, err := createOutput(dir, k.name+".stderr")
	if err != nil {
		res.err = err

		return res
	}
	defer stderr.Close()

	cmd := exec.CommandContext(ctx, "/bin/sh", append([]string{"-c", beforeShell, filepath.Join(dir, k.name+".exit")}, spec.argv...)...)
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
	rec := procRecord{
		PID: cmd.Process.Pid, StartedAt: time.Now(), StartTime: processStart(cmd.Process.Pid),
		Argv: spec.argv, Dir: spec.dir, Deadline: deadline,
	}
	// A command with no record cannot outlive this bridge, so it does not run.
	if err := writeProcRecord(k, dir, rec); err != nil {
		_ = cmd.Cancel()
		_ = cmd.Wait()
		res.err = err

		return res
	}
	if onStart != nil {
		onStart(workerProc{pid: cmd.Process.Pid, dir: dir})
	}
	killed := cmd.Wait() != nil && cancelled.Load()

	return procOutcome(k, dir, spec.dir, killed, killed && errors.Is(ctx.Err(), context.DeadlineExceeded))
}

// adoptProc waits for the command another image of the bridge started in dir,
// until the deadline that image set, and reads how it ended.
func adoptProc(ctx context.Context, k procKind, dir string) procResult {
	if dir == "" {
		return procResult{reason: "the bridge handed the " + k.label + " over with no run directory"}
	}
	rec, err := readProcRecord(k, dir)
	if err == nil && rec.PID <= 0 {
		err = fmt.Errorf("the %s record names no process", k.name)
	}
	if err != nil {
		return procResult{runDir: dir, reason: "the bridge lost the " + k.label + " across a handover: " + err.Error()}
	}
	wait := ctx
	if !rec.Deadline.IsZero() {
		var cancel context.CancelFunc
		wait, cancel = context.WithDeadline(ctx, rec.Deadline)
		defer cancel()
	}
	killed := awaitProcess(wait, rec.PID, rec.StartTime)

	return procOutcome(k, dir, rec.Dir, killed, killed && ctx.Err() == nil && errors.Is(wait.Err(), context.DeadlineExceeded))
}

// procOutcome reads how the command in dir ended. stdout follows stderr in the
// output. When a before command succeeds, the last non-empty line of stdout
// names the folder instead, and the output leaves that line out.
func procOutcome(k procKind, dir, project string, killed, timedOut bool) procResult {
	res := procResult{runDir: dir, killed: killed, timedOut: timedOut}
	stdout, stdoutErr := readTail(filepath.Join(dir, k.name+".stdout"), maxStdout)
	stderr, stderrErr := readTail(filepath.Join(dir, k.name+".stderr"), maxOutput)
	code, codeErr := readExitStatus(filepath.Join(dir, k.name+".exit"))
	if errors.Is(codeErr, errNoExitFile) {
		codeErr = fmt.Errorf("the %s's shell ended before it recorded one", k.label)
	}
	res.exitCode = code
	if codeErr != nil {
		res.exitCode = -1
	}
	rest := stdout
	if k.folder && res.exitCode == 0 && !killed {
		var folder string
		folder, rest = lastOutputLine(stdout)
		res.dir, res.reason = resolveFolder(folder, project)
		// Unread stdout can hide the folder, so the run must not start in the project dir.
		if stdoutErr != nil {
			res.dir, res.reason = "", "the bridge cannot read the "+k.label+"'s output: "+stdoutErr.Error()
		}
	}

	parts := []string{strings.TrimRight(stderr, "\n"), strings.Trim(rest, "\n")}
	if readErr := errors.Join(stdoutErr, stderrErr); readErr != nil {
		parts = append(parts, readErr.Error())
	}
	if codeErr != nil && !killed {
		parts = append(parts, "(no exit status: "+codeErr.Error()+")")
	}
	parts = slices.DeleteFunc(parts, func(part string) bool { return part == "" })
	// A failed command prints why at the end, so the output keeps the end.
	res.output = tailOf(strings.Join(parts, "\n"), maxOutput)

	return res
}

// failedOutput puts the reason of a failed command first, and the end of its
// output in the room the reason leaves under maxOutput.
func failedOutput(failure, output string) string {
	out := &capWriter{limit: maxOutput}
	_, _ = out.Write([]byte(failure))
	sep := "\n"
	if failure == "" {
		sep = ""
	}
	if room := maxOutput - len(failure) - len(sep); output != "" && room > len(truncatedMark) {
		_, _ = out.Write([]byte(sep + tailOf(output, room)))
	}

	return out.text()
}

// truncatedMark starts a text whose start tailOf dropped.
const truncatedMark = "(truncated) …"

// tailOf keeps the end of s in at most limit bytes, after truncatedMark when
// it drops the start. It cuts at the start of a rune.
func tailOf(s string, limit int) string {
	if len(s) <= limit {
		return s
	}
	cut := len(s) - max(limit-len(truncatedMark), 0)
	for cut < len(s) && !utf8.RuneStart(s[cut]) {
		cut++
	}

	return truncatedMark + s[cut:]
}

// readTail reads the last limit bytes of the file at path, after
// truncatedMark when it drops the start.
func readTail(path string, limit int64) (string, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", fmt.Errorf("read output: %w", err)
	}
	defer f.Close()
	cut := false
	if info, err := f.Stat(); err == nil && info.Size() > limit {
		if _, err := f.Seek(info.Size()-limit, io.SeekStart); err != nil {
			return "", fmt.Errorf("read output: %w", err)
		}
		cut = true
	}
	b, err := io.ReadAll(f)
	if err != nil {
		return "", fmt.Errorf("read output: %w", err)
	}
	if !cut {
		return string(b), nil
	}
	for len(b) > 0 && !utf8.RuneStart(b[0]) {
		b = b[1:]
	}

	return truncatedMark + string(b), nil
}

// lastOutputLine splits stdout into its last non-empty line, trimmed, and what
// comes before that line.
func lastOutputLine(stdout string) (string, string) {
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
