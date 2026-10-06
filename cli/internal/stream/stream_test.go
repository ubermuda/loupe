package stream

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"
	"unicode/utf8"
)

// call is the expected form of one Call. A nil pointer field expects nil.
type call struct {
	tool         string
	startedAt    string
	durationMs   *int64
	isError      *bool
	inSubagent   bool
	backgroundID *string
	waitsOn      *string
}

func i64(v int64) *int64   { return &v }
func yes() *bool           { v := true; return &v }
func no() *bool            { v := false; return &v }
func str(v string) *string { return &v }

func readFixture(t *testing.T, name string) Output {
	t.Helper()
	out, err := ReadFile(filepath.Join("testdata", name))
	if err != nil {
		t.Fatalf("ReadFile(%s): %v", name, err)
	}

	return out
}

func checkCalls(t *testing.T, got []Call, want []call) {
	t.Helper()
	if len(got) != len(want) {
		t.Fatalf("got %d calls, want %d: %+v", len(got), len(want), got)
	}
	for i, w := range want {
		g := got[i]
		if g.Seq != i+1 {
			t.Errorf("call %d: seq %d", i, g.Seq)
		}
		if g.Tool != w.tool {
			t.Errorf("call %d: tool %q, want %q", i, g.Tool, w.tool)
		}
		if s := g.StartedAt.UTC().Format("2006-01-02T15:04:05.000Z"); s != w.startedAt {
			t.Errorf("call %d: startedAt %s, want %s", i, s, w.startedAt)
		}
		if !reflect.DeepEqual(g.DurationMs, w.durationMs) {
			t.Errorf("call %d: durationMs %v, want %v", i, show(g.DurationMs), show(w.durationMs))
		}
		if !reflect.DeepEqual(g.IsError, w.isError) {
			t.Errorf("call %d: isError %v, want %v", i, show(g.IsError), show(w.isError))
		}
		if g.InSubagent != w.inSubagent {
			t.Errorf("call %d: inSubagent %v", i, g.InSubagent)
		}
		if !reflect.DeepEqual(g.BackgroundID, w.backgroundID) {
			t.Errorf("call %d: backgroundId %v, want %v", i, show(g.BackgroundID), show(w.backgroundID))
		}
		if !reflect.DeepEqual(g.WaitsOn, w.waitsOn) {
			t.Errorf("call %d: waitsOn %v, want %v", i, show(g.WaitsOn), show(w.waitsOn))
		}
		if !json.Valid([]byte(g.FullText)) {
			t.Errorf("call %d: full text %q is no JSON", i, g.FullText)
		}
	}
}

func show[T any](p *T) any {
	if p == nil {
		return "nil"
	}

	return *p
}

func checkTiming(t *testing.T, got Timing, toolTime, idleGap *int64) {
	t.Helper()
	if !reflect.DeepEqual(got.ToolTimeMs, toolTime) {
		t.Errorf("toolTimeMs %v, want %v", show(got.ToolTimeMs), show(toolTime))
	}
	if !reflect.DeepEqual(got.IdleGapMs, idleGap) {
		t.Errorf("idleGapMs %v, want %v", show(got.IdleGapMs), show(idleGap))
	}
}

func resultIndex(t *testing.T, result []byte) int {
	t.Helper()
	var doc struct {
		Type        string `json:"type"`
		ResultIndex int    `json:"result_index"`
	}
	if err := json.Unmarshal(result, &doc); err != nil || doc.Type != "result" {
		t.Fatalf("result %q is no result line: %v", result, err)
	}

	return doc.ResultIndex
}

