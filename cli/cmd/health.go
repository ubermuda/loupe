package cmd

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
)

// The retry delays of a failed report. The server allows 60 reports a minute
// per token, and a report only matters until the next one.
const (
	reportRetryBase = time.Second
	reportRetryMax  = time.Minute
)

// ruleReporter sends one project's rule health report. *api.Client is one.
type ruleReporter interface {
	ReportRules(ctx context.Context, handle, bridgeID string, rules []api.RuleHealth) error
}

// healthReporter sends rule health reports off the stream goroutine. Each
// project has one goroutine and one pending report, so a newer report replaces
// one that waits for a retry, and a slow server never delays an event.
type healthReporter struct {
	ctx      context.Context
	client   ruleReporter
	bridgeID string
	log      *slog.Logger
	base     time.Duration
	max      time.Duration

	mu       sync.Mutex
	projects map[string]*projectReport
	wg       sync.WaitGroup
}

// projectReport is the report state of one project. mu guards every field but
// wake, which a submit signals without blocking.
type projectReport struct {
	slug    string
	handle  string
	pending []api.RuleHealth
	has     bool
	stopped bool
	wake    chan struct{}
	// done closes when the project's goroutine returns.
	done chan struct{}
}

func newHealthReporter(ctx context.Context, client ruleReporter, bridgeID string, log *slog.Logger) *healthReporter {
	return &healthReporter{ctx: ctx, client: client, bridgeID: bridgeID, log: log, base: reportRetryBase, max: reportRetryMax}
}

// submit queues the report of one project, in place of any report that has
// not gone out yet. The handle is the project id, which a project rename does
// not change. The caller builds rules, and the reporter never reads the set.
func (h *healthReporter) submit(slug, handle string, rules []api.RuleHealth) {
	h.mu.Lock()
	p := h.projects[handle]
	if p == nil {
		if h.projects == nil {
			h.projects = map[string]*projectReport{}
		}
		p = &projectReport{slug: slug, handle: handle, wake: make(chan struct{}, 1), done: make(chan struct{})}
		h.projects[handle] = p
		h.wg.Add(1)
		go h.loop(p)
	}
	if p.stopped {
		h.mu.Unlock()

		return
	}
	p.pending, p.has = rules, true
	h.mu.Unlock()

	select {
	case p.wake <- struct{}{}:
	default:
	}
}

// wait blocks until every report goroutine has returned. They return once the
// context is cancelled.
func (h *healthReporter) wait() {
	h.wg.Wait()
}

// project returns the report state of one project, or nil before its first
// submit.
func (h *healthReporter) project(handle string) *projectReport {
	h.mu.Lock()
	defer h.mu.Unlock()

	return h.projects[handle]
}

func (h *healthReporter) take(p *projectReport) ([]api.RuleHealth, bool) {
	h.mu.Lock()
	defer h.mu.Unlock()
	if !p.has {
		return nil, false
	}
	rules := p.pending
	p.pending, p.has = nil, false

	return rules, true
}

// restore puts a failed report back, unless a newer one arrived meanwhile.
func (h *healthReporter) restore(p *projectReport, rules []api.RuleHealth) {
	h.mu.Lock()
	defer h.mu.Unlock()
	if !p.has {
		p.pending, p.has = rules, true
	}
}

func (h *healthReporter) stop(p *projectReport) {
	h.mu.Lock()
	defer h.mu.Unlock()
	p.stopped, p.pending, p.has = true, nil, false
}

func (h *healthReporter) loop(p *projectReport) {
	defer h.wg.Done()
	defer close(p.done)

	attempt := 0
	for {
		select {
		case <-h.ctx.Done():
			return
		case <-p.wake:
		}

		for {
			rules, ok := h.take(p)
			if !ok {
				break
			}
			err := h.client.ReportRules(h.ctx, p.handle, h.bridgeID, rules)
			switch {
			case err == nil:
				attempt = 0
				h.log.Info("report_sent", "project", p.handle, "project_slug", p.slug, "rules", len(rules), "dead", countDead(rules))

				continue
			case h.ctx.Err() != nil:
				return
			case errors.Is(err, api.ErrBoardDisabled):
				h.stop(p)
				h.log.Warn("report_failed", "project", p.handle, "project_slug", p.slug, "error", err.Error(), "retry", false,
					"message", "the board is switched off, so the bridge stops reporting rule health for project "+p.slug)

				return
			case errors.Is(err, api.ErrReportRejected):
				attempt = 0
				h.log.Error("report_failed", "project", p.handle, "project_slug", p.slug, "error", err.Error(), "retry", false,
					"message", rejectedAdvice(err, p.slug, rules))

				continue
			}

			delay := h.backoff(attempt)
			attempt++
			h.log.Warn("report_failed", "project", p.handle, "project_slug", p.slug, "error", err.Error(), "retry", true, "retry_in_ms", delay.Milliseconds())
			h.restore(p, rules)

			timer := time.NewTimer(delay)
			select {
			case <-h.ctx.Done():
				timer.Stop()

				return
			case <-p.wake:
				attempt = 0
			case <-timer.C:
			}
			timer.Stop()
		}
	}
}

// violationPath reads the rule index and field of a 422 property path.
var violationPath = regexp.MustCompile(`^rules\[(\d+)\]\.(\w+)`)

// rejectedAdvice tells the operator what to do about a report the server
// refused for good. A retry of the same body fails again.
func rejectedAdvice(err error, slug string, rules []api.RuleHealth) string {
	var rejected *api.RejectedReport
	errors.As(err, &rejected)
	switch {
	case errors.Is(err, api.ErrProjectNotFound):
		return fmt.Sprintf("the server knows no project %s of yours: fix the projects map in rules.yaml and restart the bridge", slug)
	case errors.Is(err, api.ErrEndpointMissing):
		return "the server has no rule health endpoint, or it refuses the bridgeId in config.json, which it takes as a lower-case uuid of version 1 or 3 to 8: upgrade Loupe or fix the id, then restart the bridge"
	case rejected != nil && rejected.Status == http.StatusUnprocessableEntity:
		fields := []string{"the report"}
		if len(rejected.Violations) > 0 {
			fields = nil
		}
		for _, v := range rejected.Violations {
			m := violationPath.FindStringSubmatch(v.PropertyPath)
			if m == nil {
				fields = append(fields, v.PropertyPath)

				continue
			}
			if i, _ := strconv.Atoi(m[1]); i < len(rules) {
				fields = append(fields, fmt.Sprintf("field %s of rule %s", m[2], rules[i].Name))
			} else {
				fields = append(fields, v.PropertyPath)
			}
		}

		return fmt.Sprintf("the server refused %s of project %s: fix rules.yaml and restart the bridge", strings.Join(fields, ", "), slug)
	default:
		return "the server refused the token: run loupe login with a token that has the agent scope, then restart the bridge"
	}
}

// backoff doubles from base for each failed attempt in a row, up to max.
func (h *healthReporter) backoff(attempt int) time.Duration {
	d := h.base
	for range attempt {
		if d >= h.max/2 {
			return h.max
		}
		d *= 2
	}

	return min(d, h.max)
}

func countDead(rules []api.RuleHealth) int {
	n := 0
	for _, r := range rules {
		if r.State == api.RuleDead {
			n++
		}
	}

	return n
}
