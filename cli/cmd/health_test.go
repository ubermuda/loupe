package cmd

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

const testBridgeID = "0192f3a1-7777-4d3e-8f10-a2b3c4d5e6f7"

// reportServer records each PUT and answers with the next status of its
// project, then 204 once the list runs out.
type reportServer struct {
	mu       sync.Mutex
	statuses map[string][]int
	// replies holds the body of each status, and a 404 says board_disabled
	// when it holds none.
	replies map[int]string
	bodies  map[string][]string
}

func (s *reportServer) serve(w http.ResponseWriter, r *http.Request) {
	raw, _ := io.ReadAll(r.Body)
	handle := strings.Split(strings.TrimPrefix(r.URL.Path, "/api/projects/"), "/")[0]

	s.mu.Lock()
	if s.bodies == nil {
		s.bodies = map[string][]string{}
	}
	s.bodies[handle] = append(s.bodies[handle], string(raw))
	status := http.StatusNoContent
	if next := s.statuses[handle]; len(next) > 0 {
		status, s.statuses[handle] = next[0], next[1:]
	}
	reply, ok := s.replies[status]
	s.mu.Unlock()

	if !ok && status == http.StatusNotFound {
		reply = `{"error":"board_disabled"}`
	}
	w.WriteHeader(status)
	fmt.Fprint(w, reply)
}

func (s *reportServer) sent(handle string) []string {
	s.mu.Lock()
	defer s.mu.Unlock()

	return append([]string(nil), s.bodies[handle]...)
}

func newReporter(t *testing.T, s *reportServer, base time.Duration) (*healthReporter, *syncBuffer) {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(s.serve))
	ctx, cancel := context.WithCancel(context.Background())
	log := &syncBuffer{}
	h := newHealthReporter(ctx, api.New(server.URL, "t", server.Client()), testBridgeID, newBridgeLogger(log))
	h.base, h.max = base, base
	t.Cleanup(func() {
		cancel()
		h.wait()
		server.Close()
	})

	return h, log
}

func eventually(t *testing.T, what string, ok func() bool) {
	t.Helper()
	deadline := time.After(5 * time.Second)
	for !ok() {
		select {
		case <-deadline:
			t.Fatalf("timed out waiting for %s", what)
		case <-time.After(5 * time.Millisecond):
		}
	}
}

func health(name, state string) []api.RuleHealth {
	h := api.RuleHealth{Name: name, On: event.CardMovedType, Columns: []string{"next"}, State: state}
	if state == api.RuleDead {
		reason := api.ReasonColumnRenamed
		h.Reason = &reason
	}

	return []api.RuleHealth{h}
}

func TestAFailedReportIsRetried(t *testing.T) {
	s := &reportServer{statuses: map[string][]int{testProject: {http.StatusInternalServerError, http.StatusServiceUnavailable}}}
	h, log := newReporter(t, s, time.Millisecond)

	h.submit("loupe", testProject, health("plan", api.RuleLive))
	eventually(t, "a report to succeed", func() bool { return strings.Contains(log.String(), `"report_sent"`) })

	sent := s.sent(testProject)
	if len(sent) != 3 || sent[0] != sent[2] {
		t.Fatalf("sent = %v, want the same body three times", sent)
	}
	if n := strings.Count(log.String(), `"report_failed"`); n != 2 {
		t.Fatalf("report_failed lines = %d, log = %s", n, log.String())
	}
}

// A retry waits an hour here, so only the newer report can end the wait, and
// it must go out in place of the one that failed.
func TestANewerReportReplacesAPendingRetry(t *testing.T) {
	s := &reportServer{statuses: map[string][]int{testProject: {http.StatusInternalServerError}}}
	h, log := newReporter(t, s, time.Hour)

	h.submit("loupe", testProject, health("plan", api.RuleLive))
	eventually(t, "the first report to fail", func() bool { return strings.Contains(log.String(), `"report_failed"`) })
	h.submit("loupe", testProject, health("plan", api.RuleDead))
	eventually(t, "the newer report", func() bool { return len(s.sent(testProject)) == 2 })

	sent := s.sent(testProject)
	if !strings.Contains(sent[0], `"live"`) || !strings.Contains(sent[1], `"dead"`) {
		t.Fatalf("sent = %v", sent)
	}
	eventually(t, "report_sent", func() bool { return strings.Contains(log.String(), `"report_sent"`) })
	if n := len(s.sent(testProject)); n != 2 {
		t.Fatalf("the replaced report went out too: %d reports", n)
	}
}

