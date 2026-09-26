package cmd

import (
	"cmp"
	"context"
	"errors"
	"io"
	"log/slog"
	"maps"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/hooks"
)

// The bridge events a hook listens to.
const (
	hookStart = "start"
	hookStop  = "stop"
	hookBusy  = "busy"
	hookIdle  = "idle"
)

// The outcomes of the last run of a hook.
const (
	hookOK      = "ok"
	hookFailed  = "failed"
	hookTimeout = "timeout"
	hookNever   = "never"
)

// maxHookOutput is how much of the end of a hook's output a log line and a
// row carry.
const maxHookOutput = 500

// hookRunner runs the hook packages on the bridge events, one event at a time
// in arrival order, and one hook at a time in install order. coalesceLocked
// trims a pending busy or idle. It runs on its own goroutine with its own
// context, so a shutdown that kills the workers still runs stop. A failed hook
// is logged and never stops the bridge.
type hookRunner struct {
	log      *slog.Logger
	bridgeID string
	// timeout bounds each run when it is not zero. Zero means the time limit
	// of the manifest.
	timeout time.Duration

	// mu guards the fields below. The router takes it under its own mu, so the
	// runner never runs a process under mu and never calls the router.
	mu        sync.Mutex
	hooks     []hooks.Hook
	last      map[hookKey]hookRun
	heartbeat *heartbeater
	inbox     []hookJob
	// sent is the last busy or idle each package ID got, kept across a reload
	// that removes the package. No entry means idle.
	sent map[string]string
	// state is the last busy or idle the bridge fired. Empty means idle.
	state   string
	started bool
	stopped bool
	wake    chan struct{}
	done    chan struct{}
}

// hookKey names one event of one package at one commit, so a package that a
// reload moves to a new commit starts again as never.
type hookKey struct {
	id, sha, event string
}

// hookJob is one event in the inbox, with the hooks it runs. The list is the
// one that held when the event fired, so a later reload does not change it.
type hookJob struct {
	event string
	hooks []hooks.Hook
}

// hookRun is how one run of a hook ended.
type hookRun struct {
	at      time.Time
	outcome string
	err     string
}

func newHookRunner(list []hooks.Hook, bridgeID string, log *slog.Logger) *hookRunner {
	return &hookRunner{
		log:      log,
		bridgeID: bridgeID,
		hooks:    list,
		last:     map[hookKey]hookRun{},
		sent:     map[string]string{},
		wake:     make(chan struct{}, 1),
		done:     make(chan struct{}),
	}
}

// start starts the loop and fires start.
func (hr *hookRunner) start() {
	if hr == nil {
		return
	}
	hr.mu.Lock()
	hr.started = true
	hr.fireLocked(hookStart)
	hr.mu.Unlock()
	go hr.loop()
}

// fire hands an event to the loop and returns at once. After stop it does
// nothing.
func (hr *hookRunner) fire(event string) {
	if hr == nil {
		return
	}
	hr.mu.Lock()
	defer hr.mu.Unlock()

	hr.fireLocked(event)
}

// fireLocked appends the event and wakes the loop. The caller holds mu.
func (hr *hookRunner) fireLocked(event string) {
	if hr.stopped {
		return
	}
	if isStateEvent(event) {
		hr.state = event
	}
	hr.inbox = append(hr.inbox, hookJob{event: event, hooks: hr.hooks})
	select {
	case hr.wake <- struct{}{}:
	default:
	}
}

// stop fires stop, takes no event after it, and waits until the loop has run
// every event it took except a pending busy or idle. Each hook's time limit
// bounds the wait.
func (hr *hookRunner) stop() {
	if hr == nil {
		return
	}
	hr.mu.Lock()
	hr.fireLocked(hookStop)
	hr.stopped = true
	started := hr.started
	hr.mu.Unlock()
	if started {
		<-hr.done
	}
}

// setHooks applies the hooks of a reload. A removed package loses its rows,
// and runs stop once, so it can release what busy took. When the bridge is
// busy, it fires busy again, and coalesceLocked keeps only the packages that
// have not had it, so an added package gets the idle that follows.
func (hr *hookRunner) setHooks(list []hooks.Hook) {
	if hr == nil {
		return
	}
	hr.mu.Lock()
	defer hr.mu.Unlock()

	var removed []hooks.Hook
	for _, h := range hr.hooks {
		if _, ok := h.Events[hookStop]; ok && !slices.ContainsFunc(list, func(n hooks.Hook) bool { return n.ID == h.ID }) {
			removed = append(removed, h)
		}
	}
	if len(removed) > 0 && hr.started && !hr.stopped {
		hr.inbox = append(hr.inbox, hookJob{event: hookStop, hooks: removed})
		select {
		case hr.wake <- struct{}{}:
		default:
		}
	}
	hr.hooks = list
	live := map[hookKey]bool{}
	for _, h := range list {
		for e := range h.Events {
			live[hookKey{h.ID, h.SHA, e}] = true
		}
	}
	maps.DeleteFunc(hr.last, func(k hookKey, _ hookRun) bool { return !live[k] })
	hr.pushLocked()
	if hr.state == hookBusy {
		hr.fireLocked(hookBusy)
	}
}

