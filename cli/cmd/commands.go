package cmd

import (
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"fmt"
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

// onHeartbeatReply applies the pause and takes the commands and the work
// offers of one heartbeat reply. The heartbeater hands the lost claims to
// loseClaims first. It runs on the goroutine of the heartbeat lane, and a
// handler or a claim runs on a goroutine of its own, so it never waits on one.
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
	for _, w := range reply.WorkRequests {
		if err := event.CheckWorkRequest(&w); err != nil {
			r.log.Warn("work_request_dropped", "reason", "malformed", "source", workFromHeartbeat, "work_request", w.WorkRequestID, "error", err.Error())

			continue
		}
		r.takeWork(w, workFromHeartbeat)
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
	return []any{"command", c.CommandID, "kind", c.Kind, "subject_type", c.SubjectType, "card", c.CardNumber, "project", c.ProjectID, "rule", commandRule(c), "run_key", c.RunKey, "source", source}
}

// commandRule is the rule name of the run a command names. A run of a rules:
// entry names no work kind, so it has none, and no rule matches it.
func commandRule(c api.Command) string {
	if c.WorkKind == "" {
		return ""
	}

	return rules.WorkRulePrefix + c.WorkKind
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
		r.holdCardLocked(commandCard(c))
	}
	if r.stops == nil {
		r.stops = map[string]bool{}
	}
	r.stops[c.RunKey] = true
	var dropped []pending
	if i := slices.IndexFunc(r.queue, func(q pending) bool { return q.runID == c.RunKey }); i >= 0 {
		p := r.queue[i]
		r.queue = slices.Delete(r.queue, i, i+1)
		r.closeStoppedLocked(p, api.RunStateReport{State: api.RunStopped}, false)
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

// releaseHold ends the hold of a card and starts its queued runs, until the
// bridge reads the held list once. The list then owns every hold.
func (r *router) releaseHold(cardID string) {
	r.mu.Lock()
	held := !r.holdList && r.cardHolds[cardID]
	if held {
		delete(r.cardHolds, cardID)
	}
	r.mu.Unlock()
	if held {
		r.log.Info("card_hold_released", "card_id", cardID)
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

// noteCardHold keeps the hold that a hold event states for its card. It
// reports whether the event ended a hold, and starts nothing.
func (r *router) noteCardHold(e event.Event) bool {
	switch e.Type {
	case event.CardReleasedType:
		return r.dropHold(e.Subject.ID)
	case event.CardHeldType:
		r.mu.Lock()
		r.holdCardLocked(e.Subject.ID)
		r.mu.Unlock()
	}

	return false
}

// The answers of a resume the bridge cannot act on.
const (
	noSession       = "The run has no session to resume."
	noWorkerRule    = "The work map of this bridge no longer runs workers of the kind of the run."
	noTranscript    = "The session of the run is not on the machine of this bridge."
	bridgeShutting  = "The bridge is shutting down."
	runOpen         = "The run is still open."
	resumingAlready = "The bridge resumes this run already."
	accountGone     = "The account %s that the run started on is no longer in rules.yaml."
	accountHarness  = "The account %s that the run started on now names the harness %s, and the run started on %s."
)

// runStart is the harness, the account and the model a resumed run started
// on. A run of an older bridge or server names no account.
type runStart struct {
	Harness string `json:"harness,omitempty"`
	Account string `json:"account,omitempty"`
	Model   string `json:"model,omitempty"`
}

func runStartOf(c api.Command) runStart {
	return runStart{Harness: c.Harness, Account: c.Account, Model: c.Model}
}

// settings gives r on the account the run started on, with the model it
// started with. reason says why the run cannot resume there. With no account,
// r stays as the rule gives it.
func (o runStart) settings(set *rules.Set, r rules.RunSettings) (rules.RunSettings, string) {
	if o.Account == "" {
		return r, ""
	}
	a, ok := set.Account(o.Account, r.Permissions)
	if !ok {
		return r, fmt.Sprintf(accountGone, o.Account)
	}
	if o.Harness != "" && a.Harness != o.Harness {
		return r, fmt.Sprintf(accountHarness, o.Account, a.Harness, o.Harness)
	}
	a.Model = cmp.Or(o.Model, a.Model)

	return a, ""
}

// resumeRun queues a resume of the session of a run that ended, as its next
// run. The work entry of the run's kind says how the run starts. The resume
// waits for the card and a worker slot, and a pause keeps it queued. A held
// card passes, and the resume waits until the hold ends. With an older server
// the resume ends the hold. The new run never resumes on its own, because the
// server decides each resume.
func (r *router) resumeRun(c api.Command) (state, reason string) {
	if c.SessionID == "" {
		return api.CommandRefused, noSession
	}
	if r.readCard != nil && c.SubjectType == api.SubjectCard {
		timeout := r.checkTimeout
		if timeout <= 0 {
			timeout = readTimeout
		}
		ctx, cancel := context.WithTimeout(r.workerContext(), timeout)
		_, err := r.readCard(ctx, c.ProjectID, c.SubjectID)
		cancel()
		if err != nil {
			return api.CommandRefused, "The bridge could not read the card: " + err.Error()
		}
	}
	started := runStartOf(c)
	pre, matched := matchCommandWork(r.rules(), c, "")
	run, reason := started.settings(r.rules(), pre.Run())
	if matched && reason != "" {
		return api.CommandRefused, reason
	}
	if !r.hasTranscript(c.SessionID, run) {
		return api.CommandRefused, noTranscript
	}

	e := commandEvent(c)
	e.SessionID = c.SessionID
	r.quiesce.RLock()
	defer r.quiesce.RUnlock()
	r.mu.Lock()
	current := r.rules()
	m, ok := matchCommandWork(current, c, "")
	_, onAccount := started.settings(current, m.Run())
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
	case onAccount != "":
		reason = onAccount
	case held || live:
		reason = runOpen
	case continued:
		reason = resumingAlready
	}
	if reason != "" {
		r.mu.Unlock()

		return api.CommandRefused, reason
	}

	prompt := directive.RenderResumeByPerson()
	if c.Cause == api.CauseAskClosed {
		prompt = directive.RenderResumeAskClosed()
	}
	p := pending{
		key: keyFor(e), event: e, set: current, runID: config.NewUUID(), continues: c.RunKey, startedOn: started, origin: commandWork(c),
		spec: workerSpec{resume: true, sessionID: c.SessionID, prompt: prompt},
	}
	p.apply(m)
	if r.sessions == nil {
		r.sessions = map[string]sessionCard{}
	}
	r.sessions[c.SessionID] = sessionCard{key: p.key, id: commandCard(c), number: c.CardNumber}
	r.seq++
	p.seq = r.seq
	r.queue = append(r.queue, p)
	r.log.Info("worker_resume_asked", append(about(e, p.rule),
		"worker_pool", p.pool, "session_id", c.SessionID, "continues", c.RunKey,
	)...)
	r.emitLocked(p, api.RunStateReport{State: api.RunQueued})
	dropped := r.dispatchLocked()
	r.mu.Unlock()
	r.logDropped(dropped)
	r.releaseHold(commandCard(c))

	return api.CommandDone, ""
}

// The answers of a rerun the bridge cannot act on.
const (
	noCommandRule = "The work map of this bridge no longer runs a command of the kind of the run."
	cardBusy      = "The card has a run that is still open on this bridge."
	needsEvent    = "The command of the work entry needs values that the run does not hold:"
)

// rerunCommand queues the command of a failed command run again, as a new run
// that continues it. It refuses while the card has a run that holds it or
// waits in the queue, the rerun of this run included. A held card passes, and
// the rerun waits until the hold ends. With an older server the rerun ends the
// hold.
func (r *router) rerunCommand(c api.Command) (state, reason string) {
	e := commandEvent(c)
	key := keyFor(e)
	r.quiesce.RLock()
	defer r.quiesce.RUnlock()
	r.mu.Lock()
	current := r.rules()
	m, ok := matchCommandWork(current, c, rules.ActionCommand)
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
	// A value the run lacks would render empty, so the command would differ.
	if gaps := current.WorkGaps(commandWork(c)); reason == "" && len(gaps) > 0 {
		reason = needsEvent + " {" + strings.Join(gaps, "} {") + "}"
	}
	if reason != "" {
		r.mu.Unlock()

		return api.CommandRefused, reason
	}

	p := pending{key: key, event: e, set: current, runID: config.NewUUID(), continues: c.RunKey, origin: commandWork(c)}
	p.apply(m)
	r.seq++
	p.seq = r.seq
	r.queue = append(r.queue, p)
	r.log.Info("command_rerun_asked", append(about(e, p.rule), "continues", c.RunKey)...)
	r.emitLocked(p, api.RunStateReport{State: api.RunQueued})
	dropped := r.dispatchLocked()
	r.mu.Unlock()
	r.logDropped(dropped)
	r.releaseHold(commandCard(c))

	return api.CommandDone, ""
}

// matchCommandWork matches the run a command names against the work map by
// its kind, and only while the entry keeps the action of the run. A run of a
// rules: entry names no kind, so nothing matches it.
func matchCommandWork(set *rules.Set, c api.Command, action string) (rules.Match, bool) {
	if c.WorkKind == "" {
		return rules.Match{}, false
	}
	m := set.MatchKind(commandWork(c))

	return m, m.Skip == rules.Run && m.Action == action
}

// commandWork is the work of the run a command names, as far as the command
// carries it.
func commandWork(c api.Command) api.WorkRequest {
	return api.WorkRequest{
		Type: event.WorkRequestType, ProjectID: c.ProjectID, Subject: api.WorkRequestSubject{Type: "work-request", ID: c.WorkRequestID},
		WorkRequestID: c.WorkRequestID, Kind: c.WorkKind, SubjectType: c.SubjectType, SubjectID: c.SubjectID,
		CardNumber: c.CardNumber, RuleID: c.RuleID, Context: c.Context,
	}
}

// commandEvent is the event a person's resume or rerun runs under. Its
// subject is the subject of the run, so the run keeps the key it had.
func commandEvent(c api.Command) event.Event {
	return event.Event{
		Type: event.CommandType, Subject: event.Subject{Type: c.SubjectType, ID: c.SubjectID}, ProjectID: c.ProjectID,
		CardNumber: c.CardNumber, Actor: event.ActorHuman,
	}
}

// commandCard is the card of the run a command names, and "" for a run
// about another subject.
func commandCard(c api.Command) string {
	if c.SubjectType != api.SubjectCard {
		return ""
	}

	return c.SubjectID
}

// hasTranscript reports whether the account of run holds the transcript of
// the session on this machine, which a resume needs.
func (r *router) hasTranscript(sessionID string, run rules.RunSettings) bool {
	find := r.findTranscript
	if find == nil {
		find = harnessOf(run.Harness, run.ConfigDir).HasSession
	}

	return find(sessionID) == nil
}

// sendAck hands the answer to the report queue, which retries a failure. A
// command the server no longer holds counts as settled.
func (r *router) sendAck(c api.Command, state, reason string) {
	if r.ackCommand == nil || r.reports == nil || r.bridgeID == "" {
		return
	}
	r.reports.Enqueue(outbound.Report{
		Card: c.CardNumber,
		Rule: commandRule(c),
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
