package codex

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"reflect"
	"strconv"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/harness"
	"github.com/ubermuda/loupe/cli/internal/stream"
	"github.com/ubermuda/loupe/cli/internal/transcript"
)

// probeThread is the main thread of a real Codex 0.155.1 run. It ran echo hi,
// spawned one subagent that ran pwd, and waited for it.
const probeThread = "01a11b70-beff-7193-b00b-19bc0ced154b"

// probeHarness reads the sessions of the probe, with the run id mapped to its
// main thread.
func probeHarness(t *testing.T) Harness {
	t.Helper()
	h := New(filepath.Join("testdata", "probe"), "", filepath.Join(t.TempDir(), "threads"))
	if err := h.remember(runID, probeThread); err != nil {
		t.Fatal(err)
	}

	return h
}

func at(t *testing.T, s string) time.Time {
	t.Helper()
	ts, err := time.Parse(time.RFC3339Nano, s)
	if err != nil {
		t.Fatal(err)
	}

	return ts
}

func ms(v int64) *int64 { return &v }

func flag(v bool) *bool { return &v }

// probeUsage is the usage of the main thread plus the subagent's thread. The
// main thread's totals leave the subagent out.
func probeUsage() transcript.Usage {
	input, cached, output := int64(74759+38729), int64(57984+27008), int64(110+46)

	return transcript.Usage{"gpt-6-astra": {
		InputTokens:     input - cached,
		CacheReadTokens: cached,
		OutputTokens:    output,
		CostUSD:         transcript.Cost("gpt-6-astra", input-cached, output, cached, 0, 0),
	}}
}

func TestReadRunReadsTheCallsOfTheSessionAndItsSubagent(t *testing.T) {
	h := probeHarness(t)
	got := h.ReadRun(runDir(t, filepath.Join("probe", "stdout.jsonl"), ""), harness.RunInfo{SessionID: runID})

	if !got.CallsRead {
		t.Fatalf("the calls were not read: %+v", got)
	}
	type call struct {
		tool       string
		kind       string
		startedAt  time.Time
		durationMs *int64
		isError    *bool
		inSubagent bool
		signatures []string
	}
	want := []call{
		{"exec", stream.KindShell, at(t, "2026-10-08T12:15:42.955Z"), ms(245), flag(false), false, []string{"echo"}},
		// The subagent's file ends at 12:15:54.480, long after spawn_agent
		// returns, so the call runs to it.
		{"spawn_agent", stream.KindSubagent, at(t, "2026-10-08T12:15:46.515Z"), ms(7965), nil, false, []string{"spawn_agent"}},
		{"wait_agent", stream.KindTool, at(t, "2026-10-08T12:15:49.628Z"), ms(4860), nil, false, []string{"wait_agent"}},
		{"exec", stream.KindShell, at(t, "2026-10-08T12:15:52.398Z"), ms(89), flag(false), true, []string{"pwd"}},
	}
	if len(got.Calls) != len(want) {
		t.Fatalf("got %d calls, want %d: %+v", len(got.Calls), len(want), got.Calls)
	}
	for i, w := range want {
		c := got.Calls[i]
		g := call{c.Tool, c.Kind, c.StartedAt, c.DurationMs, c.IsError, c.InSubagent, stream.Signatures(c, nil)}
		if c.Seq != i+1 || !g.startedAt.Equal(w.startedAt) {
			t.Errorf("call %d: seq %d, startedAt %s", i, c.Seq, c.StartedAt)
		}
		g.startedAt = w.startedAt
		if !reflect.DeepEqual(g, w) {
			t.Errorf("call %d = %+v, want %+v", i, g, w)
		}
	}
	// The main thread's calls end at their own outputs: 245 + 134 + 4860 ms.
	if tt := got.Timing; tt.ToolTimeMs == nil || *tt.ToolTimeMs != 5239 || tt.IdleGapMs == nil || *tt.IdleGapMs != 0 {
		t.Fatalf("timing = %v, %v", tt.ToolTimeMs, tt.IdleGapMs)
	}
	if got.PeakContextTokens == nil || *got.PeakContextTokens != 18817 {
		t.Fatalf("peak = %v", got.PeakContextTokens)
	}
	if !reflect.DeepEqual(got.Usage, probeUsage()) {
		t.Fatalf("usage = %+v, want %+v", got.Usage, probeUsage())
	}
}

