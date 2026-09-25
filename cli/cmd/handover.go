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
// the exec runs, which a recovery skips when it died before its health.
type handoverState struct {
	Format      int                         `json:"format"`
	Queue       []handoverPending           `json:"queue"`
	Running     map[string]bool             `json:"running"`
	Chains      map[string]map[string]int   `json:"chains"`
	Sessions    map[string]handoverSession  `json:"sessions"`
	Held        map[string]api.InventoryRun `json:"held"`
	Live        []handoverRun               `json:"live"`
	LastEventID string                      `json:"lastEventId"`
	RecentIDs   []string                    `json:"recentIds"`
	Seq         uint64                      `json:"seq"`
	LockFD      int                         `json:"lockFd"`
	ControlFD   int                         `json:"controlFd"`
	LockPath    string                      `json:"lockPath"`
	OldVersion  string                      `json:"oldVersion"`
	OldBinary   string                      `json:"oldBinary"`
	NewVersion  string                      `json:"newVersion,omitempty"`
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
	Checked    bool        `json:"checked,omitempty"`
	DropReason string      `json:"dropReason,omitempty"`
	handoverSeries
	SessionID string `json:"sessionId,omitempty"`
	Prompt    string `json:"prompt,omitempty"`
}

// handoverSeries is where a run stands in its series of resumes.
type handoverSeries struct {
	Continues   string `json:"continues,omitempty"`
	ResumeIndex int    `json:"resumeIndex,omitempty"`
	MaxResumes  int    `json:"maxResumes,omitempty"`
	Column      string `json:"column,omitempty"`
}

func seriesOf(p pending) handoverSeries {
	return handoverSeries{Continues: p.continues, ResumeIndex: p.resumeIndex, MaxResumes: p.maxResumes, Column: p.column}
}

func (s handoverSeries) applyTo(p *pending) {
	p.continues, p.resumeIndex, p.maxResumes, p.column = s.Continues, s.ResumeIndex, s.MaxResumes, s.Column
}

type handoverSession struct {
	Key        string `json:"key"`
	CardID     string `json:"cardId,omitempty"`
	CardNumber int    `json:"cardNumber,omitempty"`
	Column     string `json:"column,omitempty"`
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
	handoverSeries
}

// heldEvent is a stream event that arrived after a freeze.
type heldEvent struct {
	id   string
	data []byte
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
	f, err := os.CreateTemp(filepath.Dir(path), ".handover-*")
	if err != nil {
		return fmt.Errorf("write handover: %w", err)
	}
	tmp := f.Name()
	defer os.Remove(tmp)
	if err := f.Chmod(0o600); err != nil {
		f.Close()

		return fmt.Errorf("write handover: %w", err)
	}
	if _, err := f.Write(b); err != nil {
		f.Close()

		return fmt.Errorf("write handover: %w", err)
	}
	if err := f.Sync(); err != nil {
		f.Close()

		return fmt.Errorf("write handover: %w", err)
	}
	if err := f.Close(); err != nil {
		return fmt.Errorf("write handover: %w", err)
	}
	if err := os.Rename(tmp, path); err != nil {
		return fmt.Errorf("write handover: %w", err)
	}

	return nil
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
		r.handleEvent(e.id, e.data)
	}
	r.eventMu.Unlock()

	r.dispatch()
}

// drain waits until no run report waits to go out, no ask check or resume
// gate runs and no worker is between its spawn and its start. A zero timeout is drainTimeout.
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
		reports, checks, gates, starting := r.inFlight()
		if reports == 0 && checks == 0 && gates == 0 && starting == 0 {
			return nil
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-deadline.C:
			return fmt.Errorf("after %s the bridge still holds %d run reports, %d ask checks, %d resume gates and %d starting workers", timeout, reports, checks, gates, starting)
		case <-tick.C:
		}
	}
}

