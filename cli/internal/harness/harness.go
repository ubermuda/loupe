// Package harness is what the bridge needs from the coding agent that runs a
// worker: how to start and resume one, how to read its output, and where its
// sessions are.
package harness

import (
	"encoding/json"
	"time"

	"github.com/ubermuda/loupe/cli/internal/transcript"
)

// Harness is one coding agent the bridge can run.
type Harness interface {
	Name() string
	// Program is the binary the bridge looks up on PATH.
	Program() string
	// Worker starts a new session, and Resume continues spec's session.
	Worker(spec Spec) Command
	Resume(spec Spec) Command
	// Interactive is the body of the launch script that runs program in a
	// terminal.
	Interactive(program string, spec Spec) string
	// Output decodes the stdout of a worker. An overflow holds no result.
	Output(stdout []byte, overflow bool) Output
	// SessionUsage is what the session spent at or after from, and before to
	// when to is set.
	SessionUsage(sessionID string, from, to time.Time) (transcript.Usage, error)
	// SessionTotal is what the whole session spent before a resume.
	SessionTotal(sessionID string) (transcript.Usage, error)
	// StartDir is the folder the session started in, and "" with no error
	// when this machine holds no record of the session.
	StartDir(sessionID string) (string, error)
	// HasSession fails when this machine holds no record of the session.
	HasSession(sessionID string) error
}

// Spec is one run. An empty Model, PermissionMode or Schema passes no flag.
// Env is the environment the bridge built for the process.
type Spec struct {
	Dir            string
	Model          string
	PermissionMode string
	Schema         string
	SessionID      string
	Prompt         string
	Env            []string
}

// Command is the argv after the program, and the environment of the process.
type Command struct {
	Args []string
	Env  []string
}

// Output is what a worker printed. Decoded says stdout held a valid document,
// and Result can hold text of one that did not decode.
type Output struct {
	Decoded          bool
	StructuredOutput json.RawMessage
	Result           string
	Usage            transcript.Usage
}
