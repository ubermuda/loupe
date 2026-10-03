package cmd

import (
	"bytes"
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"maps"
	"net"
	"os"
	"regexp"
	"slices"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"
	"unicode"
	"unicode/utf16"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/outbound"
	"github.com/ubermuda/loupe/cli/internal/rules"
	"github.com/ubermuda/loupe/cli/internal/transcript"
	"github.com/ubermuda/loupe/cli/internal/transport"
)

// router turns each event a rule matches into a worker process and reports
// what it did. Workers run in their own goroutines. mu guards the fields below
// it, and slog serialises its own writes.
type router struct {
	ctx context.Context
	log *slog.Logger
	// set holds the rule set behind a pointer, so a reload can swap it. Each
	// handler loads one snapshot and uses only that one.
	set atomic.Pointer[rules.Set]
	// assumeAutoUpdate takes a missing autoUpdate key as on, after the
	// migration from an older image. It is fixed before subscribe.
	assumeAutoUpdate bool
	// projects names the mapped slugs in the connected line. A reload writes
	// it under mu.
	projects []string
	topic    string
	worker   workerOps
	// bridgeID names the bridge in every report it sends, of a run and of rule
	// health alike. With none, as in most tests, the bridge sends no report.
	bridgeID string
	// reports carries each state of a run to Loupe, and runs builds what it
	// carries. A nil queue reports nothing.
	reports outbound.Queue
	runs    *runReports
	health  *healthReporter
	// heartbeat tells Loupe the bridge runs. A nil one sends nothing.
	heartbeat *heartbeater
	// hookRunner runs the hook packages on start, stop, busy and idle. A nil
	// one runs nothing.
	hookRunner *hookRunner
	// checkAsk reads an ask before its session resumes, on the worker goroutine
	// and never behind the report queue. A nil one resumes with no check.
	checkAsk     func(ctx context.Context, handle, askID string) (api.AskState, error)
	checkTimeout time.Duration
	// readCard reads a card's column before a resume of an unfinished run. A
	// nil one resumes with no check. after is time.After, which tests replace.
	readCard func(ctx context.Context, handle, cardID string) (api.CardRead, error)
	after    func(time.Duration) <-chan time.Time
	// replay reads a page of the outbox after a sequence, on each connect. A
	// nil one catches up nothing. cursorFile keeps the cursor, and "" keeps
	// none.
	replay     func(ctx context.Context, after int64) (api.Replay, error)
	cursorFile string
	// readHolds reads the held cards on each connect. A nil one reads none.
	readHolds func(ctx context.Context) ([]api.CardHold, error)
	// resolvePin asks which variant of an experiment a card runs with, before
	// its worker starts. A nil one runs the variant the bridge drew.
	resolvePin func(ctx context.Context, handle, experiment, cardID, candidate string, variants []string, weights []int) (string, string, error)
	// control is the socket that `loupe bridge reload` reaches, and source is
	// what a reload reads. A nil control, as in most tests, opens no socket.
	control net.Listener
	source  reloadSource
	// buildTimeout bounds the build of a reloaded set. Zero means
	// reloadBuildTimeout.
	buildTimeout time.Duration
	// reloadMu lets one reload run at a time. It is never taken under mu.
	reloadMu sync.Mutex
	// update hands the bridge over to a new binary. A nil one, as in most
	// tests, only logs a staged release.
	update *bridgeUpdate
	// scriptDir holds the launch scripts, and "" means defaultScriptDir.
	scriptDir string
	// ackCommand answers a command through the report queue. A nil one, as in
	// most tests, answers nothing. pauseFile caches a person's pause, and ""
	// caches nothing. baseURL names the server in the cache.
	ackCommand func(ctx context.Context, bridgeID, commandID, state, reason string) (string, error)
	pauseFile  string
	baseURL    string
	// workAPI claims and settles work requests. A nil one, as in most tests,
	// claims none. beforeLaunch runs just before a launch command starts, and
	// a nil one does nothing. Tests set it.
	workAPI      workClient
	beforeLaunch func()
	// signal sends one step of the stop ladder to the process group of a
	// worker, and stopAfter times the waits between two steps. A nil one is
	// signalGroup or time.After, which tests replace.
	signal    func(pid int, sig stopSignal) error
	stopAfter func(time.Duration) <-chan time.Time
	// findTranscript fails when this machine holds no transcript of the
	// session. A nil one looks in the Claude Code config directory.
	findTranscript func(sessionID string) error
	// startDir is the folder a session started in, and "" when this machine
	// holds no transcript of it. A nil one is transcriptStartDir.
	startDir func(sessionID string) (string, error)

	mu sync.Mutex
	// reloading is on while a reload builds its set. reloadKills holds each
	// slug change seen meanwhile, and reloadGone each project found gone, so
	// the swap replays both on the new set.
	reloading   bool
	reloadKills []event.Event
	reloadGone  []goneMark
	// fetchSeq orders the GET /api/events answers by arrival. setSeq is the
	// stamp of the answer the current set was checked against, and 0 at start.
	fetchSeq uint64
	setSeq   uint64
	// queue holds the accepted events in arrival order, at most one for each
	// card and rule, or for each ask of a resume, plus the resumes of unfinished
	// runs, which never coalesce. running holds the key of each
	// card with a worker or an ask check. A card runs once, and waits once per
	// rule or ask.
	queue   []pending
	running map[string]bool
	// chains counts, per card key and rule name, the runs in a row that an
	// agent's event started. An entry must outlive its workers to hold the cap,
	// so it stays until a person acts: one small map per card agents ran on.
	chains map[string]map[string]int
	// busy is the last busy or idle the hook runner got. The bridge starts idle.
	busy bool
	// inUse counts the worker slots taken in each pool, by the pool the run
	// started in.
	inUse map[string]int
	// commandRuns counts the command runs that hold their card, from dispatch
	// to their end. They take no slot.
	commandRuns int
	closed      bool
	// claude is the absolute path a launch script runs, which a reload swaps
	// with the set. launching counts the launches whose report is not queued.
	claude    string
	launching int
	// stopped closes at shutdown, so a resume that waits wakes at once.
	stopped chan struct{}
	seq     uint64
	// inbox is the inbox flag of the last GET /api/events. A worker that starts
	// while it is on reads both ids in its prompt.
	inbox bool
	// sessions maps each session the bridge ran to its key and card. An entry
	// outlives its worker, because an ask can close before the run report lands.
	sessions map[string]sessionCard
	// held maps the id of each open run the bridge reported to its project and
	// state. Each connect sends it as the run inventory.
	held map[string]api.InventoryRun
	// claims maps the id of each work request the bridge claims or holds to
	// its claim.
	claims map[string]*heldClaim

	// unmapped remembers the projects already logged as unmapped, and gone the
	// mapped projects already logged as gone. Both hold project ids, because a
	// reload can map a slug to another project.
	unmapped map[string]bool
	gone     map[string]bool

	// paused stops dispatch, and frozen also holds back the events and the
	// finished runs that arrive after a freeze, for resume to replay. checking
	// counts the ask checks in flight, gating the resume gates, and live the
	// workers that started.
	paused       bool
	frozen       bool
	checking     int
	gating       int
	live         map[string]liveRun
	heldEvents   []heldEvent
	heldFinishes []func()
	// personPaused stops dispatch while a person pauses the bridge. resume
	// clears paused only, so a handover keeps it. pauseSynced is on once a
	// heartbeat reply wrote the cache. handled maps each command the bridge
	// took to its expiry, and commanding counts the handlers in flight.
	personPaused bool
	pauseSynced  bool
	handled      map[string]time.Time
	commanding   int
	// stops holds each run a person stopped until its stopped report goes out.
	// cardHolds holds the cards the server states as held, because a person
	// paused their agents. A held card starts no worker until the hold ends.
	// stopWaits are the waits of the stop ladder.
	stops     map[string]bool
	cardHolds map[string]bool
	stopWaits stopWaits
	// noHoldList is set once the server answers that it has no held list.
	noHoldList bool
	// holdList is on while the last answer of the held list was a list. While
	// it is off, the bridge holds a card on a stop and ends the hold on a
	// resume or a rerun, as an older server does.
	holdList bool
	// lastEventID is the resume point of the stream, and recent the ids of the
	// last events handled, oldest first, which recentSet indexes.
	lastEventID string
	recent      []string
	recentSet   map[string]bool
	// cursor is the highest outbox sequence handled, and floor the head the
	// bridge started from with no cursor. hasCursor is off while neither is
	// known. caughtUp holds the ids that catch-ups read, oldest first, which
	// caughtUpSet indexes. cursorFailing is on while the cursor file cannot be
	// written.
	cursor, floor int64
	hasCursor     bool
	caughtUp      []string
	caughtUpSet   map[string]bool
	cursorFailing bool
	// gap is on from a failed catch-up until one ends, and holds the cursor at
	// the failed page.
	gap bool
	// eventMu keeps the events in order while resume replays the held ones. It
	// is taken before mu, never under it.
	eventMu sync.Mutex
	// quiesce is read-held by work that changes a run under mu and reports it
	// after, and freeze takes it, so no state falls between the two. It is
	// taken after eventMu and before mu.
	quiesce sync.RWMutex

	// wg counts the workers in flight. Tests wait on it instead of sleeping.
	wg sync.WaitGroup
}

// rules is the current rule set.
func (r *router) rules() *rules.Set {
	return r.set.Load()
}

