package cmd

import (
	"context"
	"net/http"
	"net/http/httptest"
	"slices"
	"testing"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/directive"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// plainPrompt is the prompt the default rule renders for card 87.
var plainPrompt = "Card 87 (" + testCard + ") entered next.\n\n" + directive.Footer

func inboxLine(session string) string {
	return "Your session id is " + session + " and your bridge id is " + testBridgeID + ". Pass both to inbox_ask."
}

// listed answers GET /api/events with both mapped projects, so a refresh kills
// no rule, and with the given flags.
func listed(flags map[string]any) api.Events {
	return api.Events{
		Projects: []api.EventsProject{{ID: testProject}, {ID: otherProject}},
		Flags:    flags,
	}
}

// Each worker is a new claude session, so no two workers share an id.
func TestEachWorkerStartsItsOwnSession(t *testing.T) {
	h := newHarness(t)

	h.send(cardMoved(87))
	h.send(cardMoved(88))

	var ids []string
	for _, call := range h.worker.recorded() {
		ids = append(ids, call.sessionID)
	}
	slices.Sort(ids)
	if want := []string{sessionUUID(1), sessionUUID(2)}; !slices.Equal(ids, want) {
		t.Fatalf("session ids = %v, want %v", ids, want)
	}
}

// An instance with the inbox off, or a server that sends no flags, gets the
// prompt the rule renders and nothing more. Each case turns the inbox on first,
// so a refresh that can switch the line on but never off leaves it in.
func TestThePromptNamesNoIdsWhileTheInboxIsOff(t *testing.T) {
	for name, flags := range map[string]map[string]any{
		"no flags":      nil,
		"flag off":      {api.InboxFlag: false},
		"not a boolean": {api.InboxFlag: "true"},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarness(t)
			h.router.onRefresh(listed(map[string]any{api.InboxFlag: true}))
			h.router.onRefresh(listed(flags))

			h.send(cardMoved(87))

			if calls := h.worker.recorded(); len(calls) != 1 || calls[0].prompt != plainPrompt {
				t.Fatalf("workers = %+v, want the prompt %q", calls, plainPrompt)
			}
		})
	}
}

// With the inbox on, the footer gains the one line an agent needs to call
// inbox_ask, and the id in it is the id claude runs under.
func TestThePromptNamesTheSessionAndTheBridgeWhileTheInboxIsOn(t *testing.T) {
	h := newHarness(t)
	h.router.onRefresh(listed(map[string]any{api.InboxFlag: true}))

	h.send(cardMoved(87))

	calls := h.worker.recorded()
	if len(calls) != 1 {
		t.Fatalf("workers = %+v", calls)
	}
	if want := plainPrompt + "\n" + inboxLine(calls[0].sessionID); calls[0].prompt != want {
		t.Fatalf("prompt = %q, want %q", calls[0].prompt, want)
	}
	if calls[0].sessionID != testSession {
		t.Fatalf("session id = %q, want %q", calls[0].sessionID, testSession)
	}
}

// The bridge reads the flags again on every reconnect, and a worker starts
// with the value of the last read.
func TestARefreshSwitchesTheInboxLine(t *testing.T) {
	h := newHarness(t)

	h.router.onRefresh(listed(map[string]any{api.InboxFlag: true}))
	h.send(cardMoved(87))
	h.router.onRefresh(listed(map[string]any{api.InboxFlag: false}))
	h.send(cardMoved(88))

	calls := h.worker.recorded()
	if len(calls) != 2 {
		t.Fatalf("workers = %+v", calls)
	}
	if calls[0].prompt != plainPrompt+"\n"+inboxLine(calls[0].sessionID) {
		t.Fatalf("first prompt = %q, want the inbox line", calls[0].prompt)
	}
	if want := "Card 88 (" + cardUUID(88) + ") entered next.\n\n" + directive.Footer; calls[1].prompt != want {
		t.Fatalf("second prompt = %q, want %q", calls[1].prompt, want)
	}
}

// The first GET /api/events, before the stream opens, already sets the flags.
func TestTheFlagsOfTheFirstEventsCallReachTheFirstWorker(t *testing.T) {
	fake := &fakeLoupe{sse: "data: " + cardMoved(87) + "\n\n", flags: `{"inbox.enabled":true,"some.other":60}`}
	server := httptest.NewServer(http.HandlerFunc(fake.serve))
	t.Cleanup(server.Close)
	cfg := config.Config{BaseURL: server.URL, Token: "t"}

	body := "projects:\n  loupe:\n    dir: " + t.TempDir() + "\nrules:\n  - on: board.card_moved\n    project: loupe\n    to: next\n    prompt: go\n"
	set, err := rules.Parse([]byte(body), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	if err := set.Check(context.Background(), apiClient(cfg)); err != nil {
		t.Fatal(err)
	}
	worker := &fakeWorker{}
	r := &router{log: newBridgeLogger(&syncBuffer{}), rules: set, maxWorkers: defaultMaxWorkers, worker: worker.ops(), bridgeID: testBridgeID}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	cmd := &cobra.Command{}
	cmd.SetContext(ctx)
	done := make(chan error, 1)
	go func() { done <- subscribe(cmd, cfg, r) }()

	deadline := time.After(8 * time.Second)
	for len(worker.recorded()) < 1 {
		select {
		case <-deadline:
			t.Fatal("no worker started")
		case <-time.After(20 * time.Millisecond):
		}
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}

	call := worker.recorded()[0]
	if want := "go\n\n" + directive.Footer + "\n" + inboxLine(call.sessionID); call.prompt != want {
		t.Fatalf("prompt = %q, want %q", call.prompt, want)
	}
}
