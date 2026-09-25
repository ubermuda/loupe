package update

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
)

// GitHubAPI is the default base of the GitHub REST API.
const GitHubAPI = "https://api.github.com"

const (
	userAgent = "loupe-cli"
	// maxReleases caps the releases list. A page of 100 releases is far smaller.
	maxReleases = 10 << 20
	// maxDownload caps an archive or a checksums file.
	maxDownload = 200 << 20
)

// FetchReleases reads the newest page of releases of the Loupe repository.
func FetchReleases(ctx context.Context, hc *http.Client, apiBase string) ([]Release, error) {
	data, err := download(ctx, hc, apiBase+"/repos/ubermuda/loupe/releases?per_page=100", "application/vnd.github+json", maxReleases)
	if err != nil {
		return nil, fmt.Errorf("list the releases: %w", err)
	}
	var releases []Release
	if err := json.Unmarshal(data, &releases); err != nil {
		return nil, fmt.Errorf("read the releases: %w", err)
	}

	return releases, nil
}

// Download reads one release asset.
func Download(ctx context.Context, hc *http.Client, url string) ([]byte, error) {
	return download(ctx, hc, url, "", maxDownload)
}

func download(ctx context.Context, hc *http.Client, url, accept string, limit int64) ([]byte, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("User-Agent", userAgent)
	if accept != "" {
		req.Header.Set("Accept", accept)
	}
	resp, err := hc.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		return nil, fmt.Errorf("GET %s: HTTP %d", url, resp.StatusCode)
	}
	data, err := io.ReadAll(io.LimitReader(resp.Body, limit+1))
	if err != nil {
		return nil, fmt.Errorf("GET %s: %w", url, err)
	}
	if int64(len(data)) > limit {
		return nil, fmt.Errorf("GET %s: the body is larger than %d bytes", url, limit)
	}

	return data, nil
}
