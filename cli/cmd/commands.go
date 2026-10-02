package cmd

import (
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"maps"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/outbound"
	"github.com/ubermuda/loupe/cli/internal/rules"
	"github.com/ubermuda/loupe/cli/internal/transcript"
)

// The channels a command arrives on, as command_received names them.
const (
	commandFromEvent     = "event"
	commandFromHeartbeat = "heartbeat"
)

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
		// Under mu, so shutdown never waits on wg before this Add, and a drain
		// never misses the handler.
		r.wg.Add(1)
		r.commanding++
	}
	r.mu.Unlock()
	if reason != "" {
		// A heartbeat brings each command again until the bridge answers it.
		level := slog.LevelInfo
		if source == commandFromHeartbeat && (reason == "duplicate" || reason == "expired") {
			level = slog.LevelDebug
		}
		r.log.Log(context.Background(), level, "command_dropped", append(attrs, "reason", reason)...)

		return
	}

	r.log.Info("command_received", attrs...)
	go func() {
		defer r.wg.Done()

		state, why := r.handleCommand(c)
		r.sendAck(c, state, why)
		r.mu.Lock()
		r.commanding--
		r.mu.Unlock()
	}()
}

// commandAttrs names a command in a log line.
func commandAttrs(c api.Command, source string) []any {
	return []any{"command", c.CommandID, "kind", c.Kind, "card", c.CardNumber, "project", c.ProjectID, "rule", c.RuleName, "run_key", c.RunKey, "source", source}
}

// handleCommand runs the handler of the command's kind, and returns the
// state and the reason of its answer.
func (r *router) handleCommand(c api.Command) (string, string) {
	switch c.Kind {
	case api.CommandStopRun:
		return r.stopRun(c)
	case api.CommandRerunCommand:
		return r.rerunCommand(c)
	}

	return r.resumeRun(c)
}

// The answers of a stop the bridge cannot act on. A resume in a handover
// takes handingOver too.
const (
	noOpenRun   = "The bridge holds no open run with this key."
	handingOver = "The bridge is handing over to a new version. Send the command again."
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

// stopRun stops the run a command names. A queued run closes at once. A live
// worker gets the stop ladder, and its end reports stopped. A run in an ask
// check, in the resume gate or in its spawn is marked, and the next step of
// that run reports stopped. The answer is done once the stop is under way. With
// an older server it also holds the card.
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
	if !r.holdList {
		r.holdCardLocked(c.CardID)
	}
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
		SessionID: sessionOf(p, e),
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
	if r.dropHold(cardID) {
		r.dispatch()
	}
}

// dropHold ends the hold of a card and starts nothing. It reports whether the
// card was held.
func (r *router) dropHold(cardID string) bool {
	r.mu.Lock()
	if !r.cardHolds[cardID] {
		r.mu.Unlock()

		return false
	}
	delete(r.cardHolds, cardID)
	r.mu.Unlock()
	r.log.Info("card_hold_released", "card_id", cardID)

	return true
}

// noteCardHold keeps the hold the server states for the card of the event. A
// hold event or a held key states it, and another event changes nothing. A
// column delete ends the hold of each card it moved, as the server does. It
// reports whether the event ended a hold, and starts nothing, so the event can
// replace a stale queued run first.
func (r *router) noteCardHold(e event.Event) bool {
	switch e.Type {
	case event.CardReleasedType:
		return r.dropHold(e.Subject.ID)
	case event.CardHeldType:
		r.mu.Lock()
		r.holdCardLocked(e.Subject.ID)
		r.mu.Unlock()

		return false
	case event.ColumnDeletedType:
		released := false
		for _, id := range e.MovedCardIDs {
			released = r.dropHold(strings.ToLower(id)) || released
		}

		return released
	}
	id, _ := cardOf(e)
	if id == "" || e.Card.Held == nil {
		return false
	}
	if !*e.Card.Held {
		return r.dropHold(id)
	}
	r.mu.Lock()
	r.holdCardLocked(id)
	r.mu.Unlock()

	return false
}

// The answers of a resume the bridge cannot act on.
const (
	noSession       = "The run has no session to resume."
	noWorkerRule    = "The rule of the run no longer runs workers on this bridge."
	cardMovedAway   = "The card left the column of the run."
	noTranscript    = "The session of the run is not on the machine of this bridge."
	bridgeShutting  = "The bridge is shutting down."
	runOpen         = "The run is still open."
	resumingAlready = "The bridge resumes this run already."
)

