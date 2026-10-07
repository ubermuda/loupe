package api

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// The kinds of a command.
const (
	CommandStopRun      = "stop-run"
	CommandResumeRun    = "resume-run"
	CommandRerunCommand = "rerun-command"
	// CommandCollectSessionUsage asks for the usage of one interactive run,
	// from the transcript of its session on this machine.
	CommandCollectSessionUsage = "collect-session-usage"
)

// The causes of a command. A resume after an ask close says that the owner
// answered, and a person's resume says that a person fixed the cause.
const (
	CausePerson    = "person"
	CauseAskClosed = "ask-closed"
)

// The states a bridge answers a command with.
const (
	CommandDone    = "done"
	CommandRefused = "refused"
)

// Command asks this bridge to stop or resume one run, to run the command of a
// failed command run again, or to send the usage of an interactive run. It
// arrives as a bridge.command event and again in each heartbeat reply, with
// the same keys. A null runKey, sessionId, workRequestId, workKind, ruleId or
// runId decodes as "". A run of a rules: entry names no work request.
type Command struct {
	Type          string         `json:"type"`
	ProjectID     string         `json:"projectId"`
	Subject       CommandSubject `json:"subject"`
	CommandID     string         `json:"commandId"`
	Kind          string         `json:"kind"`
	BridgeID      string         `json:"bridgeId"`
	RunKey        string         `json:"runKey"`
	SessionID     string         `json:"sessionId"`
	CardID        string         `json:"cardId"`
	CardNumber    int            `json:"cardNumber"`
	WorkRequestID string         `json:"workRequestId"`
	WorkKind      string         `json:"workKind"`
	RuleID        string         `json:"ruleId"`
	ExpiresAt     time.Time      `json:"expiresAt"`
	Cause         string         `json:"cause,omitempty"`
	// RunID names the worker run, and StartedAt and EndedAt its window. An
	// older server sends none, and a null time decodes as nil.
	RunID     string     `json:"runId"`
	StartedAt *time.Time `json:"startedAt"`
	EndedAt   *time.Time `json:"endedAt"`
	// Context is the context of the work request of the run, so a rerun or a
	// resume fills the values the run had. An older server sends none.
	Context WorkRequestContext `json:"context"`
}

// CommandSubject names the command itself.
type CommandSubject struct {
	Type string `json:"type"`
	ID   string `json:"id"`
}

// maxCommandReason is the server's cap on the reason of an answer.
const maxCommandReason = 1000

// ErrCommandNotFound marks a command the server does not hold for this bridge.
// The caller treats the command as settled.
var ErrCommandNotFound = errors.New("the server holds no such command")

// AckCommand answers a command with done or refused, and returns the state
// the server stores. A command that was settled already keeps its own state.
// An empty reason sends no reason key.
func (c *Client) AckCommand(ctx context.Context, bridgeID, commandID, state, reason string) (string, error) {
	body, err := json.Marshal(struct {
		State  string `json:"state"`
		Reason string `json:"reason,omitempty"`
	}{state, clip(strings.TrimSpace(reason), maxCommandReason)})
	if err != nil {
		return "", err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPut,
		c.baseURL+"/api/bridges/"+url.PathEscape(bridgeID)+"/commands/"+url.PathEscape(commandID), bytes.NewReader(body))
	if err != nil {
		return "", err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.do(req)
	if err != nil {
		return "", fmt.Errorf("answer command %s: %w", commandID, err)
	}
	defer resp.Body.Close()

	detail, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
	switch resp.StatusCode {
	case http.StatusOK:
		var stored struct {
			State string `json:"state"`
		}
		_ = json.Unmarshal(detail, &stored)

		return stored.State, nil
	case http.StatusNotFound:
		var payload struct {
			Error string `json:"error"`
		}
		if json.Unmarshal(detail, &payload) == nil && payload.Error == "command_not_found" {
			return "", fmt.Errorf("%w: %s", ErrCommandNotFound, commandID)
		}
	}

	return "", fmt.Errorf("command answer failed (HTTP %d): %s", resp.StatusCode, strings.TrimSpace(string(detail)))
}

// decodeCommands decodes each command alone and drops one it cannot decode.
// A field of the wrong type would otherwise leave a zero value, which means
// something else.
func decodeCommands(raw []json.RawMessage) []Command {
	var out []Command
	for _, item := range raw {
		var cmd Command
		if json.Unmarshal(item, &cmd) == nil {
			out = append(out, cmd)
		}
	}

	return out
}
