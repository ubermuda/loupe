// Package stream reads what claude -p --verbose --output-format stream-json
// prints: one JSON object per line. It finds the result line and one Call for
// each tool call. The older --output-format json prints one result line, so
// the same reader takes both.
package stream

import (
	"bufio"
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"regexp"
	"slices"
	"strings"
	"time"
	"unicode/utf8"
)

// maxLine is the longest line the reader decodes. It skips a longer one.
var maxLine = 64 << 20

// idleGap is the shortest pause between two timed lines that counts as idle.
const idleGap = 300 * time.Second

// maxName bounds a tool name and a background id, in bytes.
const maxName = 64

// maxCommand bounds the Bash command a call keeps for its signatures, and
// maxFullText the full text it keeps, which is all the server takes. Both are
// in bytes.
const (
	maxCommand  = 64 << 10
	maxFullText = 20000
)

// Call is one tool_use block. A nil pointer is a value the stream did not give.
type Call struct {
	// Seq numbers the calls from 1, in the order the stream shows them.
	Seq  int
	Tool string
	// StartedAt is the timestamp of the assistant line, and zero when the
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
	// Command is the start of a Bash call's command, and FullText the start
	// of the raw input JSON. A call keeps no more of its input.
	Command  string
	FullText string
}

// Timing sums the time of a run. A nil field is unknown.
type Timing struct {
	// ToolTimeMs is the length of the union of the main session's timed calls.
	ToolTimeMs *int64
	// IdleGapMs sums each pause longer than idleGap between two timed lines.
	IdleGapMs *int64
}

// Output is what one stdout holds. Result is the first result line, and nil
// when there is none.
type Output struct {
	Result []byte
	Calls  []Call
	Timing Timing
}

// ReadFile reads the stdout file at path.
func ReadFile(path string) (Output, error) {
	f, err := os.Open(path)
	if err != nil {
		return Output{}, fmt.Errorf("read worker output: %w", err)
	}
	defer f.Close()

	return Read(f)
}

// Read reads a stream line by line. A line that is no JSON object, such as a
// torn last line, counts for nothing.
func Read(r io.Reader) (Output, error) {
	var rd reader
	br := bufio.NewReaderSize(r, 64<<10)
	var buf []byte
	skipping := false
	for {
		chunk, err := br.ReadSlice('\n')
		if !skipping {
			if len(buf)+len(chunk) > maxLine {
				skipping, buf = true, buf[:0]
			} else {
				buf = append(buf, chunk...)
			}
		}
		if errors.Is(err, bufio.ErrBufferFull) {
			continue
		}
		if !skipping && len(buf) > 0 {
			rd.line(buf)
		}
		buf, skipping = buf[:0], false
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return rd.output(), fmt.Errorf("read worker output: %w", err)
		}
	}

	return rd.output(), nil
}

type entry struct {
	Type            string          `json:"type"`
	Timestamp       string          `json:"timestamp"`
	ParentToolUseID *string         `json:"parent_tool_use_id"`
	Message         json.RawMessage `json:"message"`
	ToolUseResult   json.RawMessage `json:"tool_use_result"`
}

type block struct {
	Type      string          `json:"type"`
	ID        string          `json:"id"`
	Name      string          `json:"name"`
	Input     json.RawMessage `json:"input"`
	ToolUseID string          `json:"tool_use_id"`
	IsError   *bool           `json:"is_error"`
	Content   json.RawMessage `json:"content"`
}

// reader holds what the lines read so far told.
type reader struct {
	result []byte
	calls  []Call
	// ids holds the tool_use id of each call.
	ids []string
	// byID maps a tool_use id to its index in calls.
	byID map[string]int
	// ended holds the timestamp of each call's tool result, and answered
	// marks each call that has one.
	ended    map[int]time.Time
	answered map[int]bool
	// lastChild is the timestamp of the last line each parent call holds.
	lastChild map[string]time.Time
	timed     int
	previous  time.Time
	// gaps are the pauses longer than idleGap between two timed lines.
	gaps []span
}

