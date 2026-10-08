package codex

import (
	"cmp"
	"encoding/json"
	"fmt"
	"regexp"
	"slices"
	"strings"
	"time"

	"github.com/ubermuda/loupe/cli/internal/stream"
)

// rawCall is one function_call or custom_tool_call line, with its output.
type rawCall struct {
	id    string
	name  string
	input string
	start time.Time
	// end is the time of the output, and zero when there is none.
	end      time.Time
	answered bool
	// failed is what the output says of an error, and nil when it says nothing.
	failed *bool
	// taskName is the agent path the output of spawn_agent names.
	taskName string
	// shell holds the shell text of each command the call ran, and exits
	// their exit codes.
	shell []string
	exits []*int
}

// spawn is one subagent a thread started at at. id is the call_id of the
// spawn_agent call that started it, and "" in an older file.
type spawn struct {
	id     string
	path   string
	thread string
	at     time.Time
}

// shellTools are the tools that run a shell command. exec runs a script that
// can call exec_command.
var shellTools = []string{"exec", "exec_command", "shell"}

// item reads one response_item line: a call or its output.
func (s *session) item(at time.Time, raw json.RawMessage) {
	var p struct {
		Type      string          `json:"type"`
		CallID    string          `json:"call_id"`
		Name      string          `json:"name"`
		Arguments string          `json:"arguments"`
		Input     string          `json:"input"`
		Output    json.RawMessage `json:"output"`
	}
	if json.Unmarshal(raw, &p) != nil || p.CallID == "" {
		return
	}
	switch p.Type {
	case "function_call", "custom_tool_call":
		if _, seen := s.byID[p.CallID]; seen {
			return
		}
		s.byID[p.CallID] = len(s.calls)
		if slices.Contains(shellTools, p.Name) {
			s.shells = append(s.shells, len(s.calls))
		}
		s.calls = append(s.calls, rawCall{id: p.CallID, name: p.Name, input: p.Arguments + p.Input, start: at})
	case "function_call_output", "custom_tool_call_output":
		i, ok := s.byID[p.CallID]
		if !ok || s.calls[i].answered {
			return
		}
		c := &s.calls[i]
		c.answered, c.end = true, at
		text := outputText(p.Output)
		c.failed = outputError(text)
		if c.name == "spawn_agent" {
			var out struct {
				TaskName string `json:"task_name"`
			}
			_ = json.Unmarshal([]byte(text), &out)
			c.taskName = out.TaskName
		}
	}
}

// attach gives a command that started at at to the latest shell call that was
// open then. A command can end after the script that started it answered, so
// its start and not its end picks the call.
func (s *session) attach(at time.Time, text string, exit *int) {
	for j := len(s.shells) - 1; j >= 0; j-- {
		c := &s.calls[s.shells[j]]
		if c.start.After(at) || (c.answered && c.end.Before(at)) {
			continue
		}
		c.shell, c.exits = append(c.shell, text), append(c.exits, exit)

		return
	}
}

// shellText is the text a shell ran: the script after -c or -lc, or else the
// parsed commands. The parsed commands drop the stages of a pipe, so the
// script comes first.
func shellText(argv, parsed []string) string {
	if n := len(argv); n >= 3 && (argv[n-2] == "-c" || argv[n-2] == "-lc") {
		return argv[n-1]
	}
	if len(parsed) > 0 {
		return strings.Join(parsed, "\n")
	}

	return strings.Join(argv, " ")
}

// outputText is the text of an output, which is a string or a list of parts.
func outputText(raw json.RawMessage) string {
	var text string
	if json.Unmarshal(raw, &text) == nil {
		return text
	}
	var parts []struct {
		Text string `json:"text"`
	}
	_ = json.Unmarshal(raw, &parts)
	texts := make([]string, 0, len(parts))
	for _, p := range parts {
		texts = append(texts, p.Text)
	}

	return strings.Join(texts, "\n")
}

var exitPattern = regexp.MustCompile(`(?m)^Process exited with code (-?[0-9]+)$`)

// outputError reads the head of an output, which says when a script failed or
// with what code a process exited. It is nil when the head says neither.
func outputError(text string) *bool {
	if strings.HasPrefix(text, "Script failed") {
		failed := true

		return &failed
	}
	head, _, _ := strings.Cut(text, "\nOutput:")
	if m := exitPattern.FindStringSubmatch(head); m != nil {
		failed := m[1] != "0"

		return &failed
	}

	return nil
}

// tree is a session and the sessions of the subagents it started.
type tree struct {
	session
	children []*tree
	byThread map[string]*tree
}

// load reads the session files of the subagents s started, and of theirs.
// seen holds the threads read so far, so a loop ends. A subagent started at or
// after since whose file is missing or does not parse fails the load, as its
// spend is unknown. An earlier one belongs to an earlier run, and is skipped.
func (h Harness) load(s session, seen map[string]bool, since time.Time) (*tree, error) {
	t := &tree{session: s, byThread: map[string]*tree{}}
	seen[s.id] = true
	for _, sp := range s.spawns {
		if seen[sp.thread] {
			continue
		}
		seen[sp.thread] = true
		unread := fmt.Errorf("the session file of Codex subagent %s does not read", sp.thread)
		path, err := h.find(sp.thread)
		var child session
		if err == nil {
			child, err = readSession(path)
		}
		if err != nil || child.id == "" {
			if sp.at.Before(since) {
				continue
			}

			return nil, unread
		}
		c, err := h.load(child, seen, since)
		if err != nil {
			return nil, err
		}
		t.children = append(t.children, c)
		t.byThread[sp.thread] = c
	}

	return t, nil
}

