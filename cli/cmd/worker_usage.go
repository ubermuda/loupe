package cmd

import (
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	harn "github.com/ubermuda/loupe/cli/internal/harness"
	"github.com/ubermuda/loupe/cli/internal/transcript"
)

// sessionBaseline is what the whole session spent before a resume, and nil
// when the harness cannot read it.
func sessionBaseline(h harn.Harness, sessionID string) *transcript.Usage {
	usage, err := h.SessionTotal(sessionID)
	if err != nil {
		return nil
	}

	return &usage
}

// workerUsage is what the process of rec spent. claude's own counts cover the
// whole session, so a resume subtracts the baseline it read before it started,
// and one with no baseline sends the session as an estimate. A process that
// printed no counts is estimated from its transcript messages.
func workerUsage(rec runRecord, reported transcript.Usage) *api.Usage {
	switch {
	case reported != nil && !rec.Resume:
		return apiUsage(api.UsageReported, reported)
	case reported != nil && rec.Baseline != nil:
		return apiUsage(api.UsageReported, reported.Minus(*rec.Baseline))
	case reported != nil:
		return apiUsage(api.UsageEstimated, reported)
	}
	// A record from an older image has no launch time.
	since := rec.LaunchedAt
	if since.IsZero() {
		since = rec.StartedAt
	}
	if since.IsZero() {
		return nil
	}

	usage, err := recordHarness(rec).SessionUsage(rec.SessionID, since, time.Time{})
	if err != nil {
		return nil
	}

	return apiUsage(api.UsageEstimated, usage)
}

func apiUsage(source string, usage transcript.Usage) *api.Usage {
	models := make(map[string]api.ModelUsage, len(usage))
	for name, m := range usage {
		models[name] = api.ModelUsage(m)
	}

	return &api.Usage{Source: source, Models: models}
}