// pending is an accepted event that waits for a free worker slot, and for its
// card's running worker to exit. The prompt is rendered when the event is
// accepted, from the rule that matched it.
type pending struct {
	key      string
	rule     string
	maxChain int
	spec     workerSpec
	event    event.Event
	// checked marks a resume whose ask check let it run. It holds its card key
	// already, and waits for a slot alone.
	checked bool
	// seq is the arrival order. A resume back from its check returns to it.
	seq uint64
	// runID names the run in every report of it. A reload that keeps the event
	// keeps its id.
	runID string
	// dropReason says why the queue lost the event, once it did.
	dropReason string
	// set is the rule set the event matched. enqueue matches it again when a
	// reload swapped the set in between.
	set *rules.Set
	// continues is the run id a resume of an unfinished run continues, and ""
	// for any other run. resumeIndex counts from 1 in a series, and maxResumes
	// is the cap the first run's rule set for the whole series.
	continues   string
	resumeIndex int
	maxResumes  int
	// column is the card's column when the series started, and "" when unknown.
	column string
	// action and project are the rule's action and project slug.
	action  string
	project string
	// fresh marks the new session that replaces a resume whose session is
	// missing. It never resumes the event's session, so it never falls back.
	fresh bool
	// pool is the pool the rule names now. slot is the pool the run started
	// in, which a reload never changes.
	pool string
	slot string
	// experiment is the experiment of the rule, or nil. pin is the variant
	// the run resolved at start.
	experiment *rules.Experiment
	pin        runPin
	// work is the work request the run does, and empty for an event's run.
	// claimToken is set once the bridge holds its claim. A queued offer holds
	// none, and reports nothing.
	work       api.WorkRequest
	claimToken string
	// origin is the work of the run that a person's resume or rerun
	// continues. Such a run claims nothing and settles no request.
	origin api.WorkRequest
}

// runPin is the variant a run in an experiment runs with, as its reports
// and a handover carry it.
type runPin struct {
	Experiment     string `json:"experiment,omitempty"`
	Variant        string `json:"variant,omitempty"`
	RequestedModel string `json:"requestedModel,omitempty"`
	SwitchedFrom   string `json:"switchedFrom,omitempty"`
}

// apply takes the rule, the settings and the prompt of a match. The session id
// stays empty until start. A resume of an unfinished run keeps its session,
// its prompt and its cap.
func (p *pending) apply(m rules.Match) {
	p.rule, p.maxChain, p.action, p.project, p.pool = m.Rule, m.MaxChain, m.Action, m.Project, m.Pool
	p.experiment, p.pin = m.Experiment, runPin{}
	if p.continues != "" {
		p.spec.dir, p.spec.permissionMode, p.spec.model, p.spec.schema = m.Dir, m.PermissionMode, m.Model, m.Schema
		p.spec.before, p.spec.command = m.Before, m.Command

		return
	}
	p.maxResumes = m.MaxResumes
	p.spec = workerSpec{
		dir: m.Dir, permissionMode: m.PermissionMode, model: m.Model, schema: m.Schema, prompt: m.Prompt, resume: m.Resume && !p.fresh,
		before: m.Before, command: m.Command,
	}
	// The server asks the work to resume the session of an unfinished run,
	// which the offer found on this machine.
	if p.isWork() && p.event.SessionID != "" && m.Action == "" && !p.fresh {
		p.spec.resume, p.spec.prompt = true, directive.RenderResumeUnfinished("status unfinished")
	}
}

// sessionCard is the key a session's worker ran under, its card when the
// event named one, and the column of its series when known.
type sessionCard struct {
	key    string
	id     string
	number int
	column string
}

// askCheckTimeout bounds the ask check. A check that runs out resumes anyway.
// The card read before a resume uses the same bound.
const askCheckTimeout = 10 * time.Second

// resumeDelay is the wait before the resume of a failed run.
const resumeDelay = time.Minute

// maxResultFields is the largest JSON encoding of the result fields the
// server takes.
const maxResultFields = 4000

// matchWorker matches a run against its rule by name. A rule that now opens an
// interactive session or runs a command starts no worker, so the run no
// longer matches.
func matchWorker(set *rules.Set, e event.Event, name string) (rules.Match, bool) {
	return matchAction(set, e, name, "")
}

// matchAction matches a run against its rule by name, and only while the rule
// keeps the action of the run. A queued worker never turns into a command, nor
// a command into a worker.
func matchAction(set *rules.Set, e event.Event, name, action string) (rules.Match, bool) {
	m, ok := set.MatchRule(e, name)

	return m, ok && m.Action == action
}

// keyFor keys the running worker and the chain counters by the subject id,
// which every event type carries. A card number repeats across projects, and a
// type this build knows no fields of may carry none. The subject of an ask or a
// review verdict is no card, so resolve keys those instead.
func keyFor(e event.Event) string {
	return e.Subject.ID
}

// resolve keys an ask or a review verdict on its card, so its run waits behind
// any worker of that card. The card of an ask comes from the event, else from
// the session the bridge ran, and the key falls back to the session id. It
// fills the event's card from the session, so the prompt and the report name
// it too.
func (r *router) resolve(e event.Event) (event.Event, string) {
	if (e.Type == event.ReviewSubmittedType || event.IsPullRequest(e.Type)) && e.CardID != "" {
		return e, e.CardID
	}
	if e.Type != event.AskClosedType {
		return e, keyFor(e)
	}
	if e.CardID != "" {
		return e, e.CardID
	}
	r.mu.Lock()
	s, ok := r.sessions[e.SessionID]
	r.mu.Unlock()
	if !ok {
		return e, e.SessionID
	}
	if s.number > 0 {
		e.CardID, e.CardNumber = s.id, s.number
	}

	return e, s.key
}

// cardOf is the card a run of the event reports against. A number below 1
// means the event names no card.
func cardOf(e event.Event) (string, int) {
	if e.Type == event.AskClosedType || e.Type == event.ReviewSubmittedType || event.IsPullRequest(e.Type) || e.Type == event.WorkRequestType {
		return e.CardID, e.CardNumber
	}

	return e.Subject.ID, e.CardNumber
}

// askOf is the ask a resume continues, and "" for any other event. Each ask
// closes once, so a resume never replaces another in the queue.
func askOf(e event.Event) string {
	if e.Type == event.AskClosedType {
		return e.Subject.ID
	}

	return ""
}

// columnLocked is the card's column when the event's run starts, and "" when
// the bridge does not know it. An ask takes the column of the session it
// resumes. The caller holds mu.
func (r *router) columnLocked(e event.Event) string {
	switch e.Type {
	case event.CardMovedType:
		return e.ToStatus
	case event.ReviewSubmittedType:
		return e.Column
	case event.AskClosedType:
		return r.sessions[e.SessionID].column
	}

	return ""
}

// label names an event's aggregate to a reader: its card number when the event
// carries one, and its subject id otherwise.
func label(e event.Event) (string, any) {
	if e.Type == event.CardMovedType || e.Type == event.CommandType || e.Type == event.WorkRequestType || ((e.Type == event.AskClosedType || e.Type == event.ReviewSubmittedType || event.IsPullRequest(e.Type)) && e.CardNumber > 0) {
		return "card", e.CardNumber
	}

	return "subject", e.Subject.ID
}

// about names the event's aggregate in a log line, a resume's ask and session,
// and a review verdict's document.
func about(e event.Event, rule string) []any {
	k, v := label(e)
	out := []any{k, v, "project", e.ProjectID, "rule", rule}
	switch e.Type {
	case event.AskClosedType:
		out = append(out, "ask", e.Subject.ID, "session_id", e.SessionID)
	case event.ReviewSubmittedType:
		out = append(out, "document", e.Subject.ID, "verdict", e.Verdict)
	case event.WorkRequestType:
		out = append(out, "work_request", e.Subject.ID)
	}

	return out
}

// aggregate names the event's aggregate in a sentence.
func aggregate(e event.Event) string {
	k, v := label(e)

	return fmt.Sprintf("%s %v", k, v)
}

// handler starts the stream at the resume point an adopted state carried.
func (r *router) handler() transport.Handler {
	r.mu.Lock()
	last := r.lastEventID
	r.mu.Unlock()

	return transport.Handler{
		OnConnect: func() {
			r.mu.Lock()
			projects := r.projects
			r.mu.Unlock()
			r.log.Info("connected", "topic", r.topic, "projects", projects)
			r.sendInventory()
			if r.update != nil {
				r.update.markConnected()
			}
			// A list read first keeps a replayed event of a held card from
			// starting it, and a read after makes the list win over the replay.
			synced := r.syncHolds()
			r.catchUp()
			if synced {
				r.syncHolds()
			}
		},
		OnError:     func(err error) { r.log.Error("stream_error", "error", err.Error()) },
		OnEvent:     r.onEvent,
		OnID:        r.onID,
		LastEventID: last,
	}
}

// onData routes one Mercure payload.
//
// A type no rule names is dropped in silence: a newer server publishes types
// an older binary never heard of, which is normal. A command goes to its own
// intake.
func (r *router) onData(data []byte) {
	r.route(data, false)
}

