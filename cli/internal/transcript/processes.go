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
// A cost-state line belongs to the process of the last timed entry above it,
// and lines above the first start are a baseline. A line holds the session
// totals, so a process spent its last line minus the line above its first. A
// process that wrote more after its last line was killed, and gets an estimate.
func Processes(path string, starts []time.Time) ([]Process, error) {
	if !sort.SliceIsSorted(starts, func(i, j int) bool { return starts[i].Before(starts[j]) }) {
		return nil, fmt.Errorf("%w: the processes are not in start order", ErrUnmappable)
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
			process = sort.Search(len(starts), func(i int) bool { return starts[i].After(ts) }) - 1
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

	out := make([]Process, len(starts))
	bases := map[int]Usage{}
	prev := Usage{}
	for i, s := range states {
		if !s.usage.covers(prev) {
			return nil, fmt.Errorf("%w: the session totals go down", ErrUnmappable)
		}
		if i > 0 && s.process < states[i-1].process {
			return nil, fmt.Errorf("%w: the cost-state lines are not in start order", ErrUnmappable)
		}
		if s.process >= 0 {
			if _, ok := bases[s.process]; !ok {
				bases[s.process] = prev
			}
			out[s.process] = Process{Usage: s.usage.Minus(bases[s.process]), Reported: !s.followed}
		}
		prev = s.usage
	}

	// A killed process ends where the next one starts.
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