// The usage that a resume subtracts counts the subagent too.
func TestTheSessionUsageCountsTheSubagent(t *testing.T) {
	h := probeHarness(t)
	total, err := h.SessionTotal(runID)
	if err != nil || !reflect.DeepEqual(total, probeUsage()) {
		t.Fatalf("SessionTotal = %+v, %v", total, err)
	}

	// From 12:15:50, the main thread adds its last two counts and the
	// subagent its two.
	usage, err := h.SessionUsage(runID, at(t, "2026-10-08T12:15:50Z"), time.Time{})
	if err != nil {
		t.Fatal(err)
	}
	input, cached, output := int64(74759-37220+38729), int64(57984-21120+27008), int64(110-84+46)
	if m := usage["gpt-6-astra"]; m.InputTokens != input-cached || m.CacheReadTokens != cached || m.OutputTokens != output {
		t.Fatalf("usage = %+v", usage)
	}
}

// A resume appends to the session file, so a run reads only the lines from
// its own start.
func TestReadRunReadsTheCallsFromTheStartOfTheRun(t *testing.T) {
	h := probeHarness(t)
	got := h.ReadRun(runDir(t, filepath.Join("probe", "stdout.jsonl"), ""), harness.RunInfo{SessionID: runID, Since: at(t, "2026-10-08T12:15:49Z")})

	var tools []string
	for _, c := range got.Calls {
		tools = append(tools, c.Tool)
	}
	if !reflect.DeepEqual(tools, []string{"wait_agent"}) || got.Calls[0].Seq != 1 {
		t.Fatalf("calls = %+v", got.Calls)
	}
	if got.PeakContextTokens == nil || *got.PeakContextTokens != 18817 {
		t.Fatalf("peak = %v", got.PeakContextTokens)
	}
}

// A run whose session file is missing or holds nothing that parses has
// unknown metrics, not zero ones.
func TestARunWithNoReadableSessionHasUnknownMetrics(t *testing.T) {
	for name, content := range map[string]*string{
		"missing":    nil,
		"unreadable": ptr("not json\n{\"timestamp\":\n"),
	} {
		t.Run(name, func(t *testing.T) {
			home := t.TempDir()
			h := New(home, "", filepath.Join(t.TempDir(), "threads"))
			if err := h.remember(runID, probeThread); err != nil {
				t.Fatal(err)
			}
			if content != nil {
				dir := filepath.Join(home, "sessions", "2026", "10", "08")
				if err := os.MkdirAll(dir, 0o700); err != nil {
					t.Fatal(err)
				}
				if err := os.WriteFile(filepath.Join(dir, "rollout-2026-10-08T08-15-36-"+probeThread+".jsonl"), []byte(*content), 0o600); err != nil {
					t.Fatal(err)
				}
			}

			got := h.ReadRun(runDir(t, filepath.Join("probe", "stdout.jsonl"), ""), harness.RunInfo{SessionID: runID})

			if got.CallsRead || got.Calls != nil || got.Timing.ToolTimeMs != nil || got.Timing.IdleGapMs != nil || got.PeakContextTokens != nil {
				t.Fatalf("output = %+v", got)
			}
			// The usage falls back to the total on stdout.
			if got.Usage["codex"].OutputTokens != 110 {
				t.Fatalf("usage = %+v", got.Usage)
			}
		})
	}
}

func ptr[T any](v T) *T { return &v }

