package api

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
	"time"
)

// An outcome with no usage sends no usage key, which the server reads as
// unknown. An empty model list means the worker spent nothing.
func TestReportRunStateSendsTheUsageOfAnOutcome(t *testing.T) {
	outcome := stateReport(RunSucceeded)
	outcome.EndedAt = time.Date(2026, 9, 13, 10, 0, 26, 0, time.UTC)

	_, body, _, err := putState(t, outcome, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if _, ok := body["usage"]; ok {
		t.Fatalf("body = %v, want no usage key", body)
	}

	outcome.Usage = &Usage{Source: UsageReported}
	_, body, _, err = putState(t, outcome, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	if got, _ := json.Marshal(body["usage"]); string(got) != `{"models":{},"source":"reported"}` {
		t.Fatalf("usage = %s", got)
	}

	cost := 0.4321
	outcome.Usage = &Usage{Source: UsageEstimated, Models: map[string]ModelUsage{
		"claude-opus-5-5": {InputTokens: 1200, OutputTokens: 340, CacheReadTokens: 56000, CacheWriteTokens: 7800, CostUSD: &cost},
		"claude-future":   {InputTokens: 1},
	}}
	_, body, _, err = putState(t, outcome, http.StatusCreated)
	if err != nil {
		t.Fatal(err)
	}
	want := `{"models":{"claude-future":{"cacheReadTokens":0,"cacheWriteTokens":0,"costUsd":null,"inputTokens":1,"outputTokens":0},` +
		`"claude-opus-5-5":{"cacheReadTokens":56000,"cacheWriteTokens":7800,"costUsd":0.4321,"inputTokens":1200,"outputTokens":340}},"source":"estimated"}`
	if got, _ := json.Marshal(body["usage"]); string(got) != want {
		t.Fatalf("usage = %s", got)
	}
}

func TestUsageCheckKeepsToTheServersRules(t *testing.T) {
	cost := func(v float64) *float64 { return &v }
	many := map[string]ModelUsage{}
	for i := range maxUsageModels + 1 {
		many[fmt.Sprintf("m%d", i)] = ModelUsage{}
	}
	for name, tc := range map[string]struct {
		usage Usage
		ok    bool
	}{
		"empty":             {Usage{Source: UsageReported}, true},
		"one model":         {Usage{Source: UsageEstimated, Models: map[string]ModelUsage{"m": {InputTokens: 1, CostUSD: cost(999999.999999)}}}, true},
		"an unknown source": {Usage{Source: "guessed"}, false},
		"too many models":   {Usage{Source: UsageReported, Models: many}, false},
		"an empty name":     {Usage{Source: UsageReported, Models: map[string]ModelUsage{"": {}}}, false},
		"a long name":       {Usage{Source: UsageReported, Models: map[string]ModelUsage{strings.Repeat("é", 101): {}}}, false},
		"a name at the cap": {Usage{Source: UsageReported, Models: map[string]ModelUsage{strings.Repeat("é", 100): {}}}, true},
		"a negative count":  {Usage{Source: UsageReported, Models: map[string]ModelUsage{"m": {CacheWriteTokens: -1}}}, false},
		"a negative cost":   {Usage{Source: UsageReported, Models: map[string]ModelUsage{"m": {CostUSD: cost(-0.1)}}}, false},
		"a cost too large":  {Usage{Source: UsageReported, Models: map[string]ModelUsage{"m": {CostUSD: cost(1e6)}}}, false},
	} {
		t.Run(name, func(t *testing.T) {
			if err := tc.usage.Check(); (err == nil) != tc.ok {
				t.Fatalf("Check = %v, want ok %v", err, tc.ok)
			}
		})
	}
}
