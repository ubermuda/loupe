package cmd

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/event"
	"github.com/ubermuda/loupe/cli/internal/outbound"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// commandRules names the command type in a rule, which the rule file allows.
const commandRules = defaultRules + `
  - name: command
    on: bridge.command
    project: loupe
    prompt: Act on the command.
`

const testCommandID = "0199a0e2-0000-7c5e-9f2a-3b1c6d7e8f90"

// testCommand is a command for bridgeID that expires at expires.
func testCommand(id, kind, bridgeID string, expires time.Time) api.Command {
	return api.Command{
		Type: event.CommandType, ProjectID: testProject, Subject: api.CommandSubject{Type: "bridge-command", ID: id}, CommandID: id,
		Kind: kind, BridgeID: bridgeID, CardID: cardUUID(87), CardNumber: 87, RuleName: "plan", CardColumn: "next", ExpiresAt: expires,
	}
}

func commandPayload(c api.Command) string {
	b, err := json.Marshal(c)
	if err != nil {
		panic(err)
	}

	return string(b)
}

// soon is a time the tests never reach.
func soon() time.Time {
	return time.Now().Add(time.Hour)
}

// ackRecorder stands in for the command endpoint. err answers every call.
type ackRecorder struct {
	mu   sync.Mutex
	acks []string
	err  error
}

func (a *ackRecorder) ack(_ context.Context, bridgeID, commandID, state, reason string) error {
	a.mu.Lock()
	defer a.mu.Unlock()
	a.acks = append(a.acks, fmt.Sprintf("%s %s %s %s", bridgeID, commandID, state, reason))

	return a.err
}

func (a *ackRecorder) recorded() []string {
	a.mu.Lock()
	defer a.mu.Unlock()

	return slices.Clone(a.acks)
}

// withAcks sends each ack of the router through a queue that sends at once.
func (h *harness) withAcks() *ackRecorder {
	rec := &ackRecorder{}
	h.router.reports = syncQueue{}
	h.router.ackCommand = rec.ack

	return rec
}

// reply hands a heartbeat reply to the router, and waits for the handlers.
func (h *harness) reply(reply api.HeartbeatReply) {
	h.router.onHeartbeatReply(reply)
	h.router.wg.Wait()
}

func dropReasons(t *testing.T, h *harness) []string {
	t.Helper()

	var out []string
	for _, line := range h.events(t, "command_dropped") {
		out = append(out, str(t, line, "reason"))
	}

	return out
}

const refusedYet = "refused This bridge cannot act on the command yet."

// A command is never an event a rule acts on, even when a rule names its type.
// Another bridge's command is dropped in silence.
func TestACommandMatchesNoRule(t *testing.T) {
	for name, bridgeID := range map[string]string{"this bridge": testBridgeID, "another bridge": foreignBridge} {
		t.Run(name, func(t *testing.T) {
			h := newHarnessWith(t, commandRules, rules.Defaults{})

			h.send(commandPayload(testCommand(testCommandID, api.CommandStopRun, bridgeID, soon())))

			if calls := h.worker.recorded(); len(calls) != 0 {
				t.Fatalf("workers = %+v", calls)
			}
			received := len(h.events(t, "command_received"))
			if bridgeID == foreignBridge {
				if log := strings.TrimSpace(h.log.String()); log != "" {
					t.Fatalf("log = %s, want nothing", log)
				}
			} else if received != 1 {
				t.Fatalf("command_received lines = %d, want 1", received)
			}
		})
	}
}