// A background Bash call, a plain Bash call and an async Agent call whose
// subagent runs a Bash call of its own, then two result lines.
func TestReadsAPlainRunWithABackgroundTaskAndAnAsyncSubagent(t *testing.T) {
	out := readFixture(t, "plain_background_subagent.jsonl")

	if resultIndex(t, out.Result) != 0 {
		t.Fatalf("the first result line wins, got %s", out.Result)
	}
	checkCalls(t, out.Calls, []call{
		{tool: "Bash", startedAt: "2026-10-06T16:32:31.797Z", durationMs: i64(157), isError: no(), backgroundID: str("bcxsdsc2t")},
		{tool: "Bash", startedAt: "2026-10-06T16:32:32.216Z", durationMs: i64(24), isError: no()},
		// The tool result comes 24 ms after the call, and the subagent's last
		// line at 38.475 ends it.
		{tool: "Agent", startedAt: "2026-10-06T16:32:35.638Z", durationMs: i64(2837), backgroundID: str("ac413ca3d11aaf481")},
		{tool: "Bash", startedAt: "2026-10-06T16:32:36.945Z", durationMs: i64(30), isError: no(), inSubagent: true},
	})
	// The async Agent call holds its 2837 ms on its row, and its own result
	// 24 ms after the call ends its share of the main session's tool time.
	checkTiming(t, out.Timing, i64(157+24+24), i64(0))
}

func TestTheFirstOfTwoResultLinesWins(t *testing.T) {
	out := readFixture(t, "background_two_results.jsonl")

	if resultIndex(t, out.Result) != 0 {
		t.Fatalf("the first result line wins, got %s", out.Result)
	}
	if !bytes.Contains(out.Result, []byte(`"structured_output"`)) {
		t.Fatalf("the result line lost its structured output: %s", out.Result)
	}
	checkCalls(t, out.Calls, []call{
		{tool: "Bash", startedAt: "2026-10-06T16:34:01.726Z", durationMs: i64(152), isError: no(), backgroundID: str("b69si7fi9")},
		{tool: "StructuredOutput", startedAt: "2026-10-06T16:34:03.317Z", durationMs: i64(2)},
		{tool: "StructuredOutput", startedAt: "2026-10-06T16:34:09.448Z", durationMs: i64(3)},
	})
	checkTiming(t, out.Timing, i64(157), i64(0))
}

func TestReadsAResumedRun(t *testing.T) {
	out := readFixture(t, "resumed.jsonl")

	if resultIndex(t, out.Result) != 0 {
		t.Fatalf("result %s", out.Result)
	}
	checkCalls(t, out.Calls, []call{
		{tool: "Bash", startedAt: "2026-10-06T16:36:24.991Z", durationMs: i64(261), isError: no()},
	})
	checkTiming(t, out.Timing, i64(261), i64(0))
}

// The old --output-format json prints one result document and no stream.
func TestReadsTheOldJSONDocumentAsItsResult(t *testing.T) {
	out := readFixture(t, "old_json.json")

	raw, err := os.ReadFile(filepath.Join("testdata", "old_json.json"))
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(bytes.TrimSpace(out.Result), bytes.TrimSpace(raw)) {
		t.Fatalf("result %s, want the whole document", out.Result)
	}
	if out.Calls != nil {
		t.Fatalf("calls %+v, want none", out.Calls)
	}
	checkTiming(t, out.Timing, nil, nil)
}

// A gap of 400 s between two timed lines counts whole. A later call that names
// a background id waits on it, and two calls in one line overlap.
func TestCountsAnIdleGapAndAWaitOnABackgroundTask(t *testing.T) {
	out := readFixture(t, "idle_gap.jsonl")

	if resultIndex(t, out.Result) != 0 {
		t.Fatalf("result %s", out.Result)
	}
	checkCalls(t, out.Calls, []call{
		{tool: "Bash", startedAt: "2026-10-06T10:00:00.000Z", durationMs: i64(500), isError: no(), backgroundID: str("bg1")},
		{tool: "TaskOutput", startedAt: "2026-10-06T10:06:40.500Z", durationMs: i64(500), isError: yes(), waitsOn: str("bg1")},
		{tool: "Read", startedAt: "2026-10-06T10:06:40.500Z", durationMs: i64(1000)},
	})
	checkTiming(t, out.Timing, i64(500+1000), i64(400_000))
}

