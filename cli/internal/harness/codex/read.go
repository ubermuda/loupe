package codex

import (
	"bufio"
	"cmp"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"time"

	"github.com/ubermuda/loupe/cli/internal/harness"
	"github.com/ubermuda/loupe/cli/internal/transcript"
)

// fallbackModel names the model of a run that left no model behind.
const fallbackModel = "codex"

// threadPattern matches a thread id, which names a part of a file path.
var threadPattern = regexp.MustCompile(`^[A-Za-z0-9-]+$`)

// tokens are the counts of Codex. Input includes the cached tokens.
type tokens struct {
	Input      int64 `json:"input_tokens"`
	Cached     int64 `json:"cached_input_tokens"`
	CacheWrite int64 `json:"cache_write_input_tokens"`
	Output     int64 `json:"output_tokens"`
}

func (t tokens) plus(o tokens) tokens {
	return tokens{t.Input + o.Input, t.Cached + o.Cached, t.CacheWrite + o.CacheWrite, t.Output + o.Output}
}

// minus is t after o, and a count never goes below zero.
func (t tokens) minus(o tokens) tokens {
	return tokens{max(t.Input-o.Input, 0), max(t.Cached-o.Cached, 0), max(t.CacheWrite-o.CacheWrite, 0), max(t.Output-o.Output, 0)}
}

// spend is the tokens of one model with what they cost. unpriced is true when
// any reply has no price, so the cost is unknown and not a partial sum.
type spend struct {
	tokens
	cost     float64
	unpriced bool
}

// uncached is the input that the cache did not serve. Codex charges no cache
// write, so the cost counts none.
func (t tokens) uncached() int64 {
	return max(t.Input-t.Cached, 0)
}

// priceAt is the spend of the tokens of one reply whose prompt holds prompt
// tokens.
func (t tokens) priceAt(name string, prompt int64) spend {
	c := transcript.CostPrompt(name, prompt, t.uncached(), t.Output, t.Cached, 0, 0)
	if c == nil {
		return spend{tokens: t, unpriced: true}
	}

	return spend{tokens: t, cost: *c}
}

// plus adds the spend of another reply of the same model.
func (s spend) plus(o spend) spend {
	return spend{tokens: s.tokens.plus(o.tokens), cost: s.cost + o.cost, unpriced: s.unpriced || o.unpriced}
}

func (s spend) model() transcript.Model {
	m := transcript.Model{
		InputTokens:      s.uncached(),
		OutputTokens:     s.Output,
		CacheReadTokens:  s.Cached,
		CacheWriteTokens: s.CacheWrite,
	}
	if !s.unpriced {
		cost := s.cost
		m.CostUSD = &cost
	}

	return m
}

// toUsage turns the spend of each model into a usage.
func toUsage(byModel map[string]spend) transcript.Usage {
	usage := make(transcript.Usage, len(byModel))
	for name, s := range byModel {
		usage[name] = s.model()
	}

	return usage
}

// stdoutRun is what the JSONL on stdout holds.
type stdoutRun struct {
	thread    string
	usage     *tokens
	errMsg    string
	lastAgent string
}

// readStdout reads the events of `codex exec --json`. A line that does not
// decode counts for nothing, as a torn last line does.
func readStdout(path string) stdoutRun {
	var run stdoutRun
	_ = eachLine(path, func(line []byte) {
		var ev struct {
			Type     string          `json:"type"`
			ThreadID string          `json:"thread_id"`
			Usage    *tokens         `json:"usage"`
			Message  string          `json:"message"`
			Error    json.RawMessage `json:"error"`
			Item     struct {
				Type string `json:"type"`
				Text string `json:"text"`
			} `json:"item"`
		}
		if json.Unmarshal(line, &ev) != nil {
			return
		}
		switch ev.Type {
		case "thread.started":
			run.thread = cmp.Or(ev.ThreadID, run.thread)
		case "turn.completed":
			if ev.Usage != nil {
				run.usage = ev.Usage
			}
		case "item.completed":
			if ev.Item.Type == "agent_message" {
				run.lastAgent = ev.Item.Text
			}
		case "error", "turn.failed":
			run.errMsg = cmp.Or(errorText(ev.Message, ev.Error), run.errMsg)
		}
	})

	return run
}

// errorText is the message of an error event, which holds it in a message field
// or in an error object or string.
func errorText(message string, raw json.RawMessage) string {
	if message != "" {
		return message
	}
	var obj struct {
		Message string `json:"message"`
	}
	if json.Unmarshal(raw, &obj) == nil && obj.Message != "" {
		return obj.Message
	}
	var text string
	if json.Unmarshal(raw, &text) == nil {
		return text
	}

	return ""
}

