package cmd

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

const (
	testAsk       = "01a0a1b2-0000-7c3d-8e4f-5a6b7c8d9e0f"
	otherAsk      = "01a0a1b2-0000-7c3d-8e4f-000000000002"
	askSession    = "5f0c7e2a-1b3d-4c5e-8f9a-0b1c2d3e4f5a"
	foreignBridge = "7d1e2f3a-4b5c-4d6e-9f0a-1b2c3d4e5f6a"
)

// resumeRules adds a resume rule to the card rule of defaultRules.
const resumeRules = defaultRules + `
  - name: resume
    on: inbox.ask_closed
    project: loupe
    resume: true
    prompt: Ask {askId} closed on card {cardNumber}.
`

// ask describes one inbox.ask_closed payload. A zero card sends a null card,
// and a nil bridge sends a null bridge id.
type ask struct {
	id      string
	session string
	bridge  *string
	card    int
	actor   string
}

func (a ask) payload() string {
	id, session, actor := a.id, a.session, a.actor
	if id == "" {
		id = testAsk
	}
	if session == "" {
		session = askSession
	}
	if actor == "" {
		actor = "human"
	}
	bridge, cardID, cardNumber := "null", "null", "null"
	if a.bridge != nil {
		bridge = fmt.Sprintf("%q", *a.bridge)
	}
	if a.card > 0 {
		cardID, cardNumber = fmt.Sprintf("%q", cardUUID(a.card)), fmt.Sprint(a.card)
	}

	return fmt.Sprintf(`{"type":"inbox.ask_closed","projectId":%q,"subject":{"type":"inbox-ask","id":%q},"sessionId":%q,"bridgeId":%s,"cardId":%s,"cardNumber":%s,"actor":%q}`,
		testProject, id, session, bridge, cardID, cardNumber, actor)
}

// mine names the harness's own bridge, so the router accepts the event.
func (h *harness) mine(a ask) string {
	id := h.router.bridgeID
	a.bridge = &id

	return a.payload()
}

func resumePrompt(card string) string {
	return "Ask " + testAsk + " closed on card " + card + ".\n\n" + directive.ResumeFooter
}

// receive waits for the next report, so a missing one fails rather than hangs.
func receive(t *testing.T, sent chan reported) reported {
	t.Helper()
	select {
	case got := <-sent:
		return got
	case <-time.After(5 * time.Second):
		t.Fatal("no report reached the queue")

		return reported{}
	}
}

// checks records each ask check and answers with a fixed state or error.
type checks struct {
	mu    sync.Mutex
	calls []string
	state api.AskState
	err   error
	wait  bool
}

func (c *checks) check(ctx context.Context, handle, askID string) (api.AskState, error) {
	c.mu.Lock()
	c.calls = append(c.calls, handle+" "+askID)
	c.mu.Unlock()
	if c.wait {
		<-ctx.Done()

		return api.AskState{}, ctx.Err()
	}

	return c.state, c.err
}

func (c *checks) recorded() []string {
	c.mu.Lock()
	defer c.mu.Unlock()

	return append([]string(nil), c.calls...)
}

func TestResumeArgsResumeTheSession(t *testing.T) {
	for _, tc := range []struct {
		spec workerSpec
		want string
	}{
		{workerSpec{resume: true, sessionID: askSession, prompt: "go"}, "-p --resume " + askSession + " -- go"},
		{workerSpec{resume: true, sessionID: askSession, permissionMode: "plan", model: "opus", prompt: "go"}, "--permission-mode plan --model opus -p --resume " + askSession + " -- go"},
	} {
		if got := strings.Join(workerArgs(tc.spec), " "); got != tc.want {
			t.Fatalf("workerArgs(%+v) = %q, want %q", tc.spec, got, tc.want)
		}
	}
}