// route is onData for a live payload or, when replayed, for one of a catch-up.
// A replayed card move whose card left the column since does not run.
func (r *router) route(data []byte, replayed bool) {
	// Every bridge of the account receives an ask event, and only the one that
	// started the session can resume it. Another bridge's event is not ours to
	// validate, so it is dropped before Parse can log it.
	if event.ForAnotherBridge(data, r.bridgeID) {
		return
	}
	set := r.rules()
	e, err := event.Parse(data, set.ExtraTypes())
	if err != nil {
		if e.Type == event.CommandType {
			r.onCommandEvent(data)

			return
		}
		if e.Type == event.WorkRequestType {
			r.onWorkRequestEvent(data)

			return
		}
		if errors.Is(err, event.ErrUnknownType) {
			return
		}
		r.log.Error("event_malformed", "error", err.Error())

		return
	}
	// A hold event only states the hold. No rule matches it, and it is not a
	// person's look at the card that resets a chain.
	if e.Type == event.CardHeldType || e.Type == event.CardReleasedType {
		if r.noteCardHold(e) {
			r.dispatch()
		}

		return
	}
	e, key := r.resolve(e)

	// A slug change is a person's action, not a directive to an agent, so it
	// kills rules whatever its actor. Matching then goes on as for any event.
	dead, dropped := r.kill(e, func(s *rules.Set) []rules.Dead { return s.Kill(e) })
	r.logDead(e, dead)
	r.logDropped(dropped)
	// After the kill, so a released card never starts a run of a dead rule.
	if r.noteCardHold(e) {
		defer r.dispatch()
	}

	// A person who touches the card has seen it, which is what a capped chain
	// waits for. Any event of theirs that parsed counts, matched or not.
	if e.Actor == event.ActorHuman {
		r.mu.Lock()
		delete(r.chains, key)
		r.mu.Unlock()
	}

	m := set.Match(e)
	if m.Skip == rules.Run {
		r.accept(pending{key: key, event: e, set: set}, m, replayed)

		return
	}
	// A reload swaps the set under mu, so a skip is decided under mu against
	// the set now current. enqueue does the same for a run.
	r.mu.Lock()
	if cur := r.rules(); cur != set {
		set, m = cur, cur.Match(e)
	}
	first := m.Skip == rules.Unmapped && r.markUnmappedLocked(e.ProjectID)
	r.mu.Unlock()
	switch m.Skip {
	case rules.Run:
		r.accept(pending{key: key, event: e, set: set}, m, replayed)
	case rules.Untrusted:
		r.log.Warn("event_untrusted", about(e, m.Rule)...)
	case rules.Unmapped:
		if first {
			r.log.Warn("project_unmapped", "project", e.ProjectID)
		}
	}
}

// accept queues the event of a match, unless it is replayed and stale.
func (r *router) accept(p pending, m rules.Match, replayed bool) {
	p.apply(m)
	if replayed && r.stale(p) {
		return
	}
	r.enqueue(p)
}

// markUnmappedLocked marks the project as unmapped, and reports whether it was
// the first mark. The caller holds mu.
func (r *router) markUnmappedLocked(project string) bool {
	if r.unmapped[project] {
		return false
	}
	if r.unmapped == nil {
		r.unmapped = map[string]bool{}
	}
	r.unmapped[project] = true

	return true
}

// kill runs a rule kill on the current set and removes the queued events of the
// rules it killed, in one critical section, so a worker that finishes cannot
// start one of them in between. While a reload runs, it keeps e when e changes
// a slug, so the swap can replay it. The caller logs the returns.
func (r *router) kill(e event.Event, do func(*rules.Set) []rules.Dead) ([]rules.Dead, []pending) {
	r.mu.Lock()
	defer r.mu.Unlock()

	if r.reloading && slices.Contains([]string{event.ColumnRenamedType, event.ColumnDeletedType, event.ProjectRenamedType}, e.Type) {
		r.reloadKills = append(r.reloadKills, e)
	}

	set := r.rules()
	before := set.WorkDead(slugOf(set, e.ProjectID))
	dead, dropped := r.dropDeadLocked(do(set))
	r.noteWorkDeathLocked(set, e.ProjectID, before)

	return dead, append(dropped, r.dropDeadWorkLocked()...)
}

// goneMark is a project a refresh found gone, with the stamp of its answer.
type goneMark struct {
	id  string
	seq uint64
}

// stampLocked gives the GET /api/events answer that just arrived its place in
// arrival order. The caller holds mu.
func (r *router) stampLocked() uint64 {
	r.fetchSeq++

	return r.fetchSeq
}

// markGone marks the project gone and kills its rules, in one critical section
// with any swap. It does nothing, and says so, when the project is already
// gone or when the current set was checked against a newer answer.
func (r *router) markGone(id string, seq uint64) ([]rules.Dead, []pending, bool) {
	r.mu.Lock()
	defer r.mu.Unlock()

	if seq <= r.setSeq {
		return nil, nil, false
	}
	// A reload keeps the newest answer that found the project gone, even when
	// the project is marked already, so the swap weighs that answer.
	if r.reloading {
		r.reloadGone = append(r.reloadGone, goneMark{id: id, seq: seq})
	}
	if r.gone[id] {
		return nil, nil, false
	}
	if r.gone == nil {
		r.gone = map[string]bool{}
	}
	r.gone[id] = true
	set := r.rules()
	before := set.WorkDead(slugOf(set, id))
	dead, dropped := r.dropDeadLocked(killGone(set, id))
	r.noteWorkDeathLocked(set, id, before)

	return dead, append(dropped, r.dropDeadWorkLocked()...), true
}

// dropDeadLocked reports the health of the killed rules and removes their
// queued events. It submits the report under mu, so it keeps its order with
// the reports of a swap. The caller holds mu.
func (r *router) dropDeadLocked(dead []rules.Dead) ([]rules.Dead, []pending) {
	if len(dead) == 0 {
		return nil, nil
	}
	r.reportHealth(r.rules(), dead[0].Project)
	names := map[string]bool{}
	for _, d := range dead {
		names[d.Rule] = true
	}
	var dropped []pending
	released := false
	r.queue = slices.DeleteFunc(r.queue, func(p pending) bool {
		if names[p.rule] {
			p.dropReason = api.DropRuleDead
			dropped = append(dropped, p)
			// A checked resume holds its card, so dropping it frees the card.
			if p.checked {
				delete(r.running, p.key)
				released = true
			}

			return true
		}

		return false
	})
	if released {
		dropped = append(dropped, r.dispatchLocked()...)
	}
	r.noteBusyLocked()
	r.notePoolsLocked()

	return dead, dropped
}

// logDead names each rule a slug change killed.
func (r *router) logDead(e event.Event, dead []rules.Dead) {
	for _, d := range dead {
		r.log.Error("rule_dead", "rule", d.Rule, "project", e.ProjectID, "project_slug", d.Project, "reason", d.Reason,
			"message", fmt.Sprintf("rule %s matches nothing until you fix rules.yaml and run loupe bridge reload, because %s", d.Rule, slugChange(e, d.Project)))
	}
}

// slugChange says in words what a slug-changing event did.
func slugChange(e event.Event, project string) string {
	switch e.Type {
	case event.ColumnRenamedType:
		return fmt.Sprintf("column %s of project %s is now %s", e.FromSlug, project, e.ToSlug)
	case event.ColumnDeletedType:
		return fmt.Sprintf("column %s of project %s was deleted", e.Slug, project)
	default:
		return fmt.Sprintf("project %s is now %s", e.FromSlug, e.ToSlug)
	}
}

// reportHealth hands the current health of one project's rules to the
// reporter. The slice is built here, so the reporter never reads the set.
func (r *router) reportHealth(set *rules.Set, project string) {
	if r.health == nil {
		return
	}
	r.health.submit(project, set.ProjectID(project), set.Health(project))
}

// applyFlags keeps the flags of one GET /api/events answer for the workers that
// start after it, and gives its heartbeat interval to the heartbeat.
func (r *router) applyFlags(events api.Events) {
	r.mu.Lock()
	r.inbox = events.Enabled(api.InboxFlag)
	r.stopWaits = stopWaitsOf(events)
	r.mu.Unlock()
	if r.heartbeat != nil {
		r.heartbeat.setInterval(heartbeatInterval(events))
	}
}

// onRefresh applies the flags of a fresh GET /api/events. It logs, once for
// each, a mapped project that the answer no longer lists, with the rules that
// stop working.
func (r *router) onRefresh(events api.Events) {
	r.mu.Lock()
	seq := r.stampLocked()
	r.mu.Unlock()
	r.applyFlags(events)
	r.refreshGone(events, seq)
}

// refreshGone acts on the projects that the answer stamped seq no longer
// lists. A reload that checked its set against a newer answer wins.
func (r *router) refreshGone(events api.Events, seq uint64) {
	set := r.rules()
	for _, slug := range missingProjects(set, events) {
		// The kill names the project by id, because a reload can swap in a set
		// that maps the slug to another project. The health report of the kill
		// most likely gets project_not_found, which the reporter logs once.
		id := set.ProjectID(slug)
		r.quiesce.RLock()
		dead, dropped, ok := r.markGone(id, seq)
		if !ok {
			r.quiesce.RUnlock()

			continue
		}

		var names []string
		for _, rule := range set.Rules() {
			if rule.Project == slug {
				names = append(names, rule.Name)
			}
		}
		r.log.Error("project_gone",
			"project", slug,
			"rules", names,
			"message", fmt.Sprintf("project %s is deleted or no longer yours, so rules %s stop working", slug, strings.Join(names, ", ")),
		)
		r.logGone(id, dead)
		r.logDropped(dropped)
		r.quiesce.RUnlock()
	}
}

// killGone kills the rules of the project with this id, when the set maps it.
func killGone(set *rules.Set, id string) []rules.Dead {
	if slug := slugOf(set, id); slug != "" {
		return set.KillProject(slug, api.ReasonProjectGone)
	}

	return nil
}

// slugOf is the slug the set maps to a project id, and "" when it maps none.
func slugOf(set *rules.Set, id string) string {
	for _, slug := range set.Projects() {
		if set.ProjectID(slug) == id {
			return slug
		}
	}

	return ""
}

// logGone names each rule a gone project killed.
func (r *router) logGone(id string, dead []rules.Dead) {
	for _, d := range dead {
		r.log.Error("rule_dead", "rule", d.Rule, "project", id, "project_slug", d.Project, "reason", d.Reason,
			"message", fmt.Sprintf("rule %s matches nothing until you fix rules.yaml and run loupe bridge reload, because project %s is gone", d.Rule, d.Project))
	}
}