func (r *router) inFlight() (reports, checks, gates, starting int) {
	r.mu.Lock()
	checks, gates, starting = r.checking, r.gating, r.active-len(r.live)
	r.mu.Unlock()
	if r.reports != nil {
		reports = r.reports.Pending()
	}

	return reports, checks, gates, starting
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
		Format:      handoverFormat,
		Running:     maps.Clone(r.running),
		Held:        maps.Clone(r.held),
		LastEventID: r.lastEventID,
		RecentIDs:   slices.Clone(r.recent),
		Seq:         r.seq,
	}
	if r.chains != nil {
		st.Chains = make(map[string]map[string]int, len(r.chains))
		for key, counts := range r.chains {
			st.Chains[key] = maps.Clone(counts)
		}
	}
	if r.sessions != nil {
		st.Sessions = make(map[string]handoverSession, len(r.sessions))
		for id, s := range r.sessions {
			st.Sessions[id] = handoverSession{Key: s.key, CardID: s.id, CardNumber: s.number, Column: s.column}
		}
	}
	for _, p := range r.queue {
		q := handoverPending{
			Event: p.event, Rule: p.rule, Key: p.key, RunID: p.runID, Seq: p.seq, Checked: p.checked, DropReason: p.dropReason,
			handoverSeries: seriesOf(p),
		}
		if p.continues != "" {
			q.SessionID, q.Prompt = p.spec.sessionID, p.spec.prompt
		}
		st.Queue = append(st.Queue, q)
	}
	for _, run := range r.live {
		st.Live = append(st.Live, handoverRun{
			RunID: run.p.runID, Key: run.p.key, Rule: run.p.rule, Event: run.p.event, SessionID: run.p.spec.sessionID,
			Began: run.began, PID: run.proc.pid, Dir: run.proc.dir, Seq: run.p.seq, handoverSeries: seriesOf(run.p),
		})
	}
	slices.SortFunc(st.Live, func(a, b handoverRun) int {
		return cmp.Or(a.Began.Compare(b.Began), cmp.Compare(a.RunID, b.RunID))
	})

	return st
}

// adopt takes the state a former image froze, on a router that has not
// subscribed yet. The queue matches again against this image's rule set, and
// each live run gets a wait that reports it as a finished worker would be.
func (r *router) adopt(st handoverState) {
	r.mu.Lock()
	r.running = maps.Clone(st.Running)
	r.chains = st.Chains
	r.held = maps.Clone(st.Held)
	r.seq = st.Seq
	r.lastEventID = st.LastEventID
	for _, id := range st.RecentIDs {
		r.rememberLocked(id)
	}
	if st.Sessions != nil {
		r.sessions = make(map[string]sessionCard, len(st.Sessions))
		for id, s := range st.Sessions {
			r.sessions[id] = sessionCard{key: s.Key, id: s.CardID, number: s.CardNumber, column: s.Column}
		}
	}
	for _, q := range st.Queue {
		p := pending{
			key: q.Key, rule: q.Rule, event: q.Event, runID: q.RunID, seq: q.Seq, checked: q.Checked, dropReason: q.DropReason,
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
	p := pending{key: run.Key, rule: run.Rule, event: run.Event, runID: run.RunID, seq: run.Seq}
	run.applyTo(&p)
	p.spec.sessionID = run.SessionID
	r.active++
	r.hold(p.key)
	r.trackLocked(liveRun{p: p, began: run.Began, proc: workerProc{pid: run.PID, dir: run.Dir}})
	r.log.Info("worker_adopted", append(about(p.event, p.rule), "session_id", p.spec.sessionID, "pid", run.PID)...)

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

// onEvent handles one stream event, unless its id was handled already, as
// across the replay after a handover. After a freeze, it holds the event back.
func (r *router) onEvent(id string, data []byte) {
	r.eventMu.Lock()
	defer r.eventMu.Unlock()

	r.mu.Lock()
	if r.frozen {
		r.heldEvents = append(r.heldEvents, heldEvent{id: id, data: slices.Clone(data)})
		r.mu.Unlock()

		return
	}
	r.mu.Unlock()
	r.handleEvent(id, data)
}

// handleEvent records the id as handled and routes the event. The caller holds
// eventMu.
func (r *router) handleEvent(id string, data []byte) {
	if id != "" {
		r.mu.Lock()
		seen := r.recentSet[id]
		if !seen {
			r.rememberLocked(id)
		}
		r.mu.Unlock()
		if seen {
			r.log.Info("event_duplicate", "id", id)

			return
		}
	}
	r.onData(data)
}

// onID keeps the stream's resume point.
func (r *router) onID(id string) {
	r.mu.Lock()
	r.lastEventID = id
	r.mu.Unlock()
}

// rememberLocked adds an id to the recent ones, and forgets the oldest past
// recentLimit. The caller holds mu.
func (r *router) rememberLocked(id string) {
	if r.recentSet[id] {
		return
	}
	if r.recentSet == nil {
		r.recentSet = map[string]bool{}
	}
	r.recent = append(r.recent, id)
	r.recentSet[id] = true
	if len(r.recent) > recentLimit {
		delete(r.recentSet, r.recent[0])
		r.recent = slices.Delete(r.recent, 0, 1)
	}
}
