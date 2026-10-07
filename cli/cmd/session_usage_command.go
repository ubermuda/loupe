package cmd

import (
	"cmp"
	"context"
	"errors"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/transcript"
)

// The answers of a usage request the bridge cannot act on.
const (
	noSessionTranscript = "The bridge holds no transcript of the session."
	usageNotRead        = "The bridge could not read the transcript: "
	usageNotSent        = "The bridge could not send the usage: "
)

// collectSessionUsage sends the usage of one interactive run: the messages of
// its window in the transcript of the session, priced as an estimate. The
// handle is the project id the command carries, which a rename never changes.
func (r *router) collectSessionUsage(c api.Command) (state, reason string) {
	if r.reportRunUsage == nil {
		return api.CommandRefused, usageNotSent + "no usage endpoint"
	}
	dir, err := transcript.ConfigDir()
	if err != nil {
		return api.CommandRefused, usageNotRead + err.Error()
	}
	path, err := transcript.Find(dir, c.SessionID)
	if errors.Is(err, transcript.ErrNotFound) {
		return api.CommandRefused, noSessionTranscript
	}
	if err != nil {
		return api.CommandRefused, usageNotRead + err.Error()
	}
	usage, err := transcript.Between(path, *c.StartedAt, windowEnd(*c.EndedAt))
	if err != nil {
		return api.CommandRefused, usageNotRead + err.Error()
	}
	report := *apiUsage(api.UsageEstimated, usage)
	if err := report.Check(); err != nil {
		return api.CommandRefused, usageNotSent + err.Error()
	}

	ctx, cancel := context.WithTimeout(r.workerContext(), cmp.Or(r.checkTimeout, readTimeout))
	defer cancel()
	if err := r.reportRunUsage(ctx, c.ProjectID, c.SessionID, c.RunID, report); err != nil {
		return api.CommandRefused, usageNotSent + err.Error()
	}
	r.log.Info("session_usage_sent", "command", c.CommandID, "session_id", c.SessionID, "run", c.RunID)

	return api.CommandDone, ""
}

// windowEnd is the end of a window the server sends. An exact time stays as it
// is. A whole second can be a time an older server cut, so the end moves to the
// end of that second and no reply of the run is lost.
func windowEnd(end time.Time) time.Time {
	if end.Nanosecond() == 0 {
		return end.Add(time.Second)
	}

	return end
}