// attach gives the runner the heartbeat that carries its rows, and sends the
// rows at once.
func (hr *hookRunner) attach(h *heartbeater) {
	if hr == nil {
		return
	}
	hr.mu.Lock()
	defer hr.mu.Unlock()

	hr.heartbeat = h
	hr.pushLocked()
}

// pushLocked sends the rows to the heartbeat. It runs under mu, so two pushes
// never reach the heartbeat out of order. The caller holds mu.
func (hr *hookRunner) pushLocked() {
	if hr.heartbeat != nil {
		hr.heartbeat.setHooks(hr.rowsLocked())
	}
}

// rows lists the last run of each event each package defines.
func (hr *hookRunner) rows() []api.HookReport {
	hr.mu.Lock()
	defer hr.mu.Unlock()

	return hr.rowsLocked()
}

// rowsLocked lists the rows in install order, and the events of one package in
// the order they fire. The list is never nil, so the server clears the rows of
// a bridge that has no hooks. The caller holds mu.
func (hr *hookRunner) rowsLocked() []api.HookReport {
	out := []api.HookReport{}
	for _, h := range hr.hooks {
		for _, e := range hooks.Events {
			if _, ok := h.Events[e]; !ok {
				continue
			}
			row := api.HookReport{Package: h.ID, Ref: h.Ref, Event: e, Outcome: hookNever}
			if run, ok := hr.last[hookKey{h.ID, h.SHA, e}]; ok {
				row.LastRunAt, row.Outcome, row.Error = run.at.UTC().Format(time.RFC3339), run.outcome, run.err
			}
			out = append(out, row)
		}
	}

	return out
}

// record keeps the run of a hook the list still holds, and sends the rows.
func (hr *hookRunner) record(h hooks.Hook, event string, run hookRun) {
	hr.mu.Lock()
	defer hr.mu.Unlock()

	if !slices.ContainsFunc(hr.hooks, func(n hooks.Hook) bool { return n.ID == h.ID && n.SHA == h.SHA }) {
		return
	}
	hr.last[hookKey{h.ID, h.SHA, event}] = run
	hr.pushLocked()
}

// next takes the oldest event and the hooks it runs. ok is false once stop has
// run and the inbox is empty.
func (hr *hookRunner) next() (event string, list []hooks.Hook, ok, wait bool) {
	hr.mu.Lock()
	defer hr.mu.Unlock()

	hr.coalesceLocked()
	if len(hr.inbox) == 0 {
		return "", nil, false, !hr.stopped
	}
	job := hr.inbox[0]
	hr.inbox = hr.inbox[1:]
	for _, h := range job.hooks {
		switch {
		case isStateEvent(job.event):
			hr.sent[h.ID] = job.event
		case job.event == hookStop:
			delete(hr.sent, h.ID)
		}
	}
	return job.event, job.hooks, true, false
}

// coalesceLocked keeps only the last pending busy or idle, so a hung hook does
// not leave a backlog of stale changes. That one keeps only the packages whose
// state differs, and goes when none do or stop has fired. A stop queued before
// it resets the state of its packages to idle. The caller holds mu.
func (hr *hookRunner) coalesceLocked() {
	last := -1
	for i, job := range hr.inbox {
		if isStateEvent(job.event) {
			last = i
		}
	}
	state := maps.Clone(hr.sent)
	for _, job := range hr.inbox[:max(last, 0)] {
		if job.event == hookStop {
			for _, h := range job.hooks {
				delete(state, h.ID)
			}
		}
	}
	kept := hr.inbox[:0]
	for i, job := range hr.inbox {
		if isStateEvent(job.event) {
			if i != last || hr.stopped {
				continue
			}
			job.hooks = slices.DeleteFunc(slices.Clone(job.hooks), func(h hooks.Hook) bool {
				return cmp.Or(state[h.ID], hookIdle) == job.event
			})
			if len(job.hooks) == 0 {
				continue
			}
		}
		kept = append(kept, job)
	}
	clear(hr.inbox[len(kept):])
	hr.inbox = kept
}

