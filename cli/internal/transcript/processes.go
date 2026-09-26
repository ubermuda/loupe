package transcript

import (
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"time"
)

// ErrUnmappable says the cost-state lines of a transcript do not fit the worker
// processes of the session.
var ErrUnmappable = errors.New("the transcript does not fit the worker processes")

// Process is what one worker process of a session spent. Reported usage comes
// from claude's own totals, and the rest is an estimate.
type Process struct {
	Usage    Usage
	Reported bool
}

// Window is when a worker process ran. A zero End means the bridge log holds
// no end, and the window then runs to the next start.
type Window struct {
	Start, End time.Time
}

// Processes splits the session between the processes that ran in windows.
// A cost-state line belongs to the process of the last timed entry above it,
// and a line outside every window belongs to none. A line holds the session
// totals, so a process spent its last line minus the line above its first. A
// process that wrote more after its last line was killed, and gets an estimate.
func Processes(path string, windows []Window) ([]Process, error) {
	limits := make([]time.Time, len(windows))
	for i, w := range windows {
		limits[i] = w.End
		if i+1 < len(windows) {
			next := windows[i+1].Start
			if !next.After(w.Start) || (!w.End.IsZero() && w.End.After(next)) {
				return nil, fmt.Errorf("%w: the processes overlap or are not in start order", ErrUnmappable)
			}
			if w.End.IsZero() {
				limits[i] = next
			}
		}
	}
	processOf := func(ts time.Time) int {
		i := sort.Search(len(windows), func(i int) bool { return windows[i].Start.After(ts) }) - 1
		if i < 0 || (!limits[i].IsZero() && !ts.Before(limits[i])) {
			return -1
		}

		return i
	}

	type state struct {
		process  int
		usage    Usage
		followed bool
	}
	var states []state
	process, torn := -1, false
	err := eachLine(path, "", func(line []byte) {
		var entry struct {
			Type       string          `json:"type"`
			Timestamp  string          `json:"timestamp"`
			ModelUsage json.RawMessage `json:"modelUsage"`
		}
		if json.Unmarshal(line, &entry) != nil {
			return
		}
		if entry.Type == "cost-state" {
			usage, err := DecodeModelUsage(entry.ModelUsage)
			torn = torn || err != nil
			states = append(states, state{process: process, usage: usage})

			return
		}
		if ts, err := time.Parse(time.RFC3339Nano, entry.Timestamp); err == nil {
			process = processOf(ts)
			if n := len(states); n > 0 && states[n-1].process == process {
				states[n-1].followed = true
			}
		}
	})
	if err != nil {
		return nil, err
	}
	if torn {
		return nil, fmt.Errorf("%w: a cost-state line holds no model usage", ErrUnmappable)
	}

	out := make([]Process, len(windows))
	bases := map[int]Usage{}
	prev, last := Usage{}, -1
	for _, s := range states {
		if !s.usage.covers(prev) {
			return nil, fmt.Errorf("%w: the session totals go down", ErrUnmappable)
		}
		if s.process >= 0 {
			if s.process < last {
				return nil, fmt.Errorf("%w: the cost-state lines are not in start order", ErrUnmappable)
			}
			last = s.process
			if _, ok := bases[s.process]; !ok {
				bases[s.process] = prev
			}
			out[s.process] = Process{Usage: s.usage.Minus(bases[s.process]), Reported: !s.followed}
		}
		prev = s.usage
	}

	for i := range out {
		if out[i].Reported {
			continue
		}
		usage, err := Between(path, windows[i].Start, limits[i])
		if err != nil {
			return nil, err
		}
		out[i] = Process{Usage: usage}
	}

	return out, nil
}

// covers reports whether no count of base is above the same count of u.
func (u Usage) covers(base Usage) bool {
	for name, b := range base {
		m := u[name]
		if m.InputTokens < b.InputTokens || m.OutputTokens < b.OutputTokens ||
			m.CacheReadTokens < b.CacheReadTokens || m.CacheWriteTokens < b.CacheWriteTokens {
			return false
		}
	}

	return true
}
