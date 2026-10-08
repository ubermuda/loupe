package transcript

import "testing"

func setFetched(t *testing.T, list map[string]FetchedPrice) {
	t.Helper()
	SetFetchedPrices(list)
	t.Cleanup(func() { SetFetchedPrices(nil) })
}

func TestCostUsesTheFetchedListByExactID(t *testing.T) {
	setFetched(t, map[string]FetchedPrice{
		"vendor/model": {Rates: Rates{Input: 2, Output: 10, CacheRead: 0.5}},
		"vendor/free":  {},
	})
	got := Cost("vendor/model", 1e6, 1e6, 1e6, 0, 0)
	if got == nil || *got != 12.5 {
		t.Fatalf("Cost = %v, want 12.5", got)
	}
	if free := Cost("vendor/free", 1e6, 1e6, 0, 0, 0); free == nil || *free != 0 {
		t.Fatalf("free Cost = %v, want 0", free)
	}
	if Cost("vendor/model-20251001", 1, 1, 0, 0, 0) != nil || Cost("vendor/other", 1, 1, 0, 0, 0) != nil {
		t.Fatal("a model outside the list must stay unpriced")
	}
}

func TestBuiltInPricesBeatTheFetchedList(t *testing.T) {
	setFetched(t, map[string]FetchedPrice{"gpt-5": {Rates: Rates{Input: 99}}})
	if got := Cost("gpt-5", 1e6, 0, 0, 0, 0); got == nil || *got != 1.25 {
		t.Fatalf("Cost = %v, want 1.25", got)
	}
}

func TestCostPromptPicksTheHighestTierTheReplyReaches(t *testing.T) {
	setFetched(t, map[string]FetchedPrice{"vendor/tiered": {
		Rates: Rates{Input: 2, Output: 10, CacheRead: 0.1},
		Tiers: []Tier{
			{MinPrompt: 500, Rates: Rates{Input: 8, Output: 30, CacheRead: 0.8}},
			{MinPrompt: 200, Rates: Rates{Input: 4, Output: 15, CacheRead: 0.2}},
		},
	}})
	for name, tc := range map[string]struct {
		prompt int64
		want   float64
	}{
		"below every tier":  {199, 2},
		"at the first tier": {200, 4},
		"between tiers":     {499, 4},
		"at the top tier":   {500, 8},
	} {
		got := CostPrompt("vendor/tiered", tc.prompt, 1e6, 0, 0, 0, 0)
		if got == nil || *got != tc.want {
			t.Fatalf("%s: CostPrompt = %v, want %v", name, got, tc.want)
		}
	}
	if got := Cost("vendor/tiered", 1e6, 0, 0, 0, 0); got == nil || *got != 2 {
		t.Fatalf("Cost = %v, want the base price 2", got)
	}
}
