package transcript

import (
	"strings"
)

// PricesRead is the day the Anthropic prices below were read from Anthropic's
// list prices, and OpenAIPricesRead the day the OpenAI prices were read from
// the short-context standard tier of OpenAI's price page.
const (
	PricesRead       = "2026-09-25"
	OpenAIPricesRead = "2026-10-08"
)

// rates are US dollars per million tokens.
type rates struct {
	input, output, cacheRead float64
}

// A cache write costs a multiple of the input price, by how long the cache
// lives.
const (
	write5m = 1.25
	write1h = 2.0
)

var prices = map[string]rates{
	"claude-fable-5-1": {10, 50, 0.25},
	"claude-fable-5":   {10, 50, 1},
	"claude-opus-5-5":  {4, 20, 0.20},
	"claude-opus-5":    {5, 25, 0.50},
	"claude-opus-4-8":  {5, 25, 0.50},
	"claude-sonnet-5":  {2, 10, 0.20},
	"claude-haiku-4-5": {1, 5, 0.10},

	// A model whose cached input is not offered is priced at its input rate.
	"gpt-6-astra":   {10, 50, 1},
	"gpt-6.1-sol":   {2, 10, 0.10},
	"gpt-6-luna":    {0.10, 0.50, 0.01},
	"gpt-6-sol":     {2, 10, 0.20},
	"gpt-5.6-sol":   {4, 20, 0.40},
	"gpt-5.6-terra": {2, 12, 0.20},
	"gpt-5.6-luna":  {0.20, 1.20, 0.02},
	"gpt-5.5":       {5, 30, 0.50},
	"gpt-5.5-pro":   {30, 180, 30},
	"gpt-5.4":       {2.5, 15, 0.25},
	"gpt-5.4-mini":  {0.75, 4.5, 0.075},
	"gpt-5.4-nano":  {0.20, 1.25, 0.02},
	"gpt-5.4-pro":   {30, 180, 30},
	"gpt-5.2":       {1.75, 14, 0.175},
	"gpt-5.2-pro":   {21, 168, 21},
	"gpt-5.1":       {1.25, 10, 0.125},
	"gpt-5":         {1.25, 10, 0.125},
	"gpt-5-mini":    {0.25, 2, 0.025},
	"gpt-5-nano":    {0.05, 0.40, 0.005},
	"gpt-5-pro":     {15, 120, 15},
	"gpt-5.3-codex": {1.75, 14, 0.175},
}

// Cost is the list price of the tokens, and nil for a model with no price.
func Cost(model string, input, output, cacheRead, cacheWrite5m, cacheWrite1h int64) *float64 {
	r, ok := prices[baseModel(model)]
	if !ok {
		return nil
	}
	cost := (float64(input)*r.input +
		float64(output)*r.output +
		float64(cacheRead)*r.cacheRead +
		float64(cacheWrite5m)*r.input*write5m +
		float64(cacheWrite1h)*r.input*write1h) / 1e6

	return &cost
}

// baseModel drops the suffixes Claude Code adds to a model id: a context size
// such as [1m], and a snapshot date such as -20251001.
func baseModel(model string) string {
	if i := strings.IndexByte(model, '['); i >= 0 && strings.HasSuffix(model, "]") {
		model = model[:i]
	}
	if i := strings.LastIndexByte(model, '-'); i >= 0 && isDate(model[i+1:]) {
		model = model[:i]
	}

	return model
}

func isDate(s string) bool {
	if len(s) != 8 {
		return false
	}
	for _, c := range s {
		if c < '0' || c > '9' {
			return false
		}
	}

	return true
}
