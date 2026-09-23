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
	ReportWorkerRun(ctx context.Context, handle string, run api.WorkerRun) (bool, error)
}

// runReports builds the reports of worker runs for the ordered queue. A server
// older than the run state endpoints gets the old final report instead.
//
// The fallback happens when a report is sent, not when it is made, so a report
// that already waits in the queue is not lost on an old server.
type runReports struct {
	client runReportClient
	log    *slog.Logger
	// unsupported is set by the first 404 of a run state endpoint, and stays set
	// until the bridge restarts.
	unsupported atomic.Bool
}

func newRunReports(client runReportClient, log *slog.Logger) *runReports {
	return &runReports{client: client, log: log}
}

// state is one state of the run runID, in the project handle.
func (s *runReports) state(handle, runID string, report api.RunStateReport) outbound.Report {
	return outbound.Report{
		Card: report.CardNumber,
		Rule: report.RuleName,
		Send: func(ctx context.Context) (bool, error) {
			if !s.unsupported.Load() {
				created, err := s.client.ReportRunState(ctx, handle, runID, report)
				if !errors.Is(err, api.ErrRunStatesUnsupported) {
					return created, err
				}
				s.markUnsupported()
			}
			// The old report holds only how a run ended, so an open state counts as
			// delivered.
			if !api.IsOutcome(report.State) {
				return true, nil
			}

			return s.post(ctx, handle, report)
		},
	}
}

// inventory lists every run the bridge holds, across all its projects.
func (s *runReports) inventory(bridgeID string, runs []api.InventoryRun) outbound.Report {
	return outbound.Report{
		Send: func(ctx context.Context) (bool, error) {
			if s.unsupported.Load() {
				return true, nil
			}
			err := s.client.ReportRunInventory(ctx, bridgeID, runs)
			if errors.Is(err, api.ErrRunStatesUnsupported) {
				s.markUnsupported()

				return true, nil
			}

			return err == nil, err
		},
	}
}

// post sends an outcome as the old report. That report needs a session and a
// start, which a run that never reached running does not have.
func (s *runReports) post(ctx context.Context, handle string, report api.RunStateReport) (bool, error) {
	if report.SessionID == "" || report.StartedAt.IsZero() {
		s.log.Warn("report_skipped",
			"card", report.CardNumber,
			"rule", report.RuleName,
			"message", "Loupe takes only the old report, which needs a session, and this run never started one",
		)

		return true, nil
	}

	return s.client.ReportWorkerRun(ctx, handle, api.WorkerRun{
		BridgeID:      report.BridgeID,
		SessionID:     report.SessionID,
		CardID:        report.CardID,
		CardNumber:    report.CardNumber,
		RuleName:      report.RuleName,
		StartedAt:     report.StartedAt,
		EndedAt:       report.EndedAt,
		ExitCode:      report.ExitCode,
		HasResult:     report.HasResult,
		FailureReason: report.FailureReason,
		Output:        report.Output,
	})
}

func (s *runReports) markUnsupported() {
	if s.unsupported.CompareAndSwap(false, true) {
		s.log.Warn("run_states_unsupported",
			"message", "Loupe has no run state endpoint, so the bridge sends only how each run ended",
		)
	}
}