// span is the time from one instant to a later one.
type span struct{ from, to time.Time }

func (rd *reader) line(raw []byte) {
	raw = bytes.TrimSpace(raw)
	var e entry
	if len(raw) == 0 || raw[0] != '{' || json.Unmarshal(raw, &e) != nil {
		return
	}
	if e.Type == "result" {
		if rd.result == nil {
			rd.result = bytes.Clone(raw)
		}

		return
	}
	if e.Type != "assistant" && e.Type != "user" {
		return
	}

	ts, timed := stamp(e.Timestamp)
	if timed {
		if rd.timed > 0 && ts.Sub(rd.previous) > idleGap {
			rd.gaps = append(rd.gaps, span{rd.previous, ts})
		}
		rd.timed++
		rd.previous = ts
	}
	parent := ""
	if e.ParentToolUseID != nil {
		parent = *e.ParentToolUseID
	}
	if parent != "" && timed {
		if rd.lastChild == nil {
			rd.lastChild = map[string]time.Time{}
		}
		if ts.After(rd.lastChild[parent]) {
			rd.lastChild[parent] = ts
		}
	}

	var msg struct {
		Content json.RawMessage `json:"content"`
	}
	var blocks []block
	if json.Unmarshal(e.Message, &msg) != nil || json.Unmarshal(msg.Content, &blocks) != nil {
		return
	}
	agent := asyncAgent(e.ToolUseResult, blocks)
	for _, b := range blocks {
		switch {
		case e.Type == "assistant" && b.Type == "tool_use":
			rd.use(b, ts, parent != "")
		case e.Type == "user" && b.Type == "tool_result":
			rd.answer(b, ts, timed, agent)
		}
	}
}

// asyncAgent is the id of the async agent a user line launched, and "" when
// it launched none. The line must hold one tool result, so the id has one owner.
func asyncAgent(raw json.RawMessage, blocks []block) string {
	var result struct {
		IsAsync bool   `json:"isAsync"`
		AgentID string `json:"agentId"`
	}
	results := 0
	for _, b := range blocks {
		if b.Type == "tool_result" {
			results++
		}
	}
	if results != 1 || json.Unmarshal(raw, &result) != nil || !result.IsAsync {
		return ""
	}

	return cut(result.AgentID, maxName)
}

func (rd *reader) use(b block, ts time.Time, inSubagent bool) {
	if rd.byID == nil {
		rd.byID, rd.ended, rd.answered = map[string]int{}, map[int]time.Time{}, map[int]bool{}
	}
	if _, seen := rd.byID[b.ID]; !seen && b.ID != "" {
		rd.byID[b.ID] = len(rd.calls)
	}
	c := Call{
		Seq:        len(rd.calls) + 1,
		Tool:       cut(b.Name, maxName),
		StartedAt:  ts,
		InSubagent: inSubagent,
		FullText:   cut(string(b.Input), maxFullText),
	}
	if c.Tool == "Bash" {
		var input struct {
			Command string `json:"command"`
		}
		_ = json.Unmarshal(b.Input, &input)
		c.Command = cut(input.Command, maxCommand)
	}
	// A call can name only an id whose result it has seen, so the ids known
	// now are all it can wait on.
	for j := len(rd.calls) - 1; j >= 0; j-- {
		if id := rd.calls[j].BackgroundID; id != nil && bytes.Contains(b.Input, []byte(*id)) {
			c.WaitsOn = id

			break
		}
	}
	rd.ids = append(rd.ids, b.ID)
	rd.calls = append(rd.calls, c)
}

func (rd *reader) answer(b block, ts time.Time, timed bool, agent string) {
	i, ok := rd.byID[b.ToolUseID]
	if !ok || rd.answered[i] {
		return
	}
	rd.answered[i] = true
	if timed {
		rd.ended[i] = ts
	}
	rd.calls[i].IsError = b.IsError
	if agent != "" {
		rd.calls[i].BackgroundID = &agent

		return
	}
	rd.calls[i].BackgroundID = backgroundID(text(b.Content))
}

