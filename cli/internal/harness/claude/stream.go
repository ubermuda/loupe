package claude

import (
	"bufio"
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"regexp"
	"strings"
	"time"

	"github.com/ubermuda/loupe/cli/internal/stream"
)

// maxLine is the longest line the reader decodes. It skips a longer one.
var maxLine = 64 << 20

// ReadFile reads the stdout file at path, which claude -p --verbose
// --output-format stream-json wrote.
func ReadFile(path string) (stream.Output, error) {
	f, err := os.Open(path)
	if err != nil {
		return stream.Output{}, fmt.Errorf("read worker output: %w", err)
	}
	defer f.Close()

	return Read(f)
}

// Read reads a stream line by line: one JSON object per line. It finds the
// result line and one Call for each tool_use block. The older
// --output-format json prints one result line, so the same reader takes both.
// A line that is no JSON object, such as a torn last line, counts for nothing.
func Read(r io.Reader) (stream.Output, error) {
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
	calls  []stream.Call
	// ids holds the tool_use id of each call, and ends how each call ended.
	ids  []string
	ends []stream.End
	// byID maps a tool_use id to its index in calls, and answered marks each
	// call that has a tool result.
	byID     map[string]int
	answered map[int]bool
	// lastChild is the timestamp of the last line each parent call holds.
	lastChild map[string]time.Time
	clock     stream.Clock
	peak      *int64
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
		rd.clock.Tick(ts)
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
		Usage   struct {
			Input       *int64 `json:"input_tokens"`
			CacheRead   *int64 `json:"cache_read_input_tokens"`
			CacheCreate *int64 `json:"cache_creation_input_tokens"`
		} `json:"usage"`
	}
	if json.Unmarshal(e.Message, &msg) != nil {
		return
	}
	if u := msg.Usage; e.Type == "assistant" && parent == "" && u.Input != nil && u.CacheRead != nil && u.CacheCreate != nil {
		if size := *u.Input + *u.CacheRead + *u.CacheCreate; rd.peak == nil || size > *rd.peak {
			rd.peak = &size
		}
	}
	var blocks []block
	if json.Unmarshal(msg.Content, &blocks) != nil {
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

	return stream.Cut(result.AgentID, stream.MaxName)
}

func (rd *reader) use(b block, ts time.Time, inSubagent bool) {
	if rd.byID == nil {
		rd.byID, rd.answered = map[string]int{}, map[int]bool{}
	}
	if _, seen := rd.byID[b.ID]; !seen && b.ID != "" {
		rd.byID[b.ID] = len(rd.calls)
	}
	c := stream.Call{
		Seq:        len(rd.calls) + 1,
		Tool:       stream.Cut(b.Name, stream.MaxName),
		Kind:       kindOf(b.Name),
		StartedAt:  ts,
		InSubagent: inSubagent,
		FullText:   stream.Cut(string(b.Input), stream.MaxFullText),
	}
	if c.Kind == stream.KindShell {
		var input struct {
			Command string `json:"command"`
		}
		_ = json.Unmarshal(b.Input, &input)
		c.Commands = stream.Commands(input.Command)
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
	rd.ends = append(rd.ends, stream.End{})
	rd.calls = append(rd.calls, c)
}

// kindOf gives the kind of a call from its Claude Code tool name.
func kindOf(name string) string {
	switch name {
	case "Bash":
		return stream.KindShell
	case "Agent", "Task":
		return stream.KindSubagent
	}

	return stream.KindTool
}

func (rd *reader) answer(b block, ts time.Time, timed bool, agent string) {
	i, ok := rd.byID[b.ToolUseID]
	if !ok || rd.answered[i] {
		return
	}
	rd.answered[i] = true
	if timed {
		rd.ends[i].Result = ts
	}
	rd.calls[i].IsError = b.IsError
	if agent != "" {
		rd.calls[i].BackgroundID = &agent

		return
	}
	rd.calls[i].BackgroundID = backgroundID(text(b.Content))
}

func (rd *reader) output() stream.Output {
	for i, id := range rd.ids {
		rd.ends[i].Last = rd.lastChild[id]
	}
	timing := rd.clock.Finish(rd.calls, rd.ends)

	return stream.Output{Result: rd.result, Calls: rd.calls, Timing: timing, PeakContextTokens: rd.peak}
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
	id := stream.Cut(strings.TrimRight(m[1], ".,;:!?)]}'\"`"), stream.MaxName)
	if id == "" {
		return nil
	}

	return &id
}
