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

// Processes splits the session between the processes that started at starts.
// A cost-state line belongs to the process of the last timed entry above it.
// Lines above the first start are a baseline that no process spent.
func Processes(path string, starts []time.Time) ([]Process, error) {
	if !sort.SliceIsSorted(starts, func(i, j int) bool { return starts[i].Before(starts[j]) }) {
		return nil, fmt.Errorf("%w: the processes are not in start order", ErrUnmappable)
	}

	type state struct {
		process int
		usage   Usage
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
			states = append(states, state{process, usage})

			return
		}
		if ts, err := time.Parse(time.RFC3339Nano, entry.Timestamp); err == nil {
			process = sort.Search(len(starts), func(i int) bool { return starts[i].After(ts) }) - 1
		}
	})
	if err != nil {
		return nil, err
	}
	if torn {
		return nil, fmt.Errorf("%w: a cost-state line holds no model usage", ErrUnmappable)
	}

	out := make([]Process, len(starts))
	prev := Usage{}
	for _, s := range states {
		if !s.usage.covers(prev) {
			return nil, fmt.Errorf("%w: the session totals go down", ErrUnmappable)
		}
		if s.process >= 0 {
			if out[s.process].Reported {
				return nil, fmt.Errorf("%w: process %d has two cost-state lines", ErrUnmappable, s.process+1)
			}
			out[s.process] = Process{Usage: s.usage.Minus(prev), Reported: true}
		}
		prev = s.usage
	}

	// A process with no line was killed, so its window ends where the next starts.
	for i := range out {
		if out[i].Reported {
			continue
		}
		var until time.Time
		if i+1 < len(starts) {
			until = starts[i+1]
		}
		usage, err := Between(path, starts[i], until)
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
