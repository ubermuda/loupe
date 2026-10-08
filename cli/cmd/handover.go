package cmd

import (
	"cmp"
	"context"
	"encoding/json"
	"fmt"
	"maps"
	"os"
	"path/filepath"
	"slices"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// handoverFormat is the one layout of the handover file this build reads.
const handoverFormat = 2

// drainTimeout bounds the wait for the reports and ask checks in flight.
const drainTimeout = 10 * time.Second

// recentLimit bounds the event ids the router remembers for dedup.
const recentLimit = 512

// handoverState is the routing state one image of the bridge hands to the next
// across an exec. LockFD and ControlFD name the inherited descriptors of the
// lock file at LockPath and of the control socket, and OldVersion and
// OldBinary the image that froze it, for a rollback. NewVersion is the image
// the exec runs, which a recovery skips when it died before its health. Holds
// lists the cards the server states as held.
type handoverState struct {
	Format   int                         `json:"format"`
	Queue    []handoverPending           `json:"queue"`
	Running  map[string]bool             `json:"running"`
	Sessions map[string]handoverSession  `json:"sessions"`
	Held     map[string]api.InventoryRun `json:"held"`
	Holds    []string                    `json:"holds,omitempty"`
	// PersonPaused is the pause of a person, and nil from an older image.
	PersonPaused *bool         `json:"personPaused,omitempty"`
	Live         []handoverRun `json:"live"`
	LastEventID  string        `json:"lastEventId"`
	RecentIDs    []string      `json:"recentIds"`
	Seq          uint64        `json:"seq"`
	LockFD       int           `json:"lockFd"`
	ControlFD    int           `json:"controlFd"`
	LockPath     string        `json:"lockPath"`
	OldVersion   string        `json:"oldVersion"`
	OldBinary    string        `json:"oldBinary"`
	NewVersion   string        `json:"newVersion,omitempty"`
	// WorkClaims are the work requests the bridge holds, so the next image
	// renews their leases. An older image writes none.
	WorkClaims []handoverClaim `json:"workClaims,omitempty"`
}

// handoverClaim is one claim of a work request, with the run it belongs to.
type handoverClaim struct {
	ID      string          `json:"id"`
	Token   string          `json:"token"`
	RunID   string          `json:"runId,omitempty"`
	Request api.WorkRequest `json:"request"`
	Running bool            `json:"running,omitempty"`
}

// handoverPending is a queued event. The adopter matches it again against its
// own rule set by rule name, as a reload does, to rebuild the prompt. A resume
// of an unfinished run keeps its session and its prompt instead.
type handoverPending struct {
	Event      event.Event `json:"event"`
	Rule       string      `json:"rule"`
	Key        string      `json:"key"`
	RunID      string      `json:"runId"`
	Seq        uint64      `json:"seq"`
	DropReason string      `json:"dropReason,omitempty"`
	Fresh      bool        `json:"fresh,omitempty"`
	handoverSeries
	SessionID string `json:"sessionId,omitempty"`
	Prompt    string `json:"prompt,omitempty"`
	Pool      string `json:"pool,omitempty"`
	// Action is the action of the rule, and empty for a worker.
	Action string `json:"action,omitempty"`
	// Work is the work request of a queued offer, and Origin the work of the
	// run a person's resume or rerun continues.
	Work   *api.WorkRequest `json:"work,omitempty"`
	Origin *api.WorkRequest `json:"origin,omitempty"`
}

// handoverSeries names the run that a person's resume or rerun continues.
type handoverSeries struct {
	Continues string   `json:"continues,omitempty"`
	StartedOn runStart `json:"startedOn,omitzero"`
}

func seriesOf(p pending) handoverSeries {
	return handoverSeries{Continues: p.continues, StartedOn: p.startedOn}
}

func (s handoverSeries) applyTo(p *pending) {
	p.continues, p.startedOn = s.Continues, s.StartedOn
}

type handoverSession struct {
	Key        string `json:"key"`
	CardID     string `json:"cardId,omitempty"`
	CardNumber int    `json:"cardNumber,omitempty"`
}

// handoverRun is a worker in flight, with what its report needs.
type handoverRun struct {
	RunID     string      `json:"runId"`
	Key       string      `json:"key"`
	Rule      string      `json:"rule"`
	Event     event.Event `json:"event"`
	SessionID string      `json:"sessionId"`
	Began     time.Time   `json:"began"`
	PID       int         `json:"pid"`
	Dir       string      `json:"dir"`
	Seq       uint64      `json:"seq,omitempty"`
	// Resume says the run resumed the session its event names, and Fresh that
	// it replaced such a resume. A missing session reads both.
	Resume bool `json:"resume,omitempty"`
	Fresh  bool `json:"fresh,omitempty"`
	// Pool is the pool the run took its slot from. An older image writes none.
	Pool string `json:"pool,omitempty"`
	handoverSeries
	// runPin is the variant of a run in an experiment, which its outcome names.
	runPin
	// Phase is phaseBefore while the rule's before command runs, with what
	// claude takes once it ends, and phaseCommand for the command of a command
	// rule. An older image writes no phase.
	Phase  string `json:"phase,omitempty"`
	Prompt string `json:"prompt,omitempty"`
	Schema string `json:"schema,omitempty"`
	// The run settings of an agent run. An older image writes them only in
	// the before phase, and writes no account, which reads as account "".
	PermissionMode string   `json:"permissionMode,omitempty"`
	Model          string   `json:"model,omitempty"`
	Effort         string   `json:"effort,omitempty"`
	Harness        string   `json:"harness,omitempty"`
	Account        string   `json:"account,omitempty"`
	ConfigDir      string   `json:"configDir,omitempty"`
	EnvFiles       []string `json:"envFiles,omitempty"`
	// Work is the work request of the run, and ClaimToken its claim. Origin
	// is the work of the run a person's resume or rerun continues.
	Work       *api.WorkRequest `json:"work,omitempty"`
	ClaimToken string           `json:"claimToken,omitempty"`
	Origin     *api.WorkRequest `json:"origin,omitempty"`
}

// phaseBefore is the phase of a run whose before command runs, and
// phaseCommand the phase of a command run.
const (
	phaseBefore  = "before"
	phaseCommand = "command"
)

// heldEvent is a stream event that arrived after a freeze. replayed marks an
// event of a catch-up.
type heldEvent struct {
	id       string
	data     []byte
	replayed bool
}

// handoverPath names the handover file of the bridge that reads rulesPath. It
// shares the key of the lock, because the lock holder writes it.
func handoverPath(rulesPath string) (string, error) {
	key, err := lockKey(rulesPath)
	if err != nil {
		return "", err
	}

	return bridgeFile("handover-", key, ".json")
}

// writeHandover writes the state through a temporary file and a rename, so a
// reader never sees half of it.
func writeHandover(path string, st handoverState) error {
	b, err := json.Marshal(st)
	if err != nil {
		return err
	}
	if err := writeAtomic(path, ".handover-*", b); err != nil {
		return fmt.Errorf("write handover: %w", err)
	}

	return nil
}

// writeAtomic writes a private file through a temporary file named by pattern
// and a rename.
func writeAtomic(path, pattern string, b []byte) error {
	f, err := os.CreateTemp(filepath.Dir(path), pattern)
	if err != nil {
		return err
	}
	tmp := f.Name()
	defer os.Remove(tmp)
	if err := f.Chmod(0o600); err != nil {
		f.Close()

		return err
	}
	if _, err := f.Write(b); err != nil {
		f.Close()

		return err
	}
	if err := f.Sync(); err != nil {
		f.Close()

		return err
	}
	if err := f.Close(); err != nil {
		return err
	}

	return os.Rename(tmp, path)
}

// readHandover reads a handover file, and refuses a format this build does not
// know rather than guess at its fields.
func readHandover(path string) (handoverState, error) {
	var st handoverState
	b, err := os.ReadFile(path)
	if err != nil {
		return st, fmt.Errorf("read handover: %w", err)
	}
	var probe struct {
		Format int `json:"format"`
	}
	if err := json.Unmarshal(b, &probe); err != nil {
		return st, fmt.Errorf("parse handover %s: %w", path, err)
	}
	if probe.Format != handoverFormat {
		return st, fmt.Errorf("handover %s has format %d, and this bridge reads format %d only", path, probe.Format, handoverFormat)
	}
	if err := json.Unmarshal(b, &st); err != nil {
		return st, fmt.Errorf("parse handover %s: %w", path, err)
	}

	return st, nil
}

// pause stops the router from starting workers and ask checks. Events still
// queue, and the workers that run go on.
func (r *router) pause() {
	r.mu.Lock()
	r.paused = true
	r.mu.Unlock()
}

// resume starts dispatch again after a pause or a freeze. It first settles the
// runs that finished while frozen, then handles the events held back, in order.
func (r *router) resume() {
	r.eventMu.Lock()
	r.mu.Lock()
	r.paused, r.frozen = false, false
	finishes, events := r.heldFinishes, r.heldEvents
	r.heldFinishes, r.heldEvents = nil, nil
	r.mu.Unlock()
	for _, done := range finishes {
		done()
	}
	for _, e := range events {
		r.handleEvent(e.id, e.data, e.replayed)
	}
	r.eventMu.Unlock()

	r.dispatch()
}

// drain waits until no run report waits to go out, no ask check, resume gate,
// launch or command handler runs, no worker is between its spawn and its
// start, and no stopped run waits for its end. A zero timeout is drainTimeout.
// On an error the caller resumes and gives up the handover.
func (r *router) drain(ctx context.Context, timeout time.Duration) error {
	if timeout <= 0 {
		timeout = drainTimeout
	}
	deadline := time.NewTimer(timeout)
	defer deadline.Stop()
	tick := time.NewTicker(20 * time.Millisecond)
	defer tick.Stop()
	for {
		reports, starting, launches, stops, commands := r.inFlight()
		if reports == 0 && starting == 0 && launches == 0 && stops == 0 && commands == 0 {
			return nil
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-deadline.C:
			return fmt.Errorf("after %s the bridge still holds %d run reports, %d starting workers, %d launches, %d stops and %d commands", timeout, reports, starting, launches, stops, commands)
		case <-tick.C:
		}
	}
}

func (r *router) inFlight() (reports, starting, launches, stops, commands int) {
	r.mu.Lock()
	starting, launches, stops, commands = r.usedLocked()+r.commandRuns-len(r.live), r.launching, len(r.stops), r.commanding
	r.mu.Unlock()
	if r.reports != nil {
		reports = r.reports.Pending()
	}

	return reports, starting, launches, stops, commands
}

// freeze takes the routing state for the next image, and holds back what
// happens after it: no dispatch, no event, and no report of a finished run,
// which the next image adopts from its files. resume undoes it. eventMu lets an
// event in flight reach the queue first, and quiesce lets a settling run or a
// drop reach its report.
func (r *router) freeze() handoverState {
	r.eventMu.Lock()
	defer r.eventMu.Unlock()
	r.quiesce.Lock()
	defer r.quiesce.Unlock()
	r.mu.Lock()
	defer r.mu.Unlock()
	r.paused, r.frozen = true, true

	st := handoverState{
		Format:       handoverFormat,
		Running:      maps.Clone(r.running),
		Held:         maps.Clone(r.held),
		Holds:        slices.Sorted(maps.Keys(r.cardHolds)),
		PersonPaused: new(r.personPaused),
		LastEventID:  r.lastEventID,
		RecentIDs:    slices.Clone(r.recent),
		Seq:          r.seq,
	}
	if r.sessions != nil {
		st.Sessions = make(map[string]handoverSession, len(r.sessions))
		for id, s := range r.sessions {
			st.Sessions[id] = handoverSession{Key: s.key, CardID: s.id, CardNumber: s.number}
		}
	}
	for _, p := range r.queue {
		q := handoverPending{
			Event: p.event, Rule: p.rule, Key: p.key, RunID: p.runID, Seq: p.seq, DropReason: p.dropReason, Fresh: p.fresh,
			Pool: p.pool, handoverSeries: seriesOf(p), Action: p.action,
		}
		if p.continues != "" {
			q.SessionID, q.Prompt = p.spec.sessionID, p.spec.prompt
		}
		q.Work, q.Origin = workOf(p), originOf(p)
		st.Queue = append(st.Queue, q)
	}
	for _, run := range r.live {
		h := handoverRun{
			RunID: run.p.runID, Key: run.p.key, Rule: run.p.rule, Event: run.p.event, SessionID: run.p.spec.sessionID,
			Began: run.began, PID: run.proc.pid, Dir: run.proc.dir, Seq: run.p.seq, Resume: run.p.spec.resume, Fresh: run.p.fresh,
			Pool: run.p.slot, handoverSeries: seriesOf(run.p), runPin: run.p.pin,
			Work: workOf(run.p), ClaimToken: run.p.claimToken, Origin: originOf(run.p),
		}
		switch {
		case run.p.isCommand():
			h.Phase = phaseCommand
		case run.before:
			h.Phase, h.Prompt, h.Schema = phaseBefore, run.p.spec.prompt, run.p.spec.schema
		}
		if !run.p.isCommand() {
			s := run.p.spec
			h.PermissionMode, h.Model, h.Effort, h.Harness, h.Account, h.ConfigDir, h.EnvFiles = s.permissionMode, s.model, s.effort, s.harnessName, s.account, s.configDir, s.envFiles
		}
		st.Live = append(st.Live, h)
	}
	slices.SortFunc(st.Live, func(a, b handoverRun) int {
		return cmp.Or(a.Began.Compare(b.Began), cmp.Compare(a.RunID, b.RunID))
	})
	for _, id := range slices.Sorted(maps.Keys(r.claims)) {
		if c := r.claims[id]; c.token != "" {
			st.WorkClaims = append(st.WorkClaims, handoverClaim{ID: id, Token: c.token, RunID: c.runID, Request: c.req, Running: c.running})
		}
	}

	return st
}

// workOf is the work request of p for a handover, and nil for an event's run.
func workOf(p pending) *api.WorkRequest {
	if !p.isWork() {
		return nil
	}
	w := p.work

	return &w
}

// originOf is the work a run of a person's command continues, for a handover.
func originOf(p pending) *api.WorkRequest {
	if !p.followsWork() {
		return nil
	}
	w := p.origin

	return &w
}

// adopt takes the state a former image froze, on a router that has not
// subscribed yet. The queue matches again against this image's rule set, and
// each live run gets a wait that reports it as a finished worker would be.
func (r *router) adopt(st handoverState) {
	r.mu.Lock()
	r.running = maps.Clone(st.Running)
	r.held = maps.Clone(st.Held)
	for _, id := range st.Holds {
		r.holdCardLocked(id)
	}
	if st.PersonPaused != nil {
		r.personPaused = *st.PersonPaused
	}
	r.seq = st.Seq
	r.lastEventID = st.LastEventID
	for _, id := range st.RecentIDs {
		r.rememberLocked(id)
	}
	if st.Sessions != nil {
		r.sessions = make(map[string]sessionCard, len(st.Sessions))
		for id, s := range st.Sessions {
			r.sessions[id] = sessionCard{key: s.Key, id: s.CardID, number: s.CardNumber}
		}
	}
	for _, c := range st.WorkClaims {
		if r.claims == nil {
			r.claims = map[string]*heldClaim{}
		}
		r.claims[c.ID] = &heldClaim{token: c.Token, runID: c.RunID, req: c.Request, running: c.Running}
	}
	r.noteClaimsLocked()
	for _, q := range st.Queue {
		p := pending{
			key: q.Key, rule: q.Rule, event: q.Event, runID: q.RunID, seq: q.Seq, dropReason: q.DropReason, fresh: q.Fresh,
			pool: q.Pool, action: q.Action,
		}
		if q.Work != nil {
			p.work = *q.Work
		}
		if q.Origin != nil {
			p.origin = *q.Origin
		}
		q.applyTo(&p)
		if p.continues != "" {
			p.spec.resume, p.spec.sessionID, p.spec.prompt = true, q.SessionID, q.Prompt
		}
		r.queue = append(r.queue, p)
	}
	dropped := r.rewriteLocked(r.rules())
	for _, run := range st.Live {
		r.adoptLocked(run)
	}
	shutDropped := r.dispatchLocked()
	r.mu.Unlock()

	r.logDropped(dropped, "reason", "handover")
	r.logDropped(shutDropped)
}

// adoptLocked waits for one worker a former image started, on its own
// goroutine. The caller holds mu.
func (r *router) adoptLocked(run handoverRun) {
	p := pending{key: run.Key, rule: run.Rule, event: run.Event, runID: run.RunID, seq: run.Seq, fresh: run.Fresh, pin: run.runPin, claimToken: run.ClaimToken}
	if run.Work != nil {
		p.work = *run.Work
	}
	if run.Origin != nil {
		p.origin = *run.Origin
	}
	run.applyTo(&p)
	p.spec.sessionID, p.spec.resume = run.SessionID, run.Resume
	p.spec.useSettings(rules.RunSettings{
		Account: run.Account, Harness: run.Harness, ConfigDir: run.ConfigDir, EnvFiles: run.EnvFiles,
		Model: run.Model, PermissionMode: run.PermissionMode,
	})
	p.spec.effort = run.Effort
	// A command run takes no slot.
	if run.Phase == phaseCommand {
		p.action = rules.ActionCommand
		r.hold(p.key)
		r.commandRuns++
		r.adoptCommandLocked(p, run)

		return
	}
	p.pool = run.Pool
	if p.pool == "" {
		m, _ := matchPending(r.rules(), p)
		p.pool = cmp.Or(m.Pool, rules.DefaultPool)
	}
	p.slot = p.pool
	r.takeLocked(p.slot)
	r.hold(p.key)
	if run.Phase == phaseBefore {
		r.adoptBeforeLocked(p, run)

		return
	}
	r.trackLocked(liveRun{p: p, began: run.Began, proc: workerProc{pid: run.PID, dir: run.Dir}})
	r.log.Info("worker_adopted", append(about(p.event, p.rule), "worker_pool", p.slot, "session_id", p.spec.sessionID, "pid", run.PID)...)

	wait := r.worker.adopt
	if wait == nil {
		wait = adoptWorker
	}
	r.wg.Add(1)
	go func() {
		defer r.wg.Done()

		res := wait(r.workerContext(), run.Dir)
		r.settle(p, endedRun{res: res, began: run.Began, elapsed: time.Since(run.Began)})
	}()
}

// adoptBeforeLocked waits for the before command of a run a former image
// started, on its own goroutine, then goes on as that image would have. The
// caller holds mu and took the run's slot and card.
func (r *router) adoptBeforeLocked(p pending, run handoverRun) {
	p.spec.prompt, p.spec.schema = run.Prompt, run.Schema
	p.spec.runID, p.spec.rule, p.spec.key = run.RunID, run.Rule, run.Key
	r.trackLocked(liveRun{p: p, began: run.Began, proc: workerProc{pid: run.PID, dir: run.Dir}, before: true})
	r.log.Info("before_adopted", append(about(p.event, p.rule), "worker_pool", p.slot, "session_id", p.spec.sessionID, "pid", run.PID)...)

	wait := r.worker.adoptBefore
	if wait == nil {
		wait = adoptBeforeProc
	}
	r.wg.Add(1)
	go func() {
		defer r.wg.Done()

		r.afterBefore(p, run.Began, wait(r.workerContext(), run.Dir))
	}()
}

// onEvent handles one stream event, unless its id was handled already, as
// across the replay after a handover. After a freeze, it holds the event back.
func (r *router) onEvent(id string, data []byte) {
	r.takeEvent(id, data, false)
}

// takeEvent is onEvent for a stream event or, when replayed, for an event of a
// catch-up.
func (r *router) takeEvent(id string, data []byte, replayed bool) {
	r.eventMu.Lock()
	defer r.eventMu.Unlock()

	r.mu.Lock()
	if r.frozen {
		r.heldEvents = append(r.heldEvents, heldEvent{id: id, data: slices.Clone(data), replayed: replayed})
		r.mu.Unlock()

		return
	}
	r.mu.Unlock()
	r.handleEvent(id, data, replayed)
}

// handleEvent records the id as handled, routes the event and moves the
// cursor. An id a catch-up read counts as handled, because the hub can send it
// again after the catch-up. The caller holds eventMu.
func (r *router) handleEvent(id string, data []byte, replayed bool) {
	if id != "" {
		r.mu.Lock()
		seen := r.recentSet[id] || r.caughtUpSet[id]
		if !seen {
			r.rememberLocked(id)
		}
		if replayed {
			r.rememberCaughtUpLocked(id)
		}
		r.mu.Unlock()
		if seen {
			r.log.Info("event_duplicate", "id", id)

			return
		}
	}
	r.route(data, replayed)
	r.advanceCursor(id, replayed)
}

// onID keeps the stream's resume point.
func (r *router) onID(id string) {
	r.mu.Lock()
	r.lastEventID = id
	r.mu.Unlock()
}

// rememberLocked adds an id to the recent ones, and forgets the oldest past
// recentLimit. While a gap is open it forgets none. The caller holds mu.
func (r *router) rememberLocked(id string) {
	if r.recentSet[id] {
		return
	}
	if r.recentSet == nil {
		r.recentSet = map[string]bool{}
	}
	r.recent = append(r.recent, id)
	r.recentSet[id] = true
	if !r.gap {
		r.trimRecentLocked()
	}
}

// trimRecentLocked forgets the oldest recent ids past recentLimit. The caller
// holds mu.
func (r *router) trimRecentLocked() {
	if over := len(r.recent) - recentLimit; over > 0 {
		for _, id := range r.recent[:over] {
			delete(r.recentSet, id)
		}
		r.recent = slices.Delete(r.recent, 0, over)
	}
}