// eachLine calls fn with each line of the file. A line can hold a whole
// prompt, so it has no length limit.
func eachLine(path string, fn func(line []byte)) error {
	f, err := os.Open(path)
	if err != nil {
		return err
	}
	defer f.Close()

	r := bufio.NewReaderSize(f, 1<<16)
	for {
		line, err := r.ReadBytes('\n')
		fn(line)
		if errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil {
			return err
		}
	}
}

// tokenEvent is what one token count line added to the thread. model is "" when
// no turn context came before it.
type tokenEvent struct {
	at    time.Time
	model string
	delta tokens
	// prompt is the prompt size of the reply, which picks its price tier.
	prompt int64
}

// session is what a Codex session file holds that the bridge reads. id is the
// thread of the file.
type session struct {
	id       string
	cwd      string
	provider string
	model    string
	events   []tokenEvent
	// lines holds the time of each timed line, in file order, and contexts the
	// input context of each token count.
	lines    []time.Time
	contexts []sample
	calls    []rawCall
	// spawns are the subagents the thread started.
	spawns []spawn
	// byID maps a call_id to its index in calls, and shells holds the index
	// of each call that can run a shell command.
	byID   map[string]int
	shells []int
}

// sample is one value at one time.
type sample struct {
	at    time.Time
	value int64
}

// readSession reads a session file. The format is Codex's own and has no
// documentation, so a line that does not decode counts for nothing.
func readSession(path string) (session, error) {
	s := session{byID: map[string]int{}}
	var previous tokens
	err := eachLine(path, func(line []byte) {
		var entry struct {
			Timestamp time.Time       `json:"timestamp"`
			Type      string          `json:"type"`
			Payload   json.RawMessage `json:"payload"`
		}
		if json.Unmarshal(line, &entry) != nil {
			return
		}
		var owner struct {
			ThreadID string `json:"thread_id"`
		}
		_ = json.Unmarshal(entry.Payload, &owner)
		// A file can log the events of another thread, such as a review.
		if owner.ThreadID != "" && s.id != "" && owner.ThreadID != s.id {
			return
		}
		if !entry.Timestamp.IsZero() {
			s.lines = append(s.lines, entry.Timestamp)
		}
		switch entry.Type {
		case "session_meta":
			var p struct {
				ID            string `json:"id"`
				Cwd           string `json:"cwd"`
				ModelProvider string `json:"model_provider"`
			}
			if json.Unmarshal(entry.Payload, &p) == nil {
				s.id, s.cwd, s.provider = cmp.Or(s.id, p.ID), cmp.Or(s.cwd, p.Cwd), cmp.Or(s.provider, p.ModelProvider)
			}
		case "turn_context":
			var p struct {
				Model string `json:"model"`
			}
			if json.Unmarshal(entry.Payload, &p) == nil {
				s.model = cmp.Or(p.Model, s.model)
			}
		case "event_msg":
			s.event(entry.Timestamp, entry.Payload, &previous)
		case "response_item":
			s.item(entry.Timestamp, entry.Payload)
		}
	})

	return s, err
}

