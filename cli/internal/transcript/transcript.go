// Package transcript reads the token usage of a Claude Code session from the
// transcript files Claude Code writes under its config directory.
package transcript

import (
	"bufio"
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// Model is the tokens one model spent. A nil cost is unknown.
type Model struct {
	InputTokens      int64    `json:"inputTokens"`
	OutputTokens     int64    `json:"outputTokens"`
	CacheReadTokens  int64    `json:"cacheReadTokens"`
	CacheWriteTokens int64    `json:"cacheWriteTokens"`
	CostUSD          *float64 `json:"costUsd"`
}

// Usage is the tokens a session spent, by model.
type Usage map[string]Model

// ErrNotFound says Claude Code holds no transcript of the session.
var ErrNotFound = errors.New("no transcript of the session")

// ConfigDir is Claude Code's config directory: CLAUDE_CONFIG_DIR, or ~/.claude.
func ConfigDir() (string, error) {
	if dir := os.Getenv("CLAUDE_CONFIG_DIR"); dir != "" {
		return dir, nil
	}
	home, err := os.UserHomeDir()
	if err != nil {
		return "", fmt.Errorf("find the Claude Code config directory: %w", err)
	}

	return filepath.Join(home, ".claude"), nil
}

// Find is the transcript of the session in configDir. Claude Code keeps one
// directory per working directory, so the newest copy wins.
func Find(configDir, sessionID string) (string, error) {
	if sessionID == "" || strings.ContainsAny(sessionID, `/\`) || strings.Contains(sessionID, "..") {
		return "", ErrNotFound
	}
	projects := filepath.Join(configDir, "projects")
	dirs, err := os.ReadDir(projects)
	if errors.Is(err, os.ErrNotExist) {
		return "", ErrNotFound
	}
	if err != nil {
		return "", fmt.Errorf("read the transcripts: %w", err)
	}

	var found string
	var newest time.Time
	for _, d := range dirs {
		if !d.IsDir() {
			continue
		}
		path := filepath.Join(projects, d.Name(), sessionID+".jsonl")
		info, err := os.Stat(path)
		if err != nil || !info.Mode().IsRegular() {
			continue
		}
		if found == "" || info.ModTime().After(newest) {
			found, newest = path, info.ModTime()
		}
	}
	if found == "" {
		return "", ErrNotFound
	}

	return found, nil
}

// claudeModel is one model of Claude Code's modelUsage object.
type claudeModel struct {
	InputTokens              int64    `json:"inputTokens"`
	OutputTokens             int64    `json:"outputTokens"`
	CacheReadInputTokens     int64    `json:"cacheReadInputTokens"`
	CacheCreationInputTokens int64    `json:"cacheCreationInputTokens"`
	CostUSD                  *float64 `json:"costUSD"`
}

// DecodeModelUsage reads the modelUsage object that claude -p prints and that
// each cost-state line of a transcript holds.
func DecodeModelUsage(raw json.RawMessage) (Usage, error) {
	var models map[string]claudeModel
	if err := json.Unmarshal(raw, &models); err != nil {
		return nil, fmt.Errorf("decode the model usage: %w", err)
	}
	usage := make(Usage, len(models))
	for name, m := range models {
		usage[name] = Model{
			InputTokens:      m.InputTokens,
			OutputTokens:     m.OutputTokens,
			CacheReadTokens:  m.CacheReadInputTokens,
			CacheWriteTokens: m.CacheCreationInputTokens,
			CostUSD:          m.CostUSD,
		}
	}

	return usage, nil
}

// LastCostState is the session usage on the last cost-state line, which Claude
// Code writes as a process ends, and zero with no line. complete is false when
// an assistant message came after the line: a process that ended with no line,
// such as a killed one, spent tokens the line does not count.
func LastCostState(path string) (usage Usage, complete bool, err error) {
	var last json.RawMessage
	var next time.Time
	after, hasLine := false, false
	err = eachLine(path, "", func(line []byte) {
		var entry struct {
			Type       string          `json:"type"`
			Timestamp  time.Time       `json:"timestamp"`
			ModelUsage json.RawMessage `json:"modelUsage"`
			Message    struct {
				Model string `json:"model"`
			} `json:"message"`
		}
		if json.Unmarshal(line, &entry) != nil {
			return
		}
		switch {
		case entry.Type == "cost-state":
			if len(entry.ModelUsage) > 0 {
				last = entry.ModelUsage
			}
			hasLine, after, next = true, false, time.Time{}
		case isMessage(entry.Type, entry.Message.Model):
			after = true
		}
		if hasLine && next.IsZero() {
			next = entry.Timestamp
		}
	})
	if err != nil {
		return nil, false, err
	}
	// A cost-state line holds no time, and a background subagent can end after
	// the last main entry. The first timed entry after the line, or the file's
	// last write, comes no earlier than the line.
	cutoff := next
	if info, statErr := os.Stat(path); cutoff.IsZero() && statErr == nil {
		cutoff = info.ModTime()
	}
	after = after || subagentMessageAfter(path, cutoff, hasLine)

	usage = Usage{}
	if last != nil {
		usage, err = DecodeModelUsage(last)
	}

	return usage, !after, err
}

func isMessage(entryType, model string) bool {
	return entryType == "assistant" && model != "<synthetic>"
}

// subagentMessageAfter reports whether a subagent transcript holds a message
// after cutoff, or any message when the session has no cost-state line.
func subagentMessageAfter(path string, cutoff time.Time, hasLine bool) bool {
	subagents := filepath.Join(strings.TrimSuffix(path, ".jsonl"), "subagents")
	files, _ := os.ReadDir(subagents)
	found := false
	for _, f := range files {
		if f.IsDir() || !strings.HasSuffix(f.Name(), ".jsonl") {
			continue
		}
		_ = eachLine(filepath.Join(subagents, f.Name()), `"assistant"`, func(line []byte) {
			var entry struct {
				Type      string    `json:"type"`
				Timestamp time.Time `json:"timestamp"`
				Message   struct {
					Model string `json:"model"`
				} `json:"message"`
			}
			if json.Unmarshal(line, &entry) == nil && isMessage(entry.Type, entry.Message.Model) &&
				(!hasLine || entry.Timestamp.After(cutoff)) {
				found = true
			}
		})
	}

	return found
}

// message is the usage of one API response. A streamed response repeats its
// entry as it grows, so the largest count of each field is the final one.
type message struct {
	model                                      string
	input, output, cacheRead, write5m, write1h int64
}

// Since sums the usage of the assistant messages the transcript and its
// subagent transcripts hold from since on. It prices them with the list
// prices, so a process that left no usage of its own still has an estimate.
func Since(path string, since time.Time) (Usage, error) {
	messages := map[string]message{}
	add := func(line []byte) {
		id, m, at, ok := decodeAssistant(line)
		if !ok || at.Before(since) {
			return
		}
		prev := messages[id]
		messages[id] = message{
			model:     m.model,
			input:     max(prev.input, m.input),
			output:    max(prev.output, m.output),
			cacheRead: max(prev.cacheRead, m.cacheRead),
			write5m:   max(prev.write5m, m.write5m),
			write1h:   max(prev.write1h, m.write1h),
		}
	}
	if err := eachLine(path, `"assistant"`, add); err != nil {
		return nil, err
	}
	// An unreadable subagent file counts nothing, which an estimate allows.
	subagents := filepath.Join(strings.TrimSuffix(path, ".jsonl"), "subagents")
	files, _ := os.ReadDir(subagents)
	for _, f := range files {
		if !f.IsDir() && strings.HasSuffix(f.Name(), ".jsonl") {
			_ = eachLine(filepath.Join(subagents, f.Name()), `"assistant"`, add)
		}
	}

	usage := Usage{}
	unpriced := map[string]bool{}
	for _, m := range messages {
		total, seen := usage[m.model]
		total.InputTokens += m.input
		total.OutputTokens += m.output
		total.CacheReadTokens += m.cacheRead
		total.CacheWriteTokens += m.write5m + m.write1h
		c := Cost(m.model, m.input, m.output, m.cacheRead, m.write5m, m.write1h)
		switch {
		case c == nil || unpriced[m.model]:
			unpriced[m.model], total.CostUSD = true, nil
		case !seen:
			total.CostUSD = c
		default:
			sum := *total.CostUSD + *c
			total.CostUSD = &sum
		}
		usage[m.model] = total
	}

	return usage, nil
}

// decodeAssistant reads one assistant entry. An entry with no id, no time or
// no usage counts nothing, and neither does the <synthetic> model Claude Code
// uses for its own notices.
func decodeAssistant(line []byte) (string, message, time.Time, bool) {
	var entry struct {
		Type      string    `json:"type"`
		Timestamp time.Time `json:"timestamp"`
		Message   struct {
			ID    string `json:"id"`
			Model string `json:"model"`
			Usage *struct {
				Input         int64 `json:"input_tokens"`
				Output        int64 `json:"output_tokens"`
				CacheRead     int64 `json:"cache_read_input_tokens"`
				CacheCreation int64 `json:"cache_creation_input_tokens"`
				Split         *struct {
					Write5m int64 `json:"ephemeral_5m_input_tokens"`
					Write1h int64 `json:"ephemeral_1h_input_tokens"`
				} `json:"cache_creation"`
			} `json:"usage"`
		} `json:"message"`
	}
	if json.Unmarshal(line, &entry) != nil || entry.Type != "assistant" {
		return "", message{}, time.Time{}, false
	}
	u := entry.Message.Usage
	if u == nil || entry.Message.ID == "" || entry.Message.Model == "" || entry.Message.Model == "<synthetic>" || entry.Timestamp.IsZero() {
		return "", message{}, time.Time{}, false
	}
	m := message{model: entry.Message.Model, input: u.Input, output: u.Output, cacheRead: u.CacheRead, write5m: u.CacheCreation}
	// A write with no split is priced at the five-minute rate.
	if u.Split != nil && u.Split.Write1h > 0 {
		m.write1h = min(u.Split.Write1h, u.CacheCreation)
		m.write5m = u.CacheCreation - m.write1h
	}

	return entry.Message.ID, m, entry.Timestamp, true
}

// eachLine calls fn with each line of the file that holds marker. A line can
// hold a whole tool result, so it has no length limit. A torn last line, as a
// killed process leaves, fails to decode and counts nothing.
func eachLine(path, marker string, fn func(line []byte)) error {
	f, err := os.Open(path)
	if err != nil {
		return fmt.Errorf("read the transcript: %w", err)
	}
	defer f.Close()

	r := bufio.NewReaderSize(f, 1<<16)
	needle := []byte(marker)
	for {
		line, err := r.ReadBytes('\n')
		if bytes.Contains(line, needle) {
			fn(line)
		}
		if errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil {
			return fmt.Errorf("read the transcript: %w", err)
		}
	}
}

// Minus is the usage after base, per model and per count. A count never goes
// below zero. A cost is known only when both costs are, and a model that spent
// nothing after base drops out.
func (u Usage) Minus(base Usage) Usage {
	out := Usage{}
	for name, m := range u {
		b := base[name]
		d := Model{
			InputTokens:      max(m.InputTokens-b.InputTokens, 0),
			OutputTokens:     max(m.OutputTokens-b.OutputTokens, 0),
			CacheReadTokens:  max(m.CacheReadTokens-b.CacheReadTokens, 0),
			CacheWriteTokens: max(m.CacheWriteTokens-b.CacheWriteTokens, 0),
		}
		if d == (Model{}) {
			continue
		}
		switch _, inBase := base[name]; {
		case !inBase:
			d.CostUSD = m.CostUSD
		case m.CostUSD != nil && b.CostUSD != nil:
			c := max(*m.CostUSD-*b.CostUSD, 0)
			d.CostUSD = &c
		}
		out[name] = d
	}

	return out
}
