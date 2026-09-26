package api

import (
	"encoding/json"
	"fmt"
	"unicode/utf8"
)

// The sources of a usage report.
const (
	UsageReported  = "reported"
	UsageEstimated = "estimated"
)

// The server's limits on a usage report.
const (
	maxUsageModels    = 20
	maxUsageModelName = 100
	maxUsageCost      = 999999.999999
)

// Usage is the tokens one worker process spent, by model. An empty model list
// means it spent nothing.
type Usage struct {
	Source string                `json:"source"`
	Models map[string]ModelUsage `json:"models"`
}

// ModelUsage is the tokens one model spent. A nil cost is a model the bridge
// knows no price for.
type ModelUsage struct {
	InputTokens      int64    `json:"inputTokens"`
	OutputTokens     int64    `json:"outputTokens"`
	CacheReadTokens  int64    `json:"cacheReadTokens"`
	CacheWriteTokens int64    `json:"cacheWriteTokens"`
	CostUSD          *float64 `json:"costUsd"`
}

// MarshalJSON sends a nil model list as {}, because the server reads null as
// a malformed report.
func (u Usage) MarshalJSON() ([]byte, error) {
	type plain Usage
	if u.Models == nil {
		u.Models = map[string]ModelUsage{}
	}

	return json.Marshal(plain(u))
}

// Check reports whether the server takes the usage. The server refuses the
// whole outcome for a usage it does not take.
func (u Usage) Check() error {
	if u.Source != UsageReported && u.Source != UsageEstimated {
		return fmt.Errorf("the usage source %q is neither reported nor estimated", u.Source)
	}
	if len(u.Models) > maxUsageModels {
		return fmt.Errorf("the usage names %d models, and Loupe takes at most %d", len(u.Models), maxUsageModels)
	}
	for name, m := range u.Models {
		if n := utf8.RuneCountInString(name); n < 1 || n > maxUsageModelName {
			return fmt.Errorf("the model name %q is not 1 to %d characters", name, maxUsageModelName)
		}
		if m.InputTokens < 0 || m.OutputTokens < 0 || m.CacheReadTokens < 0 || m.CacheWriteTokens < 0 {
			return fmt.Errorf("the usage of %s holds a negative count", name)
		}
		if m.CostUSD != nil && (*m.CostUSD < 0 || *m.CostUSD > maxUsageCost) {
			return fmt.Errorf("the usage of %s holds a cost out of range", name)
		}
	}

	return nil
}
