package cmd

import (
	"bytes"
	"errors"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/inject"
	"github.com/ubermuda/loupe/cli/internal/tmux"
)

type spawnCall struct {
	session string
	dir     string
	opts    tmux.SpawnOptions
}

type sendCall struct {
	target string
	text   string
}

// fakeTmux stands in for a tmux server: it records what the router asked for
// and answers HasSession from a fixed set.
type fakeTmux struct {
	existing map[string]bool
	spawns   []spawnCall
	sends    []sendCall
	spawnErr error
	sendErr  error
}

func (f *fakeTmux) ops() tmuxOps {
	return tmuxOps{
		hasSession: func(target string) bool { return f.existing[tmux.SessionName(target)] },
		spawn: func(session, dir string, opts tmux.SpawnOptions) error {
			f.spawns = append(f.spawns, spawnCall{session, dir, opts})

			return f.spawnErr
		},
		send: func(target, text string) error {
			f.sends = append(f.sends, sendCall{target, text})

			return f.sendErr
		},
	}
}

type harness struct {
	router *router
	tmux   *fakeTmux
	out    *bytes.Buffer
	errOut *bytes.Buffer
}

// spawnHarness builds a router in --dir mode, where card events get their own
// session.
func spawnHarness(existing ...string) *harness {
	f := &fakeTmux{existing: map[string]bool{}}
	for _, s := range existing {
		f.existing[s] = true
	}
	h := &harness{tmux: f, out: &bytes.Buffer{}, errOut: &bytes.Buffer{}}
	h.router = &router{out: h.out, errOut: h.errOut, target: defaultSession, dir: "/src/app", tmux: f.ops()}

	return h
}

// attachHarness builds a router in --session mode, where the bridge owns no
// session and must never spawn one.
func attachHarness() *harness {
	f := &fakeTmux{existing: map[string]bool{"mine": true}}
	h := &harness{tmux: f, out: &bytes.Buffer{}, errOut: &bytes.Buffer{}}
	h.router = &router{out: h.out, errOut: h.errOut, target: "mine", tmux: f.ops()}

	return h
}

const cardMovedToNext = `{"type":"board.card_moved","subject":{"type":"card","id":"0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7"},"projectId":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7","cardNumber":87,"fromStatus":"backlog","toStatus":"next"}`

func TestSiteReviewEventIsSentToTheBridgeSession(t *testing.T) {
	h := spawnHarness(defaultSession)

	h.router.onData([]byte(`{"type":"site_review.submitted"}`))

	if len(h.tmux.spawns) != 0 {
		t.Fatalf("a site review must not spawn a session: %+v", h.tmux.spawns)
	}
	if len(h.tmux.sends) != 1 || h.tmux.sends[0].target != defaultSession {
		t.Fatalf("unexpected sends: %+v", h.tmux.sends)
	}
	if h.tmux.sends[0].text != inject.SiteReviewDirective() {
		t.Fatalf("unexpected text: %q", h.tmux.sends[0].text)
	}
}

func TestSiteReviewEventDroppedWhenSessionIsGone(t *testing.T) {
	h := spawnHarness()

	h.router.onData([]byte(`{"type":"site_review.submitted"}`))

	if len(h.tmux.sends) != 0 {
		t.Fatalf("unexpected sends: %+v", h.tmux.sends)
	}
	if !strings.Contains(h.errOut.String(), "is gone") {
		t.Fatalf("errOut = %q", h.errOut.String())
	}
}

func TestCardMovedToNextSpawnsItsOwnSession(t *testing.T) {
	h := spawnHarness(defaultSession)
	h.router.permissionMode = "acceptEdits"

	h.router.onData([]byte(cardMovedToNext))

	if len(h.tmux.spawns) != 1 {
		t.Fatalf("expected one spawn, got %+v", h.tmux.spawns)
	}
	got := h.tmux.spawns[0]
	if got.session != "card-87-a2b3c4d5e6f7" || got.dir != "/src/app" {
		t.Fatalf("unexpected spawn: %+v", got)
	}
	if got.opts.PermissionMode != "acceptEdits" {
		t.Fatalf("permission mode not passed through: %+v", got.opts)
	}
	want := inject.CardDirective(inject.Event{Subject: inject.Subject{Type: "card", ID: "0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7"}, ProjectID: "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7", CardNumber: 87})
	if got.opts.Prompt != want {
		t.Fatalf("prompt = %q, want %q", got.opts.Prompt, want)
	}
	// A session that has just started is not yet reading keys, so the
	// directive must travel as claude's own prompt.
	if len(h.tmux.sends) != 0 {
		t.Fatalf("a fresh worker must not be sent keys: %+v", h.tmux.sends)
	}
}

