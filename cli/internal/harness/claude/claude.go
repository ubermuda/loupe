// Package claude runs Claude Code as the harness of a worker.
package claude

import (
	"encoding/json"
	"errors"
	"path/filepath"
	"strings"
	"time"

	"github.com/ubermuda/loupe/cli/internal/harness"
	"github.com/ubermuda/loupe/cli/internal/transcript"
)

// ceilingEnv lifts claude -p's background wait ceiling, which otherwise ends a
// worker mid-task and exits 0.
const ceilingEnv = "CLAUDE_CODE_PRINT_BG_WAIT_CEILING_MS"

// Harness is Claude Code. configDir is the folder that holds its sessions,
// and "" is the folder of the bridge's own environment.
type Harness struct {
	configDir string
}

// New is the Claude Code harness of the config folder configDir.
func New(configDir string) Harness { return Harness{configDir: configDir} }

func (Harness) Name() string { return "claude-code" }

func (Harness) Program() string { return "claude" }

func (Harness) Worker(spec harness.Spec) harness.Command {
	return harness.Command{Args: args(spec, "--session-id"), Env: env(spec.Env)}
}

func (Harness) Resume(spec harness.Spec) harness.Command {
	return harness.Command{Args: args(spec, "--resume"), Env: env(spec.Env)}
}

// args builds claude's argv. The prompt is an argv element, so no shell reads
// it. It follows --, because claude reads a prompt that starts with - as an
// option.
func args(spec harness.Spec, session string) []string {
	args := make([]string, 0, 16)
	if spec.PermissionMode != "" {
		args = append(args, "--permission-mode", spec.PermissionMode)
	}
	if spec.Model != "" {
		args = append(args, "--model", spec.Model)
	}
	if spec.Effort != "" {
		args = append(args, "--effort", spec.Effort)
	}
	args = append(args, "--verbose", "--output-format", "stream-json")
	if spec.Schema != "" {
		args = append(args, "--json-schema", spec.Schema)
	}

	return append(args, "-p", session, spec.SessionID, "--", spec.Prompt)
}

// env is claude's environment. A ceiling the operator set, empty included,
// stays as set.
func env(environ []string) []string {
	env := make([]string, 0, len(environ)+1)
	ceiling := false
	for _, e := range environ {
		if strings.HasPrefix(e, ceilingEnv+"=") {
			ceiling = true
		}
		env = append(env, e)
	}
	if !ceiling {
		env = append(env, ceilingEnv+"=0")
	}

	return env
}

// shellQuote quotes s for a POSIX shell, so the shell reads it as one word.
func shellQuote(s string) string {
	return "'" + strings.ReplaceAll(s, "'", `'\''`) + "'"
}

// Interactive is the launch script body. The script deletes itself first, so
// the prompt does not stay on the disk.
func (Harness) Interactive(program string, spec harness.Spec) string {
	var b strings.Builder
	b.WriteString("#!/bin/sh\n")
	b.WriteString("rm -f -- \"$0\"\n")
	for _, e := range spec.Env {
		k, v, _ := strings.Cut(e, "=")
		b.WriteString("export " + k + "=" + shellQuote(v) + "\n")
	}
	b.WriteString("cd -- " + shellQuote(spec.Dir) + " || exit 1\n")
	b.WriteString("exec " + shellQuote(program) + " --session-id " + shellQuote(spec.SessionID))
	if spec.Model != "" {
		b.WriteString(" --model " + shellQuote(spec.Model))
	}
	if spec.Effort != "" {
		b.WriteString(" --effort " + shellQuote(spec.Effort))
	}
	if spec.PermissionMode != "" {
		b.WriteString(" --permission-mode " + shellQuote(spec.PermissionMode))
	}
	b.WriteString(" -- " + shellQuote(spec.Prompt) + "\n")

	return b.String()
}

// ReadRun reads the stdout of claude in dir. A stdout that does not read to
// its end gives no calls and its error, and keeps the result line it held.
func (Harness) ReadRun(dir string, _ harness.RunInfo) harness.Output {
	out, err := ReadFile(filepath.Join(dir, "stdout"))
	doc := decode(out.Result)
	doc.ReadErr = err
	if err == nil {
		doc.CallsRead, doc.Calls, doc.Timing, doc.PeakContextTokens = true, out.Calls, out.Timing, out.PeakContextTokens
	}

	return doc
}

// decode reads the result line of claude --output-format stream-json. A line
// with a field of the wrong type does not decode, and keeps the result it
// held.
func decode(result []byte) harness.Output {
	var doc struct {
		StructuredOutput json.RawMessage `json:"structured_output"`
		Result           string          `json:"result"`
		// IsError goes unread, but a document whose is_error is no bool
		// does not decode.
		IsError    bool            `json:"is_error"`
		ModelUsage json.RawMessage `json:"modelUsage"`
	}
	decoded := result != nil && json.Unmarshal(result, &doc) == nil
	out := harness.Output{Decoded: decoded, Result: doc.Result}
	if !decoded {
		return out
	}
	out.StructuredOutput = doc.StructuredOutput
	if len(doc.ModelUsage) > 0 && string(doc.ModelUsage) != "null" {
		out.Usage, _ = transcript.DecodeModelUsage(doc.ModelUsage)
	}

	return out
}

// find is the transcript of the session in the Claude Code config directory.
func (h Harness) find(sessionID string) (string, error) {
	dir := h.configDir
	if dir == "" {
		var err error
		if dir, err = transcript.ConfigDir(); err != nil {
			return "", err
		}
	}

	return transcript.Find(dir, sessionID)
}

func (h Harness) SessionUsage(sessionID string, from, to time.Time) (transcript.Usage, error) {
	path, err := h.find(sessionID)
	if err != nil {
		return nil, err
	}

	return transcript.Between(path, from, to)
}

// SessionTotal reads the last cost-state line of the transcript. A session
// with no such line spent zero.
func (h Harness) SessionTotal(sessionID string) (transcript.Usage, error) {
	path, err := h.find(sessionID)
	if err != nil {
		return nil, err
	}

	return transcript.LastCostState(path)
}

func (h Harness) StartDir(sessionID string) (string, error) {
	path, err := h.find(sessionID)
	if errors.Is(err, transcript.ErrNotFound) {
		return "", nil
	}
	if err != nil {
		return "", err
	}

	return transcript.StartDir(path)
}

func (h Harness) HasSession(sessionID string) error {
	_, err := h.find(sessionID)

	return err
}
