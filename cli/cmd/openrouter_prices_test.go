package cmd

import (
	"bytes"
	"context"
	"errors"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/openrouter"
	"github.com/ubermuda/loupe/cli/internal/rules"
	"github.com/ubermuda/loupe/cli/internal/transcript"
)

func priceSet(t *testing.T, key string) *rules.Set {
	t.Helper()
	body := key + "accounts:\n  claude:\n    harness: claude-code\ndefaults:\n  account: claude\nprojects:\n  loupe:\n    dir: " + t.TempDir() + "\nwork:\n  plan: {prompt: go}\n"
	set, err := rules.Parse([]byte(body), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}

	return set
}

func priceRouter(t *testing.T, fetch func(context.Context) (openrouter.Prices, error)) (*router, *bytes.Buffer) {
	t.Helper()
	transcript.SetFetchedPrices(nil)
	t.Cleanup(func() { transcript.SetFetchedPrices(nil) })
	var buf bytes.Buffer
	r := &router{log: debugLogger(&buf)}
	r.prices.fetch = fetch

	return r, &buf
}

func TestOpenRouterPricesAreOffUnlessTheKeyIsOn(t *testing.T) {
	var calls atomic.Int32
	r, buf := priceRouter(t, func(context.Context) (openrouter.Prices, error) {
		calls.Add(1)

		return openrouter.Prices{"a/b": {}}, nil
	})
	r.loadPrices(context.Background(), priceSet(t, ""))
	r.loadPrices(context.Background(), priceSet(t, "openRouterPrices: false\n"))

	if calls.Load() != 0 || buf.Len() != 0 || transcript.Cost("a/b", 1, 1, 0, 0, 0) != nil {
		t.Fatalf("calls = %d, log = %q", calls.Load(), buf)
	}
}

func TestOpenRouterPricesFillTheTableAndLogTheCount(t *testing.T) {
	r, buf := priceRouter(t, func(context.Context) (openrouter.Prices, error) {
		return openrouter.Prices{"a/b": {Rates: transcript.Rates{Input: 1}}}, nil
	})
	set := priceSet(t, "openRouterPrices: true\n")
	r.loadPrices(context.Background(), set)
	r.loadPrices(context.Background(), set)

	if got := transcript.Cost("a/b", 1e6, 0, 0, 0, 0); got == nil || *got != 1 {
		t.Fatalf("Cost = %v", got)
	}
	if n := strings.Count(buf.String(), "openrouter_prices_loaded"); n != 1 || !strings.Contains(buf.String(), `"models":1`) {
		t.Fatalf("log = %s", buf)
	}
}

func TestOpenRouterPricesLogOneFailureForEachStreak(t *testing.T) {
	var fail atomic.Bool
	fail.Store(true)
	r, buf := priceRouter(t, func(context.Context) (openrouter.Prices, error) {
		if fail.Load() {
			return nil, errors.New("no egress")
		}

		return openrouter.Prices{"a/b": {}}, nil
	})
	set := priceSet(t, "openRouterPrices: true\n")
	r.loadPrices(context.Background(), set)
	r.loadPrices(context.Background(), set)
	if n := strings.Count(buf.String(), "openrouter_prices_failed"); n != 1 || !strings.Contains(buf.String(), "no egress") {
		t.Fatalf("log = %s", buf)
	}
	if transcript.Cost("a/b", 1, 1, 0, 0, 0) != nil {
		t.Fatal("a failed fetch must leave the cost unknown")
	}

	fail.Store(false)
	r.loadPrices(context.Background(), set)
	fail.Store(true)
	r.loadPrices(context.Background(), set)
	if n := strings.Count(buf.String(), "openrouter_prices_failed"); n != 2 {
		t.Fatalf("a new streak must log again: %s", buf)
	}
}

func TestOpenRouterPricesUseAnOlderListWhenTheFetchFails(t *testing.T) {
	r, _ := priceRouter(t, func(context.Context) (openrouter.Prices, error) {
		return openrouter.Prices{"a/b": {}}, errors.New("down")
	})
	r.loadPrices(context.Background(), priceSet(t, "openRouterPrices: true\n"))
	if transcript.Cost("a/b", 1, 1, 0, 0, 0) == nil {
		t.Fatal("the older list must price the model")
	}
}

func TestPriceLoopRefreshesOnEachTickUntilTheContextEnds(t *testing.T) {
	var calls atomic.Int32
	r, _ := priceRouter(t, func(context.Context) (openrouter.Prices, error) {
		calls.Add(1)

		return openrouter.Prices{"a/b": {}}, nil
	})
	r.set.Store(priceSet(t, "openRouterPrices: true\n"))
	ticks := make(chan time.Time)
	r.prices.after = func(d time.Duration) <-chan time.Time {
		if d != pricesInterval {
			t.Errorf("interval = %v", d)
		}

		return ticks
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { r.priceLoop(ctx); close(done) }()

	ticks <- time.Time{}
	ticks <- time.Time{}
	cancel()
	select {
	case <-done:
	case <-time.After(5 * time.Second):
		t.Fatal("the loop did not stop")
	}
	if calls.Load() != 3 {
		t.Fatalf("calls = %d, want 3", calls.Load())
	}
}

func TestOpenRouterPricesEmptyWhenAReloadTurnsTheKeyOff(t *testing.T) {
	r, _ := priceRouter(t, func(context.Context) (openrouter.Prices, error) {
		return openrouter.Prices{"a/b": {Rates: transcript.Rates{Input: 1}}}, nil
	})
	on := priceSet(t, "openRouterPrices: true\n")
	r.loadPrices(context.Background(), on)
	if transcript.Cost("a/b", 1, 1, 0, 0, 0) == nil {
		t.Fatal("the table must hold a/b while the key is on")
	}

	r.loadPrices(context.Background(), priceSet(t, "openRouterPrices: false\n"))
	if transcript.Cost("a/b", 1, 1, 0, 0, 0) != nil {
		t.Fatal("the table must be empty once the key is off")
	}
}

func TestOpenRouterPricesIgnoreAFetchThatEndsAfterTheKeyTurnedOff(t *testing.T) {
	var r *router
	r, _ = priceRouter(t, func(context.Context) (openrouter.Prices, error) {
		r.set.Store(priceSet(t, "openRouterPrices: false\n"))

		return openrouter.Prices{"a/b": {Rates: transcript.Rates{Input: 1}}}, nil
	})
	r.loadPrices(context.Background(), priceSet(t, "openRouterPrices: true\n"))

	if transcript.Cost("a/b", 1, 1, 0, 0, 0) != nil {
		t.Fatal("a late fetch must not restore the table")
	}
}
