package cmd

import (
	"cmp"
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"os"
	"slices"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/outbound"
	"github.com/ubermuda/loupe/cli/internal/rules"
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
	// projects names the mapped slugs in the connected line. A reload writes
	// it under mu.
	projects   []string
	topic      string
	maxWorkers int
	worker     workerOps
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
	// card and rule, or for each ask of a resume. running holds the key of each
	// card with a worker or an ask check. A card runs once, and waits once per
	// rule or ask.
	queue   []pending
	running map[string]bool
	// chains counts, per card key and rule name, the runs in a row that an
	// agent's event started. An entry must outlive its workers to hold the cap,
	// so it stays until a person acts: one small map per card agents ran on.
	chains map[string]map[string]int
	// busy is the last busy or idle the hook runner got. The bridge starts idle.
	busy   bool
	active int
	closed bool
	seq    uint64
	// inbox is the inbox flag of the last GET /api/events. A worker that starts
	// while it is on reads both ids in its prompt.
	inbox bool
	// sessions maps each session the bridge ran to its key and card. An entry
	// outlives its worker, because an ask can close before the run report lands.
	sessions map[string]sessionCard
	// held maps the id of each open run the bridge reported to its project and
	// state. Each connect sends it as the run inventory.
	held map[string]api.InventoryRun

	// unmapped remembers the projects already logged as unmapped, and gone the
	// mapped projects already logged as gone. Both hold project ids, because a
	// reload can map a slug to another project.
	unmapped map[string]bool
	gone     map[string]bool

	// paused stops dispatch, and frozen also holds back the events and the
	// finished runs that arrive after a freeze, for resume to replay. checking
	// counts the ask checks in flight, and live the workers that started.
	paused       bool
	frozen       bool
	checking     int
	live         map[string]liveRun
	heldEvents   []heldEvent
	heldFinishes []func()
	// lastEventID is the resume point of the stream, and recent the ids of the
	// last events handled, oldest first, which recentSet indexes.
	lastEventID string
	recent      []string
	recentSet   map[string]bool
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
}

// apply takes the rule, the settings and the prompt of a match. The session id
// stays empty until start.
func (p *pending) apply(m rules.Match) {
	p.rule, p.maxChain = m.Rule, m.MaxChain
	p.spec = workerSpec{dir: m.Dir, permissionMode: m.PermissionMode, model: m.Model, prompt: m.Prompt, resume: m.Resume}
}

// sessionCard is the key a session's worker ran under, and its card when the
// event named one.
type sessionCard struct {
	key    string
	id     string
	number int
}