// A resume runs under the session that asked, in the project's dir, with the
// resume footer. The inbox line stays, because a resumed agent can ask again.
func TestAnAskClosedResumesItsSession(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	h.router.onRefresh(listed(map[string]any{api.InboxFlag: true}))

	h.send(h.mine(ask{card: 87}))

	calls := h.worker.recorded()
	if len(calls) != 1 {
		t.Fatalf("workers = %+v", calls)
	}
	got := calls[0]
	if !got.resume || got.sessionID != askSession || got.dir != h.dir {
		t.Fatalf("worker = %+v", got)
	}
	if want := resumePrompt("87") + "\n" + inboxLine(askSession); got.prompt != want {
		t.Fatalf("prompt = %q, want %q", got.prompt, want)
	}
	started := h.only(t, "worker_started")
	if num(t, started, "card") != 87 || str(t, started, "ask") != testAsk || str(t, started, "session_id") != askSession || str(t, started, "rule") != "resume" {
		t.Fatalf("worker_started = %v", started)
	}
}

// Only the bridge that started the session may resume it. Another bridge's
// event, or one that names none, starts nothing and resets nothing.
func TestAnAskClosedForAnotherBridgeIsDropped(t *testing.T) {
	other := foreignBridge
	empty := ""
	for name, payload := range map[string]string{
		"another bridge": ask{card: 87, bridge: &other}.payload(),
		"a null bridge":  ask{card: 87}.payload(),
		"an empty id":    ask{card: 87, bridge: &empty}.payload(),
		"no bridge key":  strings.Replace(ask{card: 87}.payload(), `"bridgeId":null,`, "", 1),
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarnessWith(t, resumeRules, rules.Defaults{})

			h.send(payload)

			if calls := h.worker.recorded(); len(calls) != 0 {
				t.Fatalf("workers = %+v", calls)
			}
			if log := strings.TrimSpace(h.log.String()); log != "" {
				t.Fatalf("log = %s, want nothing", log)
			}
		})
	}
}

// Another bridge's event is not validated, so a field that would be malformed
// for this bridge logs nothing.
func TestAMalformedAskForAnotherBridgeLogsNothing(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	other := foreignBridge
	payload := strings.Replace(ask{card: 87, bridge: &other}.payload(), `"actor":"human"`, `"actor":"system"`, 1)

	h.send(payload)

	if log := strings.TrimSpace(h.log.String()); log != "" {
		t.Fatalf("log = %s, want nothing", log)
	}

	// The same payload for this bridge is malformed, so the test above drops it
	// for its bridge id alone.
	h.send(strings.Replace(payload, foreignBridge, h.router.bridgeID, 1))
	h.only(t, "event_malformed")
}

// A resume never runs beside a worker of its card. It waits in the queue, and
// runs after that worker exits.
func TestAResumeWaitsBehindAWorkerOfItsCard(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(h.mine(ask{card: 87})))

	h.router.mu.Lock()
	active, waiting := h.router.active, len(h.router.queue)
	h.router.mu.Unlock()
	if active != 1 || waiting != 1 {
		t.Fatalf("active = %d, waiting = %d; want the resume to wait", active, waiting)
	}

	close(h.worker.block)
	h.router.wg.Wait()
	calls := h.worker.recorded()
	if len(calls) != 2 || calls[0].resume || !calls[1].resume {
		t.Fatalf("workers = %+v", calls)
	}
	if h.worker.peak() != 1 {
		t.Fatalf("peak concurrency for one card = %d, want 1", h.worker.peak())
	}
}

// An ask can close while its worker still runs, before any run report carries
// the session. The event then names no card, and the bridge takes the card of
// the session it started.
func TestAResumeWithNoCardTakesTheCardOfItsSession(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	sent := h.reports(t)
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	first := <-h.worker.started
	h.router.onData([]byte(h.mine(ask{session: first.sessionID})))

	h.router.mu.Lock()
	waiting := len(h.router.queue)
	h.router.mu.Unlock()
	if waiting != 1 {
		t.Fatalf("waiting = %d; want the resume to wait behind the worker of its session", waiting)
	}

	close(h.worker.block)
	h.router.wg.Wait()
	receive(t, sent)
	resumed := receive(t, sent)
	if resumed.run.CardID != cardUUID(87) || resumed.run.CardNumber != 87 || resumed.run.SessionID != first.sessionID || resumed.run.RuleName != "resume" {
		t.Fatalf("resume report = %+v", resumed.run)
	}
	if calls := h.worker.recorded(); calls[1].prompt != resumePrompt("87") {
		t.Fatalf("prompt = %q", calls[1].prompt)
	}
}