// resumeRun queues a resume of the session of a run that ended, as its next
// run. The resume waits for the card and a worker slot, and a pause keeps it
// queued. A held card passes, and the resume waits until the hold ends. With
// an older server the resume ends the hold. The automatic resumes of the new
// run count from zero again.
func (r *router) resumeRun(c api.Command) (state, reason string) {
	if c.SessionID == "" {
		return api.CommandRefused, noSession
	}
	if r.readCard != nil {
		timeout := r.checkTimeout
		if timeout <= 0 {
			timeout = askCheckTimeout
		}
		ctx, cancel := context.WithTimeout(r.workerContext(), timeout)
		card, err := r.readCard(ctx, c.ProjectID, c.CardID)
		cancel()
		if err != nil {
			return api.CommandRefused, "The bridge could not read the card: " + err.Error()
		}
		// A run of a pull request event records no column, so it has none to leave.
		if c.CardColumn != "" && card.Column != c.CardColumn {
			return api.CommandRefused, cardMovedAway
		}
	}
	find := r.findTranscript
	if find == nil {
		find = findTranscript
	}
	if err := find(c.SessionID); err != nil {
		return api.CommandRefused, noTranscript
	}

	e := event.Event{
		Type: event.CommandType, Subject: event.Subject{Type: "card", ID: c.CardID}, ProjectID: c.ProjectID,
		CardNumber: c.CardNumber, SessionID: c.SessionID, Actor: event.ActorHuman,
	}
	r.quiesce.RLock()
	defer r.quiesce.RUnlock()
	r.mu.Lock()
	current := r.rules()
	m, ok := matchWorker(current, e, c.RuleName)
	_, held := r.held[c.RunKey]
	_, live := r.live[c.RunKey]
	continued := slices.ContainsFunc(r.queue, func(p pending) bool { return p.continues == c.RunKey })
	for _, run := range r.live {
		continued = continued || run.p.continues == c.RunKey
	}
	switch {
	case r.frozen:
		reason = handingOver
	case r.shut():
		reason = bridgeShutting
	case !ok:
		reason = noWorkerRule
	case held || live:
		reason = runOpen
	case continued:
		reason = resumingAlready
	}
	if reason != "" {
		r.mu.Unlock()

		return api.CommandRefused, reason
	}

	p := pending{
		key: keyFor(e), event: e, set: current, runID: config.NewUUID(), continues: c.RunKey, column: c.CardColumn,
		spec: workerSpec{resume: true, sessionID: c.SessionID, prompt: directive.RenderResumeByPerson()},
	}
	p.apply(m)
	if c.ResumeIndex != nil {
		p.resumeIndex = *c.ResumeIndex
	}
	p.resumeIndex++
	p.maxResumes = p.resumeIndex + m.MaxResumes
	if r.sessions == nil {
		r.sessions = map[string]sessionCard{}
	}
	r.sessions[c.SessionID] = sessionCard{key: p.key, id: c.CardID, number: c.CardNumber, column: c.CardColumn}
	r.seq++
	p.seq = r.seq
	r.queue = append(r.queue, p)
	r.log.Info("worker_resume_asked", append(about(e, p.rule),
		"worker_pool", p.pool, "session_id", c.SessionID, "resume", p.resumeIndex, "max_resumes", p.maxResumes, "continues", c.RunKey,
	)...)
	r.emitLocked(p, api.RunStateReport{State: api.RunQueued})
	dropped := r.dispatchLocked()
	legacy := !r.holdList
	r.mu.Unlock()
	r.logDropped(dropped)
	if legacy {
		r.releaseHold(c.CardID)
	}

	return api.CommandDone, ""
}

// The answers of a rerun the bridge cannot act on.
const (
	noCommandRule = "The rule of the run no longer runs a command on this bridge."
	cardBusy      = "The card has a run that is still open on this bridge."
	needsEvent    = "The command of the rule needs values that only its first event held:"
)

// rerunCommand queues the command of a failed command run again, as a new run
// that continues it. It refuses while the card has a run that holds it or
// waits in the queue, the rerun of this run included. A held card passes, and
// the rerun waits until the hold ends. With an older server the rerun ends the
// hold.
func (r *router) rerunCommand(c api.Command) (state, reason string) {
	e := event.Event{
		Type: event.CommandType, Subject: event.Subject{Type: "card", ID: c.CardID}, ProjectID: c.ProjectID,
		CardNumber: c.CardNumber, Actor: event.ActorHuman,
	}
	key := keyFor(e)
	r.quiesce.RLock()
	defer r.quiesce.RUnlock()
	r.mu.Lock()
	current := r.rules()
	m, ok := matchAction(current, e, c.RuleName, rules.ActionCommand)
	queued := slices.ContainsFunc(r.queue, func(p pending) bool { return p.key == key })
	switch {
	case r.frozen:
		reason = handingOver
	case r.shut():
		reason = bridgeShutting
	case !ok:
		reason = noCommandRule
	case r.running[key] || queued:
		reason = cardBusy
	}
	// A value of the first event would render empty, so the command would differ.
	if gaps := current.RerunGaps(c.RuleName); reason == "" && len(gaps) > 0 {
		reason = needsEvent + " {" + strings.Join(gaps, "} {") + "}"
	}
	if reason != "" {
		r.mu.Unlock()

		return api.CommandRefused, reason
	}

	p := pending{key: key, event: e, set: current, runID: config.NewUUID(), continues: c.RunKey, column: c.CardColumn}
	p.apply(m)
	if c.ResumeIndex != nil {
		p.resumeIndex = *c.ResumeIndex
	}
	// A command run never resumes on its own, so its cap is its own place.
	p.resumeIndex++
	p.maxResumes = p.resumeIndex
	r.seq++
	p.seq = r.seq
	r.queue = append(r.queue, p)
	r.log.Info("command_rerun_asked", append(about(e, p.rule), "continues", c.RunKey)...)
	r.emitLocked(p, api.RunStateReport{State: api.RunQueued})
	dropped := r.dispatchLocked()
	legacy := !r.holdList
	r.mu.Unlock()
	r.logDropped(dropped)
	if legacy {
		r.releaseHold(c.CardID)
	}

	return api.CommandDone, ""
}