// askCheckTimeout bounds the ask check. A check that runs out resumes anyway.
const askCheckTimeout = 10 * time.Second

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
	if e.Type == event.ReviewSubmittedType && e.CardID != "" {
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
	if e.Type == event.AskClosedType || e.Type == event.ReviewSubmittedType {
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

// label names an event's aggregate to a reader: its card number when the event
// carries one, and its subject id otherwise.
func label(e event.Event) (string, any) {
	if e.Type == event.CardMovedType || ((e.Type == event.AskClosedType || e.Type == event.ReviewSubmittedType) && e.CardNumber > 0) {
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
// an older binary never heard of, which is normal.
func (r *router) onData(data []byte) {
	// Every bridge of the account receives an ask event, and only the one that
	// started the session can resume it. Another bridge's event is not ours to
	// validate, so it is dropped before Parse can log it.
	if event.ForAnotherBridge(data, r.bridgeID) {
		return
	}
	set := r.rules()
	e, err := event.Parse(data, set.ExtraTypes())
	if err != nil {
		if errors.Is(err, event.ErrUnknownType) {
			return
		}
		r.log.Error("event_malformed", "error", err.Error())

		return
	}
	e, key := r.resolve(e)

	// A slug change is a person's action, not a directive to an agent, so it
	// kills rules whatever its actor. Matching then goes on as for any event.
	dead, dropped := r.kill(e, func(s *rules.Set) []rules.Dead { return s.Kill(e) })
	r.logDead(e, dead)
	r.logDropped(dropped)

	// A person who touches the card has seen it, which is what a capped chain
	// waits for. Any event of theirs that parsed counts, matched or not.
	if e.Actor == event.ActorHuman {
		r.mu.Lock()
		delete(r.chains, key)
		r.mu.Unlock()
	}

	m := set.Match(e)
	if m.Skip == rules.Run {
		p := pending{key: key, event: e, set: set}
		p.apply(m)
		r.enqueue(p)

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
		p := pending{key: key, event: e, set: set}
		p.apply(m)
		r.enqueue(p)
	case rules.Untrusted:
		r.log.Warn("event_untrusted", about(e, m.Rule)...)
	case rules.Unmapped:
		if first {
			r.log.Warn("project_unmapped", "project", e.ProjectID)
		}
	}
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

	return r.dropDeadLocked(do(r.rules()))
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
	dead, dropped := r.dropDeadLocked(killGone(r.rules(), id))

	return dead, dropped, true
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
	if p.event.Actor == event.ActorAgent && r.chains[p.key][p.rule] >= p.maxChain {
		r.log.Warn("chain_capped", append(about(p.event, p.rule),
			"max_chain", p.maxChain,
			"message", fmt.Sprintf("%s hit the chain cap of rule %s, waiting for a person", aggregate(p.event), p.rule),
		)...)
		r.emitLocked(p, api.RunStateReport{State: api.RunWaitingForPerson, MaxChain: p.maxChain})
		r.mu.Unlock()

		return
	}
	if i := slices.IndexFunc(r.queue, func(q pending) bool {
		return q.key == p.key && q.rule == p.rule && askOf(q.event) == askOf(p.event)
	}); i >= 0 {
		p.checked, p.seq = r.queue[i].checked, r.queue[i].seq
		r.emitLocked(r.queue[i], api.RunStateReport{State: api.RunReplaced, ReplacedBy: p.runID})
		r.queue[i] = p
		r.log.Info("worker_coalesced", about(p.event, p.rule)...)
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
	r.log.Info("worker_queued", append(about(p.event, p.rule), "queue_depth", len(r.queue))...)
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

// dispatchLocked starts the oldest queued events whose card is free, while a
// slot is free, and starts the ask check of a queued resume whether or not a
// slot is. The caller holds mu, and logs the queue a shut router returns. One
// card runs one worker, because two agents in one checkout undo each other.
// Pop and start share the lock, or two finishing workers could reorder starts.
func (r *router) dispatchLocked() []pending {
	defer r.noteBusyLocked()
	if r.shut() {
		dropped := r.queue
		r.queue = nil
		for i := range dropped {
			dropped[i].dropReason = api.DropShutdown
		}

		return dropped
	}
	if r.paused {
		return nil
	}

	// waiting holds the keys of events this pass leaves queued, so a later
	// event of the same card never goes first.
	waiting := map[string]bool{}
	for i := 0; i < len(r.queue); {
		next := r.queue[i]
		if waiting[next.key] || (r.running[next.key] && !next.checked) {
			waiting[next.key] = true
			i++

			continue
		}
		if next.spec.resume && !next.checked && r.checkAsk != nil {
			r.queue = slices.Delete(r.queue, i, i+1)
			r.hold(next.key)
			r.check(next)

			continue
		}
		if r.active >= r.maxWorkers {
			waiting[next.key] = true
			i++

			continue
		}
		r.queue = slices.Delete(r.queue, i, i+1)
		// Asks never coalesce, so several agent closes of one card can pass the
		// cap at enqueue and wait together. The cap is read again here.
		if next.spec.resume && next.event.Actor == event.ActorAgent && r.chains[next.key][next.rule] >= next.maxChain {
			r.log.Warn("chain_capped", append(about(next.event, next.rule),
				"max_chain", next.maxChain,
				"message", fmt.Sprintf("%s hit the chain cap of rule %s, waiting for a person", aggregate(next.event), next.rule),
			)...)
			r.emitLocked(next, api.RunStateReport{State: api.RunWaitingForPerson, MaxChain: next.maxChain})
			delete(r.running, next.key)

			continue
		}
		r.active++
		r.hold(next.key)
		if next.event.Actor == event.ActorAgent {
			r.countChain(next.key, next.rule)
		}
		r.start(next)
	}

	return nil
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

// start runs one worker, as a new claude session or as the resume of the
// session its ask names. The caller holds mu, and start never takes it.
//
// wg counts the worker before the goroutine exists, and the finish call that
// admits the next worker runs before wg.Done, so a waiter never sees the count
// reach zero between two queued workers.
func (r *router) start(p pending) {
	if p.spec.resume {
		p.spec.sessionID = p.event.SessionID
		r.log.Info("worker_started", about(p.event, p.rule)...)
	} else {
		p.spec.sessionID = r.worker.sessionID()
		r.log.Info("worker_started", append(about(p.event, p.rule), "session_id", p.spec.sessionID)...)
	}
	p.spec.runID, p.spec.rule, p.spec.key = p.runID, p.rule, p.key
	if r.inbox {
		p.spec.prompt += "\n" + directive.InboxLine(p.spec.sessionID, r.bridgeID)
	}
	id, number := cardOf(p.event)
	if r.sessions == nil {
		r.sessions = map[string]sessionCard{}
	}
	r.sessions[p.spec.sessionID] = sessionCard{key: p.key, id: id, number: number}
	// A checked resume sent resumed when its check let it through.
	if p.spec.resume && !p.checked {
		r.emitLocked(p, api.RunStateReport{State: api.RunResumed, AskID: askOf(p.event)})
	}
	r.wg.Add(1)
	go func() {
		defer r.wg.Done()

		// The spawn time stands in for a process that never starts, because the
		// old report needs a start. onStart runs on this goroutine, before run returns.
		began := time.Now()
		onStart := func(proc workerProc) {
			began = time.Now()
			r.mu.Lock()
			defer r.mu.Unlock()
			r.trackLocked(liveRun{p: p, began: began, proc: proc})
			r.emitLocked(p, api.RunStateReport{State: api.RunRunning, SessionID: p.spec.sessionID, StartedAt: began})
		}
		res := r.worker.run(r.workerContext(), p.spec, onStart)
		r.settle(p, res, began, time.Since(began))
	}()
}

// liveRun is a worker that started, with what its report and a handover need.
type liveRun struct {
	p     pending
	began time.Time
	proc  workerProc
}

// trackLocked records a started worker. The caller holds mu.
func (r *router) trackLocked(run liveRun) {
	if r.live == nil {
		r.live = map[string]liveRun{}
	}
	r.live[run.p.runID] = run
}

// settle reports a finished worker, removes its run directory and frees its
// slot. After a freeze, the next image adopts the run from its files, so the
// run waits here for a resume that may never come.
func (r *router) settle(p pending, res workerResult, began time.Time, elapsed time.Duration) {
	r.quiesce.RLock()
	defer r.quiesce.RUnlock()
	done := func() {
		r.report(p, res, began, elapsed)
		if res.dir != "" {
			_ = os.RemoveAll(res.dir)
		}
		r.finish(p.key)
	}
	r.mu.Lock()
	delete(r.live, p.runID)
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
		m, ok := current.MatchRule(p.event, p.rule)
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

// finish frees the slot and the card, and starts what waits, in one critical
// section. A new event for the card cannot slip between the release and the
// start of the card's waiting event.
func (r *router) finish(key string) {
	r.mu.Lock()
	delete(r.running, key)
	r.active--
	dropped := r.dispatchLocked()
	r.mu.Unlock()

	r.logDropped(dropped)
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
		if ask := askOf(p.event); ask != "" {
			lost[i]["ask"] = ask
		}
	}
	r.log.Warn("queue_dropped", append([]any{"count", len(lost), "dropped", lost}, attrs...)...)
	for _, p := range dropped {
		r.emit(p, api.RunStateReport{State: api.RunDropped, Reason: p.dropReason})
	}
}

// report says how a worker ended. The log is the operator's view, and the queue
// carries the same run to Loupe.
func (r *router) report(p pending, res workerResult, began time.Time, elapsed time.Duration) {
	r.logResult(p, res, elapsed)
	r.reportOutcome(p, res, began, elapsed)
}

// reportOutcome hands how a run ended to Loupe. A worker that never ran sends
// no exit code and says why instead, because the server keeps the two faults
// apart. A run with no card logs its skip here, once. endedAt is derived from
// the start, so it never precedes startedAt, whatever the wall clock does.
func (r *router) reportOutcome(p pending, res workerResult, began time.Time, elapsed time.Duration) {
	if !r.reporting() {
		return
	}
	if _, cardNumber := cardOf(p.event); cardNumber < 1 {
		r.log.Warn("report_skipped", append(about(p.event, p.rule),
			"message", "Loupe records a run against a card, and this event names none",
		)...)

		return
	}

	report := api.RunStateReport{
		State:     api.RunSucceeded,
		SessionID: p.spec.sessionID,
		StartedAt: began,
		EndedAt:   began.Add(elapsed),
		Output:    res.output,
	}
	if res.err != nil {
		reason := res.err.Error()
		report.State, report.FailureReason = api.RunNotStarted, &reason
		r.emit(p, report)

		return
	}
	// A process that ran carries its exit code and its result flag together.
	report.ExitCode, report.HasResult = &res.exitCode, &res.hasResult
	switch {
	case res.exitCode != 0:
		report.State = api.RunFailed
	case !res.hasResult:
		report.State = api.RunNoResult
	}
	r.emit(p, report)
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
	if !r.reporting() || cardNumber < 1 {
		return
	}
	switch report.State {
	case api.RunQueued, api.RunResumed, api.RunRunning:
		if r.held == nil {
			r.held = map[string]api.InventoryRun{}
		}
		r.held[p.runID] = api.InventoryRun{RunID: p.runID, ProjectID: p.event.ProjectID, State: report.State}
	default:
		delete(r.held, p.runID)
	}

	report.BridgeID, report.At = r.bridgeID, time.Now()
	report.CardID, report.CardNumber, report.RuleName = cardID, cardNumber, p.rule
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
		"output", res.output,
	)
	switch {
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
