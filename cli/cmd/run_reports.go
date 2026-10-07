package cmd

import (
	"context"
	"errors"
	"log/slog"
	"sync/atomic"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/outbound"
)

// runReportClient is the part of the API client that carries run reports.
type runReportClient interface {
	ReportRunState(ctx context.Context, handle, runID string, report api.RunStateReport) (bool, error)
	ReportRunInventory(ctx context.Context, bridgeID string, runs []api.InventoryRun) error
	ReportInteractiveLaunch(ctx context.Context, handle, sessionID string, report api.InteractiveLaunchReport) (bool, error)
}

// runReports builds the reports of worker runs for the ordered queue.
type runReports struct {
	client runReportClient
	log    *slog.Logger
	// launchUnsupported is set by the first 404 of the interactive run endpoint.
	launchUnsupported atomic.Bool
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
