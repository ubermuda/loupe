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
	// maxReleases caps one page of the releases list. A page of 100 is far smaller.
	maxReleases = 10 << 20
	perPage     = 100
	maxPages    = 10
	// maxDownload caps an archive or a checksums file.
	maxDownload = 200 << 20
)

// FetchReleases reads the releases of the Loupe repository, newest first. Server
// releases share the list, so it reads every page, up to maxPages.
func FetchReleases(ctx context.Context, hc *http.Client, apiBase string) ([]Release, error) {
	var releases []Release
	for page := 1; page <= maxPages; page++ {
		url := fmt.Sprintf("%s/repos/ubermuda/loupe/releases?per_page=%d&page=%d", apiBase, perPage, page)
		data, err := download(ctx, hc, url, "application/vnd.github+json", maxReleases)
		if err != nil {
			return nil, fmt.Errorf("list the releases: %w", err)
		}
		var batch []Release
		if err := json.Unmarshal(data, &batch); err != nil {
			return nil, fmt.Errorf("read the releases: %w", err)
		}
		releases = append(releases, batch...)
		if len(batch) < perPage {
			break
		}
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