// The bridge keeps a session's card after its worker exits, so an ask that
// closes after the exit, before the run report lands, still finds its card.
func TestTheSessionCardOutlivesItsWorker(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	sent := h.reports(t)

	h.send(cardMoved(87))
	receive(t, sent)
	session := h.worker.recorded()[0].sessionID
	h.send(h.mine(ask{session: session}))

	resumed := receive(t, sent)
	if resumed.run.CardID != cardUUID(87) || resumed.run.CardNumber != 87 || resumed.run.SessionID != session {
		t.Fatalf("resume report = %+v", resumed.run)
	}
}

// The card the event names wins over the session's card.
func TestTheEventsCardWinsOverTheSessionsCard(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	sent := h.reports(t)

	h.send(cardMoved(87))
	receive(t, sent)
	session := h.worker.recorded()[0].sessionID
	h.send(h.mine(ask{session: session, card: 88}))

	if resumed := receive(t, sent); resumed.run.CardID != cardUUID(88) || resumed.run.CardNumber != 88 {
		t.Fatalf("resume report = %+v", resumed.run)
	}
}

// With no card from the event or the bridge, the resume keys on its session and
// reports nothing, because Loupe records a run against a card.
func TestAResumeWithNoCardAtAllKeysOnItsSession(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	sent := h.reports(t)

	h.send(h.mine(ask{}))

	calls := h.worker.recorded()
	if len(calls) != 1 || calls[0].prompt != resumePrompt("unknown") {
		t.Fatalf("workers = %+v", calls)
	}
	select {
	case got := <-sent:
		t.Fatalf("reported a resume with no card: %+v", got)
	default:
	}
	skipped := h.only(t, "report_skipped")
	if str(t, skipped, "subject") != testAsk || str(t, skipped, "ask") != testAsk || str(t, skipped, "session_id") != askSession {
		t.Fatalf("report_skipped = %v", skipped)
	}
}

func TestTheResumeKey(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	h.reports(t)
	h.send(cardMoved(87))
	started := h.worker.recorded()[0].sessionID

	for name, tc := range map[string]struct {
		ask    ask
		key    string
		number int
	}{
		"the event's card":   {ask{card: 88}, cardUUID(88), 88},
		"the session's card": {ask{session: started}, cardUUID(87), 87},
		"the session":        {ask{}, askSession, 0},
	} {
		t.Run(name, func(t *testing.T) {
			e, err := event.Parse([]byte(h.mine(tc.ask)), h.router.rules.ExtraTypes())
			if err != nil {
				t.Fatal(err)
			}
			resolved, key := h.router.resolve(e)
			if key != tc.key || resolved.CardNumber != tc.number {
				t.Fatalf("key = %q, card = %d; want %q, %d", key, resolved.CardNumber, tc.key, tc.number)
			}
		})
	}
}

// Two asks of one card close while its worker runs. Each ask closes once, so
// neither replaces the other in the queue.
func TestTwoAsksOfABusyCardBothResume(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	h.worker.started = make(chan workerSpec, 3)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(h.mine(ask{card: 87})))
	h.router.onData([]byte(h.mine(ask{id: otherAsk, session: sessionUUID(9), card: 87})))

	close(h.worker.block)
	h.router.wg.Wait()
	if calls := h.worker.recorded(); len(calls) != 3 {
		t.Fatalf("workers = %+v, want both resumes", calls)
	}
	if got := h.events(t, "worker_coalesced"); len(got) != 0 {
		t.Fatalf("worker_coalesced = %v", got)
	}
}