// findTranscript fails when the Claude Code config directory holds no
// transcript of the session.
func findTranscript(sessionID string) error {
	dir, err := transcript.ConfigDir()
	if err != nil {
		return err
	}
	_, err = transcript.Find(dir, sessionID)

	return err
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
// logged and sent with the next heartbeat, and an unpause starts the queued
// runs. The first reply writes the cache even with no change, so a broken or
// missing cache heals, and each change writes it again.
func (r *router) setPersonPause(paused bool) {
	r.mu.Lock()
	changed := r.personPaused != paused
	if !changed && r.pauseSynced {
		r.mu.Unlock()

		return
	}
	r.personPaused, r.pauseSynced = paused, true
	hb := r.heartbeat
	r.mu.Unlock()

	if r.pauseFile != "" {
		if err := r.writePause(paused); err != nil {
			r.log.Warn("pause_cache_failed", "path", r.pauseFile, "error", err.Error())
		}
	}
	if !changed {
		return
	}
	if paused {
		r.log.Info("bridge_paused", "source", "server")
	} else {
		r.log.Info("bridge_unpaused", "source", "server")
	}
	if hb != nil {
		hb.setPaused(paused)
	}
	if !paused {
		r.dispatch()
	}
}

// usePauseCache takes the path of the pause cache and reads it. With no path
// the bridge runs with no cache.
func (r *router) usePauseCache(path string, err error) {
	if err != nil {
		r.log.Warn("pause_cache_unavailable", "error", err.Error())

		return
	}
	r.pauseFile = path
	r.loadPause()
}

// loadPause reads the cached pause at start, before any dispatch. A missing
// cache leaves the bridge free, and so does one it cannot read or one that
// another bridge or server wrote.
func (r *router) loadPause() {
	if r.pauseFile == "" {
		return
	}
	paused, err := r.readPause()
	switch {
	case errors.Is(err, os.ErrNotExist):
		return
	case errors.Is(err, errOtherPause):
		r.log.Info("pause_cache_ignored", "path", r.pauseFile)

		return
	case err != nil:
		r.log.Warn("pause_cache_unreadable", "path", r.pauseFile, "error", err.Error())

		return
	}
	r.mu.Lock()
	r.personPaused = paused
	r.mu.Unlock()
	if paused {
		r.log.Info("bridge_paused", "source", "cache")
	}
}

// pauseCache is what the pause cache holds. The server keys a pause by the
// bridge id and holds it on one server, so the cache names both.
type pauseCache struct {
	Paused   bool   `json:"paused"`
	BridgeID string `json:"bridgeId"`
	BaseURL  string `json:"baseUrl"`
}

// errOtherPause is a cache that another bridge or another server wrote.
var errOtherPause = errors.New("the pause cache names another bridge or server")

// pausePath names the pause cache in the config directory.
func pausePath() (string, error) {
	dir, err := config.Dir()
	if err != nil {
		return "", err
	}

	return filepath.Join(dir, "pause.json"), nil
}

func (r *router) readPause() (bool, error) {
	b, err := os.ReadFile(r.pauseFile)
	if err != nil {
		return false, err
	}
	var c pauseCache
	if err := json.Unmarshal(b, &c); err != nil {
		return false, err
	}
	if !strings.EqualFold(c.BridgeID, r.bridgeID) || c.BaseURL != r.baseURL {
		return false, errOtherPause
	}

	return c.Paused, nil
}

func (r *router) writePause(paused bool) error {
	b, err := json.Marshal(pauseCache{Paused: paused, BridgeID: r.bridgeID, BaseURL: r.baseURL})
	if err != nil {
		return err
	}

	return writeAtomic(r.pauseFile, ".pause-*", b)
}
