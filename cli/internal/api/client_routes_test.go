package api

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
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

func TestStreamCredentialsPutsTheHandleInThePath(t *testing.T) {
	var gotPath, gotQuery string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath, gotQuery = r.URL.EscapedPath(), r.URL.RawQuery
		fmt.Fprint(w, `{"hubUrl":"https://hub.example/.well-known/mercure","topic":"t","jwt":"j","site":{"id":"1","name":"My Site"}}`)
	}))
	t.Cleanup(server.Close)

	got, err := New(server.URL, "t", server.Client()).StreamCredentials(context.Background(), "My Site")
	if err != nil {
		t.Fatal(err)
	}
	if gotPath != "/api/projects/My%20Site/stream" || gotQuery != "" {
		t.Fatalf("path = %q, query = %q", gotPath, gotQuery)
	}
	if got.JWT != "j" || got.Site.Name != "My Site" {
		t.Fatalf("creds = %+v", got)
	}
}

// The handle is one path segment, so a slash in it must not reach another route.
func TestStreamCredentialsEscapesTheHandle(t *testing.T) {
	var gotPath string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.EscapedPath()
		fmt.Fprint(w, `{}`)
	}))
	t.Cleanup(server.Close)

	_, _ = New(server.URL, "t", server.Client()).StreamCredentials(context.Background(), "a/../b")
	if gotPath != "/api/projects/a%2F..%2Fb/stream" {
		t.Fatalf("path = %q", gotPath)
	}
}