// A session that read every item of its closed ask needs no resume.
func TestAResumeIsSkippedWhenTheSessionReadEveryItem(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	sent := h.reports(t)
	c := &checks{state: api.AskState{AskID: testAsk, Closed: true, AllRead: true}}
	h.router.checkAsk = c.check

	h.send(h.mine(ask{card: 87}))

	if calls := h.worker.recorded(); len(calls) != 0 {
		t.Fatalf("workers = %+v, want the resume skipped", calls)
	}
	if got := c.recorded(); len(got) != 1 || got[0] != testProject+" "+testAsk {
		t.Fatalf("checks = %v", got)
	}
	skipped := h.only(t, "resume_skipped")
	if str(t, skipped, "ask") != testAsk || str(t, skipped, "session_id") != askSession || num(t, skipped, "card") != 87 || str(t, skipped, "rule") != "resume" {
		t.Fatalf("resume_skipped = %v", skipped)
	}
	select {
	case got := <-sent:
		t.Fatalf("reported a skipped resume: %+v", got)
	default:
	}

	// The skip frees the card.
	h.send(cardMoved(87))
	if calls := h.worker.recorded(); len(calls) != 1 {
		t.Fatalf("workers = %+v", calls)
	}
}

// The check runs when the queue releases the resume, not when the event arrives,
// so a read by the running worker counts.
func TestTheCheckRunsWhenTheResumeLeavesTheQueue(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	c := &checks{state: api.AskState{AskID: testAsk, Closed: true, AllRead: true}}
	h.router.checkAsk = c.check
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(h.mine(ask{card: 87})))
	if got := c.recorded(); len(got) != 0 {
		t.Fatalf("checked %v while the resume waited", got)
	}

	close(h.worker.block)
	h.router.wg.Wait()
	if got := c.recorded(); len(got) != 1 {
		t.Fatalf("checks = %v", got)
	}
	h.only(t, "resume_skipped")
}

// A check that cannot say the session read everything never loses a resume.
func TestAResumeRunsWhenTheCheckDoesNotSayAllRead(t *testing.T) {
	for name, c := range map[string]*checks{
		"not all read":  {state: api.AskState{AskID: testAsk, Closed: true, AllRead: false}},
		"not closed":    {state: api.AskState{AskID: testAsk, Closed: false, AllRead: true}},
		"ask not found": {err: errors.New(`ask check failed (HTTP 404): {"error":"ask_not_found"}`)},
		"server error":  {err: errors.New("ask check failed (HTTP 500)")},
		"timeout":       {wait: true},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarnessWith(t, resumeRules, rules.Defaults{})
			h.router.checkAsk = c.check
			h.router.checkTimeout = 20 * time.Millisecond

			h.send(h.mine(ask{card: 87}))

			if calls := h.worker.recorded(); len(calls) != 1 || !calls[0].resume {
				t.Fatalf("workers = %+v, want the resume", calls)
			}
			if len(h.events(t, "resume_skipped")) != 0 {
				t.Fatal("logged resume_skipped")
			}
			if c.err != nil || c.wait {
				failed := h.only(t, "resume_check_failed")
				if str(t, failed, "ask") != testAsk || str(t, failed, "error") == "" {
					t.Fatalf("resume_check_failed = %v", failed)
				}
			}
		})
	}
}

// gate holds each ask check until the test releases it, and answers not read.
type gate struct {
	entered chan struct{}
	release chan struct{}
}

func newGate() *gate {
	return &gate{entered: make(chan struct{}, 4), release: make(chan struct{})}
}

func (g *gate) check(_ context.Context, _, askID string) (api.AskState, error) {
	g.entered <- struct{}{}
	<-g.release

	return api.AskState{AskID: askID, Closed: true, AllRead: false}, nil
}

// A stream failure shuts the queue with the context still live. A check that
// finishes after that starts no worker on a bridge that is exiting.
func TestAResumeWhoseCheckEndsAfterShutdownDoesNotRun(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	g := newGate()
	h.router.checkAsk = g.check

	h.router.onData([]byte(h.mine(ask{card: 87})))
	<-g.entered
	h.router.shutdown()
	close(g.release)
	h.router.wg.Wait()

	if calls := h.worker.recorded(); len(calls) != 0 {
		t.Fatalf("workers = %+v, want none after shutdown", calls)
	}
	if len(h.events(t, "worker_started")) != 0 {
		t.Fatal("logged worker_started after shutdown")
	}
	line := h.only(t, "queue_dropped")
	if got := dropped(t, line); len(got) != 1 || got[0] != "87/resume" {
		t.Fatalf("dropped = %v", got)
	}
	entry, _ := line["dropped"].([]any)[0].(map[string]any)
	if entry["ask"] != testAsk {
		t.Fatalf("dropped entry = %v, want the ask id", entry)
	}
}

