package cmd

import (
	"context"
	"sync"
	"time"

	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/openrouter"
	"github.com/ubermuda/loupe/cli/internal/rules"
	"github.com/ubermuda/loupe/cli/internal/transcript"
)

// pricesInterval is the time between two refresh checks. A refresh skips the
// network while the cache is fresh.
const pricesInterval = time.Hour

// priceState is what the router keeps about the price refresh. mu serializes
// the refreshes.
type priceState struct {
	// fetch loads the list. A nil one reads the cache folder and openrouter.ai.
	fetch func(ctx context.Context) (openrouter.Prices, error)
	// after stands in for time.After in a test. A nil one uses the real one.
	after func(time.Duration) <-chan time.Time

	mu      sync.Mutex
	failing bool
	loaded  int
}

func defaultFetchPrices(ctx context.Context) (openrouter.Prices, error) {
	dir, err := config.Dir()
	if err != nil {
		return nil, err
	}

	return openrouter.Client{Dir: dir}.Load(ctx)
}

// loadPrices refreshes the fetched price table when the rule set asks for it.
// A failure leaves the cost unknown, or on an older list, and never stops the
// bridge. It logs one failure for each streak.
func (r *router) loadPrices(ctx context.Context, set *rules.Set) {
	if !set.OpenRouterPrices() {
		return
	}
	p := &r.prices
	p.mu.Lock()
	defer p.mu.Unlock()

	fetch := p.fetch
	if fetch == nil {
		fetch = defaultFetchPrices
	}
	list, err := fetch(ctx)
	if len(list) > 0 {
		transcript.SetFetchedPrices(list)
	}
	if err != nil {
		if !p.failing {
			r.log.Warn("openrouter_prices_failed", "error", err.Error())
		}
		p.failing = true

		return
	}
	if p.failing || len(list) != p.loaded {
		r.log.Info("openrouter_prices_loaded", "models", len(list))
	}
	p.failing, p.loaded = false, len(list)
}

// refreshPrices loads the prices off the calling goroutine.
func (r *router) refreshPrices(set *rules.Set) {
	if !set.OpenRouterPrices() {
		return
	}
	go r.loadPrices(r.workerContext(), set)
}

// priceLoop loads the prices at once and then each pricesInterval, until ctx
// ends. A reload that turns the key off stops the loads.
func (r *router) priceLoop(ctx context.Context) {
	after := r.prices.after
	if after == nil {
		after = time.After
	}
	for {
		r.loadPrices(ctx, r.rules())
		select {
		case <-ctx.Done():
			return
		case <-after(pricesInterval):
		}
	}
}
