// Package openrouter fetches the model price list of openrouter.ai and keeps a
// copy in the config folder.
package openrouter

import (
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math"
	"net/http"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"time"

	"github.com/ubermuda/loupe/cli/internal/transcript"
)

// URL is the public model list. It needs no key.
const URL = "https://openrouter.ai/api/v1/models"

// Timeout bounds one fetch. MaxAge is how long a cached list stands in for a
// fetch.
const (
	Timeout = 10 * time.Second
	MaxAge  = 24 * time.Hour
)

// CacheFile is the name of the cache in the config folder.
const CacheFile = "openrouter-prices.json"

// maxBody bounds the reply the bridge reads.
const maxBody = 16 << 20

// Prices maps a model id to its price.
type Prices = map[string]transcript.FetchedPrice

// Client loads the price list. The zero value of HTTP, URL and Now takes the
// real ones.
type Client struct {
	HTTP *http.Client
	URL  string
	// Dir holds the cache file. An empty Dir keeps no cache.
	Dir string
	Now func() time.Time
}

type cache struct {
	FetchedAt time.Time `json:"fetchedAt"`
	Prices    Prices    `json:"prices"`
}

// Load returns the price list. A cache younger than MaxAge answers without a
// network call. When the fetch fails, Load returns the older cache and the
// error. With no cache it returns a nil list and the error.
func (c Client) Load(ctx context.Context) (Prices, error) {
	now := time.Now
	if c.Now != nil {
		now = c.Now
	}
	old, haveOld := c.read()
	if haveOld && now().Sub(old.FetchedAt) < MaxAge && now().After(old.FetchedAt) {
		return old.Prices, nil
	}

	prices, err := c.fetch(ctx)
	if err != nil {
		if haveOld {
			return old.Prices, err
		}

		return nil, err
	}
	if err := c.write(cache{FetchedAt: now(), Prices: prices}); err != nil {
		return prices, fmt.Errorf("write the price cache: %w", err)
	}

	return prices, nil
}

func (c Client) read() (cache, bool) {
	if c.Dir == "" {
		return cache{}, false
	}
	b, err := os.ReadFile(filepath.Join(c.Dir, CacheFile))
	if err != nil {
		return cache{}, false
	}
	var got cache
	if json.Unmarshal(b, &got) != nil || got.FetchedAt.IsZero() || got.Prices == nil {
		return cache{}, false
	}

	return got, true
}

func (c Client) write(v cache) error {
	if c.Dir == "" {
		return nil
	}
	if err := os.MkdirAll(c.Dir, 0o700); err != nil {
		return err
	}
	b, err := json.Marshal(v)
	if err != nil {
		return err
	}
	tmp, err := os.CreateTemp(c.Dir, CacheFile+".*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(b); err != nil {
		tmp.Close()

		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}

	return os.Rename(tmp.Name(), filepath.Join(c.Dir, CacheFile))
}

func (c Client) fetch(ctx context.Context) (Prices, error) {
	ctx, cancel := context.WithTimeout(ctx, Timeout)
	defer cancel()
	url := c.URL
	if url == "" {
		url = URL
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Accept", "application/json")
	client := c.HTTP
	if client == nil {
		client = http.DefaultClient
	}
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("openrouter answered %s", resp.Status)
	}
	body, err := io.ReadAll(io.LimitReader(resp.Body, maxBody))
	if err != nil {
		return nil, err
	}
	prices, err := Parse(body)
	if err != nil {
		return nil, err
	}
	if len(prices) == 0 {
		return nil, errors.New("openrouter listed no model with a price")
	}

	return prices, nil
}

type wirePrice struct {
	Prompt     *string `json:"prompt"`
	Completion *string `json:"completion"`
	CacheRead  *string `json:"input_cache_read"`
}

// Parse reads the model list. It drops a model whose prices do not parse.
func Parse(body []byte) (Prices, error) {
	var doc struct {
		Data []struct {
			ID      string `json:"id"`
			Pricing struct {
				wirePrice
				Overrides []struct {
					MinPromptTokens int64 `json:"min_prompt_tokens"`
					wirePrice
				} `json:"overrides"`
			} `json:"pricing"`
		} `json:"data"`
	}
	if err := json.Unmarshal(body, &doc); err != nil {
		return nil, fmt.Errorf("decode the openrouter list: %w", err)
	}

	out := Prices{}
	for _, m := range doc.Data {
		base, ok := rates(m.Pricing.wirePrice, nil)
		if m.ID == "" || !ok {
			continue
		}
		price := transcript.FetchedPrice{Rates: base}
		for _, o := range m.Pricing.Overrides {
			tier, ok := rates(o.wirePrice, &base)
			if !ok || o.MinPromptTokens <= 0 {
				continue
			}
			price.Tiers = append(price.Tiers, transcript.Tier{MinPrompt: o.MinPromptTokens, Rates: tier})
		}
		slices.SortFunc(price.Tiers, func(a, b transcript.Tier) int { return cmp.Compare(a.MinPrompt, b.MinPrompt) })
		out[m.ID] = price
	}

	return out, nil
}

// rates converts per-token dollars to dollars per million tokens. A missing
// cache read price is the input price. A tier takes a missing field from
// inherit. A field that does not parse, or a negative one, fails the whole set.
func rates(w wirePrice, inherit *transcript.Rates) (transcript.Rates, bool) {
	var r transcript.Rates
	if inherit != nil {
		r = *inherit
	}
	fields := []struct {
		raw *string
		dst *float64
	}{{w.Prompt, &r.Input}, {w.Completion, &r.Output}, {w.CacheRead, &r.CacheRead}}
	for _, f := range fields {
		if f.raw == nil {
			continue
		}
		v, err := strconv.ParseFloat(*f.raw, 64)
		if err != nil || math.IsNaN(v) || math.IsInf(v, 0) || v < 0 {
			return transcript.Rates{}, false
		}
		*f.dst = v * 1e6
	}
	if inherit == nil && (w.Prompt == nil || w.Completion == nil) {
		return transcript.Rates{}, false
	}
	if w.CacheRead == nil && inherit == nil {
		r.CacheRead = r.Input
	}

	return r, true
}