// projectRenamed kills every rule of the loupe project.
func projectRenamed() string {
	return fmt.Sprintf(`{"type":"project.renamed","subject":{"type":"project","id":%q},"projectId":%q,"fromSlug":"loupe","toSlug":"loupe-app","actor":"human"}`, testProject, testProject)
}

// cardHeld reports whether the router still reserves the card key.
func (h *harness) cardHeld(number int) bool {
	h.router.mu.Lock()
	defer h.router.mu.Unlock()

	return h.router.running[cardUUID(number)]
}

// A resume is out of the queue while its check runs, so a kill cannot drop it
// there. The check reads the rule again, and a dead rule starts nothing.
func TestARuleThatDiesDuringTheCheckStartsNoResume(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	g := newGate()
	h.router.checkAsk = g.check

	h.router.onData([]byte(h.mine(ask{card: 87})))
	<-g.entered
	h.router.onData([]byte(projectRenamed()))
	close(g.release)
	h.router.wg.Wait()

	if calls := h.worker.recorded(); len(calls) != 0 {
		t.Fatalf("workers = %+v, want none for a dead rule", calls)
	}
	if got := dropped(t, h.only(t, "queue_dropped")); len(got) != 1 || got[0] != "87/resume" {
		t.Fatalf("dropped = %v", got)
	}
	if h.cardHeld(87) {
		t.Fatal("the dropped resume still holds card 87")
	}
}

// A checked resume that waits for a slot holds its card. A kill that drops it
// releases the card too.
func TestAKilledCheckedResumeReleasesItsCard(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	h.router.maxWorkers = 1
	c := &checks{state: api.AskState{AskID: testAsk, Closed: true, AllRead: false}}
	h.router.checkAsk = c.check
	h.worker.started = make(chan workerSpec, 2)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(88)))
	<-h.worker.started
	h.router.onData([]byte(h.mine(ask{card: 87})))
	eventually(t, "the checked resume in the queue", func() bool {
		h.router.mu.Lock()
		defer h.router.mu.Unlock()

		return len(h.router.queue) == 1 && h.router.queue[0].checked
	})
	h.router.onData([]byte(projectRenamed()))
	close(h.worker.block)
	h.router.wg.Wait()

	if got := dropped(t, h.only(t, "queue_dropped")); len(got) != 1 || got[0] != "87/resume" {
		t.Fatalf("dropped = %v", got)
	}
	if h.cardHeld(87) {
		t.Fatal("the dropped resume still holds card 87")
	}
	if calls := h.worker.recorded(); len(calls) != 1 {
		t.Fatalf("workers = %+v, want card 88 alone", calls)
	}
}

// A checked resume keeps its place in arrival order, so an event that waited
// before it still starts first.
func TestACheckedResumeKeepsItsPlaceInTheQueue(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	h.router.maxWorkers = 1
	c := &checks{state: api.AskState{AskID: testAsk, Closed: true, AllRead: false}}
	h.router.checkAsk = c.check
	h.worker.started = make(chan workerSpec, 3)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(88)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(89)))
	h.router.onData([]byte(h.mine(ask{card: 87})))
	eventually(t, "the checked resume in the queue", func() bool {
		h.router.mu.Lock()
		defer h.router.mu.Unlock()

		return len(h.router.queue) == 2
	})
	close(h.worker.block)
	h.router.wg.Wait()

	if got := startedCards(t, h); len(got) != 3 || got[0] != 88 || got[1] != 89 || got[2] != 87 {
		t.Fatalf("started = %v, want 88, 89, then the resume of 87", got)
	}
}

