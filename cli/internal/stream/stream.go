// Package stream holds what the bridge reads from a finished run, whatever
// harness ran it: one Call for each tool call, the timing of the run, and the
// signatures of each call. Each harness adapter reads its own files into these
// types, and uses the timing math here.
package stream

import (
	"slices"
	"strings"
	"time"
	"unicode/utf8"
)

// idleGap is the shortest pause between two timed lines that counts as idle.
const idleGap = 300 * time.Second

// MaxName bounds a tool name and a background id, in bytes.
const MaxName = 64

// MaxFullText bounds the full text a call keeps, in bytes. It is all the
// server takes.
const MaxFullText = 20000

// The kinds of a call. A shell call runs shell commands, and a subagent call
// starts a subagent.
const (
	KindShell    = "shell"
	KindSubagent = "subagent"
	KindTool     = "tool"
)

// Call is one tool call. A nil pointer is a value the run did not give.
type Call struct {
	// Seq numbers the calls from 1, in the order the run shows them.
	Seq  int
	Tool string
	Kind string
	// StartedAt is the timestamp of the line of the call, and zero when the
	// line has none.
	StartedAt  time.Time
	DurationMs *int64
	IsError    *bool
	// InSubagent says a subagent made the call.
	InSubagent bool
	// BackgroundID is the id of the background task the call started.
	BackgroundID *string
	// WaitsOn is the background id of an earlier call that this call's input
	// names.
	WaitsOn *string
	// Commands are the simple commands of a shell call's command, and FullText
	// the start of the raw input. A call keeps no more of its input.
	Commands []Command
	FullText string
}

// Timing sums the time of a run. A nil field is unknown.
type Timing struct {
	// ToolTimeMs is the length of the union of the main session's timed calls.
	ToolTimeMs *int64
	// IdleGapMs sums each pause longer than idleGap between two timed lines.
	IdleGapMs *int64
}

// Output is what one Claude Code stdout holds. Result is the first result
// line, and nil when there is none.
type Output struct {
	Result []byte
	Calls  []Call
	Timing Timing
	// PeakContextTokens is the largest input context of one main-session
	// assistant line, and nil when no line gives all three token counts.
	PeakContextTokens *int64
}

// End is how one call ended. Result is the time of its own result, and zero
// when it has none or the line has no time. Last is the time of the last line
// of the subagent the call started, and zero when there is none.
type End struct {
	Result time.Time
	Last   time.Time
}

// Clock reads the timed lines of a run, in the order the run gives them.
type Clock struct {
	lines    int
	previous time.Time
	// gaps are the pauses longer than idleGap between two timed lines.
	gaps []span
}

// span is the time from one instant to a later one.
type span struct{ from, to time.Time }

// Tick counts one timed line.
func (c *Clock) Tick(t time.Time) {
	if c.lines > 0 && t.Sub(c.previous) > idleGap {
		c.gaps = append(c.gaps, span{c.previous, t})
	}
	c.lines++
	c.previous = t
}

// Finish gives each call with a start and a result its duration, and returns
// the timing of the run. ends[i] is the end of calls[i]. A subagent call runs
// to the last line of its subagent when that comes after its result. The
// timing is unknown when no line was timed.
func (c *Clock) Finish(calls []Call, ends []End) Timing {
	for i := range calls {
		call := &calls[i]
		end := ends[i].Result
		if end.IsZero() || call.StartedAt.IsZero() {
			continue
		}
		if call.Kind == KindSubagent && ends[i].Last.After(end) {
			end = ends[i].Last
		}
		d := max(end.Sub(call.StartedAt).Milliseconds(), 0)
		call.DurationMs = &d
	}
	if c.lines == 0 {
		return Timing{}
	}
	idle, tool := c.idleTime(calls), toolTime(calls, ends)

	return Timing{ToolTimeMs: &tool, IdleGapMs: &idle}
}

// toolTime is the length of the union of the main session's timed calls,
// each from its start to its own result.
func toolTime(calls []Call, ends []End) int64 {
	var spans []span
	for i, c := range calls {
		if !c.InSubagent && c.DurationMs != nil {
			spans = append(spans, span{c.StartedAt, later(c.StartedAt, ends[i].Result)})
		}
	}
	var total time.Duration
	for _, s := range union(spans) {
		total += s.to.Sub(s.from)
	}

	return total.Milliseconds()
}

// idleTime sums the part of each gap that no timed call covers, so a long
// call with no line inside it counts as tool time and not as idle. Every
// call covers its gap to its extended end, so an async subagent's work does
// not count as idle.
func (c *Clock) idleTime(calls []Call) int64 {
	var spans []span
	for _, call := range calls {
		if call.DurationMs != nil {
			spans = append(spans, span{call.StartedAt, call.StartedAt.Add(time.Duration(*call.DurationMs) * time.Millisecond)})
		}
	}
	busy := union(spans)
	var total time.Duration
	for _, g := range c.gaps {
		total += g.to.Sub(g.from)
		for _, b := range busy {
			if from, to := later(b.from, g.from), earlier(b.to, g.to); to.After(from) {
				total -= to.Sub(from)
			}
		}
	}

	return total.Milliseconds()
}

// union sorts the spans and merges the ones that overlap.
func union(spans []span) []span {
	slices.SortFunc(spans, func(a, b span) int { return a.from.Compare(b.from) })

	var merged []span
	for _, s := range spans {
		if n := len(merged); n > 0 && !s.from.After(merged[n-1].to) {
			merged[n-1].to = later(merged[n-1].to, s.to)

			continue
		}
		merged = append(merged, s)
	}

	return merged
}

func later(a, b time.Time) time.Time {
	if a.After(b) {
		return a
	}

	return b
}

func earlier(a, b time.Time) time.Time {
	if a.Before(b) {
		return a
	}

	return b
}

// Cut keeps at most n bytes of s as valid UTF-8, and never splits a rune. It
// copies what it keeps, so the result holds no reference to a long s.
func Cut(s string, n int) string {
	if len(s) > n {
		for n > 0 && !utf8.RuneStart(s[n]) {
			n--
		}
		s = s[:n]
	}

	return strings.Clone(strings.ToValidUTF8(s, ""))
}
