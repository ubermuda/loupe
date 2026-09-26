package cmd

import (
	"math"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/transcript"
)

func ptr[T any](v T) *T { return &v }

// claudeHome points CLAUDE_CONFIG_DIR at a new directory, and writes the
// transcript lines of session there when lines are given.
func claudeHome(t *testing.T, session string, lines ...string) string {
	t.Helper()
	dir := t.TempDir()
	t.Setenv("CLAUDE_CONFIG_DIR", dir)
	if len(lines) > 0 {
		writeTranscript(t, dir, session, lines...)
	}

	return dir
}

func writeTranscript(t *testing.T, dir, session string, lines ...string) {
	t.Helper()
	path := filepath.Join(dir, "projects", "-work", session+".jsonl")
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(strings.Join(lines, "\n")+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
}

const (
	costState = `{"type":"cost-state","modelUsage":{"claude-opus-5-5":{"inputTokens":100,"outputTokens":10,"cacheReadInputTokens":1000,"cacheCreationInputTokens":50,"costUSD":1.25}}}`
	// Two entries of one streamed message, then a second message.
	streamed1 = `{"type":"assistant","timestamp":"2099-01-01T00:00:01Z","message":{"id":"m1","model":"claude-opus-5-5","usage":{"input_tokens":10,"output_tokens":1,"cache_read_input_tokens":0,"cache_creation_input_tokens":0}}}`
	streamed2 = `{"type":"assistant","timestamp":"2099-01-01T00:00:02Z","message":{"id":"m1","model":"claude-opus-5-5","usage":{"input_tokens":10,"output_tokens":100,"cache_read_input_tokens":0,"cache_creation_input_tokens":0}}}`
	second    = `{"type":"assistant","timestamp":"2099-01-01T00:00:03Z","message":{"id":"m2","model":"claude-opus-5-5","usage":{"input_tokens":5,"output_tokens":5,"cache_read_input_tokens":0,"cache_creation_input_tokens":0}}}`
	early     = `{"type":"assistant","timestamp":"2000-01-01T00:00:00Z","message":{"id":"m0","model":"claude-opus-5-5","usage":{"input_tokens":999,"output_tokens":999,"cache_read_input_tokens":0,"cache_creation_input_tokens":0}}}`
)

// the sum of streamed2 and second, at 4 and 20 dollars per million tokens.
var estimated = &api.Usage{Source: api.UsageEstimated, Models: map[string]api.ModelUsage{
	"claude-opus-5-5": {InputTokens: 15, OutputTokens: 105, CostUSD: ptr((15*4.0 + 105*20.0) / 1e6)},
}}

func TestWorkerUsage(t *testing.T) {
	reported := transcript.Usage{"claude-opus-5-5": {InputTokens: 150, OutputTokens: 30, CacheReadTokens: 1500, CacheWriteTokens: 50, CostUSD: ptr(2.0)}}
	baseline := transcript.Usage{"claude-opus-5-5": {InputTokens: 100, OutputTokens: 10, CacheReadTokens: 1000, CacheWriteTokens: 50, CostUSD: ptr(1.25)}}
	started := time.Date(2098, 1, 1, 0, 0, 0, 0, time.UTC)

	for name, tc := range map[string]struct {
		rec      runRecord
		reported transcript.Usage
		lines    []string
		want     *api.Usage
	}{
		"a new session reports its own usage": {
			rec:      runRecord{SessionID: testSession, StartedAt: started},
			reported: reported,
			want: &api.Usage{Source: api.UsageReported, Models: map[string]api.ModelUsage{
				"claude-opus-5-5": {InputTokens: 150, OutputTokens: 30, CacheReadTokens: 1500, CacheWriteTokens: 50, CostUSD: ptr(2.0)},
			}},
		},
		"a resume takes its baseline away": {
			rec:      runRecord{SessionID: testSession, StartedAt: started, Resume: true, Baseline: &baseline},
			reported: reported,
			want: &api.Usage{Source: api.UsageReported, Models: map[string]api.ModelUsage{
				"claude-opus-5-5": {InputTokens: 50, OutputTokens: 20, CacheReadTokens: 500, CostUSD: ptr(0.75)},
			}},
		},
		"a resume that spent nothing sends an empty list": {
			rec:      runRecord{SessionID: testSession, StartedAt: started, Resume: true, Baseline: &baseline},
			reported: baseline,
			want:     &api.Usage{Source: api.UsageReported, Models: map[string]api.ModelUsage{}},
		},
		"a resume with no baseline sends the session as an estimate": {
			rec:      runRecord{SessionID: testSession, StartedAt: started, Resume: true},
			reported: reported,
			want: &api.Usage{Source: api.UsageEstimated, Models: map[string]api.ModelUsage{
				"claude-opus-5-5": {InputTokens: 150, OutputTokens: 30, CacheReadTokens: 1500, CacheWriteTokens: 50, CostUSD: ptr(2.0)},
			}},
		},
		"a process with no usage of its own is counted from the transcript": {
			rec:   runRecord{SessionID: testSession, StartedAt: started, Resume: true},
			lines: []string{early, costState, streamed1, streamed2, second, `{"type":"assistant","timestamp":"2099-01`},
			want:  estimated,
		},
		"a process with no transcript has unknown usage": {
			rec: runRecord{SessionID: testSession, StartedAt: started},
		},
		"a record with no start counts nothing": {
			rec:   runRecord{SessionID: testSession},
			lines: []string{streamed2},
		},
	} {
		t.Run(name, func(t *testing.T) {
			claudeHome(t, testSession, tc.lines...)
			got := workerUsage(tc.rec, tc.reported)
			if !sameUsage(got, tc.want) {
				t.Fatalf("workerUsage = %s, want %s", usageText(got), usageText(tc.want))
			}
		})
	}
}

func TestSessionBaseline(t *testing.T) {
	claudeHome(t, testSession, early, costState, streamed2)
	got := sessionBaseline(testSession)
	want := transcript.Usage{"claude-opus-5-5": {InputTokens: 100, OutputTokens: 10, CacheReadTokens: 1000, CacheWriteTokens: 50, CostUSD: ptr(1.25)}}
	if got == nil || !reflect.DeepEqual(*got, want) {
		t.Fatalf("sessionBaseline = %v", got)
	}

	// A session with no cost-state line spent nothing that claude carries over.
	claudeHome(t, testSession, streamed2)
	if got := sessionBaseline(testSession); got == nil || len(*got) != 0 {
		t.Fatalf("sessionBaseline = %v, want zero", got)
	}

	claudeHome(t, testSession)
	if got := sessionBaseline(testSession); got != nil {
		t.Fatalf("sessionBaseline of a missing session = %v, want nil", *got)
	}
}

// The usage rides the outcome of a run that ran. The bridge drops a usage the
// server refuses, because the server would refuse the whole outcome with it.
func TestTheOutcomeCarriesTheUsage(t *testing.T) {
	long := strings.Repeat("m", 101)
	for name, tc := range map[string]struct {
		usage   *api.Usage
		want    bool
		dropped bool
	}{
		"none":    {nil, false, false},
		"zero":    {&api.Usage{Source: api.UsageReported}, true, false},
		"refused": {&api.Usage{Source: api.UsageReported, Models: map[string]api.ModelUsage{long: {}}}, false, true},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarness(t)
			rec := h.states()
			h.worker.result = workerResult{hasResult: true, status: "finished", usage: tc.usage}

			h.send(cardMoved(87))

			sent := rec.states()
			got := outcomeOf(t, sent, runIDs(sent)[0])
			if (got.Usage != nil) != tc.want {
				t.Fatalf("usage = %v", got.Usage)
			}
			if lines := h.events(t, "usage_dropped"); (len(lines) == 1) != tc.dropped {
				t.Fatalf("usage_dropped = %v", lines)
			}
		})
	}
}

// sameUsage compares two usages, with costs equal to a rounding error.
func sameUsage(a, b *api.Usage) bool {
	if a == nil || b == nil {
		return a == b
	}
	if a.Source != b.Source || len(a.Models) != len(b.Models) || (a.Models == nil) != (b.Models == nil) {
		return false
	}
	for name, m := range a.Models {
		n, ok := b.Models[name]
		if !ok || (m.CostUSD == nil) != (n.CostUSD == nil) {
			return false
		}
		if m.CostUSD != nil && math.Abs(*m.CostUSD-*n.CostUSD) > 1e-12 {
			return false
		}
		m.CostUSD, n.CostUSD = nil, nil
		if m != n {
			return false
		}
	}

	return true
}

func usageText(u *api.Usage) string {
	if u == nil {
		return "<nil>"
	}
	b, _ := u.MarshalJSON()

	return string(b)
}