// The check holds its card, and no worker slot. With one slot, another card
// starts while the check still waits.
func TestTheAskCheckHoldsNoWorkerSlot(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	h.router.maxWorkers = 1
	g := newGate()
	h.router.checkAsk = g.check
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(h.mine(ask{card: 87})))
	<-g.entered
	h.router.onData([]byte(cardMoved(88)))
	h.router.onData([]byte(cardMoved(87)))

	// onData dispatches on this goroutine, so the starts are settled.
	h.router.mu.Lock()
	active, waiting := h.router.active, len(h.router.queue)
	h.router.mu.Unlock()
	started := startedCards(t, h)
	close(g.release)
	close(h.worker.block)
	if active != 1 || waiting != 1 || len(started) != 1 || started[0] != 88 {
		h.router.wg.Wait()
		t.Fatalf("while the check waits: active = %d, waiting = %d, started = %v; want card 88 to run and card 87 to wait", active, waiting, started)
	}
	h.router.wg.Wait()
	calls := h.worker.recorded()
	if len(calls) != 3 {
		t.Fatalf("workers = %+v, want card 88, the resume and card 87", calls)
	}
	if got := startedCards(t, h); len(got) != 3 || got[0] != 88 || got[1] != 87 || got[2] != 87 {
		t.Fatalf("started = %v", got)
	}
	if first := h.events(t, "worker_started")[1]; first["ask"] != testAsk {
		t.Fatalf("the resume did not start before card 87's move: %v", first)
	}
	if h.worker.peak() != 1 {
		t.Fatalf("peak = %d, want the bound of 1", h.worker.peak())
	}
}

// A resume that a stopping bridge releases does not run on a dead context.
func TestAResumeReleasedDuringShutdownDoesNotRun(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	ctx, cancel := context.WithCancel(context.Background())
	h.router.ctx = ctx
	c := &checks{wait: true}
	h.router.checkAsk = func(ctx context.Context, handle, askID string) (api.AskState, error) {
		cancel()

		return c.check(ctx, handle, askID)
	}

	h.send(h.mine(ask{card: 87}))

	if calls := h.worker.recorded(); len(calls) != 0 {
		t.Fatalf("workers = %+v", calls)
	}
	if got := dropped(t, h.only(t, "queue_dropped")); len(got) != 1 || got[0] != "87/resume" {
		t.Fatalf("dropped = %v", got)
	}
}

// A person's answer resets the chain of the ask's card. An agent's close, such
// as a withdraw, counts toward the rule's cap.
func TestTheChainCountsAResumeByItsActor(t *testing.T) {
	h := newHarnessWith(t, defaultRules+`
  - name: resume
    on: inbox.ask_closed
    project: loupe
    resume: true
    maxChain: 1
    prompt: Ask {askId} closed.
`, rules.Defaults{})

	h.send(h.mine(ask{card: 87, actor: "agent"}))
	h.send(h.mine(ask{id: otherAsk, card: 87, actor: "agent"}))
	if h.runs() != 1 {
		t.Fatalf("runs = %d, want the second agent resume capped", h.runs())
	}
	if capped := h.only(t, "chain_capped"); num(t, capped, "card") != 87 || str(t, capped, "rule") != "resume" {
		t.Fatalf("chain_capped = %v", capped)
	}

	h.send(h.mine(ask{id: otherAsk, card: 87, actor: "human"}))
	h.send(h.mine(ask{card: 87, actor: "agent"}))
	if h.runs() != 3 {
		t.Fatalf("runs = %d, want a person's answer to reset the cap", h.runs())
	}
}

// Agent closes of several asks do not coalesce, so they can all wait behind a
// busy card. The cap still holds when the queue releases them.
func TestQueuedAgentResumesStillHitTheCap(t *testing.T) {
	h := newHarnessWith(t, defaultRules+`
  - name: resume
    on: inbox.ask_closed
    project: loupe
    resume: true
    maxChain: 1
    prompt: Ask {askId} closed.
`, rules.Defaults{})
	h.worker.started = make(chan workerSpec, 3)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(h.mine(ask{card: 87, actor: "agent"})))
	h.router.onData([]byte(h.mine(ask{id: otherAsk, card: 87, actor: "agent"})))
	close(h.worker.block)
	h.router.wg.Wait()

	if h.runs() != 2 {
		t.Fatalf("runs = %d, want the card run and one resume", h.runs())
	}
	if capped := h.only(t, "chain_capped"); str(t, capped, "ask") != otherAsk {
		t.Fatalf("chain_capped = %v", capped)
	}
}

