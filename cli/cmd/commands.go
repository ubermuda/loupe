package cmd

import (
	"context"
	"encoding/json"
	"errors"
	"maps"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/outbound"
)

// The channels a command arrives on, as command_received names them.
const (
	commandFromEvent     = "event"
	commandFromHeartbeat = "heartbeat"
)

// notYet is the answer of a kind this build cannot act on.
const notYet = "This bridge cannot act on the command yet."

// onCommandEvent takes a bridge.command event. ForAnotherBridge dropped the
// events of other bridges already.
func (r *router) onCommandEvent(data []byte) {
	c, err := event.ParseCommand(data)
	if err != nil {
		r.log.Warn("command_dropped", "reason", "malformed", "source", commandFromEvent, "error", err.Error())

		return
	}
	r.takeCommand(c, commandFromEvent)
}

// onHeartbeatReply applies the pause and takes the commands of one heartbeat
// reply. It runs on the goroutine of the heartbeat lane, and a handler runs
// on a goroutine of its own, so it never waits on one.
func (r *router) onHeartbeatReply(reply api.HeartbeatReply) {
	if reply.Paused != nil {
		r.setPersonPause(*reply.Paused)
	}
	for _, raw := range reply.Commands {
		c, err := event.CheckCommand(raw)
		if err != nil {
			r.log.Warn("command_dropped", "reason", "malformed", "source", commandFromHeartbeat, "command", raw.CommandID, "error", err.Error())

			continue
		}
		r.takeCommand(c, commandFromHeartbeat)
	}
}

// takeCommand runs the handler of a checked command once, whichever channel
// brought it first. It keeps the id until the command expires. A frozen router
// leaves a heartbeat command unmarked, so the next image takes it.
func (r *router) takeCommand(c api.Command, source string) {
	attrs := commandAttrs(c, source)
	r.mu.Lock()
	reason := ""
	now := time.Now()
	switch {
	case r.shut():
		reason = "shutdown"
	case r.frozen:
		reason = "handover"
	case !strings.EqualFold(c.BridgeID, r.bridgeID):
		reason = "other_bridge"
	case !now.Before(c.ExpiresAt):
		reason = "expired"
	default:
		maps.DeleteFunc(r.handled, func(_ string, expires time.Time) bool { return !now.Before(expires) })
		if _, ok := r.handled[c.CommandID]; ok {
			reason = "duplicate"

			break
		}
		if r.handled == nil {
			r.handled = map[string]time.Time{}
		}
		r.handled[c.CommandID] = c.ExpiresAt
		// Under mu, so shutdown never waits on wg before this Add.
		r.wg.Add(1)
	}
	r.mu.Unlock()
	if reason != "" {
		r.log.Info("command_dropped", append(attrs, "reason", reason)...)

		return
	}

	r.log.Info("command_received", attrs...)
	go func() {
		defer r.wg.Done()

		state, why := r.handleCommand(c)
		r.sendAck(c, state, why)
	}()
}

// commandAttrs names a command in a log line.
func commandAttrs(c api.Command, source string) []any {
	return []any{"command", c.CommandID, "kind", c.Kind, "card", c.CardNumber, "project", c.ProjectID, "rule", c.RuleName, "run_key", c.RunKey, "source", source}
}

// handleCommand runs the handler of the command's kind, and returns the
// state and the reason of its answer.
func (r *router) handleCommand(c api.Command) (string, string) {
	if c.Kind == api.CommandStopRun {
		return r.stopRun(c)
	}

	return r.resumeRun(c)
}

// stopRun stops the run a command names.
func (r *router) stopRun(api.Command) (state, reason string) {
	return api.CommandRefused, notYet
}

// resumeRun resumes the run a command names.
func (r *router) resumeRun(api.Command) (state, reason string) {
	return api.CommandRefused, notYet
}

// sendAck hands the answer to the report queue, which retries a failure. A
// command the server no longer holds counts as settled.
func (r *router) sendAck(c api.Command, state, reason string) {
	if r.ackCommand == nil || r.reports == nil || r.bridgeID == "" {
		return
	}
	r.reports.Enqueue(outbound.Report{
		Card: c.CardNumber,
		Rule: c.RuleName,
		Send: func(ctx context.Context) (bool, error) {
			err := r.ackCommand(ctx, r.bridgeID, c.CommandID, state, reason)
			switch {
			case err == nil:
				r.log.Info("command_acked", "command", c.CommandID, "kind", c.Kind, "state", state)
			case errors.Is(err, api.ErrCommandNotFound):
				r.log.Info("command_acked", "command", c.CommandID, "kind", c.Kind, "state", state, "answer", "command_not_found")
			default:
				return false, err
			}

			return true, nil
		},
	})
}

// setPersonPause applies the pause the server holds for a person. A change is
// logged, cached and sent with the next heartbeat, and an unpause starts the
// queued runs.
func (r *router) setPersonPause(paused bool) {
	r.mu.Lock()
	if r.personPaused == paused {
		r.mu.Unlock()

		return
	}
	r.personPaused = paused
	hb := r.heartbeat
	r.mu.Unlock()

	if paused {
		r.log.Info("bridge_paused", "source", "server")
	} else {
		r.log.Info("bridge_unpaused", "source", "server")
	}
	if r.pauseFile != "" {
		if err := writePause(r.pauseFile, paused); err != nil {
			r.log.Warn("pause_cache_failed", "path", r.pauseFile, "error", err.Error())
		}
	}
	if hb != nil {
		hb.setPaused(paused)
	}
	if !paused {
		r.dispatch()
	}
}

// loadPause reads the cached pause at start, before any dispatch. A missing
// cache leaves the bridge free, and so does one it cannot read.
func (r *router) loadPause() {
	if r.pauseFile == "" {
		return
	}
	paused, err := readPause(r.pauseFile)
	if err != nil {
		if !errors.Is(err, os.ErrNotExist) {
			r.log.Warn("pause_cache_unreadable", "path", r.pauseFile, "error", err.Error())
		}

		return
	}
	r.mu.Lock()
	r.personPaused = paused
	r.mu.Unlock()
	if paused {
		r.log.Info("bridge_paused", "source", "cache")
	}
}

// pauseCache is what the pause cache holds.
type pauseCache struct {
	Paused bool `json:"paused"`
}

// pausePath names the pause cache. The server keys a pause by the bridge id,
// which config.json holds, so one cache serves the directory.
func pausePath() (string, error) {
	dir, err := config.Dir()
	if err != nil {
		return "", err
	}

	return filepath.Join(dir, "pause.json"), nil
}

func readPause(path string) (bool, error) {
	b, err := os.ReadFile(path)
	if err != nil {
		return false, err
	}
	var c pauseCache
	if err := json.Unmarshal(b, &c); err != nil {
		return false, err
	}

	return c.Paused, nil
}

func writePause(path string, paused bool) error {
	b, err := json.Marshal(pauseCache{Paused: paused})
	if err != nil {
		return err
	}

	return writeAtomic(path, ".pause-*", b)
}