// between sums the spend of the thread and of its subagents.
func (t *tree) between(from, to time.Time) map[string]spend {
	byModel := t.session.between(from, to, cmp.Or(t.model, fallbackModel))
	for _, c := range t.children {
		for name, n := range c.between(from, to) {
			byModel[name] = byModel[name].plus(n)
		}
	}

	return byModel
}

// last is the time of the last line of the thread and of its subagents.
func (t *tree) last() time.Time {
	var last time.Time
	if len(t.lines) > 0 {
		last = slices.MaxFunc(t.lines, time.Time.Compare)
	}
	for _, c := range t.children {
		if l := c.last(); l.After(last) {
			last = l
		}
	}

	return last
}

// spawned maps the index of each spawn_agent call to the subagent it
// started: by its call_id first, then by the agent path its output names.
// Each subagent goes to one call at most.
func (t *tree) spawned() map[int]*tree {
	out := map[int]*tree{}
	used := make([]bool, len(t.spawns))
	took := map[string]bool{}
	joined := map[int]bool{}
	take := func(i, j int) {
		used[j], joined[i] = true, true
		if sp := t.spawns[j]; !took[sp.thread] {
			took[sp.thread] = true
			if child := t.byThread[sp.thread]; child != nil {
				out[i] = child
			}
		}
	}
	for i, c := range t.calls {
		if c.name != "spawn_agent" {
			continue
		}
		if j := slices.IndexFunc(t.spawns, func(sp spawn) bool { return sp.id == c.id }); j >= 0 && !used[j] {
			take(i, j)
		}
	}
	for i, c := range t.calls {
		if c.name != "spawn_agent" || c.taskName == "" || joined[i] {
			continue
		}
		for j, sp := range t.spawns {
			if !used[j] && sp.path == c.taskName && !took[sp.thread] {
				take(i, j)

				break
			}
		}
	}

	return out
}

// timed is one call and how it ended.
type timed struct {
	call stream.Call
	end  stream.End
}

// collect adds the calls of the thread from since on, then the calls of each
// subagent they started.
func (t *tree) collect(since time.Time, inSubagent bool, out []timed) []timed {
	spawned := t.spawned()
	for i, c := range t.calls {
		if c.start.Before(since) {
			continue
		}
		call := c.toCall(inSubagent)
		out = append(out, timed{call: call, end: stream.End{Result: c.end}})
		if child := spawned[i]; child != nil {
			out[len(out)-1].end.Last = child.last()
			out = child.collect(time.Time{}, true, out)
		}
	}

	return out
}

// toCall is the call as the bridge reports it.
func (c rawCall) toCall(inSubagent bool) stream.Call {
	shell := c.shell
	if len(shell) == 0 && (c.name == "exec_command" || c.name == "shell") {
		var args struct {
			Cmd     string   `json:"cmd"`
			Command []string `json:"command"`
		}
		_ = json.Unmarshal([]byte(c.input), &args)
		if text := cmp.Or(args.Cmd, shellText(args.Command, nil)); text != "" {
			shell = []string{text}
		}
	}

	call := stream.Call{
		Tool:       stream.Cut(c.name, stream.MaxName),
		Kind:       stream.KindTool,
		StartedAt:  c.start,
		IsError:    c.isError(),
		InSubagent: inSubagent,
		FullText:   stream.Cut(c.input, stream.MaxFullText),
	}
	switch {
	case c.name == "spawn_agent":
		call.Kind = stream.KindSubagent
	case len(c.shell) > 0 || c.name == "exec_command" || c.name == "shell":
		call.Kind = stream.KindShell
		call.Commands = stream.Commands(strings.Join(shell, "\n"))
	}

	return call
}

// isError is true when a command of the call exited non-zero or its output
// says it failed, false when the output or every command says it succeeded,
// and nil when nothing says.
func (c rawCall) isError() *bool {
	zero := len(c.exits) > 0
	for _, code := range c.exits {
		if code != nil && *code != 0 {
			failed := true

			return &failed
		}
		zero = zero && code != nil
	}
	if c.failed != nil || !zero {
		return c.failed
	}
	failed := false

	return &failed
}

// metrics are the calls, the timing and the peak context of the run that
// started at since.
func (t *tree) metrics(since time.Time) ([]stream.Call, stream.Timing, *int64) {
	items := t.collect(since, false, nil)
	slices.SortStableFunc(items, func(a, b timed) int { return a.call.StartedAt.Compare(b.call.StartedAt) })
	var calls []stream.Call
	var ends []stream.End
	for i, it := range items {
		it.call.Seq = i + 1
		calls, ends = append(calls, it.call), append(ends, it.end)
	}

	var clock stream.Clock
	for _, at := range t.lines {
		if !at.Before(since) {
			clock.Tick(at)
		}
	}
	timing := clock.Finish(calls, ends)

	var peak *int64
	for _, c := range t.contexts {
		if !c.at.Before(since) && (peak == nil || c.value > *peak) {
			peak = &c.value
		}
	}

	return calls, timing, peak
}