// enqueue puts the event at the back of the queue, or in place of a waiting
// event for the same card and rule. A resume replaces only a waiting resume of
// the same ask, because each ask closes once. It refuses an agent's event once
// its rule has run maxChain times in a row on the card from agents' events. It
// logs under mu, so no line for this event can follow worker_started.
func (r *router) enqueue(p pending) {
	p.runID = config.NewUUID()
	r.mu.Lock()
	// A reload swapped the set after the match, and rewrote the queue before
	// this event reached it. The event matches again as it arrived.
	if current := r.rules(); p.set != current {
		m := current.Match(p.event)
		if p.isWork() {
			m = current.MatchWork(p.work)
		}
		switch m.Skip {
		case rules.Untrusted:
			r.log.Warn("event_untrusted", about(p.event, m.Rule)...)
		case rules.Unmapped:
			if r.markUnmappedLocked(p.event.ProjectID) {
				r.log.Warn("project_unmapped", "project", p.event.ProjectID)
			}
		}
		if m.Skip != rules.Run {
			r.mu.Unlock()

			return
		}
		p.set = current
		p.apply(m)
	}
	// One work request waits once, whichever channel offered it. A frozen
	// router takes no offer, so the next image claims it.
	if p.isWork() && (r.frozen || r.knownWorkLocked(p.work.WorkRequestID)) {
		r.mu.Unlock()

		return
	}
	// The server holds the card, so no rule starts anything on it.
	if r.heldLocked(p.event) {
		r.log.Info("card_held", about(p.event, p.rule)...)
		r.mu.Unlock()

		return
	}
	// A match of an interactive rule opens a session and never waits in the queue.
	if p.action == rules.ActionInteractive {
		// A session opens at once, and a person's pause holds back new work, so
		// a paused bridge leaves the offer to another bridge.
		if p.isWork() && (r.personPaused || r.paused) {
			r.log.Debug("work_request_skipped", append(about(p.event, p.rule), "reason", "paused")...)
		} else if p.isWork() {
			r.launching++
			r.claimThenLocked(p, func(p pending) {
				r.launching--
				r.launchLocked(p, r.abortableLocked(p))
			}, func() { r.launching-- })
		} else {
			r.launchLocked(p, context.Background())
		}
		r.mu.Unlock()

		return
	}
	p.column = r.columnLocked(p.event)
	// A command never counts toward the cap, so a count left by a worker rule
	// of the same name before a reload never refuses it.
	if !p.isCommand() && p.event.Actor == event.ActorAgent && r.chains[p.key][p.rule] >= p.maxChain {
		r.log.Warn("chain_capped", append(about(p.event, p.rule),
			"worker_pool", p.pool,
			"max_chain", p.maxChain,
			"message", fmt.Sprintf("%s hit the chain cap of rule %s, waiting for a person", aggregate(p.event), p.rule),
		)...)
		r.emitLocked(p, api.RunStateReport{State: api.RunWaitingForPerson, MaxChain: p.maxChain})
		r.mu.Unlock()

		return
	}
	// A queued resume of an unfinished run, or a fresh run, is never replaced,
	// so the new event waits behind it. Two work requests never replace each
	// other.
	if i := slices.IndexFunc(r.queue, func(q pending) bool {
		return q.continues == "" && !q.fresh && !q.isWork() && q.key == p.key && q.rule == p.rule && askOf(q.event) == askOf(p.event)
	}); !p.isWork() && i >= 0 {
		p.checked, p.seq = r.queue[i].checked, r.queue[i].seq
		r.emitLocked(r.queue[i], api.RunStateReport{State: api.RunReplaced, ReplacedBy: p.runID})
		r.queue[i] = p
		r.log.Info("worker_coalesced", append(about(p.event, p.rule), "worker_pool", p.pool)...)
		r.emitLocked(p, api.RunStateReport{State: api.RunQueued})
		// The new run takes the place of a resume its check let through.
		if p.checked {
			r.emitLocked(p, api.RunStateReport{State: api.RunResumed, AskID: askOf(p.event)})
		}
		r.mu.Unlock()

		return
	}
	r.seq++
	p.seq = r.seq
	r.queue = append(r.queue, p)
	depth := 0
	for _, q := range r.queue {
		if q.pool == p.pool {
			depth++
		}
	}
	r.log.Info("worker_queued", append(about(p.event, p.rule), "worker_pool", p.pool, "queue_depth", len(r.queue), "pool_depth", depth)...)
	r.emitLocked(p, api.RunStateReport{State: api.RunQueued})
	r.mu.Unlock()

	r.dispatch()
}

// dispatch starts queued workers while a slot is free. It runs on the goroutine
// that accepted an event.
func (r *router) dispatch() {
	r.mu.Lock()
	dropped := r.dispatchLocked()
	r.mu.Unlock()

	r.logDropped(dropped)
}

// dispatchLocked starts the oldest queued events whose card and pool slot are
// free, and starts the ask check of a queued resume whether or not a slot is.
// The caller holds mu, and logs the queue a shut router returns. One card runs
// one worker, because two agents in one checkout undo each other. Pop and
// start share the lock, or two finishing workers could reorder starts.
func (r *router) dispatchLocked() []pending {
	defer r.notePoolsLocked()
	defer r.noteBusyLocked()
	if r.shut() {
		dropped := r.queue
		r.queue = nil
		for i := range dropped {
			dropped[i].dropReason = api.DropShutdown
		}

		return dropped
	}
	if r.paused || r.personPaused {
		return nil
	}

	// A pool the current set lacks has no slot.
	set := r.rules()
	budget, pools := set.MaxWorkers(), set.Pools()
	// waiting holds the keys of events this pass leaves queued, so a later
	// event of the same card never goes first.
	waiting := map[string]bool{}
	for i := 0; i < len(r.queue); {
		next := r.queue[i]
		// A queued run of a held card waits until the hold ends.
		if waiting[next.key] || (r.running[next.key] && !next.checked) || r.heldLocked(next.event) {
			waiting[next.key] = true
			i++

			continue
		}
		if next.continues == "" && next.spec.resume && !next.checked && r.checkAsk != nil && next.event.Type == event.AskClosedType {
			r.queue = slices.Delete(r.queue, i, i+1)
			r.hold(next.key)
			r.check(next)

			continue
		}
		// A command takes no worker slot and never counts toward the chain
		// cap. It holds its card, so a worker of the card waits for it.
		if next.isCommand() {
			r.queue = slices.Delete(r.queue, i, i+1)
			r.hold(next.key)
			r.commandRuns++
			r.begin(next)

			continue
		}
		if r.usedLocked() >= budget || r.inUse[next.pool] >= pools[next.pool] {
			waiting[next.key] = true
			i++

			continue
		}
		r.queue = slices.Delete(r.queue, i, i+1)
		// Asks never coalesce, so several agent closes of one card can pass the
		// cap at enqueue and wait together. The cap is read again here. A resume
		// of an unfinished run continues a run the chain counted already.
		if next.continues == "" && next.spec.resume && next.event.Actor == event.ActorAgent && r.chains[next.key][next.rule] >= next.maxChain {
			r.log.Warn("chain_capped", append(about(next.event, next.rule),
				"worker_pool", next.pool,
				"max_chain", next.maxChain,
				"message", fmt.Sprintf("%s hit the chain cap of rule %s, waiting for a person", aggregate(next.event), next.rule),
			)...)
			r.emitLocked(next, api.RunStateReport{State: api.RunWaitingForPerson, MaxChain: next.maxChain})
			delete(r.running, next.key)

			continue
		}
		next.slot = next.pool
		r.takeLocked(next.slot)
		r.hold(next.key)
		if next.continues == "" && !next.fresh && next.event.Actor == event.ActorAgent {
			r.countChain(next.key, next.rule)
		}
		r.begin(next)
	}

	return nil
}

// usedLocked is the number of worker slots taken in every pool. The caller
// holds mu.
func (r *router) usedLocked() int {
	n := 0
	for _, used := range r.inUse {
		n += used
	}

	return n
}

// takeLocked takes one slot of the pool. The caller holds mu.
func (r *router) takeLocked(pool string) {
	if r.inUse == nil {
		r.inUse = map[string]int{}
	}
	r.inUse[pool]++
}

// releaseLocked gives back one slot of the pool the run started in. A second
// release of one slot gives no slot back. The caller holds mu.
func (r *router) releaseLocked(pool string) {
	r.inUse[pool]--
	if r.inUse[pool] <= 0 {
		delete(r.inUse, pool)
	}
}

// notePoolsLocked hands the rows of the worker pools to the heartbeat. The
// caller holds mu.
func (r *router) notePoolsLocked() {
	if r.heartbeat == nil {
		return
	}
	r.heartbeat.setPools(r.poolRowsLocked())
}

// poolRowsLocked lists, in name order, each pool of the set, and each pool
// that the set lacks and a run or a queued event still names, with size 0.
// The caller holds mu.
func (r *router) poolRowsLocked() []api.WorkerPoolReport {
	sizes := r.rules().Pools()
	queued := map[string]int{}
	for _, p := range r.queue {
		if p.pool != "" {
			queued[p.pool]++
		}
	}
	names := slices.Concat(slices.Collect(maps.Keys(sizes)), slices.Collect(maps.Keys(r.inUse)), slices.Collect(maps.Keys(queued)))
	slices.Sort(names)
	rows := []api.WorkerPoolReport{}
	for _, name := range slices.Compact(names) {
		rows = append(rows, api.WorkerPoolReport{Name: name, Size: sizes[name], InUse: r.inUse[name], Queued: queued[name]})
	}

	return rows
}

