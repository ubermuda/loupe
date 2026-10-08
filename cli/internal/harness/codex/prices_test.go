package codex

import (
	"fmt"
	"math"
	"os"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/transcript"
)

// sessionWith writes a session of one model with two replies. The first reply
// has a prompt of 100 tokens and the second of 300.
func sessionWith(t *testing.T, model string) testHarness {
	t.Helper()
	h := newHarness(t, "")
	if err := h.remember(runID, thread); err != nil {
		t.Fatal(err)
	}
	path, _ := h.find(thread)
	count := func(at string, total, last int) string {
		return fmt.Sprintf(`{"timestamp":"%s","type":"event_msg","payload":{"type":"token_count","info":{"total_token_usage":{"input_tokens":%d,"cached_input_tokens":0,"output_tokens":%d},"last_token_usage":{"input_tokens":%d,"cached_input_tokens":0,"output_tokens":%d}}}}`, at, total, total/10, last, last/10)
	}
	lines := []string{
		`{"timestamp":"2026-10-08T11:26:47.656Z","type":"session_meta","payload":{"cwd":"/w","model_provider":"openrouter"}}`,
		`{"timestamp":"2026-10-08T11:26:48.276Z","type":"turn_context","payload":{"model":"` + model + `"}}`,
		count("2026-10-08T11:26:55.808Z", 100, 100),
		count("2026-10-08T11:28:06.535Z", 400, 300),
	}
	if err := os.WriteFile(path, []byte(strings.Join(lines, "\n")+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	return h
}

func withFetched(t *testing.T, list map[string]transcript.FetchedPrice) {
	t.Helper()
	transcript.SetFetchedPrices(list)
	t.Cleanup(func() { transcript.SetFetchedPrices(nil) })
}

func TestEachReplyIsPricedAtItsOwnTier(t *testing.T) {
	withFetched(t, map[string]transcript.FetchedPrice{"vendor/tiered": {
		Rates: transcript.Rates{Input: 1e6, Output: 2e6},
		Tiers: []transcript.Tier{{MinPrompt: 200, Rates: transcript.Rates{Input: 3e6, Output: 4e6}}},
	}})
	h := sessionWith(t, "vendor/tiered")

	total, err := h.SessionTotal(runID)
	if err != nil || total["vendor/tiered"].CostUSD == nil {
		t.Fatalf("total = %+v, %v", total, err)
	}
	// Reply one: 100 in, 10 out at the base tier. Reply two: 300 in, 30 out at the upper tier.
	want := float64(100*1+10*2) + float64(300*3+30*4)
	if got := *total["vendor/tiered"].CostUSD; math.Abs(got-want) > 1e-6 {
		t.Fatalf("cost = %v, want %v", got, want)
	}
	if total["vendor/tiered"].InputTokens != 400 {
		t.Fatalf("tokens = %+v", total["vendor/tiered"])
	}
}

func TestAModelWithNoPriceStaysUnknown(t *testing.T) {
	withFetched(t, map[string]transcript.FetchedPrice{"vendor/other": {}})
	total, err := sessionWith(t, "vendor/unknown").SessionTotal(runID)
	if err != nil || total["vendor/unknown"].CostUSD != nil {
		t.Fatalf("total = %+v, %v; the cost must be nil", total, err)
	}
}

func TestAFreeModelCostsZeroAndNotUnknown(t *testing.T) {
	withFetched(t, map[string]transcript.FetchedPrice{"vendor/free": {}})
	total, err := sessionWith(t, "vendor/free").SessionTotal(runID)
	if err != nil || total["vendor/free"].CostUSD == nil || *total["vendor/free"].CostUSD != 0 {
		t.Fatalf("total = %+v, %v; the cost must be 0", total, err)
	}
}

func TestOneUnpricedReplyKeepsTheModelCostUnknown(t *testing.T) {
	withFetched(t, nil)
	priced := tokens{Input: 100, Output: 10}.priceAt("gpt-5.5", 100)
	unpriced := tokens{Input: 100, Output: 10}.priceAt("vendor/gone", 100)

	if got := priced.plus(unpriced).model(); got.CostUSD != nil {
		t.Fatalf("cost = %v, want unknown when one reply has no price", *got.CostUSD)
	}
	if got := unpriced.plus(priced).model(); got.CostUSD != nil {
		t.Fatalf("cost = %v, want unknown in either order", *got.CostUSD)
	}
	if got := priced.plus(priced).model(); got.CostUSD == nil {
		t.Fatal("two priced replies must give a known cost")
	}
}