// The event and the heartbeat carry the same command. The bridge acts on it
// once, and the heartbeat copy with upper-case ids matches it too.
func TestACommandIsTakenOnceAcrossBothChannels(t *testing.T) {
	h := newHarness(t)
	acks := h.withAcks()
	c := testCommand(testCommandID, api.CommandStopRun, testBridgeID, soon())

	h.send(commandPayload(c))
	upper := c
	upper.CommandID, upper.Subject.ID, upper.BridgeID = strings.ToUpper(c.CommandID), strings.ToUpper(c.CommandID), strings.ToUpper(testBridgeID)
	h.reply(api.HeartbeatReply{Commands: []api.Command{upper}})

	if got := acks.recorded(); !slices.Equal(got, []string{testBridgeID + " " + testCommandID + " " + refusedYet}) {
		t.Fatalf("acks = %v", got)
	}
	received := h.only(t, "command_received")
	if str(t, received, "command") != testCommandID || str(t, received, "kind") != api.CommandStopRun || str(t, received, "source") != "event" || num(t, received, "card") != 87 {
		t.Fatalf("command_received = %v", received)
	}
	if got := dropReasons(t, h); !slices.Equal(got, []string{"duplicate"}) {
		t.Fatalf("drop reasons = %v", got)
	}
	acked := h.only(t, "command_acked")
	if str(t, acked, "command") != testCommandID || str(t, acked, "state") != api.CommandRefused {
		t.Fatalf("command_acked = %v", acked)
	}
}

// A handled id stays until its command expires, and goes after.
func TestTheBridgeForgetsAHandledCommandOnceItExpires(t *testing.T) {
	h := newHarness(t)
	h.withAcks()
	expires := time.Now().Add(200 * time.Millisecond)
	h.reply(api.HeartbeatReply{Commands: []api.Command{testCommand(testCommandID, api.CommandStopRun, testBridgeID, expires)}})
	h.router.mu.Lock()
	_, kept := h.router.handled[testCommandID]
	h.router.mu.Unlock()
	if !kept {
		t.Skip("the command expired before the bridge took it")
	}

	eventually(t, "the command to expire", func() bool { return time.Now().After(expires) })
	other := "0199a0e2-0000-7c5e-9f2a-3b1c6d7e8f91"
	h.reply(api.HeartbeatReply{Commands: []api.Command{testCommand(other, api.CommandResumeRun, testBridgeID, soon())}})

	h.router.mu.Lock()
	defer h.router.mu.Unlock()
	if _, ok := h.router.handled[testCommandID]; ok || len(h.router.handled) != 1 {
		t.Fatalf("handled = %v", h.router.handled)
	}
}

// An expired command, another bridge's command in a heartbeat reply, and a
// command that fails its check are dropped with no ack.
func TestTheBridgeDropsACommandItMustNotRun(t *testing.T) {
	bad := testCommand(testCommandID, "reboot", testBridgeID, soon())
	for name, tc := range map[string]struct {
		send   func(h *harness)
		reason string
	}{
		"expired event": {func(h *harness) {
			h.send(commandPayload(testCommand(testCommandID, api.CommandStopRun, testBridgeID, time.Now().Add(-time.Second))))
		}, "expired"},
		"expired heartbeat": {func(h *harness) {
			h.reply(api.HeartbeatReply{Commands: []api.Command{testCommand(testCommandID, api.CommandStopRun, testBridgeID, time.Now().Add(-time.Second))}})
		}, "expired"},
		"another bridge": {func(h *harness) {
			h.reply(api.HeartbeatReply{Commands: []api.Command{testCommand(testCommandID, api.CommandStopRun, foreignBridge, soon())}})
		}, "other_bridge"},
		"malformed event":     {func(h *harness) { h.send(commandPayload(bad)) }, "malformed"},
		"malformed heartbeat": {func(h *harness) { h.reply(api.HeartbeatReply{Commands: []api.Command{bad}}) }, "malformed"},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarness(t)
			acks := h.withAcks()

			tc.send(h)

			if got := dropReasons(t, h); !slices.Equal(got, []string{tc.reason}) {
				t.Fatalf("drop reasons = %v, want %s", got, tc.reason)
			}
			if n := len(h.events(t, "command_received")); n != 0 || len(acks.recorded()) != 0 {
				t.Fatalf("received = %d, acks = %v", n, acks.recorded())
			}
		})
	}
}

