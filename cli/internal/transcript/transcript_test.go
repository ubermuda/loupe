package transcript

import (
	"encoding/json"
	"errors"
	"math"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

const (
	workSession  = "11111111-1111-4111-8111-111111111111"
	emptySession = "22222222-2222-4222-8222-222222222222"
)

func TestConfigDirPrefersTheEnvironment(t *testing.T) {
	t.Setenv("CLAUDE_CONFIG_DIR", "/elsewhere")
	if dir, err := ConfigDir(); err != nil || dir != "/elsewhere" {
		t.Fatalf("ConfigDir = %q, %v", dir, err)
	}

	t.Setenv("CLAUDE_CONFIG_DIR", "")
	t.Setenv("HOME", "/home/someone")
	if dir, err := ConfigDir(); err != nil || dir != filepath.Join("/home/someone", ".claude") {
		t.Fatalf("ConfigDir = %q, %v", dir, err)
	}
}

func TestFindLooksInEveryProjectDirectory(t *testing.T) {
	path, err := Find("testdata", workSession)
	if err != nil || path != filepath.Join("testdata", "projects", "-tmp-work", workSession+".jsonl") {
		t.Fatalf("Find = %q, %v", path, err)
	}
	if _, err := Find("testdata", "33333333-3333-4333-8333-333333333333"); !errors.Is(err, ErrNotFound) {
		t.Fatalf("Find of an unknown session = %v", err)
	}
	for _, id := range []string{"", "*", "../x", "a/b"} {
		if _, err := Find("testdata", id); !errors.Is(err, ErrNotFound) {
			t.Fatalf("Find(%q) = %v", id, err)
		}
	}
}

// Two project directories can hold the same session, and the newest file is
// the one claude writes to.
func TestFindTakesTheNewestOfTwoCopies(t *testing.T) {
	dir := t.TempDir()
	older := filepath.Join(dir, "projects", "a", workSession+".jsonl")
	newer := filepath.Join(dir, "projects", "b", workSession+".jsonl")
	for i, path := range []string{older, newer} {
		if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(path, nil, 0o600); err != nil {
			t.Fatal(err)
		}
		at := time.Now().Add(time.Duration(i-2) * time.Hour)
		if err := os.Chtimes(path, at, at); err != nil {
			t.Fatal(err)
		}
	}
	if path, err := Find(dir, workSession); err != nil || path != newer {
		t.Fatalf("Find = %q, %v", path, err)
	}
}

// The fixture's subagent wrote messages after the last line.
func TestLastCostStateReadsTheLastLine(t *testing.T) {
	got, complete, err := LastCostState(workPath(t))
	if err != nil || complete {
		t.Fatalf("complete = %v, err = %v", complete, err)
	}
	want := Usage{
		"claude-opus-5-5":           {InputTokens: 110, OutputTokens: 120, CacheReadTokens: 130, CacheWriteTokens: 140, CostUSD: cost(1.5)},
		"claude-haiku-4-5-20251001": {InputTokens: 1, OutputTokens: 2, CacheReadTokens: 3, CacheWriteTokens: 4, CostUSD: cost(0.01)},
	}
	assertUsage(t, got, want)
}

func TestLastCostStateOfASessionWithNoneIsZero(t *testing.T) {
	path, err := Find("testdata", emptySession)
	if err != nil {
		t.Fatal(err)
	}
	got, complete, err := LastCostState(path)
	if err != nil || !complete || got == nil || len(got) != 0 {
		t.Fatalf("LastCostState = %v, %v, %v", got, complete, err)
	}
}

// A process that ended with no cost-state line, such as a killed one, left
// messages after the last line, and the line does not count them.
func TestLastCostStateSaysWhetherItCountsEveryMessage(t *testing.T) {
	const (
		line      = `{"type":"cost-state","modelUsage":{"m":{"inputTokens":1}}}`
		before    = `{"type":"assistant","timestamp":"2026-09-25T10:00:00Z","message":{"id":"a","model":"m","usage":{"input_tokens":1}}}`
		after     = `{"type":"assistant","timestamp":"2026-09-25T11:00:00Z","message":{"id":"b","model":"m","usage":{"input_tokens":1}}}`
		synthetic = `{"type":"assistant","timestamp":"2026-09-25T11:00:00Z","message":{"id":"c","model":"<synthetic>","usage":{"input_tokens":0}}}`
		prompt    = `{"type":"user","timestamp":"2026-09-25T09:00:00Z","message":{"content":"go"}}`
		subBefore = `{"type":"assistant","timestamp":"2026-09-25T09:59:00Z","message":{"id":"s","model":"m","usage":{"input_tokens":1}}}`
		subAfter  = `{"type":"assistant","timestamp":"2026-09-25T10:05:00Z","message":{"id":"s","model":"m","usage":{"input_tokens":1}}}`
	)
	for name, tc := range map[string]struct {
		main, sub []string
		complete  bool
	}{
		"every message before the line":      {main: []string{prompt, before, line}, complete: true},
		"a message after the line":           {main: []string{before, line, after}},
		"messages and no line":               {main: []string{prompt, before}},
		"no messages and no line":            {main: []string{prompt}, complete: true},
		"a notice after the line":            {main: []string{before, line, synthetic}, complete: true},
		"a subagent message before the line": {main: []string{before, line}, sub: []string{subBefore}, complete: true},
		"a subagent message after the line":  {main: []string{before, line}, sub: []string{subAfter}},
		"a subagent message and no line":     {main: []string{prompt}, sub: []string{subBefore}},
	} {
		t.Run(name, func(t *testing.T) {
			path := filepath.Join(t.TempDir(), workSession+".jsonl")
			writeLines(t, path, tc.main)
			if tc.sub != nil {
				writeLines(t, filepath.Join(strings.TrimSuffix(path, ".jsonl"), "subagents", "agent-1.jsonl"), tc.sub)
			}
			if _, complete, err := LastCostState(path); err != nil || complete != tc.complete {
				t.Fatalf("complete = %v, err = %v, want %v", complete, err, tc.complete)
			}
		})
	}
}

func writeLines(t *testing.T, path string, lines []string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(strings.Join(lines, "\n")+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
}

func TestLastCostStateOfAMissingFileFails(t *testing.T) {
	if _, _, err := LastCostState(filepath.Join(t.TempDir(), "gone.jsonl")); err == nil {
		t.Fatal("LastCostState read a file that does not exist")
	}
}

// The sum takes the messages from the start on, in the session and in its
// subagents, and counts a streamed message once, with its last usage.
func TestSinceSumsEachMessageOnce(t *testing.T) {
	since := time.Date(2026, 9, 25, 10, 0, 0, 0, time.UTC)
	got, err := Since(workPath(t), since)
	if err != nil {
		t.Fatal(err)
	}
	want := Usage{
		"claude-opus-5-5":           {InputTokens: 15, OutputTokens: 55, CacheReadTokens: 1000, CacheWriteTokens: 2000, CostUSD: cost(0.01736)},
		"claude-haiku-4-5-20251001": {InputTokens: 2, OutputTokens: 3, CacheWriteTokens: 100, CostUSD: cost(0.000142)},
	}
	assertUsage(t, got, want)
}

func TestSinceAfterTheLastMessageIsZero(t *testing.T) {
	got, err := Since(workPath(t), time.Date(2026, 9, 26, 0, 0, 0, 0, time.UTC))
	if err != nil || got == nil || len(got) != 0 {
		t.Fatalf("Since = %v, %v", got, err)
	}
}

func TestSincePricesAnUnknownModelAsUnknown(t *testing.T) {
	path := filepath.Join(t.TempDir(), workSession+".jsonl")
	line := `{"type":"assistant","timestamp":"2026-09-25T10:00:01Z","message":{"id":"m","model":"claude-future-9","usage":{"input_tokens":4,"output_tokens":5,"cache_read_input_tokens":6,"cache_creation_input_tokens":7}}}` + "\n"
	if err := os.WriteFile(path, []byte(line), 0o600); err != nil {
		t.Fatal(err)
	}
	got, err := Since(path, time.Time{})
	if err != nil {
		t.Fatal(err)
	}
	assertUsage(t, got, Usage{"claude-future-9": {InputTokens: 4, OutputTokens: 5, CacheReadTokens: 6, CacheWriteTokens: 7}})
}

func TestDecodeModelUsage(t *testing.T) {
	got, err := DecodeModelUsage(json.RawMessage(`{"claude-sonnet-5":{"inputTokens":1,"outputTokens":2,"cacheReadInputTokens":3,"cacheCreationInputTokens":4,"costUSD":0.25}}`))
	if err != nil {
		t.Fatal(err)
	}
	assertUsage(t, got, Usage{"claude-sonnet-5": {InputTokens: 1, OutputTokens: 2, CacheReadTokens: 3, CacheWriteTokens: 4, CostUSD: cost(0.25)}})

	got, err = DecodeModelUsage(json.RawMessage(`{}`))
	if err != nil || got == nil || len(got) != 0 {
		t.Fatalf("DecodeModelUsage of {} = %v, %v", got, err)
	}
	if _, err := DecodeModelUsage(json.RawMessage(`[1]`)); err == nil {
		t.Fatal("DecodeModelUsage took a list")
	}
}

// A resume reports the whole session, so the bridge takes the part before the
// resume away. A count never goes below zero, and a cost it cannot subtract is
// unknown.
func TestMinus(t *testing.T) {
	total := Usage{
		"a": {InputTokens: 10, OutputTokens: 20, CacheReadTokens: 30, CacheWriteTokens: 40, CostUSD: cost(1)},
		"b": {InputTokens: 5, OutputTokens: 5, CostUSD: cost(0.5)},
		"c": {InputTokens: 1, CostUSD: cost(0.1)},
		"d": {InputTokens: 3, OutputTokens: 3, CostUSD: cost(0.3)},
	}
	base := Usage{
		"a": {InputTokens: 4, OutputTokens: 25, CacheReadTokens: 10, CacheWriteTokens: 40, CostUSD: cost(0.25)},
		"b": {InputTokens: 5, OutputTokens: 5, CostUSD: cost(0.5)},
		"c": {},
		"e": {InputTokens: 9},
	}
	want := Usage{
		"a": {InputTokens: 6, CacheReadTokens: 20, CostUSD: cost(0.75)},
		"c": {InputTokens: 1},
		"d": {InputTokens: 3, OutputTokens: 3, CostUSD: cost(0.3)},
	}
	assertUsage(t, total.Minus(base), want)
	if got := (Usage{}).Minus(base); got == nil || len(got) != 0 {
		t.Fatalf("zero minus a baseline = %v", got)
	}
}

func TestCost(t *testing.T) {
	for model, want := range map[string]float64{
		"claude-opus-5-5":               4 + 20 + 0.2 + 4*1.25 + 4*2,
		"claude-opus-5-5[1m]":           4 + 20 + 0.2 + 4*1.25 + 4*2,
		"claude-opus-5":                 5 + 25 + 0.5 + 5*1.25 + 5*2,
		"claude-opus-5-20260401":        5 + 25 + 0.5 + 5*1.25 + 5*2,
		"claude-opus-4-8":               5 + 25 + 0.5 + 5*1.25 + 5*2,
		"claude-sonnet-5":               2 + 10 + 0.2 + 2*1.25 + 2*2,
		"claude-haiku-4-5-20251001":     1 + 5 + 0.1 + 1*1.25 + 1*2,
		"claude-fable-5-1":              10 + 50 + 0.25 + 10*1.25 + 10*2,
		"claude-fable-5":                10 + 50 + 1 + 10*1.25 + 10*2,
		"claude-haiku-4-5-20251001[1m]": 1 + 5 + 0.1 + 1*1.25 + 1*2,
	} {
		got := Cost(model, 1e6, 1e6, 1e6, 1e6, 1e6)
		if got == nil || math.Abs(*got-want) > 1e-9 {
			t.Fatalf("Cost(%q) = %v, want %v", model, got, want)
		}
	}
	for _, model := range []string{"claude-opus-5-7", "claude-opus", "claude-sonnet-5x", "claude-opus-5-5-1", "gpt", ""} {
		if got := Cost(model, 1, 1, 1, 1, 1); got != nil {
			t.Fatalf("Cost(%q) = %v, want unknown", model, *got)
		}
	}
}

func workPath(t *testing.T) string {
	t.Helper()
	path, err := Find("testdata", workSession)
	if err != nil {
		t.Fatal(err)
	}

	return path
}

func cost(v float64) *float64 { return &v }

func assertUsage(t *testing.T, got, want Usage) {
	t.Helper()
	if len(got) != len(want) {
		t.Fatalf("usage = %v, want %v", got, want)
	}
	for name, w := range want {
		g, ok := got[name]
		if !ok {
			t.Fatalf("usage has no %q: %v", name, got)
		}
		gc, wc := g.CostUSD, w.CostUSD
		g.CostUSD, w.CostUSD = nil, nil
		if g != w {
			t.Fatalf("usage[%q] = %+v, want %+v", name, g, w)
		}
		if (gc == nil) != (wc == nil) || (gc != nil && math.Abs(*gc-*wc) > 1e-12) {
			t.Fatalf("usage[%q] cost = %v, want %v", name, gc, wc)
		}
	}
}
