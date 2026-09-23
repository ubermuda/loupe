package cmd

import (
	"context"
	"fmt"
	"reflect"
	"strings"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// reloadResult says what a reload changed, or why it changed nothing. Stage
// names the step that failed: parse, check or server.
type reloadResult struct {
	OK       bool     `json:"ok"`
	Added    []string `json:"added,omitempty"`
	Removed  []string `json:"removed,omitempty"`
	Changed  []string `json:"changed,omitempty"`
	Projects []string `json:"projects,omitempty"`
	Problems []string `json:"problems,omitempty"`
	Stage    string   `json:"stage,omitempty"`
}

// reloadSource gives a reload what it needs from the disk and the server; tests replace it.
type reloadSource struct {
	load   func() (*rules.Set, error)
	check  func(ctx context.Context, set *rules.Set) error
	events func(ctx context.Context) (api.Events, error)
}

// newReloadSource reads the rule file at path with the bridge flags as
// defaults, and checks it against the server of cfg.
func newReloadSource(path string, defaults rules.Defaults, cfg config.Config) reloadSource {
	return reloadSource{
		load:   func() (*rules.Set, error) { return rules.Load(path, defaults) },
		check:  func(ctx context.Context, set *rules.Set) error { return set.Check(ctx, apiClient(cfg)) },
		events: func(ctx context.Context) (api.Events, error) { return apiClient(cfg).Events(ctx) },
	}
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
		r.reloading, r.reloadKills = true, nil
	}
	r.mu.Unlock()
	if shut {
		return shuttingDown()
	}
	defer func() {
		r.mu.Lock()
		r.reloading, r.reloadKills = false, nil
		r.mu.Unlock()
	}()

	set, stage, err := buildSet(ctx, src)
	if err != nil {
		problems := problemsOf(err)
		r.log.Error("reload_failed", "stage", stage, "problems", problems)

		return reloadResult{Stage: stage, Problems: problems}
	}

	return r.swap(set)
}

func shuttingDown() reloadResult {
	return reloadResult{Problems: []string{"the bridge is shutting down"}}
}

// buildSet loads, checks and confirms a new set, and names the stage that
// failed.
func buildSet(ctx context.Context, src reloadSource) (*rules.Set, string, error) {
	set, err := src.load()
	if err != nil {
		return nil, "parse", err
	}
	if err := src.check(ctx, set); err != nil {
		return nil, "check", err
	}
	events, err := src.events(ctx)
	if err != nil {
		return nil, "server", err
	}
	if missing := missingProjects(set, events); len(missing) > 0 {
		return nil, "server", fmt.Errorf("GET /api/events does not list %s, so no event of theirs can reach the bridge", strings.Join(missing, ", "))
	}

	return set, "", nil
}

// problemsOf gives each problem of a joined error one entry.
func problemsOf(err error) []string {
	if _, ok := err.(interface{ Unwrap() []error }); !ok {
		return []string{err.Error()}
	}

	return strings.Split(err.Error(), "\n")
}

// swap puts the new set in place in one critical section with the queue
// rewrite, so no event of the old set starts after it. It first replays on the
// new set each slug change the old set saw during the reload.
func (r *router) swap(set *rules.Set) reloadResult {
	r.mu.Lock()
	if r.shut() {
		r.mu.Unlock()

		return shuttingDown()
	}
	old := r.rules()
	kills := r.reloadKills
	r.reloading, r.reloadKills = false, nil
	dead := make([][]rules.Dead, len(kills))
	for i, e := range kills {
		dead[i] = set.Kill(e)
	}
	r.set.Store(set)
	dropped := r.rewriteLocked(set)
	r.pruneLocked(set)
	r.projects = set.Projects()
	shutDropped := r.dispatchLocked()
	r.reportAllLocked(set, old)
	r.mu.Unlock()

	for i, e := range kills {
		r.logDead(e, dead[i])
	}
	r.logDropped(dropped, "reason", "reload")
	r.logDropped(shutDropped)
	if r.heartbeat != nil {
		r.heartbeat.setBody(heartbeatBody(set))
	}

	res := diffRules(old, set)
	r.log.Info("reload_applied", "added", res.Added, "removed", res.Removed, "changed", res.Changed, "projects", res.Projects)
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
// new set in GET /api/events, so no gone mark holds. The caller holds mu.
func (r *router) pruneLocked(set *rules.Set) {
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
// rule changes when any field differs after the defaults are filled.
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

	return res
}