// A killed claude can leave a torn last line, and a line can be no JSON.
func TestIgnoresAPartialLastLineAndALineThatIsNoJSON(t *testing.T) {
	out := readFixture(t, "partial_last_line.jsonl")

	if out.Result != nil {
		t.Fatalf("result %s, want none", out.Result)
	}
	checkCalls(t, out.Calls, []call{
		{tool: "Bash", startedAt: "2026-10-06T10:00:00.000Z", durationMs: i64(250), isError: no()},
	})
	checkTiming(t, out.Timing, i64(250), i64(0))
}

func TestReadsARunWithNoResultLine(t *testing.T) {
	out := readFixture(t, "no_result.jsonl")

	if out.Result != nil {
		t.Fatalf("result %s, want none", out.Result)
	}
	checkCalls(t, out.Calls, []call{
		{tool: "Edit", startedAt: "2026-10-06T10:00:00.000Z", durationMs: i64(100), isError: no()},
	})
	checkTiming(t, out.Timing, i64(100), i64(0))
}

// A call with no tool result has no duration and no error flag. One timed
// line has no gap, and no timed call takes no time.
func TestACallWithNoToolResultHasNoDuration(t *testing.T) {
	out := readFixture(t, "unanswered_call.jsonl")

	if resultIndex(t, out.Result) != 0 {
		t.Fatalf("result %s", out.Result)
	}
	checkCalls(t, out.Calls, []call{
		{tool: "Bash", startedAt: "2026-10-06T10:00:00.000Z"},
	})
	checkTiming(t, out.Timing, i64(0), i64(0))
}

func TestAnEmptyStdoutHoldsNothing(t *testing.T) {
	out, err := Read(strings.NewReader(""))
	if err != nil {
		t.Fatal(err)
	}
	if out.Result != nil || out.Calls != nil {
		t.Fatalf("out = %+v", out)
	}
	checkTiming(t, out.Timing, nil, nil)
}

// A line past the limit is skipped whole, and the lines after it still count.
func TestSkipsALineLongerThanTheLimit(t *testing.T) {
	defer func(old int) { maxLine = old }(maxLine)
	maxLine = 64

	long := `{"type":"result","result":"` + strings.Repeat("x", 200) + `"}`
	in := long + "\n" + `{"type":"result","result":"short"}` + "\n"
	out, err := Read(strings.NewReader(in))
	if err != nil {
		t.Fatal(err)
	}
	if string(out.Result) != `{"type":"result","result":"short"}` {
		t.Fatalf("result %s", out.Result)
	}
}

// A tool name is cut to 64 bytes on a rune boundary, and a background id to
// 64 bytes with its trailing punctuation stripped.
func TestCutsALongToolNameAndBackgroundID(t *testing.T) {
	name := strings.Repeat("a", 63) + "é"
	id := strings.Repeat("b", 70)
	in := `{"type":"assistant","timestamp":"2026-10-06T10:00:00.000Z","message":{"content":[{"type":"tool_use","id":"t1","name":"` + name + `","input":{}}]}}` + "\n" +
		`{"type":"user","timestamp":"2026-10-06T10:00:01.000Z","message":{"content":[{"type":"tool_result","tool_use_id":"t1","content":"running in background with ID: ` + id + `.)"}]}}` + "\n"
	out, err := Read(strings.NewReader(in))
	if err != nil {
		t.Fatal(err)
	}
	if len(out.Calls) != 1 {
		t.Fatalf("calls %+v", out.Calls)
	}
	if out.Calls[0].Tool != strings.Repeat("a", 63) {
		t.Fatalf("tool %q", out.Calls[0].Tool)
	}
	if out.Calls[0].BackgroundID == nil || *out.Calls[0].BackgroundID != id[:64] {
		t.Fatalf("background id %v", show(out.Calls[0].BackgroundID))
	}
}