// noteBusyLocked hands busy or idle to the hook runner when the bridge turns
// busy or idle. A card that waits in the queue or holds its key makes it busy.
// A shut router hands nothing, so only stop follows a shutdown. The caller
// holds mu.
func (r *router) noteBusyLocked() {
	if r.shut() {
		return
	}
	busy := len(r.running) > 0 || len(r.queue) > 0
	if busy == r.busy {
		return
	}
	r.busy = busy
	if busy {
		r.hookRunner.fire(hookBusy)
	} else {
		r.hookRunner.fire(hookIdle)
	}
}

// hold reserves a card key. The caller holds mu.
func (r *router) hold(key string) {
	if r.running == nil {
		r.running = map[string]bool{}
	}
	r.running[key] = true
}

// countChain records one more run in a row from an agent's event. The caller
// holds mu. It counts at start rather than on acceptance, so an event that
// replaces a waiting one counts once.
func (r *router) countChain(key, rule string) {
	if r.chains == nil {
		r.chains = map[string]map[string]int{}
	}
	if r.chains[key] == nil {
		r.chains[key] = map[string]int{}
	}
	r.chains[key][rule]++
}

// begin starts a run that holds its card and its slot. A work request is
// claimed first, and a failed claim gives both back. The caller holds mu.
func (r *router) begin(p pending) {
	if !p.isWork() {
		r.start(p)

		return
	}
	r.claimThenLocked(p, r.start, func() {
		delete(r.running, p.key)
		r.freeLocked(p)
	})
}

// start runs one worker, as a new claude session, as the resume of the session
// its ask or fix request names, or as the resume of an unfinished run. The
// caller holds mu, and start never takes it.
//
// wg counts the worker before the goroutine exists, and the finish call that
// admits the next worker runs before wg.Done, so a waiter never sees the count
// reach zero between two queued workers.
func (r *router) start(p pending) {
	if p.isCommand() {
		p.spec.runID, p.spec.rule, p.spec.key = p.runID, p.rule, p.key
		r.wg.Add(1)
		go func() {
			defer r.wg.Done()
			r.execute(p)
		}()

		return
	}
	args := append(about(p.event, p.rule), "worker_pool", p.slot)
	switch {
	case p.continues != "":
		// The resume gate set the session of the run this one continues.
		r.log.Info("worker_started", append(args, "session_id", p.spec.sessionID, "resume", p.resumeIndex)...)
	case p.spec.resume:
		p.spec.sessionID = p.event.SessionID
		// about names the session of an ask already.
		if p.event.Type != event.AskClosedType {
			args = append(args, "session_id", p.spec.sessionID)
		}
		r.log.Info("worker_started", args...)
	default:
		p.spec.sessionID = r.worker.sessionID()
		r.log.Info("worker_started", append(args, "session_id", p.spec.sessionID)...)
	}
	p.spec.runID, p.spec.rule, p.spec.key = p.runID, p.rule, p.key
	if r.inbox {
		p.spec.prompt += "\n" + directive.InboxLine(p.spec.sessionID, r.bridgeID)
	}
	id, number := cardOf(p.event)
	if r.sessions == nil {
		r.sessions = map[string]sessionCard{}
	}
	// A fix request knows no column, and its resume keeps the one the session
	// had, which a later ask on the session reads.
	column := cmp.Or(p.column, r.sessions[p.spec.sessionID].column)
	r.sessions[p.spec.sessionID] = sessionCard{key: p.key, id: id, number: number, column: column}
	// A checked resume sent resumed when its check let it through. A resume
	// that continues a run, or that a work request asks for, sends queued alone.
	if p.spec.resume && !p.checked && p.continues == "" && !p.isWork() {
		r.emitLocked(p, api.RunStateReport{State: api.RunResumed, AskID: askOf(p.event)})
	}
	r.wg.Add(1)
	go func() {
		defer r.wg.Done()

		if p.experiment != nil {
			p.spec.model, p.pin = r.resolveVariant(p)
		}
		if p.spec.before != nil {
			r.prepare(p)

			return
		}
		r.runAgent(p, time.Time{}, "")
	}()
}

// runAgent runs claude for the run and settles it. began is when the run's
// before command started, and zero when the rule has none. beforeDir is the
// run directory of that command.
func (r *router) runAgent(p pending, began time.Time, beforeDir string) {
	prepared := !began.IsZero()
	// The spawn time stands in for a process that never starts, because the
	// old report needs a start. onStart runs on this goroutine, before run returns.
	if !prepared {
		began = time.Now()
	}
	if p.spec.resume {
		var reason string
		if p, reason = r.resumeDir(p); reason != "" {
			failed := workerResult{exitCode: -1, output: reason, dir: beforeDir, resumeGone: true}
			r.settle(p, endedRun{res: failed, began: began, elapsed: time.Since(began)})

			return
		}
	}
	onStart := func(proc workerProc) {
		if !prepared {
			began = time.Now()
		}
		r.mu.Lock()
		defer r.mu.Unlock()
		r.trackLocked(liveRun{p: p, began: began, proc: proc})
		r.emitLocked(p, api.RunStateReport{State: api.RunRunning, SessionID: p.spec.sessionID, StartedAt: began})
		// A stop that came while the worker spawned starts its ladder now.
		if r.stops[p.runID] {
			r.stopLiveLocked(r.live[p.runID])
		}
	}
	res := r.worker.run(r.workerContext(), p.spec, onStart)
	// A claude that never started made no run directory, so the files of the
	// before command are what remains.
	res.dir = cmp.Or(res.dir, beforeDir)
	r.settle(p, endedRun{res: res, began: began, elapsed: time.Since(began)})
}

// resumeDir starts a resume in the folder its conversation started in, because
// claude --resume finds the conversation only from there. When that folder is
// gone, the run starts a new session in the folder it has, with the rule's
// prompt. reason says why the run cannot start at all.
func (r *router) resumeDir(p pending) (pending, string) {
	lookup := r.startDir
	if lookup == nil {
		lookup = transcriptStartDir
	}
	recorded, err := lookup(p.spec.sessionID)
	if err != nil {
		r.log.Warn("resume_dir_unknown", append(about(p.event, p.rule), "session_id", p.spec.sessionID, "error", err.Error())...)

		return p, ""
	}
	if recorded == "" {
		return p, ""
	}
	info, err := os.Stat(recorded)
	if err == nil && info.IsDir() {
		p.spec.dir = recorded

		return p, ""
	}
	if err != nil && !errors.Is(err, fs.ErrNotExist) && !errors.Is(err, syscall.ENOTDIR) {
		return p, fmt.Sprintf("the bridge cannot read %s, where the conversation started: %s", recorded, err)
	}
	gone := fmt.Sprintf("the conversation started in %s, which is gone", recorded)
	// A person's resume carries nothing of the event that started the series,
	// so the rule's prompt would hold its placeholders as text.
	if p.event.Type == event.CommandType {
		return p, gone + ", so the bridge cannot resume it"
	}
	r.mu.Lock()
	m, ok := matchPending(r.rules(), p)
	if !ok {
		r.mu.Unlock()

		return p, gone + ", and the rule no longer runs the event"
	}
	old := p.spec.sessionID
	p.fresh, p.spec.resume, p.spec.sessionID, p.spec.prompt = true, false, r.worker.sessionID(), m.Prompt
	if r.inbox {
		p.spec.prompt += "\n" + directive.InboxLine(p.spec.sessionID, r.bridgeID)
	}
	if s, found := r.sessions[old]; found {
		r.sessions[p.spec.sessionID] = s
	}
	r.mu.Unlock()
	r.log.Warn("resume_dir_gone", append(about(p.event, p.rule),
		"session_id", old, "new_session_id", p.spec.sessionID, "dir", p.spec.dir,
		"message", gone+", so the bridge starts a new session",
	)...)

	return p, ""
}

// transcriptStartDir is the folder the session started in, from its transcript
// in the Claude Code config directory, and "" when there is none.
func transcriptStartDir(sessionID string) (string, error) {
	dir, err := transcript.ConfigDir()
	if err != nil {
		return "", err
	}
	path, err := transcript.Find(dir, sessionID)
	if errors.Is(err, transcript.ErrNotFound) {
		return "", nil
	}
	if err != nil {
		return "", err
	}

	return transcript.StartDir(path)
}

// prepare runs the before command of the run's rule in the project dir, in
// the slot the run holds, and reports preparing once it exists. A person's
// stop reaches it as it reaches a worker.
func (r *router) prepare(p pending) {
	began := time.Now()
	onStart := func(proc workerProc) {
		began = time.Now()
		r.mu.Lock()
		defer r.mu.Unlock()
		r.trackLocked(liveRun{p: p, began: began, proc: proc, before: true})
		r.log.Info("before_started", append(about(p.event, p.rule), "worker_pool", p.slot, "pid", proc.pid)...)
		r.emitLocked(p, api.RunStateReport{State: api.RunPreparing, StartedAt: began})
		if r.stops[p.runID] {
			r.stopLiveLocked(r.live[p.runID])
		}
	}
	run := r.worker.before
	if run == nil {
		run = runBefore
	}
	timeout := cmp.Or(p.spec.before.Timeout, rules.DefaultBeforeTimeout)
	ctx, cancel := context.WithTimeout(r.workerContext(), timeout)
	res := run(ctx, procSpec{argv: p.spec.before.Argv, dir: p.spec.dir, runID: p.runID}, onStart)
	cancel()
	r.afterBefore(p, began, res)
}

