package cmd

import (
	"errors"
	"fmt"
	"io"

	"github.com/ubermuda/loupe/cli/internal/inject"
	"github.com/ubermuda/loupe/cli/internal/tmux"
)

// tmuxOps is the tmux surface the router drives. Tests replace the fields to
// run the routing rules without a tmux server.
type tmuxOps struct {
	hasSession func(target string) bool
	spawn      func(session, dir string, opts tmux.SpawnOptions) error
	send       func(target, text string) error
}

func defaultTmuxOps() tmuxOps {
	return tmuxOps{hasSession: tmux.HasSession, spawn: tmux.Spawn, send: tmux.Send}
}

// router decides which tmux session each event reaches.
//
// dir is empty in --session mode, where the bridge owns no session and must
// never spawn one.
type router struct {
	out, errOut    io.Writer
	target         string
	dir            string
	permissionMode string
	tmux           tmuxOps
}

// workerSession names the session that works one card. One card gets one
// session: a repeated name resolves latest-wins in the agent addressing layer
// and misroutes messages with no error.
func workerSession(cardNumber int) string {
	return fmt.Sprintf("card-%d", cardNumber)
}

// ensureSession spawns or validates the tmux session for site-review events.
func (r *router) ensureSession(out io.Writer, dir, session string) (string, error) {
	if session != "" {
		if !r.tmux.hasSession(session) {
			return "", fmt.Errorf("tmux session %q not found", session)
		}

		return session, nil
	}

	target := defaultSession
	if r.tmux.hasSession(target) {
		fmt.Fprintf(out, "Reusing the existing tmux session %q, so --dir %s is ignored\n", target, dir)

		return target, nil
	}
	if err := r.tmux.spawn(target, dir, tmux.SpawnOptions{PermissionMode: r.permissionMode}); err != nil {
		return "", err
	}
	fmt.Fprintf(out, "Started claude in tmux session %q\n", target)

	return target, nil
}

// onData routes one Mercure payload.
//
// A type this build does not handle is dropped in silence: a newer server
// publishes types an older binary never heard of, which is normal.
func (r *router) onData(data []byte) {
	event, err := inject.Parse(data)
	if err != nil {
		if errors.Is(err, inject.ErrUnknownType) {
			return
		}
		fmt.Fprintf(r.errOut, "skipping malformed event: %v\n", err)

		return
	}

	switch event.Type {
	case inject.SubmittedType:
		r.deliver(r.target, inject.SiteReviewDirective(), "site-review notification")
	case inject.CardMovedType:
		r.onCardMoved(event)
	}
}

// onCardMoved starts a worker for a card that entered next.
//
// Every other move is dropped, which also closes the feedback loop: the
// worker's own first act moves the card to in-progress and publishes a second
// event that this filter rejects.
func (r *router) onCardMoved(event inject.Event) {
	if event.ToStatus != inject.StatusNext {
		return
	}

	directive := inject.CardDirective(event)
	if r.dir == "" {
		r.deliver(r.target, directive, fmt.Sprintf("directive for card %d", event.CardNumber))

		return
	}

	session := workerSession(event.CardNumber)
	if r.tmux.hasSession(session) {
		fmt.Fprintf(r.errOut, "tmux session %q already exists, so a worker for card %d is already running; dropping event\n", session, event.CardNumber)

		return
	}
	// The directive is claude's own first prompt rather than typed keys: a
	// session that has just started is not yet reading input.
	opts := tmux.SpawnOptions{PermissionMode: r.permissionMode, Prompt: directive}
	if err := r.tmux.spawn(session, r.dir, opts); err != nil {
		fmt.Fprintf(r.errOut, "failed to start a worker for card %d: %v\n", event.CardNumber, err)

		return
	}
	fmt.Fprintf(r.out, "Started claude in tmux session %q for card %d\n", session, event.CardNumber)
}

func (r *router) deliver(target, text, what string) {
	if !r.tmux.hasSession(target) {
		fmt.Fprintf(r.errOut, "tmux session %q is gone; dropping %s\n", tmux.SessionName(target), what)

		return
	}
	if err := r.tmux.send(target, text); err != nil {
		fmt.Fprintf(r.errOut, "failed to inject %s: %v\n", what, err)

		return
	}
	fmt.Fprintf(r.out, "Injected %s\n", what)
}