// A tool result line with no timestamp gives the call no duration.
func TestAResultWithNoTimestampGivesNoDuration(t *testing.T) {
	in := `{"type":"assistant","timestamp":"2026-10-06T10:00:00.000Z","message":{"content":[{"type":"tool_use","id":"t1","name":"Bash","input":{"command":"ls"}}]}}` + "\n" +
		`{"type":"user","message":{"content":[{"type":"tool_result","tool_use_id":"t1","content":"x","is_error":true}]}}` + "\n"
	out, err := Read(strings.NewReader(in))
	if err != nil {
		t.Fatal(err)
	}
	if len(out.Calls) != 1 || out.Calls[0].DurationMs != nil || out.Calls[0].IsError == nil || !*out.Calls[0].IsError {
		t.Fatalf("calls %+v", out.Calls)
	}
	if !out.Calls[0].StartedAt.Equal(time.Date(2026, 10, 6, 10, 0, 0, 0, time.UTC)) {
		t.Fatalf("startedAt %s", out.Calls[0].StartedAt)
	}
}

// An async Agent call names its agent in tool_use_result.agentId, and a later
// call that names that id waits on it.
func TestAnAsyncAgentGivesItsAgentIDAsItsBackgroundID(t *testing.T) {
	out := readFixture(t, "plain_background_subagent.jsonl")
	if id := out.Calls[2].BackgroundID; id == nil || *id != "ac413ca3d11aaf481" {
		t.Fatalf("agent background id %v", show(id))
	}

	in := `{"type":"assistant","timestamp":"2026-10-06T10:00:00.000Z","message":{"content":[{"type":"tool_use","id":"t1","name":"Agent","input":{}}]}}` + "\n" +
		`{"type":"user","timestamp":"2026-10-06T10:00:01.000Z","message":{"content":[{"type":"tool_result","tool_use_id":"t1","content":"text"}]},"tool_use_result":{"isAsync":true,"agentId":"a1b2c3"}}` + "\n" +
		`{"type":"assistant","timestamp":"2026-10-06T10:00:02.000Z","message":{"content":[{"type":"tool_use","id":"t2","name":"SendMessage","input":{"to":"a1b2c3"}}]}}` + "\n"
	out, err := Read(strings.NewReader(in))
	if err != nil {
		t.Fatal(err)
	}
	if out.Calls[0].BackgroundID == nil || *out.Calls[0].BackgroundID != "a1b2c3" {
		t.Fatalf("background id %v", show(out.Calls[0].BackgroundID))
	}
	if out.Calls[1].WaitsOn == nil || *out.Calls[1].WaitsOn != "a1b2c3" {
		t.Fatalf("waits on %v", show(out.Calls[1].WaitsOn))
	}
}

// A gap that a call covers is tool time, not idle time. Only a gap with no
// call open counts as idle.
func TestAGapThatACallCoversIsNotIdle(t *testing.T) {
	use := func(at, id string) string {
		return `{"type":"assistant","timestamp":"` + at + `","message":{"content":[{"type":"tool_use","id":"` + id + `","name":"Bash","input":{"command":"sleep 600"}}]}}` + "\n"
	}
	result := func(at, id string) string {
		return `{"type":"user","timestamp":"` + at + `","message":{"content":[{"type":"tool_result","tool_use_id":"` + id + `","content":"text"}]}}` + "\n"
	}
	text := func(at string) string {
		return `{"type":"assistant","timestamp":"` + at + `","message":{"content":[{"type":"text","text":"text"}]}}` + "\n"
	}

	for name, tc := range map[string]struct {
		in   string
		idle int64
	}{
		"a 600 s call": {use("2026-10-06T10:00:00.000Z", "t1") + result("2026-10-06T10:10:00.000Z", "t1"), 0},
		"a 600 s gap with no call open": {
			use("2026-10-06T10:00:00.000Z", "t1") + result("2026-10-06T10:00:01.000Z", "t1") + text("2026-10-06T10:10:01.000Z"), 600_000,
		},
		"a gap a call covers in part": {
			text("2026-10-06T10:00:00.000Z") + use("2026-10-06T10:00:00.000Z", "t1") + text("2026-10-06T10:10:00.000Z") + result("2026-10-06T10:20:00.000Z", "t1") + text("2026-10-06T10:30:00.000Z"),
			600_000,
		},
	} {
		t.Run(name, func(t *testing.T) {
			out, err := Read(strings.NewReader(tc.in))
			if err != nil {
				t.Fatal(err)
			}
			if out.Timing.IdleGapMs == nil || *out.Timing.IdleGapMs != tc.idle {
				t.Fatalf("idleGapMs %v, want %d", show(out.Timing.IdleGapMs), tc.idle)
			}
		})
	}
}