// afterBefore starts claude in the folder the before command printed, or ends
// the run when the command failed, a person stopped the run or the bridge
// shuts down. A handover in progress holds the start back and keeps the run
// in its before phase, so the next image adopts it from its files.
func (r *router) afterBefore(p pending, began time.Time, res procResult) {
	failure := res.failure()
	r.quiesce.RLock()
	r.mu.Lock()
	stopped, shut := r.stops[p.runID], r.shut()
	ok := failure == "" && !stopped && !shut
	if ok && (r.paused || r.frozen) {
		r.wg.Add(1)
		r.heldFinishes = append(r.heldFinishes, func() {
			go func() {
				defer r.wg.Done()
				r.afterBefore(p, began, res)
			}()
		})
		r.mu.Unlock()
		r.quiesce.RUnlock()

		return
	}
	if ok {
		if run, found := r.live[p.runID]; found {
			close(run.ended)
			delete(r.live, p.runID)
		}
	}
	r.mu.Unlock()
	r.quiesce.RUnlock()

	if ok {
		r.log.Info("before_finished", append(about(p.event, p.rule), "dir", res.dir, "output", res.output)...)
		p.spec.dir = cmp.Or(res.dir, p.spec.dir)
		r.runAgent(p, began, res.runDir)

		return
	}
	if failure == "" && shut {
		failure, res.killed = "the bridge shut down before the agent started", true
	}
	// The server reads a failed run from a non-zero exit code alone.
	code := res.exitCode
	if code == 0 {
		code = -1
	}
	failed := workerResult{exitCode: code, output: failedOutput(failure, res.output), killed: res.killed && !res.timedOut, timedOut: res.timedOut, dir: res.runDir, before: true}
	r.settle(p, endedRun{res: failed, began: began, elapsed: time.Since(began)})
}

// resolveVariant is the model and the variant of a run in an experiment. The
// server keeps the variant a card first ran with. A run with no card, or with
// no answer from the server, runs the variant the bridge drew.
func (r *router) resolveVariant(p pending) (string, runPin) {
	exp := p.experiment
	cardID, number := cardOf(p.event)
	if number < 1 {
		cardID = ""
	}
	candidate := exp.Pick(cmp.Or(cardID, p.key))
	drawn := runPin{Experiment: exp.Name, Variant: candidate.Name, RequestedModel: candidate.Model}
	if cardID == "" || r.resolvePin == nil {
		return candidate.Model, drawn
	}

	names := make([]string, len(exp.Variants))
	weights := make([]int, len(exp.Variants))
	for i, v := range exp.Variants {
		names[i], weights[i] = v.Name, v.Weight
	}
	timeout := r.checkTimeout
	if timeout <= 0 {
		timeout = askCheckTimeout
	}
	ctx, cancel := context.WithTimeout(r.workerContext(), timeout)
	name, switchedFrom, err := r.resolvePin(ctx, p.event.ProjectID, exp.Name, cardID, candidate.Name, names, weights)
	cancel()
	i := slices.IndexFunc(exp.Variants, func(v rules.Variant) bool { return v.Name == name })
	if err == nil && i < 0 {
		err = fmt.Errorf("the server answered variant %q, which the experiment does not offer", name)
	}
	if err != nil {
		// A bridge that shuts down cancels the request, which is no pin failure.
		if r.workerContext().Err() == nil {
			r.log.Warn("experiment_pin_failed", append(about(p.event, p.rule),
				"experiment", exp.Name,
				"variant", candidate.Name,
				"error", err.Error(),
				"message", "the bridge could not read the pin of the card, so it runs the variant it drew",
			)...)
		}

		return candidate.Model, drawn
	}
	v := exp.Variants[i]

	return v.Model, runPin{Experiment: exp.Name, Variant: v.Name, RequestedModel: v.Model, SwitchedFrom: switchedFrom}
}

// liveRun is a worker that started, with what its report and a handover need.
type liveRun struct {
	p     pending
	began time.Time
	proc  workerProc
	// ended closes when the worker exits, which wakes its stop ladder.
	ended chan struct{}
	// before says the process is the rule's before command, and claude has
	// not started.
	before bool
}

// trackLocked records a started worker. The caller holds mu.
func (r *router) trackLocked(run liveRun) {
	if r.live == nil {
		r.live = map[string]liveRun{}
	}
	run.ended = make(chan struct{})
	r.live[run.p.runID] = run
}

// settle ends a finished worker and removes its run directory. After a
// freeze, the next image adopts the run from its files, so the run waits here
// for a resume that may never come.
func (r *router) settle(p pending, e endedRun) {
	r.quiesce.RLock()
	defer r.quiesce.RUnlock()
	done := func() {
		r.end(p, e)
		if e.res.dir != "" {
			_ = os.RemoveAll(e.res.dir)
		}
	}
	r.mu.Lock()
	if run, ok := r.live[p.runID]; ok {
		close(run.ended)
		delete(r.live, p.runID)
	}
	if r.frozen {
		r.wg.Add(1)
		r.heldFinishes = append(r.heldFinishes, func() {
			defer r.wg.Done()
			done()
		})
		r.mu.Unlock()

		return
	}
	r.mu.Unlock()
	done()
}

// endedRun is how one worker ended. state is the outcome to report, and
// skipped says why a run worth a resume got none.
type endedRun struct {
	res     workerResult
	began   time.Time
	elapsed time.Duration
	state   string
	skipped string
}

// classify names the state a run ended in, as the server reads it, and the
// reason the run is worth a resume. An empty reason means no resume.
func classify(res workerResult) (string, string) {
	switch {
	case res.command && res.exitCode == 0:
		return api.RunSucceeded, ""
	case res.command, res.before, res.resumeGone:
		return api.RunFailed, ""
	case res.err != nil:
		return api.RunNotStarted, ""
	case res.exitCode != 0:
		return api.RunFailed, fmt.Sprintf("exit code %d", res.exitCode)
	case !res.hasResult:
		return api.RunNoResult, "no structured result"
	case res.status == "blocked":
		return api.RunBlocked, ""
	case res.status == "waiting":
		return api.RunWaitingOnForge, ""
	case res.status == "unfinished":
		return api.RunUnfinished, "status unfinished"
	}

	return api.RunSucceeded, ""
}

// end logs and reports how a worker ended, and frees its card, or hands the
// card to the resume gate. It runs on the worker goroutine.
func (r *router) end(p pending, e endedRun) {
	r.logResult(p, e.res, e.elapsed)
	r.mu.Lock()
	if r.endStoppedLocked(p, e) {
		return
	}
	shut := r.shut()
	r.mu.Unlock()
	var reason string
	e.state, reason = classify(e.res)
	// A work request takes one result, so its run never resumes. The server
	// decides each resume of a run that continues one.
	if p.isWork() || p.followsWork() {
		reason = ""
	}

	if !e.res.killed && !shut && sessionMissing(p, e.res) && r.startFresh(p, e) {
		return
	}
	switch {
	case (e.res.before || e.res.resumeGone) && (e.res.killed || shut):
		e.skipped = api.DropShutdown
	case reason == "":
	case e.res.killed || shut:
		e.skipped = api.DropShutdown
	case p.resumeIndex >= p.maxResumes:
		r.log.Error("worker_gave_up", append(about(p.event, p.rule),
			"resume", p.resumeIndex,
			"max_resumes", p.maxResumes,
			"reason", reason,
			"message", fmt.Sprintf("%s ended with %s after %d of %d resumes, so the bridge stops resuming it", aggregate(p.event), reason, p.resumeIndex, p.maxResumes),
		)...)
		e.state = api.RunGaveUp
	default:
		r.finishInto(p, e, reason)

		return
	}
	// A stop can come after the check above. The outcome and the check share mu.
	r.mu.Lock()
	if r.endStoppedLocked(p, e) {
		return
	}
	r.emitLocked(p, r.outcome(p, e))
	delete(r.running, p.key)
	r.freeLocked(p)
	state, why, post := r.workResultLocked(p, e.res, shut || r.shut())
	dropped := r.dispatchLocked()
	r.mu.Unlock()

	r.logDropped(dropped)
	if post {
		r.settleWork(p, state, why)
	}
}

// freeLocked gives back what the run held to run: its worker slot, or its
// count as a command run. The caller holds mu.
func (r *router) freeLocked(p pending) {
	if p.isCommand() {
		r.commandRuns--

		return
	}
	r.releaseLocked(p.slot)
}

// endStoppedLocked reports a run that a person stopped as stopped, however it
// ended, and frees its slot and card. A stopped run never resumes. The caller
// holds mu, which endStoppedLocked releases when it returns true.
func (r *router) endStoppedLocked(p pending, e endedRun) bool {
	if !r.stops[p.runID] {
		return false
	}
	r.freeLocked(p)
	if p.isWork() {
		r.dropClaimLocked(p.work.WorkRequestID, p.claimToken)
	}
	r.closeStoppedLocked(p, r.stoppedReport(p, e), true)
	dropped := r.dispatchLocked()
	r.mu.Unlock()
	r.logDropped(dropped)

	return true
}

// missingSessionOutput starts what claude prints when it has no session to
// resume.
const missingSessionOutput = "No conversation found with session ID"

// sessionMissing reports whether a fix request's resume of the session its
// event names failed because this machine has no such session.
func sessionMissing(p pending, res workerResult) bool {
	return p.event.Type == event.FixRequestedType && p.continues == "" && p.spec.resume && !p.fresh &&
		!res.before && !res.resumeGone && res.err == nil && res.exitCode != 0 && !res.hasResult &&
		strings.HasPrefix(strings.TrimSpace(res.output), missingSessionOutput)
}