// board_disabled stops the reports of that project for good, with one log
// line, and leaves every other project reporting. The project's goroutine has
// returned before the second submit, so nothing can send it.
func TestBoardDisabledStopsReportingForTheProject(t *testing.T) {
	s := &reportServer{statuses: map[string][]int{testProject: {http.StatusNotFound}}}
	h, log := newReporter(t, s, time.Millisecond)

	h.submit("loupe", testProject, health("plan", api.RuleLive))
	p := h.project(testProject)
	select {
	case <-p.done:
	case <-time.After(5 * time.Second):
		t.Fatal("the project's report goroutine did not stop")
	}
	h.submit("loupe", testProject, health("plan", api.RuleDead))
	h.mu.Lock()
	pending := p.has
	h.mu.Unlock()
	if pending {
		t.Fatal("a stopped project accepted a report")
	}
	if n := len(s.sent(testProject)); n != 1 {
		t.Fatalf("the disabled project sent %d reports", n)
	}
	if n := strings.Count(log.String(), `"report_failed"`); n != 1 {
		t.Fatalf("report_failed lines = %d, log = %s", n, log.String())
	}

	h.submit("other", otherProject, health("plan", api.RuleLive))
	eventually(t, "the other project's report", func() bool { return len(s.sent(otherProject)) == 1 })
}

// A report the server refuses for good names what the operator must fix.
func TestARejectedReportSaysWhatToFix(t *testing.T) {
	for name, tc := range map[string]struct {
		status int
		reply  string
		want   string
	}{
		"unknown project": {http.StatusNotFound, `{"error":"project_not_found"}`, "the server knows no project loupe of yours: fix the projects map in rules.yaml and restart the bridge"},
		"old server":      {http.StatusNotFound, ``, "upgrade Loupe"},
		"invalid field":   {http.StatusUnprocessableEntity, `{"violations":[{"propertyPath":"rules[0].name","title":"This value is too long."}]}`, "the server refused field name of rule plan of project loupe: fix rules.yaml and restart the bridge"},
		"no violations":   {http.StatusUnprocessableEntity, `{}`, "the server refused the report of project loupe: fix rules.yaml"},
		"wrong scope":     {http.StatusForbidden, `{"error":"insufficient_scope"}`, "agent scope"},
	} {
		t.Run(name, func(t *testing.T) {
			s := &reportServer{statuses: map[string][]int{testProject: {tc.status}}, replies: map[int]string{tc.status: tc.reply}}
			h, log := newReporter(t, s, time.Hour)

			h.submit("loupe", testProject, health("plan", api.RuleLive))
			eventually(t, "report_failed", func() bool { return strings.Contains(log.String(), `"report_failed"`) })

			var line map[string]any
			if err := json.Unmarshal([]byte(strings.TrimSpace(log.String())), &line); err != nil {
				t.Fatal(err)
			}
			if msg, _ := line["message"].(string); !strings.Contains(msg, tc.want) || line["retry"] != false {
				t.Fatalf("report_failed = %v, want a message with %q", line, tc.want)
			}
		})
	}
}

func TestTheBackoffDoublesToItsCap(t *testing.T) {
	h := &healthReporter{base: time.Second, max: time.Minute}
	for attempt, want := range map[int]time.Duration{0: time.Second, 1: 2 * time.Second, 2: 4 * time.Second, 5: 32 * time.Second, 6: time.Minute, 40: time.Minute} {
		if got := h.backoff(attempt); got != want {
			t.Fatalf("backoff(%d) = %s, want %s", attempt, got, want)
		}
	}
}

// deadHarness maps loupe and other, and reports through a real reporter.
func deadHarness(t *testing.T) (*harness, *reportServer) {
	t.Helper()
	body := `
projects:
  loupe:
    dir: {dir}
  other:
    dir: {dir}
rules:
  - name: plan
    on: board.card_moved
    project: loupe
    to: next
    prompt: SECRET PROMPT {cardNumber}
  - name: review
    on: board.card_moved
    project: loupe
    to: review
    prompt: review {cardNumber}
  - name: other-plan
    on: board.card_moved
    project: other
    to: next
    prompt: plan {cardNumber}
`
	h := newHarnessWith(t, body, rules.Defaults{})
	s := &reportServer{}
	h.router.health, _ = newReporter(t, s, time.Millisecond)
	h.router.health.log = h.router.log

	return h, s
}

func slugPayload(typ, fields string) string {
	return fmt.Sprintf(`{"type":%q,"projectId":%q,"subject":{"type":"board_column","id":"0192f3a1-5555-7d3e-8f10-a2b3c4d5e6f7"},"actor":"agent",%s}`, typ, testProject, fields)
}

