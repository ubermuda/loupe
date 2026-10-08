package openrouter

import (
	"context"
	"math"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sync/atomic"
	"testing"
	"time"
)

const fixture = `{"data":[
 {"id":"openai/gpt-6.1-sol-pro","pricing":{"prompt":"0.000002","completion":"0.00001","input_cache_read":"0.0000001",
   "overrides":[{"min_prompt_tokens":272000,"prompt":"0.000004","completion":"0.000015","input_cache_read":"0.0000002"},
   {"min_prompt_tokens":500000,"prompt":"0.000008"}]}},
 {"id":"openrouter/free","pricing":{"prompt":"0","completion":"0"}},
 {"id":"deepseek/deepseek-v4.1-flash","pricing":{"prompt":"0.0000000356","completion":"0.000001","input_cache_read":"0.00000001"}},
 {"id":"openrouter/auto","pricing":{"prompt":"-1","completion":"-1"}},
 {"id":"bad/text","pricing":{"prompt":"cheap","completion":"0.1"}},
 {"id":"bad/missing","pricing":{"prompt":"0.1"}},
 {"id":"bad/tier","pricing":{"prompt":"0.000001","completion":"0.000002","overrides":[{"min_prompt_tokens":100,"prompt":"x"}]}}
]}`

func near(t *testing.T, name string, got, want float64) {
	t.Helper()
	if math.Abs(got-want) > 1e-9 {
		t.Fatalf("%s = %v, want %v", name, got, want)
	}
}

func TestParseConvertsToDollarsPerMillionTokens(t *testing.T) {
	got, err := Parse([]byte(fixture))
	if err != nil {
		t.Fatal(err)
	}

	pro := got["openai/gpt-6.1-sol-pro"]
	near(t, "pro input", pro.Input, 2)
	near(t, "pro output", pro.Output, 10)
	near(t, "pro cache", pro.CacheRead, 0.1)
	if len(pro.Tiers) != 2 || pro.Tiers[0].MinPrompt != 272000 {
		t.Fatalf("tiers = %+v", pro.Tiers)
	}
	near(t, "tier input", pro.Tiers[0].Input, 4)
	near(t, "tier output", pro.Tiers[0].Output, 15)
	near(t, "tier cache", pro.Tiers[0].CacheRead, 0.2)
	near(t, "tier input without a cache price", pro.Tiers[1].Input, 8)
	near(t, "tier keeps the inherited cache price", pro.Tiers[1].CacheRead, 0.1)

	flash := got["deepseek/deepseek-v4.1-flash"]
	near(t, "flash input", flash.Input, 0.0356)

	free, ok := got["openrouter/free"]
	if !ok || free.Input != 0 || free.Output != 0 {
		t.Fatalf("free = %+v, %v; a zero price is a real price", free, ok)
	}
	near(t, "free cache", free.CacheRead, 0)

	for _, id := range []string{"openrouter/auto", "bad/text", "bad/missing"} {
		if _, ok := got[id]; ok {
			t.Fatalf("%s must be dropped", id)
		}
	}
	if tier := got["bad/tier"]; len(tier.Tiers) != 0 {
		t.Fatalf("a tier that does not parse must be dropped: %+v", tier.Tiers)
	}
}

func TestParseRefusesAnUndecodableReply(t *testing.T) {
	if _, err := Parse([]byte("<html>")); err == nil {
		t.Fatal("want an error")
	}
}

func server(t *testing.T, status int, body string) (*httptest.Server, *atomic.Int32) {
	t.Helper()
	var calls atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		calls.Add(1)
		w.WriteHeader(status)
		_, _ = w.Write([]byte(body))
	}))
	t.Cleanup(srv.Close)

	return srv, &calls
}

func TestLoadFetchesThenServesTheCacheForADay(t *testing.T) {
	srv, calls := server(t, 200, fixture)
	now := time.Date(2026, 10, 8, 12, 0, 0, 0, time.UTC)
	c := Client{HTTP: srv.Client(), URL: srv.URL, Dir: t.TempDir(), Now: func() time.Time { return now }}

	got, err := c.Load(context.Background())
	if err != nil || len(got) != 4 {
		t.Fatalf("Load = %d models, %v", len(got), err)
	}
	now = now.Add(23 * time.Hour)
	got, err = c.Load(context.Background())
	if err != nil || len(got) != 4 || calls.Load() != 1 {
		t.Fatalf("a fresh cache must not call the network: %d models, %v, %d calls", len(got), err, calls.Load())
	}
	near(t, "cached tier", got["openai/gpt-6.1-sol-pro"].Tiers[0].Input, 4)

	now = now.Add(2 * time.Hour)
	if _, err := c.Load(context.Background()); err != nil || calls.Load() != 2 {
		t.Fatalf("a stale cache must refetch: %v, %d calls", err, calls.Load())
	}
}

func TestLoadFallsBackToAnOlderCacheWhenTheFetchFails(t *testing.T) {
	good, _ := server(t, 200, fixture)
	bad, _ := server(t, 500, "down")
	dir := t.TempDir()
	now := time.Date(2026, 10, 8, 12, 0, 0, 0, time.UTC)
	clock := func() time.Time { return now }
	if _, err := (Client{HTTP: good.Client(), URL: good.URL, Dir: dir, Now: clock}).Load(context.Background()); err != nil {
		t.Fatal(err)
	}

	now = now.Add(48 * time.Hour)
	got, err := Client{HTTP: bad.Client(), URL: bad.URL, Dir: dir, Now: clock}.Load(context.Background())
	if err == nil || len(got) != 4 {
		t.Fatalf("want the old list and an error, got %d models, %v", len(got), err)
	}
}

func TestLoadReturnsNothingWhenTheFetchFailsWithNoCache(t *testing.T) {
	bad, _ := server(t, 500, "down")
	got, err := Client{HTTP: bad.Client(), URL: bad.URL, Dir: t.TempDir()}.Load(context.Background())
	if err == nil || got != nil {
		t.Fatalf("got %v, %v", got, err)
	}
}

func TestLoadIgnoresACorruptCacheAndAnEmptyList(t *testing.T) {
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, CacheFile), []byte("{"), 0o600); err != nil {
		t.Fatal(err)
	}
	empty, _ := server(t, 200, `{"data":[]}`)
	if got, err := (Client{HTTP: empty.Client(), URL: empty.URL, Dir: dir}).Load(context.Background()); err == nil || got != nil {
		t.Fatalf("an empty list must be an error: %v, %v", got, err)
	}
	ok, _ := server(t, 200, fixture)
	if got, err := (Client{HTTP: ok.Client(), URL: ok.URL, Dir: dir}).Load(context.Background()); err != nil || len(got) != 4 {
		t.Fatalf("got %d models, %v", len(got), err)
	}
}

func TestLoadTimesOutWithTheContext(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(_ http.ResponseWriter, r *http.Request) { <-r.Context().Done() }))
	t.Cleanup(srv.Close)
	ctx, cancel := context.WithTimeout(context.Background(), 50*time.Millisecond)
	defer cancel()
	if _, err := (Client{HTTP: srv.Client(), URL: srv.URL}).Load(ctx); err == nil {
		t.Fatal("want an error")
	}
}
