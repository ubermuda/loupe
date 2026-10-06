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
	Input   json.RawMessage
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
	idle      int64
}

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
			rd.idle += ts.Sub(rd.previous).Milliseconds()
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
	for _, b := range blocks {
		switch {
		case e.Type == "assistant" && b.Type == "tool_use":
			rd.use(b, ts, parent != "")
		case e.Type == "user" && b.Type == "tool_result":
			rd.answer(b, ts, timed)
		}
	}
}

func (rd *reader) use(b block, ts time.Time, inSubagent bool) {
	if rd.byID == nil {
		rd.byID, rd.ended, rd.answered = map[string]int{}, map[int]time.Time{}, map[int]bool{}
	}
	if _, seen := rd.byID[b.ID]; !seen && b.ID != "" {
		rd.byID[b.ID] = len(rd.calls)
	}
	rd.ids = append(rd.ids, b.ID)
	rd.calls = append(rd.calls, Call{
		Seq:        len(rd.calls) + 1,
		Tool:       cut(b.Name, maxName),
		StartedAt:  ts,
		InSubagent: inSubagent,
		Input:      b.Input,
	})
}

func (rd *reader) answer(b block, ts time.Time, timed bool) {
	i, ok := rd.byID[b.ToolUseID]
	if !ok || rd.answered[i] {
		return
	}
	rd.answered[i] = true
	if timed {
		rd.ended[i] = ts
	}
	rd.calls[i].IsError = b.IsError
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
	for i := range rd.calls {
		for j := i - 1; j >= 0; j-- {
			if id := rd.calls[j].BackgroundID; id != nil && bytes.Contains(rd.calls[i].Input, []byte(*id)) {
				rd.calls[i].WaitsOn = id

				break
			}
		}
	}

	out := Output{Result: rd.result, Calls: rd.calls}
	if rd.timed > 0 {
		idle, tool := rd.idle, rd.toolTime()
		out.Timing = Timing{ToolTimeMs: &tool, IdleGapMs: &idle}
	}

	return out
}

// toolTime is the length of the union of the main session's timed calls.
func (rd *reader) toolTime() int64 {
	type span struct{ from, to time.Time }
	var spans []span
	for _, c := range rd.calls {
		if !c.InSubagent && c.DurationMs != nil {
			spans = append(spans, span{c.StartedAt, c.StartedAt.Add(time.Duration(*c.DurationMs) * time.Millisecond)})
		}
	}
	slices.SortFunc(spans, func(a, b span) int { return a.from.Compare(b.from) })

	var total time.Duration
	for i := 0; i < len(spans); {
		from, to := spans[i].from, spans[i].to
		for i++; i < len(spans) && !spans[i].from.After(to); i++ {
			if spans[i].to.After(to) {
				to = spans[i].to
			}
		}
		total += to.Sub(from)
	}

	return total.Milliseconds()
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

// cut keeps at most n bytes of s, and never splits a rune.
func cut(s string, n int) string {
	s = strings.ToValidUTF8(s, "")
	if len(s) <= n {
		return s
	}
	for n > 0 && !utf8.RuneStart(s[n]) {
		n--
	}

	return s[:n]
}
