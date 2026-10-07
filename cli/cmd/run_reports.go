package cmd

import (
	"context"
	"errors"
	"log/slog"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/outbound"
	"github.com/ubermuda/loupe/cli/internal/stream"
)

// toolCallBatch is the most calls one tool call report carries.
const toolCallBatch = 500

// siteTTL is how long the bridge keeps the settings of the projects.
const siteTTL = 10 * time.Minute

// runReportClient is the part of the API client that carries run reports.
type runReportClient interface {
	ReportRunState(ctx context.Context, handle, runID string, report api.RunStateReport) (bool, error)
	ReportRunInventory(ctx context.Context, bridgeID string, runs []api.InventoryRun) error
	ReportInteractiveLaunch(ctx context.Context, handle, sessionID string, report api.InteractiveLaunchReport) (bool, error)
	ReportToolCalls(ctx context.Context, handle, runID string, batch api.ToolCallBatch) error
	Sites(ctx context.Context) ([]api.Site, error)
}

// runReports builds the reports of worker runs for the ordered queue.
type runReports struct {
	client runReportClient
	log    *slog.Logger
	// launchUnsupported is set by the first 404 of the interactive run endpoint.
	launchUnsupported atomic.Bool
	// toolCallsUnsupported logs the first 404 of the tool call endpoint once.
	// Each later batch still tries, because agent push can come back on.
	toolCallsUnsupported atomic.Bool

	// sites caches the projects of the caller, read at sitesAt. now is
	// time.Now when nil, and tests set it.
	sitesMu sync.Mutex
	sites   []api.Site
	sitesAt time.Time
	now     func() time.Time
}

func newRunReports(client runReportClient, log *slog.Logger) *runReports {
	return &runReports{client: client, log: log}
}

// state is one state of the run runID, in the project handle.
func (s *runReports) state(handle, runID string, report api.RunStateReport) outbound.Report {
	return outbound.Report{
		Card: report.CardNumber,
		Rule: report.Rule,
		Send: func(ctx context.Context) (bool, error) {
			return s.client.ReportRunState(ctx, handle, runID, report)
		},
	}
}

// inventory lists every run the bridge holds, across all its projects.
func (s *runReports) inventory(bridgeID string, runs []api.InventoryRun) outbound.Report {
	return outbound.Report{
		Send: func(ctx context.Context) (bool, error) {
			err := s.client.ReportRunInventory(ctx, bridgeID, runs)

			return err == nil, err
		},
	}
}

// launch is how the launch of the interactive session sessionID went. A server
// with no endpoint for it counts the report as delivered.
func (s *runReports) launch(handle, sessionID string, report api.InteractiveLaunchReport) outbound.Report {
	return outbound.Report{
		Card: report.CardNumber,
		Rule: report.WorkKind,
		Send: func(ctx context.Context) (bool, error) {
			if s.launchUnsupported.Load() {
				return true, nil
			}
			created, err := s.client.ReportInteractiveLaunch(ctx, handle, sessionID, report)
			if !errors.Is(err, api.ErrInteractiveRunsUnsupported) {
				return created, err
			}
			if s.launchUnsupported.CompareAndSwap(false, true) {
				s.log.Warn("interactive_runs_unsupported",
					"message", "Loupe has no interactive run endpoint, so the bridge does not report its launches",
				)
			}

			return true, nil
		},
	}
}

// toolCalls are the reports that carry the tool calls of the run runID, in
// batches of toolCallBatch. Only the last batch carries the timing, so a run
// with no call still sends one empty batch. The rows are built now, with the
// project settings read now, so a queued batch holds no unsent raw input.
func (s *runReports) toolCalls(ctx context.Context, handle, runID string, card int, rule string, calls []stream.Call, timing stream.Timing) []outbound.Report {
	rows := toolCallRows(calls, s.site(ctx, handle))
	n := max(1, (len(rows)+toolCallBatch-1)/toolCallBatch)
	reports := make([]outbound.Report, 0, n)
	for i := range n {
		part := rows[i*toolCallBatch : min((i+1)*toolCallBatch, len(rows))]
		var last *api.ToolTiming
		if i == n-1 {
			last = &api.ToolTiming{ToolTimeMs: timing.ToolTimeMs, IdleGapMs: timing.IdleGapMs}
		}
		reports = append(reports, outbound.Report{
			Card: card,
			Rule: rule,
			Send: func(ctx context.Context) (bool, error) {
				batch := api.ToolCallBatch{Calls: part, Timing: last}
				err := s.client.ReportToolCalls(ctx, handle, runID, batch)
				if !errors.Is(err, api.ErrToolCallsUnsupported) {
					return err == nil, err
				}
				if s.toolCallsUnsupported.CompareAndSwap(false, true) {
					s.log.Warn("tool_calls_unsupported",
						"message", "Loupe has no tool call endpoint, so the bridge does not send the tool calls of its runs",
					)
				}

				return true, nil
			},
		})
	}

	return reports
}

// toolCallRows are the calls as the server takes them. A call with no start
// time has no row the server takes, so it stays out.
func toolCallRows(calls []stream.Call, site api.Site) []api.ToolCall {
	rows := make([]api.ToolCall, 0, len(calls))
	for _, c := range calls {
		if c.StartedAt.IsZero() {
			continue
		}
		row := api.ToolCall{
			Seq: c.Seq, Tool: c.Tool, StartedAt: c.StartedAt, DurationMs: c.DurationMs, IsError: c.IsError,
			InSubagent: c.InSubagent, BackgroundID: c.BackgroundID, WaitsOn: c.WaitsOn,
			Signatures: stream.Signatures(c, site.SubcommandPrograms),
		}
		if site.CollectFullText {
			text := c.FullText
			row.FullText = &text
		}
		rows = append(rows, row)
	}

	return rows
}

// site is the project handle names, from a cache of siteTTL. A project the
// bridge cannot read gets the defaults: no full text and the default programs.
func (s *runReports) site(ctx context.Context, handle string) api.Site {
	s.sitesMu.Lock()
	defer s.sitesMu.Unlock()

	now := time.Now
	if s.now != nil {
		now = s.now
	}
	if s.sites == nil || now().Sub(s.sitesAt) >= siteTTL {
		sites, err := s.client.Sites(ctx)
		if err != nil {
			s.sites = nil

			return api.Site{}
		}
		s.sites, s.sitesAt = append([]api.Site{}, sites...), now()
	}
	for _, site := range s.sites {
		if strings.EqualFold(site.ID, handle) {
			return site
		}
	}

	return api.Site{}
}