func (rd *reader) output() Output {
	for i := range rd.calls {
		c := &rd.calls[i]
		end, ok := rd.ended[i]
		if !ok || c.StartedAt.IsZero() {
			continue
		}
		if c.Tool == "Agent" || c.Tool == "Task" {
			if last := rd.lastChild[rd.ids[i]]; last.After(end) {
				end = last
			}
		}
		d := max(end.Sub(c.StartedAt).Milliseconds(), 0)
		c.DurationMs = &d
	}

	out := Output{Result: rd.result, Calls: rd.calls}
	if rd.timed > 0 {
		idle, tool := rd.idleTime(), rd.toolTime()
		out.Timing = Timing{ToolTimeMs: &tool, IdleGapMs: &idle}
	}

	return out
}

// toolTime is the length of the union of the main session's timed calls,
// each from its start to its own tool result.
func (rd *reader) toolTime() int64 {
	var total time.Duration
	for _, s := range rd.busy(false) {
		total += s.to.Sub(s.from)
	}

	return total.Milliseconds()
}

// idleTime sums the part of each gap that no timed call covers, so a long
// call with no line inside it counts as tool time and not as idle.
func (rd *reader) idleTime() int64 {
	busy := rd.busy(true)
	var total time.Duration
	for _, g := range rd.gaps {
		total += g.to.Sub(g.from)
		for _, b := range busy {
			if from, to := later(b.from, g.from), earlier(b.to, g.to); to.After(from) {
				total -= to.Sub(from)
			}
		}
	}

	return total.Milliseconds()
}

// busy is the union of the timed calls, sorted and with no overlap. With
// coverage, it holds every call to its extended end. Without, it holds the
// main session's calls to their own results, so an async agent's work does
// not count as the main session's.
func (rd *reader) busy(coverage bool) []span {
	var spans []span
	for i, c := range rd.calls {
		switch {
		case coverage && c.DurationMs != nil:
			spans = append(spans, span{c.StartedAt, c.StartedAt.Add(time.Duration(*c.DurationMs) * time.Millisecond)})
		case !coverage && !c.InSubagent && c.DurationMs != nil:
			spans = append(spans, span{c.StartedAt, later(c.StartedAt, rd.ended[i])})
		}
	}
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

func stamp(s string) (time.Time, bool) {
	if s == "" {
		return time.Time{}, false
	}
	t, err := time.Parse(time.RFC3339Nano, s)

	return t, err == nil
}

// text is the text of a tool result, which is a string or a list of blocks.
func text(content json.RawMessage) string {
	var s string
	if json.Unmarshal(content, &s) == nil {
		return s
	}
	var parts []struct {
		Text string `json:"text"`
	}
	_ = json.Unmarshal(content, &parts)
	texts := make([]string, 0, len(parts))
	for _, p := range parts {
		texts = append(texts, p.Text)
	}

	return strings.Join(texts, "\n")
}

var backgroundPattern = regexp.MustCompile(`running in background with ID: (\S+)`)

func backgroundID(s string) *string {
	m := backgroundPattern.FindStringSubmatch(s)
	if m == nil {
		return nil
	}
	id := cut(strings.TrimRight(m[1], ".,;:!?)]}'\"`"), maxName)
	if id == "" {
		return nil
	}

	return &id
}

// cut keeps at most n bytes of s as valid UTF-8, and never splits a rune. It
// copies what it keeps, so the result holds no reference to a long s.
func cut(s string, n int) string {
	if len(s) > n {
		for n > 0 && !utf8.RuneStart(s[n]) {
			n--
		}
		s = s[:n]
	}

	return strings.Clone(strings.ToValidUTF8(s, ""))
}
