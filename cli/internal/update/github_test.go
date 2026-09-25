package update

import (
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestFetchReleasesReadsTheReleasesList(t *testing.T) {
	var path, query, accept, agent string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		path, query, accept, agent = r.URL.Path, r.URL.RawQuery, r.Header.Get("Accept"), r.Header.Get("User-Agent")
		_, _ = io.WriteString(w, `[{"tag_name":"v1.2.0","assets":[{"name":"checksums.txt","browser_download_url":"https://x/c"}]}]`)
	}))
	t.Cleanup(server.Close)

	releases, err := FetchReleases(context.Background(), server.Client(), server.URL)
	if err != nil {
		t.Fatal(err)
	}
	if path != "/repos/ubermuda/loupe/releases" || query != "per_page=100" || accept != "application/vnd.github+json" || agent == "" {
		t.Fatalf("path = %q, query = %q, accept = %q, user agent = %q", path, query, accept, agent)
	}
	if len(releases) != 1 || releases[0].TagName != "v1.2.0" || releases[0].Assets[0].URL != "https://x/c" {
		t.Fatalf("releases = %+v", releases)
	}
}

func TestFetchReleasesNamesAFailedStatus(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusForbidden)
	}))
	t.Cleanup(server.Close)

	if _, err := FetchReleases(context.Background(), server.Client(), server.URL); err == nil || !strings.Contains(err.Error(), "403") {
		t.Fatalf("err = %v", err)
	}
}

func TestDownloadReturnsTheBody(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = io.WriteString(w, "archive bytes")
	}))
	t.Cleanup(server.Close)

	got, err := Download(context.Background(), server.Client(), server.URL+"/a.tar.gz")
	if err != nil || string(got) != "archive bytes" {
		t.Fatalf("got %q, err = %v", got, err)
	}
}

func TestDownloadRefusesAFailedStatus(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusNotFound)
	}))
	t.Cleanup(server.Close)

	if _, err := Download(context.Background(), server.Client(), server.URL); err == nil || !strings.Contains(err.Error(), "404") {
		t.Fatalf("err = %v", err)
	}
}

func TestDownloadRefusesABodyPastTheCap(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = io.WriteString(w, "0123456789")
	}))
	t.Cleanup(server.Close)

	if _, err := download(context.Background(), server.Client(), server.URL, "", 9); err == nil {
		t.Fatal("a body past the cap was accepted")
	}
	if got, err := download(context.Background(), server.Client(), server.URL, "", 10); err != nil || len(got) != 10 {
		t.Fatalf("a body at the cap: %q, %v", got, err)
	}
}