// A person's answer resets the card's chain for every rule, the card rules too.
func TestAPersonsAnswerResetsTheCardsChain(t *testing.T) {
	h := newHarnessWith(t, chainRules+`
  - name: resume
    on: inbox.ask_closed
    project: loupe
    resume: true
    prompt: Ask {askId} closed.
`, rules.Defaults{})

	h.send(movedPayload(87, "backlog", "next", "agent"))
	h.send(movedPayload(87, "backlog", "next", "agent"))
	h.send(movedPayload(87, "backlog", "next", "agent"))
	if h.runs() != 2 {
		t.Fatalf("runs = %d, want the cap of 2", h.runs())
	}

	h.send(h.mine(ask{card: 87}))
	h.send(movedPayload(87, "backlog", "next", "agent"))
	if h.runs() != 4 {
		t.Fatalf("runs = %d, want the resume and a card run after the reset", h.runs())
	}
}

// claude exits 1 for a session it does not know. That resume is a failed run,
// reported against its card like any worker.
func TestAFailedResumeIsReportedAsAFailedRun(t *testing.T) {
	h := newHarnessWith(t, resumeRules, rules.Defaults{})
	sent := h.reports(t)
	h.worker.result = workerResult{exitCode: 1, output: "No conversation found with session ID: " + askSession}

	h.send(h.mine(ask{card: 87}))

	got := receive(t, sent)
	if got.handle != testProject || got.run.ExitCode == nil || *got.run.ExitCode != 1 || got.run.FailureReason != nil {
		t.Fatalf("report = %+v", got)
	}
	if got.run.CardID != cardUUID(87) || got.run.CardNumber != 87 || got.run.SessionID != askSession || got.run.RuleName != "resume" || got.run.BridgeID != testBridge {
		t.Fatalf("run = %+v", got.run)
	}
	if finished := h.only(t, "worker_finished"); num(t, finished, "exit") != 1 || finished["level"] != "ERROR" {
		t.Fatalf("worker_finished = %v", finished)
	}
}

// The bridge wires the check to the server's route, by project id.
func TestTheBridgeChecksTheAskBeforeItResumes(t *testing.T) {
	fake := &fakeLoupe{askState: `{"askId":"` + testAsk + `","closed":true,"allRead":true}`}
	id := testBridgeID
	fake.sse = "data: " + ask{card: 87, bridge: &id}.payload() + "\n\n"
	server := httptest.NewServer(http.HandlerFunc(fake.serve))
	t.Cleanup(server.Close)
	cfg := testLogin(server.URL)

	body := "projects:\n  loupe:\n    dir: " + t.TempDir() + "\nrules:\n  - name: resume\n    on: inbox.ask_closed\n    project: loupe\n    resume: true\n    prompt: go\n"
	set, err := rules.Parse([]byte(body), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	if err := set.Check(context.Background(), apiClient(cfg)); err != nil {
		t.Fatal(err)
	}
	log := &syncBuffer{}
	worker := &fakeWorker{}
	r := &router{log: newBridgeLogger(log), rules: set, maxWorkers: defaultMaxWorkers, worker: worker.ops(), bridgeID: testBridgeID}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	cmd := &cobra.Command{}
	cmd.SetContext(ctx)
	done := make(chan error, 1)
	go func() { done <- subscribe(cmd, cfg, r) }()

	eventually(t, "the skipped resume", func() bool {
		return strings.Contains(log.String(), `"event":"resume_skipped"`)
	})
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}

	if calls := worker.recorded(); len(calls) != 0 {
		t.Fatalf("workers = %+v", calls)
	}
	fake.mu.Lock()
	defer fake.mu.Unlock()
	if len(fake.askChecks) != 1 || fake.askChecks[0] != "/api/projects/"+testProject+"/inbox/asks/"+testAsk {
		t.Fatalf("ask checks = %v", fake.askChecks)
	}
}