// Each kind reaches its own handler, and each answers refused for now.
func TestEachKindOfCommandIsAnswered(t *testing.T) {
	h := newHarness(t)
	acks := h.withAcks()
	stop := testCommand(testCommandID, api.CommandStopRun, testBridgeID, soon())
	resume := testCommand("0199a0e2-0000-7c5e-9f2a-3b1c6d7e8f91", api.CommandResumeRun, testBridgeID, soon())

	h.reply(api.HeartbeatReply{Commands: []api.Command{stop, resume}})

	got := acks.recorded()
	slices.Sort(got)
	want := []string{testBridgeID + " " + stop.CommandID + " " + refusedYet, testBridgeID + " " + resume.CommandID + " " + refusedYet}
	if !slices.Equal(got, want) {
		t.Fatalf("acks = %v", got)
	}
}

// heldQueue keeps each report for the test to send.
type heldQueue struct {
	syncQueue

	mu      sync.Mutex
	reports []outbound.Report
}

func (q *heldQueue) Enqueue(report outbound.Report) {
	q.mu.Lock()
	defer q.mu.Unlock()
	q.reports = append(q.reports, report)
}

// An ack goes out through the report queue and never from the goroutine that
// took the command. A command the server no longer holds counts as settled,
// and any other failure lets the queue retry.
func TestAnAckGoesOutThroughTheQueue(t *testing.T) {
	h := newHarness(t)
	acks := &ackRecorder{}
	queue := &heldQueue{}
	h.router.reports, h.router.ackCommand = queue, acks.ack

	h.send(commandPayload(testCommand(testCommandID, api.CommandStopRun, testBridgeID, soon())))

	if len(acks.recorded()) != 0 || len(queue.reports) != 1 {
		t.Fatalf("acks = %v, queued = %d", acks.recorded(), len(queue.reports))
	}
	report := queue.reports[0]
	if report.Card != 87 || report.Rule != "plan" {
		t.Fatalf("report names card %d and rule %q", report.Card, report.Rule)
	}
	for _, tc := range []struct {
		err     error
		created bool
		failed  bool
	}{
		{errors.New("HTTP 502"), false, true},
		{fmt.Errorf("%w: %s", api.ErrCommandNotFound, testCommandID), true, false},
		{nil, true, false},
	} {
		acks.err = tc.err
		created, err := report.Send(context.Background())
		if created != tc.created || (err != nil) != tc.failed {
			t.Fatalf("with %v: created = %v, err = %v", tc.err, created, err)
		}
	}
	if n := len(h.events(t, "command_acked")); n != 2 {
		t.Fatalf("command_acked lines = %d, want 2", n)
	}
}

func pausedReply(p bool) api.HeartbeatReply {
	return api.HeartbeatReply{Paused: &p}
}

// A pause from the server holds every queued run back, and a reply with no
// pause keeps it. The unpause starts the queued run.
func TestAPauseFromTheServerHoldsDispatch(t *testing.T) {
	h := newHarness(t)

	h.reply(pausedReply(true))
	h.send(cardMoved(87))
	h.reply(api.HeartbeatReply{})
	if h.runs() != 0 {
		t.Fatalf("runs = %d while paused", h.runs())
	}
	h.only(t, "bridge_paused")

	h.reply(pausedReply(false))
	h.router.wg.Wait()
	if h.runs() != 1 {
		t.Fatalf("runs = %d after the unpause", h.runs())
	}
	h.only(t, "bridge_unpaused")
	h.reply(pausedReply(false))
	h.only(t, "bridge_unpaused")
}

// The handover resumes dispatch after its own pause, and a person's pause
// outlives that resume.
func TestAHandoverResumeKeepsThePersonPause(t *testing.T) {
	h := newHarness(t)
	h.reply(pausedReply(true))

	h.router.pause()
	h.router.freeze()
	h.router.resume()
	h.send(cardMoved(87))

	if h.runs() != 0 {
		t.Fatalf("runs = %d after a handover resume", h.runs())
	}
}