func TestARenamedColumnKillsItsRulesAndReportsThem(t *testing.T) {
	h, s := deadHarness(t)

	h.router.onData([]byte(slugPayload(event.ColumnRenamedType, `"fromSlug":"next","toSlug":"ready"`)))
	h.router.onData([]byte(cardMoved(87)))
	h.router.wg.Wait()

	if n := len(h.worker.recorded()); n != 0 {
		t.Fatalf("a dead rule started %d workers", n)
	}
	line := h.only(t, "rule_dead")
	if str(t, line, "rule") != "plan" || str(t, line, "project") != testProject || str(t, line, "project_slug") != "loupe" || str(t, line, "reason") != "column_renamed" || line["level"] != "ERROR" {
		t.Fatalf("rule_dead = %v", line)
	}
	eventually(t, "the report", func() bool { return len(s.sent(testProject)) == 1 })

	var report struct {
		Rules []map[string]any `json:"rules"`
	}
	sent := s.sent(testProject)[0]
	if err := json.Unmarshal([]byte(sent), &report); err != nil {
		t.Fatal(err)
	}
	if len(report.Rules) != 2 || report.Rules[0]["state"] != "dead" || report.Rules[1]["state"] != "live" || strings.Contains(sent, "SECRET") {
		t.Fatalf("report = %s", sent)
	}
	if len(s.sent(otherProject)) != 0 {
		t.Fatal("a project with no change reported")
	}

	h.router.onData([]byte(strings.Replace(movedPayload(88, "backlog", "next", "human"), testProject, otherProject, 1)))
	h.router.wg.Wait()
	if n := len(h.worker.recorded()); n != 1 {
		t.Fatalf("the other project's rule started %d workers", n)
	}
}

// An event that waits for a slot when its rule dies must not start later.
func TestARuleThatDiesDropsItsQueuedEvents(t *testing.T) {
	h, _ := deadHarness(t)
	h.router.maxWorkers = 1
	h.worker.started = make(chan workerSpec, 3)
	h.worker.block = make(chan struct{})

	h.router.onData([]byte(cardMoved(87)))
	<-h.worker.started
	h.router.onData([]byte(cardMoved(88)))
	h.router.onData([]byte(movedPayload(89, "backlog", "review", "human")))
	h.router.onData([]byte(slugPayload(event.ColumnRenamedType, `"fromSlug":"next","toSlug":"ready"`)))

	line := h.only(t, "queue_dropped")
	if got := strings.Join(dropped(t, line), " "); got != "88/plan" {
		t.Fatalf("dropped = %s, want 88/plan", got)
	}

	close(h.worker.block)
	h.router.wg.Wait()
	if got := startedCards(t, h); len(got) != 2 || got[0] != 87 || got[1] != 89 {
		t.Fatalf("started %v, want 87 and 89", got)
	}
}

func TestADeletedColumnKillsItsRules(t *testing.T) {
	h, _ := deadHarness(t)

	h.router.onData([]byte(slugPayload(event.ColumnDeletedType, `"slug":"review","targetSlug":"done","movedCardIds":[]`)))
	h.router.onData([]byte(movedPayload(87, "backlog", "review", "human")))
	h.router.onData([]byte(movedPayload(88, "backlog", "next", "human")))
	h.router.wg.Wait()

	if line := h.only(t, "rule_dead"); str(t, line, "rule") != "review" || str(t, line, "reason") != "column_deleted" {
		t.Fatalf("rule_dead = %v", line)
	}
	if cards := startedCards(t, h); len(cards) != 1 || cards[0] != 88 {
		t.Fatalf("started cards = %v", cards)
	}
}

func TestARenamedProjectKillsEveryRuleOfIt(t *testing.T) {
	h, _ := deadHarness(t)

	payload := fmt.Sprintf(`{"type":"project.renamed","subject":{"type":"project","id":%q},"projectId":%q,"fromSlug":"loupe","toSlug":"loupe-app","actor":"human"}`, testProject, testProject)
	h.router.onData([]byte(payload))
	h.router.onData([]byte(cardMoved(87)))
	h.router.onData([]byte(movedPayload(88, "backlog", "review", "human")))
	h.router.wg.Wait()

	var dead []string
	for _, line := range h.events(t, "rule_dead") {
		dead = append(dead, str(t, line, "rule")+"/"+str(t, line, "reason"))
	}
	if strings.Join(dead, ",") != "plan/project_renamed,review/project_renamed" {
		t.Fatalf("rule_dead = %v", dead)
	}
	if n := len(h.worker.recorded()); n != 0 {
		t.Fatalf("a dead rule started %d workers", n)
	}
}