// event reads one event line: a token count or a finished item.
func (s *session) event(at time.Time, raw json.RawMessage, previous *tokens) {
	var p struct {
		Type string `json:"type"`
		Info *struct {
			Total *tokens `json:"total_token_usage"`
			Last  *tokens `json:"last_token_usage"`
		} `json:"info"`
		ThreadID    string `json:"thread_id"`
		StartedAtMs *int64 `json:"started_at_ms"`
		Item        struct {
			Type          string                 `json:"type"`
			ID            string                 `json:"id"`
			Kind          string                 `json:"kind"`
			Command       json.RawMessage        `json:"command"`
			ParsedCmd     []struct{ Cmd string } `json:"parsed_cmd"`
			ExitCode      *int                   `json:"exit_code"`
			AgentThreadID string                 `json:"agent_thread_id"`
			AgentPath     string                 `json:"agent_path"`
		} `json:"item"`
	}
	if json.Unmarshal(raw, &p) != nil {
		return
	}
	switch {
	case p.Type == "token_count" && p.Info != nil:
		if p.Info.Last != nil {
			s.contexts = append(s.contexts, sample{at, p.Info.Last.Input})
		}
		// The total counts the whole thread, so a line the file repeats adds
		// nothing the second time.
		var delta tokens
		switch {
		case p.Info.Total != nil && p.Info.Total.Input >= previous.Input && p.Info.Total.Output >= previous.Output:
			delta = p.Info.Total.minus(*previous)
			*previous = *p.Info.Total
		case p.Info.Last != nil:
			delta = *p.Info.Last
			if p.Info.Total != nil {
				*previous = *p.Info.Total
			}
		default:
			return
		}
		prompt := delta.Input
		if p.Info.Last != nil {
			prompt = p.Info.Last.Input
		}
		s.events = append(s.events, tokenEvent{at: at, model: s.model, delta: delta, prompt: prompt})
	case p.Type != "item_completed" || p.ThreadID != s.id || s.id == "":
		// A file can log the items of another thread, such as a review.
	case p.Item.Type == "CommandExecution":
		var argv []string
		_ = json.Unmarshal(p.Item.Command, &argv)
		parsed := make([]string, 0, len(p.Item.ParsedCmd))
		for _, c := range p.Item.ParsedCmd {
			parsed = append(parsed, c.Cmd)
		}
		if p.StartedAtMs != nil {
			at = time.UnixMilli(*p.StartedAtMs)
		}
		s.attach(at, shellText(argv, parsed), p.Item.ExitCode)
	case p.Item.Type == "SubAgentActivity" && p.Item.Kind == "started" && p.Item.AgentThreadID != "":
		s.spawns = append(s.spawns, spawn{id: p.Item.ID, path: p.Item.AgentPath, thread: p.Item.AgentThreadID, at: at})
	}
}

// between sums the token counts at or after from, and before to when to is set.
// A count from a line with no model counts for fallback. Each reply is priced
// by its own prompt size.
func (s session) between(from, to time.Time, fallback string) map[string]spend {
	byModel := map[string]spend{}
	for _, ev := range s.events {
		if ev.at.Before(from) || (!to.IsZero() && !ev.at.Before(to)) {
			continue
		}
		name := cmp.Or(ev.model, fallback)
		byModel[name] = byModel[name].plus(ev.delta.priceAt(name, ev.prompt))
	}

	return byModel
}

// find is the session file of the thread, and the newest wins.
func (h Harness) find(thread string) (string, error) {
	if !threadPattern.MatchString(thread) {
		return "", transcript.ErrNotFound
	}
	home, err := h.homeDir()
	if err != nil {
		return "", err
	}
	found, err := filepath.Glob(filepath.Join(home, "sessions", "*", "*", "*", "rollout-*-"+thread+".jsonl"))
	if err != nil || len(found) == 0 {
		return "", transcript.ErrNotFound
	}
	slices.Sort(found)

	return found[len(found)-1], nil
}

