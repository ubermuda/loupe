package api

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"slices"
	"strings"
	"testing"
)

func TestSitesCallsTheProjectsRoute(t *testing.T) {
	var gotPath, gotAuth string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath, gotAuth = r.URL.EscapedPath(), r.Header.Get("Authorization")
		fmt.Fprint(w, `{"sites":[{"id":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7","name":"Loupe"}]}`)
	}))
	t.Cleanup(server.Close)

	got, err := New(server.URL, "secret", server.Client()).Sites(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if gotPath != "/api/projects" || gotAuth != "Bearer secret" {
		t.Fatalf("path = %q, auth = %q", gotPath, gotAuth)
	}
	if len(got) != 1 || got[0].Name != "Loupe" {
		t.Fatalf("sites = %+v", got)
	}
}

// A project with no slug yet sends null, which decodes as "".
func TestSitesCarryTheirSlug(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		fmt.Fprint(w, `{"sites":[{"id":"a","slug":"loupe","name":"Loupe"},{"id":"b","slug":null,"name":"Old"}]}`)
	}))
	t.Cleanup(server.Close)

	got, err := New(server.URL, "t", server.Client()).Sites(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 2 || got[0].Slug != "loupe" || got[1].Slug != "" {
		t.Fatalf("sites = %+v", got)
	}
}

func TestEventsCallsTheEventsRoute(t *testing.T) {
	var gotPath, gotQuery, gotAuth string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath, gotQuery, gotAuth = r.URL.EscapedPath(), r.URL.RawQuery, r.Header.Get("Authorization")
		fmt.Fprint(w, `{"hubUrl":"https://hub.example/.well-known/mercure","jwt":"j","projects":[`+
			`{"id":"a","slug":"loupe","name":"Loupe","topic":"https://loupe.test/projects/a/events"},`+
			`{"id":"b","slug":"other","name":"Other","topic":"https://loupe.test/projects/b/events"}]}`)
	}))
	t.Cleanup(server.Close)

	got, err := New(server.URL, "secret", server.Client()).Events(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if gotPath != "/api/events" || gotQuery != "" || gotAuth != "Bearer secret" {
		t.Fatalf("path = %q, query = %q, auth = %q", gotPath, gotQuery, gotAuth)
	}
	want := []string{"https://loupe.test/projects/a/events", "https://loupe.test/projects/b/events"}
	if got.JWT != "j" || got.HubURL != "https://hub.example/.well-known/mercure" || !slices.Equal(got.Topics(), want) || got.Projects[1].Slug != "other" {
		t.Fatalf("events = %+v", got)
	}
}

// A 404 means push is off or the server predates the route, and the error says
// both rather than a bare status.
func TestEventsNamesAMissingRoute(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusNotFound)
	}))
	t.Cleanup(server.Close)

	_, err := New(server.URL, "t", server.Client()).Events(context.Background())
	if err == nil || !strings.Contains(err.Error(), "no GET /api/events endpoint") {
		t.Fatalf("err = %v", err)
	}
}
