package cmd

import (
	"context"
	"fmt"
	"maps"
	"reflect"
	"slices"
	"strings"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/hooks"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// reloadResult says what a reload changed, or why it changed nothing. Stage
// names the step that failed: lock, parse, hooks, claude, check or server.
type reloadResult struct {
	OK       bool     `json:"ok"`
	Added    []string `json:"added,omitempty"`
	Removed  []string `json:"removed,omitempty"`
	Changed  []string `json:"changed,omitempty"`
	Dirs     []string `json:"dirs,omitempty"`
	Projects []string `json:"projects,omitempty"`
	Problems []string `json:"problems,omitempty"`
	Stage    string   `json:"stage,omitempty"`
	// AccountsOff maps each account that failed its check to the reason.
	AccountsOff map[string]string `json:"accountsOff,omitempty"`
	// AccountsUnused names the accounts of AccountsOff that no rule runs on.
	AccountsUnused []string `json:"accountsUnused,omitempty"`
}

// reloadSource gives a reload what it needs from the disk and the server; tests
// replace it. A nil lock takes no lock, a nil resolveHooks resolves no hooks,
// a nil resolveClaude keeps the claude path, and a nil checkAccounts checks
// no account.
type reloadSource struct {
	lock          func() (check func() error, done func(applied bool), err error)
	load          func() (*rules.Set, error)
	resolveHooks  func(set *rules.Set) ([]hooks.Hook, error)
	resolveClaude func() (string, error)
	check         func(ctx context.Context, set *rules.Set) error
	events        func(ctx context.Context) (api.Events, error)
	checkAccounts func(ctx context.Context, set *rules.Set) []accountResult
}

// newReloadSource reads the rule file at path with the bridge flags as
// defaults, and checks it against the server of cfg. A reload moves lock
// when the path resolves to a new file.
func newReloadSource(path string, defaults rules.Defaults, cfg config.Config, lock *bridgeLock) reloadSource {
	src := reloadSource{
		load:          func() (*rules.Set, error) { return rules.Load(path, defaults) },
		resolveHooks:  resolveHooks,
		resolveClaude: resolveClaude,
		check:         func(ctx context.Context, set *rules.Set) error { return set.Check(ctx, apiClient(cfg)) },
		events:        func(ctx context.Context) (api.Events, error) { return apiClient(cfg).Events(ctx, cfg.BridgeID) },
		checkAccounts: checkAccounts,
	}
	if lock != nil {
		src.lock = lock.follow
	}

	return src
}

// reload builds a new rule set, swaps it in and applies the server flags of
// its GET /api/events answer. It runs one at a time. It builds and checks the
// set off mu, and any failure changes nothing.
func (r *router) reload(ctx context.Context, src reloadSource) reloadResult {
	if !r.reloadMu.TryLock() {
		return reloadResult{Problems: []string{"a reload is already running"}}
	}
	defer r.reloadMu.Unlock()

	r.mu.Lock()
	shut := r.shut()
	if !shut {
		r.reloading, r.reloadKills, r.reloadGone = true, nil, nil
	}
	r.mu.Unlock()
	if shut {
		return shuttingDown()
	}
	defer func() {
		r.mu.Lock()
		r.reloading, r.reloadKills, r.reloadGone = false, nil, nil
		r.mu.Unlock()
	}()

	check, done := func() error { return nil }, func(bool) {}
	if src.lock != nil {
		var err error
		if check, done, err = src.lock(); err != nil {
			return r.lockFailed(err)
		}
	}

	timeout := r.buildTimeout
	if timeout <= 0 {
		timeout = reloadBuildTimeout
	}
	// The stamp orders this answer against the answers of token refreshes.
	var seq uint64
	fetch := src.events
	src.events = func(ctx context.Context) (api.Events, error) {
		events, err := fetch(ctx)
		r.mu.Lock()
		seq = r.stampLocked()
		r.mu.Unlock()

		return events, err
	}
	buildCtx, cancel := context.WithTimeout(ctx, timeout)
	b, stage, err := buildSet(buildCtx, src)
	if err != nil {
		cancel()
		done(false)
		problems := problemsOf(err)
		r.log.Error("reload_failed", "stage", stage, "problems", problems)

		return reloadResult{Stage: stage, Problems: problems}
	}
	cancel()
	// The checks have their own time, so a slow build turns off no account.
	// A failing account fails no reload.
	if src.checkAccounts != nil {
		checkCtx, cancel := context.WithTimeout(ctx, reloadAccountsTimeout)
		b.accounts = src.checkAccounts(checkCtx, b.set)
		cancel()
		b.set.SetAccountProblems(accountsOff(b.accounts))
	}
	if err := check(); err != nil {
		done(false)

		return r.lockFailed(err)
	}
	res := r.swap(b, seq)
	done(res.OK)

	return res
}

func (r *router) lockFailed(err error) reloadResult {
	r.log.Error("reload_failed", "stage", "lock", "problems", []string{err.Error()})

	return reloadResult{Stage: "lock", Problems: []string{err.Error()}}
}

func shuttingDown() reloadResult {
	return reloadResult{Problems: []string{"the bridge is shutting down"}}
}

// built is a set a reload built, with its hooks, the GET /api/events answer it
// was checked against, the claude path when the set has an interactive rule,
// and the checks of its accounts.
type built struct {
	set      *rules.Set
	hooks    []hooks.Hook
	claude   string
	accounts []accountResult
	events   api.Events
}

// buildSet loads, checks and confirms a new set, resolves its hooks and its
// claude path, and names the stage that failed.
func buildSet(ctx context.Context, src reloadSource) (built, string, error) {
	var b built
	set, err := src.load()
	if err != nil {
		return b, "parse", err
	}
	b.set = set
	if src.resolveHooks != nil {
		if b.hooks, err = src.resolveHooks(set); err != nil {
			return b, "hooks", err
		}
	}
	if src.resolveClaude != nil && set.HasInteractive() && set.NeedsClaude() {
		if b.claude, err = src.resolveClaude(); err != nil {
			return b, "claude", err
		}
	}
	if err := src.check(ctx, set); err != nil {
		return b, "check", err
	}
	events, err := src.events(ctx)
	if err != nil {
		return b, "server", err
	}
	if missing := missingProjects(set, events); len(missing) > 0 {
		return b, "server", fmt.Errorf("GET /api/events does not list %s, so no event of theirs can reach the bridge", strings.Join(missing, ", "))
	}
	b.events = events

	return b, "", nil
}

// problemsOf gives each problem of a joined error one entry.
func problemsOf(err error) []string {
	if _, ok := err.(interface{ Unwrap() []error }); !ok {
		return []string{err.Error()}
	}

	return strings.Split(err.Error(), "\n")
}

// swap puts the new set and its hooks in place in one critical section with
// the queue rewrite, so no event of the old set starts after it. It first
// replays on the new set each slug change the old set saw during the reload,
// and each gone project from an answer newer than seq, the stamp of the
// reload's answer.
func (r *router) swap(b built, seq uint64) reloadResult {
	set := b.set
	r.mu.Lock()
	if r.shut() {
		r.mu.Unlock()

		return shuttingDown()
	}
	old := r.rules()
	kills := r.reloadKills
	var gone []string
	for _, g := range r.reloadGone {
		if g.seq > seq && !slices.Contains(gone, g.id) {
			gone = append(gone, g.id)
		}
	}
	r.reloading, r.reloadKills, r.reloadGone = false, nil, nil
	for _, e := range kills {
		set.KillWork(e)
	}
	for _, id := range gone {
		killGone(set, id)
	}
	r.set.Store(set)
	r.hookRunner.setHooks(b.hooks)
	if b.claude != "" {
		r.claude = b.claude
	}
	r.setSeq = seq
	dropped := r.rewriteLocked(set)
	r.pruneLocked(set, gone)
	r.projects = set.Projects()
	r.keepFlagsLocked(b.events)
	shutDropped := r.dispatchLocked()
	r.mu.Unlock()

	r.logDropped(dropped, "reason", "reload")
	r.logDropped(shutDropped)
	if r.heartbeat != nil {
		r.heartbeat.setBody(heartbeatBody(set, r.pushLogin))
	}
	r.useFlags()
	r.refreshPrices(set)

	res := diffRules(old, set)
	attrs := []any{"added", res.Added, "removed", res.Removed, "changed", res.Changed, "dirs", res.Dirs, "projects", res.Projects}
	if pools := diffPools(old, set); len(pools) > 0 {
		attrs = append(attrs, "pools", pools)
	}
	if set.MaxWorkers() != old.MaxWorkers() {
		attrs = append(attrs, "max_workers", set.MaxWorkers())
	}
	r.log.Info("reload_applied", attrs...)
	warnUnknownModes(r.log, set)
	warnAgentsOff(r.log, set)
	warnAccountsOff(r.log, b.accounts)
	if off := set.AccountsOff(); len(off) > 0 {
		res.AccountsOff = off
		used := set.UsedAccounts()
		for name := range off {
			if !slices.Contains(used, name) {
				res.AccountsUnused = append(res.AccountsUnused, name)
			}
		}
		slices.Sort(res.AccountsUnused)
	}

	return res
}

// rewriteLocked keeps each queued event whose rule, by name, still runs it on
// the new set, with the new settings and prompt. It drops the others, and a
// dropped checked resume frees its card. The caller holds mu.
func (r *router) rewriteLocked(set *rules.Set) []pending {
	var dropped []pending
	kept := r.queue[:0]
	for _, p := range r.queue {
		m, ok := matchPending(set, p)
		if !ok {
			p.dropReason = api.DropReload
			dropped = append(dropped, p)

			continue
		}
		p.set = set
		p.apply(m)
		kept = append(kept, p)
	}
	clear(r.queue[len(kept):])
	r.queue = kept

	return dropped
}

// pruneLocked forgets the unmapped marks of the projects the new set maps. The reload saw each project of the
// new set in GET /api/events, so a gone mark holds only for the ids a newer
// answer found gone. The caller holds mu.
func (r *router) pruneLocked(set *rules.Set, gone []string) {
	for _, slug := range set.Projects() {
		delete(r.unmapped, set.ProjectID(slug))
	}
	r.gone = nil
	for _, id := range gone {
		if slugOf(set, id) != "" {
			if r.gone == nil {
				r.gone = map[string]bool{}
			}
			r.gone[id] = true
		}
	}
}

// diffRules names the work kinds the new set adds, removes and changes. A
// kind changes when any field of its entry differs after the defaults are
// filled. It also names each project of both sets whose dir changed.
func diffRules(old, set *rules.Set) reloadResult {
	res := reloadResult{OK: true, Projects: set.Projects()}
	for _, kind := range set.WorkKinds() {
		prev, ok := old.WorkEntry(kind)
		entry, _ := set.WorkEntry(kind)
		switch {
		case !ok:
			res.Added = append(res.Added, kind)
		case !reflect.DeepEqual(prev, entry):
			res.Changed = append(res.Changed, kind)
		}
	}
	for _, kind := range old.WorkKinds() {
		if _, ok := set.WorkEntry(kind); !ok {
			res.Removed = append(res.Removed, kind)
		}
	}
	for _, slug := range set.Projects() {
		if dir := old.Dir(slug); dir != "" && dir != set.Dir(slug) {
			res.Dirs = append(res.Dirs, slug)
		}
	}

	return res
}

// diffPools names each worker pool the new set adds, removes or resizes, in
// name order.
func diffPools(old, set *rules.Set) []string {
	before, after := old.Pools(), set.Pools()
	var out []string
	names := slices.AppendSeq(slices.Collect(maps.Keys(before)), maps.Keys(after))
	slices.Sort(names)
	for _, name := range slices.Compact(names) {
		prev, was := before[name]
		size, is := after[name]
		switch {
		case !was:
			out = append(out, fmt.Sprintf("%s: added %d", name, size))
		case !is:
			out = append(out, name+": removed")
		case prev != size:
			out = append(out, fmt.Sprintf("%s: %d -> %d", name, prev, size))
		}
	}

	return out
}