// startFresh reports the run whose session is missing, and queues one run of
// the same event and rule in a new session. The new run keeps the card key, so
// no other event of the card starts first. When the rule no longer runs the
// event, startFresh does nothing and returns false.
func (r *router) startFresh(p pending, e endedRun) bool {
	r.mu.Lock()
	if r.endStoppedLocked(p, e) {
		return true
	}
	current := r.rules()
	m, ok := matchWorker(current, p.event, p.rule)
	if !ok {
		r.mu.Unlock()

		return false
	}
	r.log.Warn("resume_session_missing", append(about(p.event, p.rule),
		"session_id", p.spec.sessionID,
		"message", fmt.Sprintf("the session of %s is not on this machine, so the bridge starts a new session", aggregate(p.event)),
	)...)
	r.emitLocked(p, r.outcome(p, e))
	next := pending{key: p.key, event: p.event, set: current, runID: config.NewUUID(), seq: p.seq, checked: true, fresh: true, column: p.column}
	next.apply(m)
	r.emitLocked(next, api.RunStateReport{State: api.RunQueued})
	i, _ := slices.BinarySearchFunc(r.queue, next.seq, func(q pending, seq uint64) int { return cmp.Compare(q.seq, seq) })
	r.queue = slices.Insert(r.queue, i, next)
	r.releaseLocked(p.slot)
	dropped := r.dispatchLocked()
	r.mu.Unlock()

	r.logDropped(dropped)

	return true
}

// finishInto frees the worker slot and keeps the card key for the resume gate,
// in one critical section, so no new event of the card starts first.
func (r *router) finishInto(p pending, e endedRun, reason string) {
	r.mu.Lock()
	r.releaseLocked(p.slot)
	r.gating++
	r.wg.Add(1)
	go r.resumeGate(p, e, reason, r.stoppedLocked())
	dropped := r.dispatchLocked()
	r.mu.Unlock()

	r.logDropped(dropped)
}

// stoppedLocked is the channel shutdown closes. The caller holds mu.
func (r *router) stoppedLocked() chan struct{} {
	if r.stopped == nil {
		r.stopped = make(chan struct{})
	}

	return r.stopped
}

// resumeGate decides whether a run that did not finish resumes. It holds the
// card key and no worker slot. A failed run waits resumeDelay first. A card
// that left the column of its series is not resumed, and a failed card read
// resumes anyway. The ended run's outcome waits for the decision, so it can
// say why no resume ran.
func (r *router) resumeGate(p pending, e endedRun, reason string, stopped <-chan struct{}) {
	defer r.wg.Done()

	if e.state == api.RunFailed {
		after := r.after
		if after == nil {
			after = time.After
		}
		select {
		case <-after(resumeDelay):
		case <-stopped:
		case <-r.workerContext().Done():
		}
	}

	var column string
	var readErr error
	cardID, _ := cardOf(p.event)
	read := r.readCard != nil && p.column != "" && cardID != ""
	// A shutdown does not cancel the worker context, so it would not end a read.
	select {
	case <-stopped:
		read = false
	default:
	}
	if read {
		timeout := r.checkTimeout
		if timeout <= 0 {
			timeout = askCheckTimeout
		}
		ctx, cancel := context.WithTimeout(r.workerContext(), timeout)
		var card api.CardRead
		card, readErr = r.readCard(ctx, p.event.ProjectID, cardID)
		column = card.Column
		cancel()
	}

	// A handover counts this gate until it decides, and holds back a decision
	// that comes after the freeze, so the frozen state stays true.
	r.quiesce.RLock()
	defer r.quiesce.RUnlock()
	r.mu.Lock()
	if r.frozen {
		r.wg.Add(1)
		r.heldFinishes = append(r.heldFinishes, func() {
			defer r.wg.Done()
			r.mu.Lock()
			r.decideResume(p, e, reason, read, column, readErr)
		})
		r.mu.Unlock()

		return
	}
	r.decideResume(p, e, reason, read, column, readErr)
}

// decideResume queues the resume of a run that did not finish, or reports why
// it gets none, and frees its card then. The caller holds mu, which
// decideResume releases.
func (r *router) decideResume(p pending, e endedRun, reason string, read bool, column string, readErr error) {
	r.gating--
	if r.stops[p.runID] {
		r.closeStoppedLocked(p, r.stoppedReport(p, e), true)
		dropped := r.dispatchLocked()
		r.mu.Unlock()
		r.logDropped(dropped)

		return
	}
	current := r.rules()
	m, ok := matchWorker(current, p.event, p.rule)
	switch {
	case r.shut():
		e.skipped = api.DropShutdown
	case !ok:
		// A dead rule means a kill ended it, and a live one a reload.
		e.skipped = api.DropRuleDead
		if current.Live(p.rule) {
			e.skipped = api.DropReload
		}
	case read && readErr != nil:
		r.log.Warn("card_read_failed", append(about(p.event, p.rule),
			"error", readErr.Error(),
			"message", "the bridge could not read the card, so it resumes the session anyway",
		)...)
	case read && column != p.column:
		e.skipped = api.SkipCardMoved
	}
	if e.skipped != "" {
		r.log.Warn("resume_skipped", append(about(p.event, p.rule),
			"reason", e.skipped,
			"message", fmt.Sprintf("the bridge does not resume the session of %s, which ended with %s", aggregate(p.event), reason),
		)...)
		r.emitLocked(p, r.outcome(p, e))
		delete(r.running, p.key)
		dropped := r.dispatchLocked()
		r.mu.Unlock()
		r.logDropped(dropped)

		return
	}

	next := p
	next.slot = ""
	next.continues, next.runID, next.resumeIndex = p.runID, config.NewUUID(), p.resumeIndex+1
	next.set, next.checked = current, true
	next.spec.resume, next.spec.prompt = true, directive.RenderResumeUnfinished(reason)
	next.apply(m)
	r.log.Warn("worker_resuming", append(about(p.event, p.rule),
		"session_id", next.spec.sessionID,
		"resume", next.resumeIndex,
		"max_resumes", next.maxResumes,
		"reason", reason,
	)...)
	r.emitLocked(p, r.outcome(p, e))
	r.emitLocked(next, api.RunStateReport{State: api.RunQueued})
	// The resume takes the place of the run it continues, ahead of each event
	// that arrived after that run.
	i, _ := slices.BinarySearchFunc(r.queue, next.seq, func(q pending, seq uint64) int { return cmp.Compare(q.seq, seq) })
	r.queue = slices.Insert(r.queue, i, next)
	dropped := r.dispatchLocked()
	r.mu.Unlock()

	r.logDropped(dropped)
}

// check reads the ask of a resume the queue released, on its own goroutine.
// The resume holds its card key and no worker slot meanwhile. A session that
// read every item of its closed ask is skipped. Any failed check resumes, so a
// fault never loses a resume. The caller holds mu, and check never takes it.
func (r *router) check(p pending) {
	r.checking++
	r.wg.Add(1)
	go func() {
		defer r.wg.Done()

		timeout := r.checkTimeout
		if timeout <= 0 {
			timeout = askCheckTimeout
		}
		ctx, cancel := context.WithTimeout(r.workerContext(), timeout)
		state, err := r.checkAsk(ctx, p.event.ProjectID, p.event.Subject.ID)
		cancel()

		// A kill or a reload rewrites queued events only, and this resume was out
		// of the queue during the check, so its rule matches again under the
		// same lock.
		r.quiesce.RLock()
		defer r.quiesce.RUnlock()
		r.mu.Lock()
		r.checking--
		current := r.rules()
		m, ok := matchWorker(current, p.event, p.rule)
		if ok {
			p.set = current
			p.apply(m)
		}
		switch {
		case r.shut():
			p.dropReason = api.DropShutdown
			delete(r.running, p.key)
			dropped := append([]pending{p}, r.dispatchLocked()...)
			r.mu.Unlock()
			r.logDropped(dropped)

			return
		case r.stops[p.runID]:
			r.closeStoppedLocked(p, api.RunStateReport{State: api.RunStopped}, true)
		case !ok:
			// A dead rule means a kill ended it, and a live one a reload.
			var reason []any
			p.dropReason = api.DropRuleDead
			if current.Live(p.rule) {
				reason = []any{"reason", "reload"}
				p.dropReason = api.DropReload
			}
			delete(r.running, p.key)
			shutDropped := r.dispatchLocked()
			r.mu.Unlock()
			r.logDropped([]pending{p}, reason...)
			r.logDropped(shutDropped)

			return
		case err != nil:
			r.log.Warn("resume_check_failed", append(about(p.event, p.rule),
				"error", err.Error(),
				"message", "the bridge could not check the ask, so it resumes the session anyway",
			)...)
			p.checked = true
		case state.Closed && state.AllRead:
			r.log.Info("resume_skipped", append(about(p.event, p.rule),
				"message", "the session already read every item of its ask",
			)...)
			r.emitLocked(p, api.RunStateReport{State: api.RunSkipped})
			delete(r.running, p.key)
		default:
			p.checked = true
		}
		// A resume the check lets through returns to its place in arrival order.
		if p.checked {
			r.emitLocked(p, api.RunStateReport{State: api.RunResumed, AskID: askOf(p.event)})
			i, _ := slices.BinarySearchFunc(r.queue, p.seq, func(q pending, seq uint64) int { return cmp.Compare(q.seq, seq) })
			r.queue = slices.Insert(r.queue, i, p)
		}
		dropped := r.dispatchLocked()
		r.mu.Unlock()

		r.logDropped(dropped)
	}()
}

// shut reports whether the queue accepts no more starts. The caller holds mu.
//
// A cancelled context counts. Ctrl-C kills the workers before Subscribe
// unwinds, so a worker that finishes first would otherwise start a queued card
// that cannot run.
func (r *router) shut() bool {
	return r.closed || r.workerContext().Err() != nil
}

// shutdown stops the queue for good and drops what is still in it. A paused or
// frozen router resumes, so the runs and events it holds back reach the report.
func (r *router) shutdown() {
	r.mu.Lock()
	if !r.closed {
		close(r.stoppedLocked())
	}
	r.closed = true
	r.mu.Unlock()

	r.resume()
}

