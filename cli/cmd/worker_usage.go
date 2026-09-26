package cmd

import (
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/transcript"
)

// sessionBaseline is the usage of the session on the last cost-state line of
// its transcript, and nil when the bridge cannot read it. A session with no
// such line has a zero baseline.
func sessionBaseline(sessionID string) *transcript.Usage {
	dir, err := transcript.ConfigDir()
	if err != nil {
		return nil
	}
	path, err := transcript.Find(dir, sessionID)
	if err != nil {
		return nil
	}
	usage, err := transcript.LastCostState(path)
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
	case rec.StartedAt.IsZero():
		return nil
	}

	dir, err := transcript.ConfigDir()
	if err != nil {
		return nil
	}
	path, err := transcript.Find(dir, rec.SessionID)
	if err != nil {
		return nil
	}
	usage, err := transcript.Since(path, rec.StartedAt)
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