// A heartbeat command that comes while a freeze holds the router is not marked
// handled, so the next image, or this one after a resume, takes it.
func TestAFrozenRouterLeavesAHeartbeatCommandForLater(t *testing.T) {
	h := newHarness(t)
	acks := h.withAcks()
	c := testCommand(testCommandID, api.CommandStopRun, testBridgeID, soon())

	h.router.freeze()
	h.reply(api.HeartbeatReply{Commands: []api.Command{c}})
	h.router.resume()
	h.reply(api.HeartbeatReply{Commands: []api.Command{c}})

	if got := dropReasons(t, h); !slices.Equal(got, []string{"handover"}) {
		t.Fatalf("drop reasons = %v", got)
	}
	if len(acks.recorded()) != 1 {
		t.Fatalf("acks = %v", acks.recorded())
	}
}

// A shut router takes no command, so no handler starts after the wait for the
// workers.
func TestAShutRouterTakesNoCommand(t *testing.T) {
	h := newHarness(t)
	acks := h.withAcks()

	h.router.shutdown()
	h.reply(api.HeartbeatReply{Commands: []api.Command{testCommand(testCommandID, api.CommandStopRun, testBridgeID, soon())}})

	if got := dropReasons(t, h); !slices.Equal(got, []string{"shutdown"}) || len(acks.recorded()) != 0 {
		t.Fatalf("drop reasons = %v, acks = %v", got, acks.recorded())
	}
}

// The bridge keeps the last pause in its config directory, and a bridge that
// starts reads it before any dispatch.
func TestThePauseCacheStartsTheBridgePaused(t *testing.T) {
	shortConfigHome(t)
	path, err := pausePath()
	if err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		t.Fatal(err)
	}
	first := newHarness(t)
	first.router.pauseFile = path
	first.reply(pausedReply(true))
	if paused, err := readPause(path); err != nil || !paused {
		t.Fatalf("readPause = %v, %v", paused, err)
	}

	next := newHarness(t)
	next.router.pauseFile = path
	next.router.loadPause()
	next.send(cardMoved(87))
	if next.runs() != 0 {
		t.Fatalf("runs = %d on a bridge that starts paused", next.runs())
	}
	if line := next.only(t, "bridge_paused"); str(t, line, "source") != "cache" {
		t.Fatalf("bridge_paused = %v", line)
	}

	first.reply(pausedReply(false))
	if paused, err := readPause(path); err != nil || paused {
		t.Fatalf("readPause after the unpause = %v, %v", paused, err)
	}
}

// A missing cache starts the bridge free and logs nothing. A cache it cannot
// read starts it free and says so.
func TestAMissingOrBrokenPauseCacheStartsTheBridgeFree(t *testing.T) {
	dir := t.TempDir()
	h := newHarness(t)
	h.router.pauseFile = filepath.Join(dir, "pause.json")
	h.router.loadPause()
	if log := strings.TrimSpace(h.log.String()); log != "" || h.router.personPaused {
		t.Fatalf("log = %s, paused = %v", log, h.router.personPaused)
	}

	if err := os.WriteFile(h.router.pauseFile, []byte("{"), 0o600); err != nil {
		t.Fatal(err)
	}
	h.router.loadPause()
	h.only(t, "pause_cache_unreadable")
	if h.router.personPaused {
		t.Fatal("a broken cache paused the bridge")
	}
}

// A failed cache write logs and changes nothing else.
func TestAPauseCacheWriteFailureLogsAndGoesOn(t *testing.T) {
	h := newHarness(t)
	h.router.pauseFile = filepath.Join(t.TempDir(), "missing", "pause.json")

	h.reply(pausedReply(true))

	h.only(t, "pause_cache_failed")
	if !h.router.personPaused {
		t.Fatal("the pause did not apply")
	}
}