// writeSession writes a session file of thread into the sessions folder of
// home.
func writeSession(t *testing.T, home, thread string, lines ...string) {
	t.Helper()
	dir := filepath.Join(home, "sessions", "2026", "10", "08")
	if err := os.MkdirAll(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	body := ""
	for _, l := range lines {
		body += l + "\n"
	}
	if err := os.WriteFile(filepath.Join(dir, "rollout-2026-10-08T10-00-00-"+thread+".jsonl"), []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
}

func metaLine(id string) string {
	return `{"timestamp":"2026-10-08T10:00:00.000Z","type":"session_meta","payload":{"id":"` + id + `"}}`
}

func call(at, typ, id, name, field, input string) string {
	in, _ := json.Marshal(input)

	return `{"timestamp":"` + at + `","type":"response_item","payload":{"type":"` + typ + `","call_id":"` + id + `","name":"` + name + `","` + field + `":` + string(in) + `}}`
}

func output(at, typ, id, text string) string {
	out, _ := json.Marshal(text)

	return `{"timestamp":"` + at + `","type":"response_item","payload":{"type":"` + typ + `","call_id":"` + id + `","output":` + string(out) + `}}`
}

// started is the line that says thread started the subagent agent. id is the
// id of the item, which Codex sets to the call_id of the spawn_agent call.
func started(at, thread, id, path, agent string) string {
	return `{"timestamp":"` + at + `","type":"event_msg","payload":{"type":"item_completed","thread_id":"` + thread + `","item":{"type":"SubAgentActivity","id":"` + id + `","kind":"started","agent_thread_id":"` + agent + `","agent_path":"` + path + `"}}}`
}

func TestEachCommandGoesToTheShellCallOpenWhenItStarted(t *testing.T) {
	const main, child = "aaaaaaaa-0000-0000-0000-000000000001", "aaaaaaaa-0000-0000-0000-000000000002"
	home := t.TempDir()
	command := func(at, thread string, startedMs int64, script string, exit int) string {
		return `{"timestamp":"` + at + `","type":"event_msg","payload":{"type":"item_completed","thread_id":"` + thread + `","item":{"type":"CommandExecution","command":["/bin/zsh","-lc",` + strconv.Quote(script) + `],"parsed_cmd":[{"cmd":"x"}],"exit_code":` + strconv.Itoa(exit) + `},"started_at_ms":` + strconv.FormatInt(startedMs, 10) + `}}`
	}
	milli := func(s string) int64 { return at(t, s).UnixMilli() }

	writeSession(t, home, main,
		metaLine(main),
		// The script yields before git diff ends, and the command still
		// belongs to it.
		call("2026-10-08T10:00:01.000Z", "custom_tool_call", "c1", "exec", "input", "tools.exec_command(...)"),
		output("2026-10-08T10:00:02.000Z", "custom_tool_call_output", "c1", "Script running with cell ID 1"),
		command("2026-10-08T10:00:03.000Z", main, milli("2026-10-08T10:00:01.500Z"), "git diff | head", 1),
		// A command of another thread, such as a review, goes nowhere.
		call("2026-10-08T10:00:04.000Z", "custom_tool_call", "c2", "exec", "input", "tools.exec_command(...)"),
		command("2026-10-08T10:00:04.500Z", "bbbbbbbb-0000-0000-0000-000000000009", milli("2026-10-08T10:00:04.100Z"), "rm -rf x", 0),
		output("2026-10-08T10:00:05.000Z", "custom_tool_call_output", "c2", "Script failed\nError: x"),
		// The older exec_command tool names its command in its arguments.
		call("2026-10-08T10:00:06.000Z", "function_call", "c3", "exec_command", "arguments", `{"cmd":"just phpunit tests"}`),
		output("2026-10-08T10:00:07.000Z", "function_call_output", "c3", "Chunk ID: 1\nWall time: 1 seconds\nProcess exited with code 2\nOutput:\nProcess exited with code 0\n"),
		call("2026-10-08T10:00:08.000Z", "function_call", "c4", "spawn_agent", "arguments", `{"task_name":"x"}`),
		started("2026-10-08T10:00:08.100Z", main, "", "/root/x", child),
		output("2026-10-08T10:00:08.200Z", "function_call_output", "c4", `{"task_name":"/root/x"}`),
	)
	// The subagent names its parent as a subagent it started, and the reader
	// still ends.
	writeSession(t, home, child,
		metaLine(child),
		started("2026-10-08T10:00:09.000Z", child, "", "/root", main),
		call("2026-10-08T10:00:09.500Z", "function_call", "d1", "read_file", "arguments", `{}`),
		`{"timestamp":"2026-10-08T10:00:20.000Z","type":"event_msg","payload":{"type":"task_complete"}}`,
	)
	h := New(home, "", filepath.Join(t.TempDir(), "threads"))
	if err := h.remember(runID, main); err != nil {
		t.Fatal(err)
	}

	got := h.ReadRun(t.TempDir(), harness.RunInfo{SessionID: runID})

	type row struct {
		tool       string
		kind       string
		isError    *bool
		signatures []string
		durationMs *int64
		inSubagent bool
	}
	var rows []row
	for _, c := range got.Calls {
		rows = append(rows, row{c.Tool, c.Kind, c.IsError, stream.Signatures(c, nil), c.DurationMs, c.InSubagent})
	}
	want := []row{
		{"exec", stream.KindShell, flag(true), []string{"git diff", "head"}, ms(1000), false},
		{"exec", stream.KindTool, flag(true), []string{"exec"}, ms(1000), false},
		{"exec_command", stream.KindShell, flag(true), []string{"just phpunit"}, ms(1000), false},
		{"spawn_agent", stream.KindSubagent, nil, []string{"spawn_agent"}, ms(12000), false},
		{"read_file", stream.KindTool, nil, []string{"read_file"}, nil, true},
	}
	if !reflect.DeepEqual(rows, want) {
		t.Fatalf("calls:\n%+v\nwant\n%+v", rows, want)
	}
}

// A subagent whose session file is missing or holds nothing that parses
// leaves the calls, the timing and the peak unknown. The usage falls back to
// the total on stdout, and a resume reads no baseline.
func TestARunWithAnUnreadableSubagentHasUnknownMetrics(t *testing.T) {
	const child = "01a11b70-e76b-78f2-a305-b8269ef1b542"
	for name, content := range map[string]*string{
		"missing":    nil,
		"unreadable": ptr("not json\n{\"timestamp\":\n"),
	} {
		t.Run(name, func(t *testing.T) {
			home := t.TempDir()
			dir := filepath.Join(home, "sessions", "2026", "10", "08")
			if err := os.MkdirAll(dir, 0o700); err != nil {
				t.Fatal(err)
			}
			main := "rollout-2026-10-08T08-15-36-" + probeThread + ".jsonl"
			copyFile(t, filepath.Join("testdata", "probe", "sessions", "2026", "10", "08", main), filepath.Join(dir, main))
			if content != nil {
				if err := os.WriteFile(filepath.Join(dir, "rollout-2026-10-08T08-15-46-"+child+".jsonl"), []byte(*content), 0o600); err != nil {
					t.Fatal(err)
				}
			}
			h := New(home, "", filepath.Join(t.TempDir(), "threads"))
			if err := h.remember(runID, probeThread); err != nil {
				t.Fatal(err)
			}

			got := h.ReadRun(runDir(t, filepath.Join("probe", "stdout.jsonl"), ""), harness.RunInfo{SessionID: runID})

			if got.CallsRead || got.Calls != nil || got.Timing.ToolTimeMs != nil || got.Timing.IdleGapMs != nil || got.PeakContextTokens != nil {
				t.Fatalf("output = %+v", got)
			}
			if m, ok := got.Usage["gpt-6-astra"]; !ok || len(got.Usage) != 1 || m.OutputTokens != 110 {
				t.Fatalf("usage = %+v", got.Usage)
			}
			if _, err := h.SessionTotal(runID); err == nil {
				t.Fatal("SessionTotal read a baseline with no subagent")
			}
			if _, err := h.SessionUsage(runID, time.Time{}, time.Time{}); err == nil || errors.Is(err, transcript.ErrNotFound) {
				t.Fatalf("SessionUsage err = %v, want a read error", err)
			}
		})
	}
}

// A resumed run reads only what came after its start, so a missing subagent
// of an earlier run leaves its metrics and its usage window readable.
func TestAMissingSubagentOfAnEarlierRunLeavesTheRunReadable(t *testing.T) {
	home := t.TempDir()
	dir := filepath.Join(home, "sessions", "2026", "10", "08")
	if err := os.MkdirAll(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	main := "rollout-2026-10-08T08-15-36-" + probeThread + ".jsonl"
	copyFile(t, filepath.Join("testdata", "probe", "sessions", "2026", "10", "08", main), filepath.Join(dir, main))
	h := New(home, "", filepath.Join(t.TempDir(), "threads"))
	if err := h.remember(runID, probeThread); err != nil {
		t.Fatal(err)
	}
	since := time.Date(2026, 10, 8, 12, 15, 50, 0, time.UTC)

	got := h.ReadRun(runDir(t, filepath.Join("probe", "stdout.jsonl"), ""), harness.RunInfo{SessionID: runID, Since: since})

	if !got.CallsRead {
		t.Fatalf("output = %+v", got)
	}
	if _, err := h.SessionUsage(runID, since, time.Time{}); err != nil {
		t.Fatalf("SessionUsage err = %v", err)
	}
	if _, err := h.SessionTotal(runID); err == nil {
		t.Fatal("SessionTotal read a baseline with no subagent")
	}
}

// Codex sets the id of the item that starts a subagent to the call_id of its
// spawn_agent call, so each call takes its own subagent whatever the order of
// the lines. A call with no such id falls back to the agent path, and takes a
// subagent no other call took.
func TestEachSpawnTakesTheSubagentItStarted(t *testing.T) {
	const main, first, second = "cccccccc-0000-0000-0000-000000000001", "cccccccc-0000-0000-0000-000000000002", "cccccccc-0000-0000-0000-000000000003"
	home := t.TempDir()
	writeSession(t, home, main,
		metaLine(main),
		call("2026-10-08T10:00:01.000Z", "function_call", "s1", "spawn_agent", "arguments", `{"task_name":"x"}`),
		call("2026-10-08T10:00:01.100Z", "function_call", "s2", "spawn_agent", "arguments", `{"task_name":"x"}`),
		started("2026-10-08T10:00:01.200Z", main, "s2", "/root/x", second),
		started("2026-10-08T10:00:01.300Z", main, "s1", "/root/x", first),
		output("2026-10-08T10:00:01.400Z", "function_call_output", "s1", `{"task_name":"/root/x"}`),
		output("2026-10-08T10:00:01.500Z", "function_call_output", "s2", `{"task_name":"/root/x"}`),
		// An older line with no id names the first subagent again, and the
		// third call takes nothing.
		call("2026-10-08T10:00:02.000Z", "function_call", "s3", "spawn_agent", "arguments", `{"task_name":"x"}`),
		started("2026-10-08T10:00:02.100Z", main, "", "/root/x", first),
		output("2026-10-08T10:00:02.200Z", "function_call_output", "s3", `{"task_name":"/root/x"}`),
	)
	writeSession(t, home, first,
		metaLine(first),
		call("2026-10-08T10:00:03.000Z", "function_call", "f1", "first_tool", "arguments", `{}`),
		`{"timestamp":"2026-10-08T10:00:10.000Z","type":"event_msg","payload":{"type":"task_complete"}}`,
	)
	writeSession(t, home, second,
		metaLine(second),
		call("2026-10-08T10:00:04.000Z", "function_call", "g1", "second_tool", "arguments", `{}`),
		`{"timestamp":"2026-10-08T10:00:20.000Z","type":"event_msg","payload":{"type":"task_complete"}}`,
	)
	h := New(home, "", filepath.Join(t.TempDir(), "threads"))
	if err := h.remember(runID, main); err != nil {
		t.Fatal(err)
	}

	got := h.ReadRun(t.TempDir(), harness.RunInfo{SessionID: runID})

	type row struct {
		tool       string
		durationMs *int64
	}
	var rows []row
	for _, c := range got.Calls {
		rows = append(rows, row{c.Tool, c.DurationMs})
	}
	want := []row{
		{"spawn_agent", ms(9000)},
		{"spawn_agent", ms(18900)},
		{"spawn_agent", ms(200)},
		{"first_tool", nil},
		{"second_tool", nil},
	}
	if !reflect.DeepEqual(rows, want) {
		t.Fatalf("calls:\n%+v\nwant\n%+v", rows, want)
	}
}
