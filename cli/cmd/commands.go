package cmd

import (
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"maps"
	"os"
	"path/filepath"
	"slices"
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

// The answers of a stop the bridge cannot act on.
const (
	noOpenRun   = "The bridge holds no open run with this key."
	handingOver = "The bridge is handing over to a new version. Send the stop again."
)

// The waits of the stop ladder when the server shares none. A flag below
// minStopWait reads as the default, as on the server.
const (
	defaultStopTerm = 7500 * time.Millisecond
	defaultStopKill = 2500 * time.Millisecond
	minStopWait     = 100 * time.Millisecond
)

// stopWaits are the waits of the stop ladder: from SIGINT to SIGTERM, and
// from SIGTERM to SIGKILL. A zero wait is the default.
type stopWaits struct {
	term, kill time.Duration
}

func stopWaitsOf(events api.Events) stopWaits {
	return stopWaits{term: stopWait(events, api.StopSigtermFlag, defaultStopTerm), kill: stopWait(events, api.StopSigkillFlag, defaultStopKill)}
}

func stopWait(events api.Events, flag string, fallback time.Duration) time.Duration {
	if d, ok := events.Milliseconds(flag); ok && d >= minStopWait {
		return d
	}

	return fallback
}

// stopRun stops the run a command names, and holds its card. A queued run
// closes at once. A live worker gets the stop ladder, and its end reports
// stopped. A run in an ask check, in the resume gate or in its spawn is
// marked, and the next step of that run reports stopped. The answer is done
// once the stop is under way, and does not wait for the worker to exit.
func (r *router) stopRun(c api.Command) (state, reason string) {
	r.quiesce.RLock()
	defer r.quiesce.RUnlock()
	r.mu.Lock()
	switch {
	case r.frozen:
		r.mu.Unlock()

		return api.CommandRefused, handingOver
	case r.stops[c.RunKey]:
		r.mu.Unlock()

		return api.CommandDone, ""
	}
	if _, ok := r.held[c.RunKey]; !ok {
		r.mu.Unlock()

		return api.CommandRefused, noOpenRun
	}
	r.holdCardLocked(c.CardID)
	if r.stops == nil {
		r.stops = map[string]bool{}
	}
	r.stops[c.RunKey] = true
	var dropped []pending
	if i := slices.IndexFunc(r.queue, func(q pending) bool { return q.runID == c.RunKey }); i >= 0 {
		p := r.queue[i]
		r.queue = slices.Delete(r.queue, i, i+1)
		// A checked resume holds its card key already.
		r.closeStoppedLocked(p, api.RunStateReport{State: api.RunStopped}, p.checked)
		dropped = r.dispatchLocked()
	} else if run, ok := r.live[c.RunKey]; ok {
		r.stopLiveLocked(run)
	}
	r.mu.Unlock()
	r.logDropped(dropped)

	return api.CommandDone, ""
}

// stopLiveLocked reports a live worker as stopping and starts its ladder. The
// caller holds mu and marked the run.
func (r *router) stopLiveLocked(run liveRun) {
	r.log.Info("worker_stopping", append(about(run.p.event, run.p.rule), "pid", run.proc.pid)...)
	r.emitLocked(run.p, api.RunStateReport{State: api.RunStopping})
	r.wg.Add(1)
	go r.ladder(run, r.stopWaits, r.stoppedLocked())
}

// ladder sends SIGINT to the process group of a worker, SIGTERM after the
// first wait, and SIGKILL after the second. It ends early when the group is
// gone or the bridge shuts down, since the shutdown kills every group.
func (r *router) ladder(run liveRun, waits stopWaits, stopped <-chan struct{}) {
	defer r.wg.Done()

	signal, after := r.signal, r.stopAfter
	if signal == nil {
		signal = signalGroup
	}
	if after == nil {
		after = time.After
	}
	steps := []struct {
		sig  stopSignal
		wait time.Duration
	}{{stopInt, cmp.Or(waits.term, defaultStopTerm)}, {stopTerm, cmp.Or(waits.kill, defaultStopKill)}, {stopKill, 0}}
	ended := run.ended
	for _, step := range steps {
		err := signal(run.proc.pid, step.sig)
		if errors.Is(err, errGroupGone) {
			return
		}
		if err != nil {
			r.log.Warn("stop_signal_failed", append(about(run.p.event, run.p.rule), "pid", run.proc.pid, "signal", step.sig.String(), "error", err.Error())...)

			return
		}
		r.log.Info("stop_signal_sent", append(about(run.p.event, run.p.rule), "pid", run.proc.pid, "signal", step.sig.String())...)
		if step.sig == stopKill {
			return
		}
		timer := after(step.wait)
	wait:
		for {
			select {
			case <-timer:
				break wait
			case <-ended:
				// The worker exited. Its tools can outlive it in the group.
				if errors.Is(signal(run.proc.pid, stopProbe), errGroupGone) {
					return
				}
				ended = nil
			case <-stopped:
				return
			case <-r.workerContext().Done():
				return
			}
		}
	}
}

// closeStoppedLocked sends the report that closes a stopped run, and frees
// its card key when the run holds it. The caller holds mu.
func (r *router) closeStoppedLocked(p pending, report api.RunStateReport, holdsKey bool) {
	delete(r.stops, p.runID)
	if holdsKey {
		delete(r.running, p.key)
	}
	r.log.Info("worker_stopped", about(p.event, p.rule)...)
	r.emitLocked(p, report)
}

// stoppedReport is the stopped report of a worker that ended. It keeps the
// output and the usage, and carries no exit code and no result.
func (r *router) stoppedReport(p pending, e endedRun) api.RunStateReport {
	report := api.RunStateReport{
		State:     api.RunStopped,
		SessionID: p.spec.sessionID,
		StartedAt: e.began,
		EndedAt:   e.began.Add(e.elapsed),
		Output:    e.res.output,
	}
	if e.res.err == nil {
		report.Usage = r.usage(p, e.res.usage)
	}

	return report
}

// holdCardLocked holds a card, so it starts no worker. The caller holds mu.
func (r *router) holdCardLocked(cardID string) {
	if cardID == "" {
		return
	}
	if r.cardHolds == nil {
		r.cardHolds = map[string]bool{}
	}
	r.cardHolds[cardID] = true
}

// heldLocked reports whether the card of the event is held. The caller
// holds mu.
func (r *router) heldLocked(e event.Event) bool {
	id, _ := cardOf(e)

	return id != "" && r.cardHolds[id]
}

// releaseHold ends the hold of a card, and starts its queued runs.
func (r *router) releaseHold(cardID string) {
	r.mu.Lock()
	if !r.cardHolds[cardID] {
		r.mu.Unlock()

		return
	}
	delete(r.cardHolds, cardID)
	r.mu.Unlock()

	r.log.Info("card_hold_released", "card_id", cardID)
	r.dispatch()
}

// noteCardHold keeps the hold the server states for the card of the event. An
// event with no held key changes nothing.
func (r *router) noteCardHold(e event.Event) {
	id, _ := cardOf(e)
	if id == "" || e.Card.Held == nil {
		return
	}
	if !*e.Card.Held {
		r.releaseHold(id)

		return
	}
	r.mu.Lock()
	r.holdCardLocked(id)
	r.mu.Unlock()
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
			stored, err := r.ackCommand(ctx, r.bridgeID, c.CommandID, state, reason)
			switch {
			case err == nil:
				r.log.Info("command_acked", "command", c.CommandID, "kind", c.Kind, "state", state)
				// A command that expired or was cancelled first keeps its own state.
				if stored != "" && stored != state {
					r.log.Warn("command_ack_state", "command", c.CommandID, "kind", c.Kind, "state", state, "stored", stored)
				}
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