// logDropped names what a shut queue lost. These workers never started, so a
// silent drop would hide a trigger the operator asked for. One card can wait
// once per rule or per ask, so each entry names the card, the rule and the ask.
// attrs add to the line, such as the reason of a reload.
func (r *router) logDropped(dropped []pending, attrs ...any) {
	if len(dropped) == 0 {
		return
	}

	lost := make([]map[string]any, len(dropped))
	for i, p := range dropped {
		k, v := label(p.event)
		lost[i] = map[string]any{k: v, "rule": p.rule}
		if p.pool != "" {
			lost[i]["worker_pool"] = p.pool
		}
		if ask := askOf(p.event); ask != "" {
			lost[i]["ask"] = ask
		}
	}
	r.log.Warn("queue_dropped", append([]any{"count", len(lost), "dropped", lost}, attrs...)...)
	for _, p := range dropped {
		r.emit(p, api.RunStateReport{State: api.RunDropped, Reason: p.dropReason})
	}
}

// outcome is how a run ended, as Loupe takes it. A worker that never ran sends
// no exit code and says why instead, because the server keeps the two faults
// apart. A run with no card logs its skip here, once. endedAt is derived from
// the start, so it never precedes startedAt, whatever the wall clock does.
func (r *router) outcome(p pending, e endedRun) api.RunStateReport {
	if !r.reporting() {
		return api.RunStateReport{}
	}
	if _, cardNumber := cardOf(p.event); cardNumber < 1 {
		r.log.Warn("report_skipped", append(about(p.event, p.rule),
			"message", "Loupe records a run against a card, and this event names none",
		)...)

		return api.RunStateReport{}
	}

	report := api.RunStateReport{
		State:         e.state,
		SessionID:     sessionOf(p, e),
		StartedAt:     e.began,
		EndedAt:       e.began.Add(e.elapsed),
		Output:        e.res.output,
		ResumeSkipped: e.skipped,
	}
	if e.res.err != nil {
		reason := e.res.err.Error()
		report.FailureReason = &reason

		return report
	}
	// A process that ran carries its exit code and its result flag together.
	report.ExitCode, report.HasResult = &e.res.exitCode, &e.res.hasResult
	if e.res.hasResult {
		report.ResultStatus = e.res.status
		report.ResultReason = resultReason(e.res.reason)
		report.ResultFields = r.resultFields(p, e.res.fields)
	}
	report.Usage = r.usage(p, e.res.usage)

	return report
}

// reasonPattern is the reason code Loupe takes. Loupe refuses the whole report
// for any other reason, so the bridge sends no reason instead.
var reasonPattern = regexp.MustCompile(`^[a-z][a-z0-9-]{0,39}$`)

func resultReason(reason string) string {
	reason = strings.TrimSpace(reason)
	if !reasonPattern.MatchString(reason) {
		return ""
	}

	return reason
}

// sessionOf is the session a report of the ended run names. A run whose
// before command ended it started no new session, so it names none.
func sessionOf(p pending, e endedRun) string {
	if e.res.before && !p.spec.resume {
		return ""
	}

	return p.spec.sessionID
}

// usage is the usage the server takes. The bridge sends none rather than one
// the server refuses, because a 422 would lose the whole outcome.
func (r *router) usage(p pending, usage *api.Usage) *api.Usage {
	if usage == nil {
		return nil
	}
	if err := usage.Check(); err != nil {
		r.log.Warn("usage_dropped", append(about(p.event, p.rule), "message", err.Error())...)

		return nil
	}

	return usage
}

// resultFields are the extra result fields the server takes. Past its limit
// the bridge sends none, because a 422 would lose the whole outcome.
func (r *router) resultFields(p pending, fields map[string]any) map[string]any {
	if len(fields) == 0 {
		return nil
	}
	var buffer bytes.Buffer
	encoder := json.NewEncoder(&buffer)
	encoder.SetEscapeHTML(false)
	err := encoder.Encode(fields)
	size := phpJSONLength(bytes.TrimSuffix(buffer.Bytes(), []byte("\n")))
	if err == nil && size <= maxResultFields {
		return fields
	}
	r.log.Warn("result_fields_dropped", append(about(p.event, p.rule),
		"bytes", size,
		"message", fmt.Sprintf("the result fields take %d bytes as JSON, and Loupe takes at most %d", size, maxResultFields),
	)...)

	return nil
}

// phpJSONLength is the length of PHP's json_encode of the same value, which
// the server measures. It takes Go's encoding without HTML escapes, which
// already writes U+2028 and U+2029 as \u2028 and \u2029 like PHP. PHP also
// escapes each slash, and writes each UTF-16 unit of a non-ASCII character as \uXXXX.
func phpJSONLength(encoded []byte) int {
	n := 0
	for _, c := range string(encoded) {
		switch {
		case c == '/':
			n += 2
		case c > unicode.MaxASCII:
			n += 6 * len(utf16.Encode([]rune{c}))
		default:
			n++
		}
	}

	return n
}

// reporting reports whether the router sends run reports at all.
func (r *router) reporting() bool {
	return r.bridgeID != "" && r.reports != nil && r.runs != nil
}

// emit hands one state of p's run to the queue, under mu.
func (r *router) emit(p pending, report api.RunStateReport) {
	r.mu.Lock()
	defer r.mu.Unlock()

	r.emitLocked(p, report)
}

// emitLocked hands one state of p's run to the queue, and keeps held in step
// with it. The caller holds mu, so the states of a run keep their order and an
// inventory never lists a run whose closed state went before it. A run with no
// card sends nothing.
func (r *router) emitLocked(p pending, report api.RunStateReport) {
	cardID, cardNumber := cardOf(p.event)
	if !r.reporting() || cardNumber < 1 || (p.isWork() && p.claimToken == "") {
		return
	}
	switch report.State {
	case api.RunQueued, api.RunResumed, api.RunPreparing, api.RunRunning, api.RunStopping:
		if r.held == nil {
			r.held = map[string]api.InventoryRun{}
		}
		r.held[p.runID] = api.InventoryRun{RunID: p.runID, ProjectID: p.event.ProjectID, State: report.State}
	default:
		// A run that closes another way drops its stop mark too, so no mark
		// outlives its run and holds up the drain of a handover.
		delete(r.held, p.runID)
		delete(r.stops, p.runID)
	}

	// The server stores the series link from the report that creates the run.
	if report.State == api.RunQueued && p.continues != "" {
		report.Continues = p.continues
	}
	report.BridgeID, report.At = r.bridgeID, time.Now()
	report.CardID, report.CardNumber, report.Rule = cardID, cardNumber, p.rule
	if w := p.workOrOrigin(); w.Kind != "" {
		report.WorkRequestID, report.WorkKind, report.RuleID = w.WorkRequestID, w.Kind, w.RuleID
	}
	report.WorkerPool = cmp.Or(p.slot, p.pool)
	if p.isCommand() {
		report.Kind = api.RunKindCommand
	}
	// The pin is known once the run starts, so a queued run sends none.
	if report.State == api.RunRunning || api.IsOutcome(report.State) {
		report.Experiment, report.Variant = p.pin.Experiment, p.pin.Variant
		report.RequestedModel, report.SwitchedFrom = p.pin.RequestedModel, p.pin.SwitchedFrom
	}
	// The handle is the project id the event carried, which a rename never
	// changes.
	r.reports.Enqueue(r.runs.state(p.event.ProjectID, p.runID, report))
}

// sendInventory hands the runs the bridge holds to the queue. It takes mu, so
// the inventory lands after every state queued before it.
func (r *router) sendInventory() {
	if !r.reporting() {
		return
	}
	r.mu.Lock()
	defer r.mu.Unlock()

	runs := make([]api.InventoryRun, 0, len(r.held))
	for _, run := range r.held {
		runs = append(runs, run)
	}
	slices.SortFunc(runs, func(a, b api.InventoryRun) int { return strings.Compare(a.RunID, b.RunID) })
	r.reports.Enqueue(r.runs.inventory(r.bridgeID, runs))
}

// logResult writes what a worker ended as. The bridge owns the worker's
// streams, so this line is the operator's only view of what claude answered or
// why it failed.
func (r *router) logResult(p pending, res workerResult, elapsed time.Duration) {
	if res.err != nil {
		r.log.Error("worker_failed", append(about(p.event, p.rule), "error", res.err.Error())...)

		return
	}

	args := append(about(p.event, p.rule),
		"exit", res.exitCode,
		"duration_ms", elapsed.Milliseconds(),
		"status", res.status,
		"output", res.output,
	)
	switch {
	case res.command && res.exitCode == 0:
		r.log.Info("command_finished", append(about(p.event, p.rule), "exit", res.exitCode, "duration_ms", elapsed.Milliseconds(), "output", res.output)...)
	case res.command:
		r.log.Error("command_failed", append(about(p.event, p.rule), "exit", res.exitCode, "duration_ms", elapsed.Milliseconds(), "output", res.output)...)
	case res.before:
		r.log.Error("before_failed", append(about(p.event, p.rule), "exit", res.exitCode, "duration_ms", elapsed.Milliseconds(), "output", res.output)...)
	case res.resumeGone:
		r.log.Error("resume_failed", append(about(p.event, p.rule), "session_id", p.spec.sessionID, "output", res.output)...)
	case res.killed:
		r.log.Error("worker_finished", args...)
	case !res.hasResult:
		// claude -p can end a worker mid-task and still exit 0.
		r.log.Error("worker_no_result", args...)
	case res.exitCode != 0:
		r.log.Error("worker_finished", args...)
	default:
		r.log.Info("worker_finished", args...)
	}
}

func (r *router) workerContext() context.Context {
	if r.ctx == nil {
		return context.Background()
	}

	return r.ctx
}
