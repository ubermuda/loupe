package cmd

import (
	"context"
	"fmt"
	"reflect"
	"slices"
	"strings"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/hooks"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// reloadResult says what a reload changed, or why it changed nothing. Stage
// names the step that failed: lock, parse, hooks, check or server.
type reloadResult struct {
	OK       bool     `json:"ok"`
	Added    []string `json:"added,omitempty"`
	Removed  []string `json:"removed,omitempty"`
	Changed  []string `json:"changed,omitempty"`
	Dirs     []string `json:"dirs,omitempty"`
	Projects []string `json:"projects,omitempty"`
	Problems []string `json:"problems,omitempty"`
	Stage    string   `json:"stage,omitempty"`
}

// reloadSource gives a reload what it needs from the disk and the server; tests
// replace it. A nil lock takes no lock, and a nil resolveHooks resolves no
// hooks.
type reloadSource struct {
	lock         func() (check func() error, done func(applied bool), err error)
	load         func() (*rules.Set, error)
	resolveHooks func(set *rules.Set) ([]hooks.Hook, error)
	check        func(ctx context.Context, set *rules.Set) error
	events       func(ctx context.Context) (api.Events, error)
}

// newReloadSource reads the rule file at path with the bridge flags as
// defaults, and checks it against the server of cfg. A reload moves lock
// when the path resolves to a new file.
func newReloadSource(path string, defaults rules.Defaults, cfg config.Config, lock *bridgeLock) reloadSource {
	src := reloadSource{
		load:         func() (*rules.Set, error) { return rules.Load(path, defaults) },
		resolveHooks: resolveHooks,
		check:        func(ctx context.Context, set *rules.Set) error { return set.Check(ctx, apiClient(cfg)) },
		events:       func(ctx context.Context) (api.Events, error) { return apiClient(cfg).Events(ctx) },
	}
	if lock != nil {
		src.lock = lock.follow
	}

	return src
}

// reload builds a new rule set and swaps it in. It runs one at a time. It
// builds and checks the set off mu, and any failure changes nothing.
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
	set, list, stage, err := buildSet(buildCtx, src)
	cancel()
	if err != nil {
		done(false)
		problems := problemsOf(err)
		r.log.Error("reload_failed", "stage", stage, "problems", problems)

		return reloadResult{Stage: stage, Problems: problems}
	}
	if err := check(); err != nil {
		done(false)

		return r.lockFailed(err)
	}
	res := r.swap(set, list, seq)
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

// buildSet loads, checks and confirms a new set, resolves its hooks, and names
// the stage that failed.
func buildSet(ctx context.Context, src reloadSource) (*rules.Set, []hooks.Hook, string, error) {
	set, err := src.load()
	if err != nil {
		return nil, nil, "parse", err
	}
	var list []hooks.Hook
	if src.resolveHooks != nil {
		if list, err = src.resolveHooks(set); err != nil {
			return nil, nil, "hooks", err
		}
	}
	if err := src.check(ctx, set); err != nil {
		return nil, nil, "check", err
	}
	events, err := src.events(ctx)
	if err != nil {
		return nil, nil, "server", err
	}
	if missing := missingProjects(set, events); len(missing) > 0 {
		return nil, nil, "server", fmt.Errorf("GET /api/events does not list %s, so no event of theirs can reach the bridge", strings.Join(missing, ", "))
	}

	return set, list, "", nil
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
func (r *router) swap(set *rules.Set, list []hooks.Hook, seq uint64) reloadResult {
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
	dead := make([][]rules.Dead, len(kills))
	for i, e := range kills {
		dead[i] = set.Kill(e)
	}
	goneDead := make([][]rules.Dead, len(gone))
	for i, id := range gone {
		goneDead[i] = killGone(set, id)
	}
	r.set.Store(set)
	r.hookRunner.setHooks(list)
	r.setSeq = seq
	dropped := r.rewriteLocked(set)
	r.pruneLocked(set, gone)
	r.projects = set.Projects()
	shutDropped := r.dispatchLocked()
	r.reportAllLocked(set, old)
	r.mu.Unlock()

	for i, e := range kills {
		r.logDead(e, dead[i])
	}
	for i, id := range gone {
		r.logGone(id, goneDead[i])
	}
	r.logDropped(dropped, "reason", "reload")
	r.logDropped(shutDropped)
	if r.heartbeat != nil {
		r.heartbeat.setBody(heartbeatBody(set))
	}

	res := diffRules(old, set)
	r.log.Info("reload_applied", "added", res.Added, "removed", res.Removed, "changed", res.Changed, "dirs", res.Dirs, "projects", res.Projects)
	warnUnknownModes(r.log, set)

	return res
}

// rewriteLocked keeps each queued event whose rule, by name, still runs it on
// the new set, with the new settings and prompt. It drops the others, and a
// dropped checked resume frees its card. The caller holds mu.
func (r *router) rewriteLocked(set *rules.Set) []pending {
	var dropped []pending
	kept := r.queue[:0]
	for _, p := range r.queue {
		m, ok := set.MatchRule(p.event, p.rule)
		if !ok {
			p.dropReason = api.DropReload
			dropped = append(dropped, p)
			if p.checked {
				delete(r.running, p.key)
			}

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

// pruneLocked forgets the chain counts of the rules the new set lacks, and the
// unmapped marks of the projects it maps. The reload saw each project of the
// new set in GET /api/events, so a gone mark holds only for the ids a newer
// answer found gone. The caller holds mu.
func (r *router) pruneLocked(set *rules.Set, gone []string) {
	names := map[string]bool{}
	for _, rule := range set.Rules() {
		names[rule.Name] = true
	}
	for key, counts := range r.chains {
		for name := range counts {
			if !names[name] {
				delete(counts, name)
			}
		}
		if len(counts) == 0 {
			delete(r.chains, key)
		}
	}
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

// reportAllLocked reports the health of every project of the new set, and an
// empty report for each project only the old set mapped. It runs under mu, so
// the report of a later kill always goes out after these. The empty reports go
// first, because a renamed project keeps its id.
func (r *router) reportAllLocked(set, old *rules.Set) {
	if r.health == nil {
		return
	}
	ids := map[string]bool{}
	for _, slug := range set.Projects() {
		ids[set.ProjectID(slug)] = true
	}
	for _, slug := range old.Projects() {
		if id := old.ProjectID(slug); !ids[id] {
			r.health.submit(slug, id, []api.RuleHealth{})
		}
	}
	for _, slug := range set.Projects() {
		r.reportHealth(set, slug)
	}
}

// diffRules names the rules the new set adds, removes and changes, by name. A
// rule changes when any field differs after the defaults are filled. It also
// names each project of both sets whose dir changed.
func diffRules(old, set *rules.Set) reloadResult {
	res := reloadResult{OK: true, Projects: set.Projects()}
	before := map[string]rules.Rule{}
	for _, rule := range old.Rules() {
		before[rule.Name] = rule
	}
	after := map[string]bool{}
	for _, rule := range set.Rules() {
		after[rule.Name] = true
		prev, ok := before[rule.Name]
		switch {
		case !ok:
			res.Added = append(res.Added, rule.Name)
		case !reflect.DeepEqual(prev, rule):
			res.Changed = append(res.Changed, rule.Name)
		}
	}
	for _, rule := range old.Rules() {
		if !after[rule.Name] {
			res.Removed = append(res.Removed, rule.Name)
		}
	}
	for _, slug := range set.Projects() {
		if dir := old.Dir(slug); dir != "" && dir != set.Dir(slug) {
			res.Dirs = append(res.Dirs, slug)
		}
	}

	return res
}