// TestCardMovedToNextDropsWhenWorkerExists prevents a second worker on one
// card, which would also give two sessions the same name.
func TestCardMovedToNextDropsWhenWorkerExists(t *testing.T) {
	h := spawnHarness(defaultSession, "card-87-a2b3c4d5e6f7")

	h.router.onData([]byte(cardMovedToNext))

	if len(h.tmux.spawns) != 0 || len(h.tmux.sends) != 0 {
		t.Fatalf("expected nothing to happen: spawns=%+v sends=%+v", h.tmux.spawns, h.tmux.sends)
	}
	if !strings.Contains(h.errOut.String(), "already running") {
		t.Fatalf("errOut = %q", h.errOut.String())
	}
}

// TestCardMovedElsewhereIsIgnored also closes the feedback loop: the worker's
// own move to in-progress publishes an event this filter rejects.
func TestCardMovedElsewhereIsIgnored(t *testing.T) {
	for _, to := range []string{"backlog", "in-progress", "done", ""} {
		h := spawnHarness(defaultSession)

		h.router.onData([]byte(`{"type":"board.card_moved","subject":{"type":"card","id":"0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7"},"projectId":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7","cardNumber":87,"fromStatus":"next","toStatus":"` + to + `"}`))

		if len(h.tmux.spawns) != 0 || len(h.tmux.sends) != 0 {
			t.Fatalf("to=%q acted on: spawns=%+v sends=%+v", to, h.tmux.spawns, h.tmux.sends)
		}
	}
}

func TestCardSpawnFailureIsReported(t *testing.T) {
	h := spawnHarness(defaultSession)
	h.tmux.spawnErr = errors.New("boom")

	h.router.onData([]byte(cardMovedToNext))

	if !strings.Contains(h.errOut.String(), "failed to start a worker for card 87") {
		t.Fatalf("errOut = %q", h.errOut.String())
	}
}

// TestCardEventInAttachModeSendsToTheOneSession keeps --session behaving as it
// always has: it targets one session and spawns nothing.
func TestCardEventInAttachModeSendsToTheOneSession(t *testing.T) {
	h := attachHarness()

	h.router.onData([]byte(cardMovedToNext))

	if len(h.tmux.spawns) != 0 {
		t.Fatalf("--session mode must not spawn: %+v", h.tmux.spawns)
	}
	if len(h.tmux.sends) != 1 || h.tmux.sends[0].target != "mine" {
		t.Fatalf("unexpected sends: %+v", h.tmux.sends)
	}
	if !strings.Contains(h.tmux.sends[0].text, "Card 87") {
		t.Fatalf("unexpected text: %q", h.tmux.sends[0].text)
	}
}

// TestUnknownTypeIsDroppedQuietly keeps an older binary usable against a newer
// server, which publishes types this build has never heard of.
func TestUnknownTypeIsDroppedQuietly(t *testing.T) {
	h := spawnHarness(defaultSession)

	h.router.onData([]byte(`{"type":"board.card_created","projectId":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7","cardNumber":87}`))

	if h.errOut.Len() != 0 || h.out.Len() != 0 {
		t.Fatalf("unknown type was reported: out=%q errOut=%q", h.out.String(), h.errOut.String())
	}
	if len(h.tmux.spawns) != 0 || len(h.tmux.sends) != 0 {
		t.Fatalf("unknown type acted on: spawns=%+v sends=%+v", h.tmux.spawns, h.tmux.sends)
	}
}

func TestMalformedEventIsReported(t *testing.T) {
	h := spawnHarness(defaultSession)

	h.router.onData([]byte(`not json`))

	if !strings.Contains(h.errOut.String(), "skipping malformed event") {
		t.Fatalf("errOut = %q", h.errOut.String())
	}
}

