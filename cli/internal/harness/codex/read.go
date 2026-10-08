package codex

import (
	"bufio"
	"bytes"
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

// model prices the tokens of a model. Codex charges no cache write, so the
// cost counts none.
func (t tokens) model(name string) transcript.Model {
	input := max(t.Input-t.Cached, 0)

	return transcript.Model{
		InputTokens:      input,
		OutputTokens:     t.Output,
		CacheReadTokens:  t.Cached,
		CacheWriteTokens: t.CacheWrite,
		CostUSD:          transcript.Cost(name, input, t.Output, t.Cached, 0, 0),
	}
}

// toUsage turns the tokens of each model into a usage.
func toUsage(byModel map[string]tokens) transcript.Usage {
	usage := make(transcript.Usage, len(byModel))
	for name, t := range byModel {
		usage[name] = t.model(name)
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
	_ = eachLine(path, nil, func(line []byte) {
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

// eachLine calls fn with each line of the file. When markers is not nil, only a
// line that holds one of them. A line can hold a whole prompt, so it has no
// length limit.
func eachLine(path string, markers [][]byte, fn func(line []byte)) error {
	f, err := os.Open(path)
	if err != nil {
		return err
	}
	defer f.Close()

	r := bufio.NewReaderSize(f, 1<<16)
	for {
		line, err := r.ReadBytes('\n')
		if markers == nil || slices.ContainsFunc(markers, func(m []byte) bool { return bytes.Contains(line, m) }) {
			fn(line)
		}
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
}

// session is what a Codex session file holds that the bridge reads.
type session struct {
	cwd      string
	provider string
	model    string
	events   []tokenEvent
}

var sessionMarkers = [][]byte{[]byte(`"session_meta"`), []byte(`"turn_context"`), []byte(`"token_count"`)}

// readSession reads a session file. The format is Codex's own and has no
// documentation, so a line that does not decode counts for nothing.
func readSession(path string) (session, error) {
	var s session
	var previous tokens
	err := eachLine(path, sessionMarkers, func(line []byte) {
		var entry struct {
			Timestamp time.Time `json:"timestamp"`
			Type      string    `json:"type"`
			Payload   struct {
				Type          string `json:"type"`
				Cwd           string `json:"cwd"`
				ModelProvider string `json:"model_provider"`
				Model         string `json:"model"`
				Info          *struct {
					Total *tokens `json:"total_token_usage"`
					Last  *tokens `json:"last_token_usage"`
				} `json:"info"`
			} `json:"payload"`
		}
		if json.Unmarshal(line, &entry) != nil {
			return
		}
		p := entry.Payload
		switch {
		case entry.Type == "session_meta":
			s.cwd, s.provider = cmp.Or(s.cwd, p.Cwd), cmp.Or(s.provider, p.ModelProvider)
		case entry.Type == "turn_context":
			s.model = cmp.Or(p.Model, s.model)
		case entry.Type == "event_msg" && p.Type == "token_count" && p.Info != nil:
			// The total counts the whole thread, so a line the file repeats adds
			// nothing the second time.
			var delta tokens
			switch {
			case p.Info.Total != nil && p.Info.Total.Input >= previous.Input && p.Info.Total.Output >= previous.Output:
				delta = p.Info.Total.minus(previous)
				previous = *p.Info.Total
			case p.Info.Last != nil:
				delta = *p.Info.Last
				if p.Info.Total != nil {
					previous = *p.Info.Total
				}
			default:
				return
			}
			s.events = append(s.events, tokenEvent{at: entry.Timestamp, model: s.model, delta: delta})
		}
	})

	return s, err
}

// between sums the token counts at or after from, and before to when to is set.
// A count from a line with no model counts for fallback.
func (s session) between(from, to time.Time, fallback string) map[string]tokens {
	byModel := map[string]tokens{}
	for _, ev := range s.events {
		if ev.at.Before(from) || (!to.IsZero() && !ev.at.Before(to)) {
			continue
		}
		name := cmp.Or(ev.model, fallback)
		byModel[name] = byModel[name].plus(ev.delta)
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

	// The session file gives usage per model, with the keys SessionTotal uses, so
	// a resume subtracts its baseline model by model. The stdout total is the
	// fallback when the file is missing or unreadable.
	sess, sessErr := h.session(run.SessionID)
	switch {
	case sessErr == nil && len(sess.events) > 0:
		out.Usage = toUsage(sess.between(time.Time{}, time.Time{}, cmp.Or(sess.model, fallbackModel)))
	case stdout.usage != nil:
		out.Usage = toUsage(map[string]tokens{cmp.Or(sess.model, run.Model, fallbackModel): *stdout.usage})
	}

	if msg := h.providerProblem(sess, sessErr == nil); msg != "" {
		out.Decoded, out.StructuredOutput, out.Result = false, nil, msg
	}

	return out
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

// SessionUsage is what the thread spent at or after from, and before to when
// to is set.
func (h Harness) SessionUsage(runID string, from, to time.Time) (transcript.Usage, error) {
	sess, err := h.session(runID)
	if err != nil {
		return nil, err
	}
	if len(sess.events) == 0 {
		return nil, errNoUsage
	}

	return toUsage(sess.between(from, to, cmp.Or(sess.model, fallbackModel))), nil
}

// errNoUsage says the session file holds no token count, so the spend is
// unknown and not zero.
var errNoUsage = errors.New("the Codex session holds no token count")

// SessionTotal is what the whole thread spent.
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