// threadFile is the file that maps the run id to its thread.
func (h Harness) threadFile(runID string) (string, error) {
	if h.threads == "" || runID == "" || strings.ContainsAny(runID, `/\`) || strings.Contains(runID, "..") {
		return "", transcript.ErrNotFound
	}

	return filepath.Join(h.threads, runID), nil
}

// thread is the Codex thread of the run id. A run with no mapping is an
// interactive one, and its thread comes from the record of its launch.
func (h Harness) thread(runID string) (string, error) {
	path, err := h.threadFile(runID)
	if err != nil {
		return "", err
	}
	b, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return h.locate(runID)
	}
	if err != nil {
		return "", err
	}
	if id := strings.TrimSpace(string(b)); threadPattern.MatchString(id) {
		return id, nil
	}

	return "", transcript.ErrNotFound
}

// remember maps the run id to the thread Codex chose for it.
func (h Harness) remember(runID, thread string) error {
	path, err := h.threadFile(runID)
	if err != nil {
		return err
	}
	if !threadPattern.MatchString(thread) {
		return fmt.Errorf("thread id %q is not an id", thread)
	}
	if err := os.MkdirAll(h.threads, 0o700); err != nil {
		return err
	}

	return os.WriteFile(path, []byte(thread+"\n"), 0o600)
}

// session is the parsed session file of the run id.
func (h Harness) session(runID string) (session, error) {
	thread, err := h.thread(runID)
	if err != nil {
		return session{}, err
	}
	path, err := h.find(thread)
	if err != nil {
		return session{}, err
	}

	return readSession(path)
}

// tree is the session of the run id, with the sessions of its subagents. A
// subagent started before since, or at or after until when until is set,
// need not read.
func (h Harness) tree(runID string, since, until time.Time) (*tree, error) {
	s, err := h.session(runID)
	if err != nil {
		return nil, err
	}

	return h.load(s, map[string]bool{}, since, until)
}

// ReadRun reads a finished run from the stdout of Codex and the files it wrote.
// A run whose profile names another provider than the session used did not run
// as configured, so it reads as undecoded.
func (h Harness) ReadRun(dir string, run harness.RunInfo) harness.Output {
	stdout := readStdout(filepath.Join(dir, "stdout"))
	if stdout.thread != "" {
		_ = h.remember(run.SessionID, stdout.thread)
	}
	last, _ := os.ReadFile(filepath.Join(dir, lastMessageFile))
	text := cmp.Or(strings.TrimSpace(string(last)), strings.TrimSpace(stdout.lastAgent))
	doc := document(text)

	out := harness.Output{Decoded: doc != nil, StructuredOutput: doc, Result: text}
	if !out.Decoded {
		out.Result = cmp.Or(stdout.errMsg, text)
	}

	// The session files give usage per model, with the keys SessionTotal uses,
	// so a resume subtracts its baseline model by model. The stdout total is
	// the fallback when a file of the run is missing or unreadable. A file
	// that does not parse leaves the calls unknown.
	sess, sessErr := h.session(run.SessionID)
	end := runEnd(dir)
	var t *tree
	treeErr := sessErr
	if sessErr == nil {
		t, treeErr = h.load(sess, map[string]bool{}, run.Since, end)
	}
	if treeErr == nil && sess.id != "" {
		out.CallsRead = true
		out.Calls, out.Timing, out.PeakContextTokens = t.metrics(run.Since, end)
	}
	var counted map[string]spend
	if treeErr == nil && !t.skipped {
		counted = t.between(time.Time{}, end)
	}
	switch {
	case treeErr == nil && t.skipped:
		// The session total lacks a subagent, so the bridge reads the spend of
		// the run window with SessionUsage instead.
	case len(counted) > 0:
		out.Usage = toUsage(counted)
	case stdout.usage != nil:
		name := cmp.Or(sess.model, run.Model, fallbackModel)
		out.Usage = toUsage(map[string]spend{name: stdout.usage.priceAt(name, 0)})
	}

	if msg := h.providerProblem(sess, sessErr == nil); msg != "" {
		out.Decoded, out.StructuredOutput, out.Result = false, nil, msg
	}

	return out
}

// runEnd bounds the lines of the run in dir, so a later resume of the session
// adds nothing to an adopted run read after it. The worker shell writes its
// exit file when Codex exits. A killed run has none, and its window has no
// end, as stdout can stop long before the process does.
func runEnd(dir string) time.Time {
	info, err := os.Stat(filepath.Join(dir, "status.exit"))
	if err != nil {
		return time.Time{}
	}

	return info.ModTime()
}

// document is the JSON object in the final message, which a model can wrap in
// a code fence, or nil when there is none.
func document(text string) json.RawMessage {
	text = strings.TrimSpace(text)
	if rest, ok := strings.CutPrefix(text, "```"); ok && strings.HasSuffix(rest, "```") {
		if _, body, found := strings.Cut(rest, "\n"); found {
			text = strings.TrimSpace(strings.TrimSuffix(body, "```"))
		}
	}
	var obj map[string]json.RawMessage
	if json.Unmarshal([]byte(text), &obj) != nil || obj == nil {
		return nil
	}

	return json.RawMessage(text)
}

// SessionUsage is what the thread and its subagents spent at or after from,
// and before to when to is set.
func (h Harness) SessionUsage(runID string, from, to time.Time) (transcript.Usage, error) {
	t, err := h.tree(runID, from, to)
	if err != nil {
		return nil, err
	}
	if !t.counted() {
		return nil, errNoUsage
	}

	return toUsage(t.between(from, to)), nil
}

// errNoUsage says the session file holds no token count, so the spend is
// unknown and not zero.
var errNoUsage = errors.New("the Codex session holds no token count")

// SessionTotal is what the whole thread and its subagents spent.
func (h Harness) SessionTotal(runID string) (transcript.Usage, error) {
	return h.SessionUsage(runID, time.Time{}, time.Time{})
}

func (h Harness) StartDir(runID string) (string, error) {
	sess, err := h.session(runID)
	if errors.Is(err, transcript.ErrNotFound) {
		return "", nil
	}
	if err != nil {
		return "", err
	}

	return sess.cwd, nil
}

func (h Harness) HasSession(runID string) error {
	thread, err := h.thread(runID)
	if err != nil {
		return err
	}
	_, err = h.find(thread)

	return err
}