func isStateEvent(event string) bool {
	return event == hookBusy || event == hookIdle
}

func (hr *hookRunner) loop() {
	defer close(hr.done)

	for {
		event, list, ok, wait := hr.next()
		switch {
		case ok:
			for _, h := range list {
				if cmd, has := h.Events[event]; has {
					hr.record(h, event, hr.run(h, event, cmd))
				}
			}
		case wait:
			<-hr.wake
		default:
			return
		}
	}
}

// run runs one hook and logs how it ended.
func (hr *hookRunner) run(h hooks.Hook, event string, argv []string) hookRun {
	res := hookRun{at: time.Now(), outcome: hookOK}
	attrs := []any{"package", h.ID, "hook_event", event}
	limit := hookLimit(h, hr.timeout)
	out := &tailWriter{limit: 4 * maxHookOutput}
	timedOut, err := execHook(h, event, argv, hr.bridgeID, limit, out)
	output := out.text(maxHookOutput)
	var exitErr *exec.ExitError
	switch {
	case err == nil:
		hr.log.Info("hook_ran", append(attrs, "duration_ms", time.Since(res.at).Milliseconds())...)
	case timedOut:
		res.outcome, res.err = hookTimeout, output
		hr.log.Warn("hook_timeout", append(attrs, "timeout_seconds", int(limit/time.Second), "output", output)...)
	case errors.As(err, &exitErr):
		res.outcome, res.err = hookFailed, cmp.Or(output, err.Error())
		hr.log.Warn("hook_failed", append(attrs, "exit_code", exitErr.ExitCode(), "output", output)...)
	default:
		res.outcome, res.err = hookFailed, tail(err.Error(), maxHookOutput)
		hr.log.Warn("hook_failed", append(attrs, "error", err.Error())...)
	}

	return res
}

// hookLimit bounds one run: override when it is not zero, else the time limit
// of the manifest.
func hookLimit(h hooks.Hook, override time.Duration) time.Duration {
	if override > 0 {
		return override
	}

	return cmp.Or(h.Timeout, hooks.DefaultTimeout)
}

// execHook runs one hook in its package directory, with no shell and an empty
// stdin, and writes its output to out. timedOut says the limit killed it.
func execHook(h hooks.Hook, event string, argv []string, bridgeID string, limit time.Duration, out io.Writer) (timedOut bool, err error) {
	if err := h.MakeStateDir(); err != nil {
		return false, err
	}
	ctx, cancel := context.WithTimeout(context.Background(), limit)
	defer cancel()

	cmd := exec.CommandContext(ctx, filepath.Join(h.Dir, filepath.FromSlash(argv[0])), argv[1:]...)
	cmd.Dir = h.Dir
	cmd.Env = hookEnv(os.Environ(), h, event, bridgeID)
	cmd.Stdout, cmd.Stderr = out, out
	cmd.WaitDelay = waitDelay
	setProcessGroup(cmd)

	err = cmd.Run()

	return err != nil && errors.Is(ctx.Err(), context.DeadlineExceeded), err
}

// hookEnv is the environment of a hook: the bridge's, then the hook variables,
// which win over any of the same name.
func hookEnv(environ []string, h hooks.Hook, event, bridgeID string) []string {
	env := append(slices.Clip(environ),
		"LOUPE_HOOK_EVENT="+event,
		"LOUPE_HOOK_PACKAGE="+h.ID,
		"LOUPE_BRIDGE_ID="+bridgeID,
		"LOUPE_HOOK_STATE_DIR="+h.StateDir,
	)
	for _, name := range slices.Sorted(maps.Keys(h.Settings)) {
		env = append(env, "LOUPE_HOOK_SETTING_"+strings.ToUpper(name)+"="+h.Settings[name])
	}

	return env
}

// tailWriter keeps the last limit bytes written to it.
type tailWriter struct {
	limit int
	buf   []byte
}

func (w *tailWriter) Write(p []byte) (int, error) {
	w.buf = append(w.buf, p...)
	if over := len(w.buf) - w.limit; over > 0 {
		w.buf = append(w.buf[:0], w.buf[over:]...)
	}

	return len(p), nil
}

// text is the last n characters of the output, trimmed. A character the byte
// cap cut in half is dropped.
func (w *tailWriter) text(n int) string {
	return tail(strings.TrimSpace(strings.ToValidUTF8(string(w.buf), "")), n)
}

// tail is the last n characters of s.
func tail(s string, n int) string {
	r := []rune(s)
	if len(r) <= n {
		return s
	}

	return string(r[len(r)-n:])
}