func TestIncompleteCardEventIsReportedAndDropped(t *testing.T) {
	h := spawnHarness(defaultSession)

	h.router.onData([]byte(`{"type":"board.card_moved","subject":{"type":"card","id":"0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7"},"cardNumber":87,"toStatus":"next"}`))

	if !strings.Contains(h.errOut.String(), "skipping malformed event") {
		t.Fatalf("errOut = %q", h.errOut.String())
	}
	if len(h.tmux.spawns) != 0 || len(h.tmux.sends) != 0 {
		t.Fatalf("acted on an incomplete card event: spawns=%+v sends=%+v", h.tmux.spawns, h.tmux.sends)
	}
}

func TestEnsureSessionSpawnsTheBridgeSession(t *testing.T) {
	h := spawnHarness()
	h.router.permissionMode = "acceptEdits"
	out := &bytes.Buffer{}

	target, err := h.router.ensureSession(out, "/src/app", "")
	if err != nil {
		t.Fatalf("ensureSession: %v", err)
	}
	if target != defaultSession {
		t.Fatalf("target = %q", target)
	}
	if len(h.tmux.spawns) != 1 || h.tmux.spawns[0].session != defaultSession || h.tmux.spawns[0].dir != "/src/app" {
		t.Fatalf("unexpected spawns: %+v", h.tmux.spawns)
	}
	if h.tmux.spawns[0].opts.Prompt != "" {
		t.Fatalf("the bridge session takes no prompt: %+v", h.tmux.spawns[0].opts)
	}
	if h.tmux.spawns[0].opts.PermissionMode != "acceptEdits" {
		t.Fatalf("permission mode not passed through: %+v", h.tmux.spawns[0].opts)
	}
}

// TestEnsureSessionSaysItIgnoresDir covers the reuse path, which used to drop
// --dir with no word to the operator.
func TestEnsureSessionSaysItIgnoresDir(t *testing.T) {
	h := spawnHarness(defaultSession)
	out := &bytes.Buffer{}

	if _, err := h.router.ensureSession(out, "/src/app", ""); err != nil {
		t.Fatalf("ensureSession: %v", err)
	}
	if len(h.tmux.spawns) != 0 {
		t.Fatalf("an existing session must not be spawned again: %+v", h.tmux.spawns)
	}
	if !strings.Contains(out.String(), "--dir /src/app is ignored") {
		t.Fatalf("out = %q", out.String())
	}
}

func TestEnsureSessionRequiresAnExistingAttachTarget(t *testing.T) {
	h := spawnHarness()

	if _, err := h.router.ensureSession(&bytes.Buffer{}, "", "mine"); err == nil {
		t.Fatal("expected an error for a missing session")
	}

	h = spawnHarness("mine")
	target, err := h.router.ensureSession(&bytes.Buffer{}, "", "mine:0.1")
	if err != nil {
		t.Fatalf("ensureSession: %v", err)
	}
	if target != "mine:0.1" {
		t.Fatalf("target = %q", target)
	}
	if len(h.tmux.spawns) != 0 {
		t.Fatalf("--session mode must not spawn: %+v", h.tmux.spawns)
	}
}

// Two projects number their cards from 1 independently, so the name must
// separate them or one bridge drops the other's event as already running.
func TestWorkerSessionNameSeparatesProjects(t *testing.T) {
	const a = "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7"
	const b = "0192f3a1-4b2c-7d3e-8f10-ffffffffffff"

	if got := workerSession(87, a); got != "card-87-a2b3c4d5e6f7" {
		t.Fatalf("workerSession(87, a) = %q", got)
	}
	if workerSession(87, a) == workerSession(87, b) {
		t.Fatalf("card 87 in two projects collided on %q", workerSession(87, a))
	}
}

// These ids are uuidv7, so the leading digits are a millisecond timestamp and
// two projects created close together share them. Naming a session from a
// leading prefix would reintroduce the collision.
func TestWorkerSessionNameIgnoresTheTimestampPrefix(t *testing.T) {
	const sameMillisecond = "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7"
	const alsoSameMillisecond = "0192f3a1-4b2c-7d3e-8f10-0000000000ff"

	if workerSession(87, sameMillisecond) == workerSession(87, alsoSameMillisecond) {
		t.Fatalf("two projects sharing a timestamp prefix collided")
	}
}