// A synchronous Agent call returns after its subagent's lines, so its own
// result ends both its row and its share of the tool time.
func TestASynchronousAgentCountsWholeAsToolTime(t *testing.T) {
	in := `{"type":"assistant","timestamp":"2026-10-06T10:00:00.000Z","message":{"content":[{"type":"tool_use","id":"t1","name":"Agent","input":{}}]}}` + "\n" +
		`{"type":"assistant","timestamp":"2026-10-06T10:00:02.000Z","parent_tool_use_id":"t1","message":{"content":[{"type":"text","text":"text"}]}}` + "\n" +
		`{"type":"user","timestamp":"2026-10-06T10:00:05.000Z","message":{"content":[{"type":"tool_result","tool_use_id":"t1","content":"text"}]}}` + "\n"
	out, err := Read(strings.NewReader(in))
	if err != nil {
		t.Fatal(err)
	}
	if d := out.Calls[0].DurationMs; d == nil || *d != 5000 {
		t.Fatalf("durationMs %v", show(d))
	}
	checkTiming(t, out.Timing, i64(5000), i64(0))
}

// A call keeps a bounded part of its input: the Bash command up to
// maxCommand and the full text up to maxFullText. waitsOn still reads the
// whole input, so an id past the cut still links.
func TestACallKeepsABoundedPartOfItsInput(t *testing.T) {
	big := strings.Repeat("é", 3<<20)
	write, _ := json.Marshal(map[string]string{"file_path": "/work/run/a", "content": big + " bg1"})
	bash, _ := json.Marshal(map[string]string{"command": "git status; echo " + big})
	in := `{"type":"assistant","timestamp":"2026-10-06T10:00:00.000Z","message":{"content":[{"type":"tool_use","id":"t1","name":"Bash","input":{"command":"sleep 9","run_in_background":true}}]}}` + "\n" +
		`{"type":"user","timestamp":"2026-10-06T10:00:01.000Z","message":{"content":[{"type":"tool_result","tool_use_id":"t1","content":"Command running in background with ID: bg1."}]}}` + "\n" +
		`{"type":"assistant","timestamp":"2026-10-06T10:00:02.000Z","message":{"content":[{"type":"tool_use","id":"t2","name":"Write","input":` + string(write) + `}]}}` + "\n" +
		`{"type":"assistant","timestamp":"2026-10-06T10:00:03.000Z","message":{"content":[{"type":"tool_use","id":"t3","name":"Bash","input":` + string(bash) + `}]}}` + "\n"
	out, err := Read(strings.NewReader(in))
	if err != nil {
		t.Fatal(err)
	}

	w, b := out.Calls[1], out.Calls[2]
	if len(w.FullText) > maxFullText || !strings.HasPrefix(string(write), w.FullText) || !utf8.ValidString(w.FullText) || w.Command != "" {
		t.Fatalf("Write keeps %d bytes of full text and command %q", len(w.FullText), w.Command)
	}
	if w.WaitsOn == nil || *w.WaitsOn != "bg1" {
		t.Fatalf("Write waits on %v", show(w.WaitsOn))
	}
	if len(b.Command) > maxCommand || !strings.HasPrefix(b.Command, "git status; echo é") || !utf8.ValidString(b.Command) {
		t.Fatalf("Bash keeps %d bytes of command", len(b.Command))
	}
	if len(b.FullText) > maxFullText {
		t.Fatalf("Bash keeps %d bytes of full text", len(b.FullText))
	}
	if got := Signatures(b, nil); !reflect.DeepEqual(got, []string{"git status", "echo"}) {
		t.Fatalf("signatures %q", got)
	}
}
