package api

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestColumnsDecodesTheProjectAndItsColumns(t *testing.T) {
	var gotPath, gotAuth string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath, gotAuth = r.URL.EscapedPath(), r.Header.Get("Authorization")
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprint(w, `{"project":{"id":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7","slug":"loupe"},"columns":[{"slug":"backlog","label":"Backlog","terminal":false,"default":true},{"slug":"done","label":"Done","terminal":true,"default":false}]}`)
	}))
	t.Cleanup(server.Close)

	got, err := New(server.URL, "secret", server.Client()).Columns(context.Background(), "loupe")
	if err != nil {
		t.Fatal(err)
	}
	if gotPath != "/api/projects/loupe/board/columns" || gotAuth != "Bearer secret" {
		t.Fatalf("path = %q, auth = %q", gotPath, gotAuth)
	}
	if got.Project.ID != "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7" || got.Project.Slug != "loupe" {
		t.Fatalf("project = %+v", got.Project)
	}
	if len(got.Columns) != 2 || got.Columns[0].Slug != "backlog" || !got.Columns[0].Default || !got.Columns[1].Terminal {
		t.Fatalf("columns = %+v", got.Columns)
	}
}

// The handle is a path segment, so a slash in it must not reach another route.
func TestColumnsEscapesTheHandle(t *testing.T) {
	var gotPath string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.EscapedPath()
		fmt.Fprint(w, `{}`)
	}))
	t.Cleanup(server.Close)

	_, _ = New(server.URL, "t", server.Client()).Columns(context.Background(), "a/../b")
	if gotPath != "/api/projects/a%2F..%2Fb/board/columns" {
		t.Fatalf("path = %q", gotPath)
	}
}

// A server with no project slugs yet sends a null slug. That must decode, not
// fail.
func TestColumnsAcceptsANullSlug(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		fmt.Fprint(w, `{"project":{"id":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7","slug":null},"columns":[{"slug":"next","label":"Next","terminal":false,"default":false}]}`)
	}))
	t.Cleanup(server.Close)

	got, err := New(server.URL, "t", server.Client()).Columns(context.Background(), "loupe")
	if err != nil {
		t.Fatal(err)
	}
	if got.Project.Slug != "" || got.Project.ID == "" || len(got.Columns) != 1 {
		t.Fatalf("got = %+v", got)
	}
}

// A success body is read to a cap, as an error body is. The padding is valid
// JSON, so only the cap can refuse it.
func TestASuccessBodyPastTheCapIsRefused(t *testing.T) {
	pad := strings.Repeat("x", 2<<20)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		fmt.Fprint(w, `{"pad":"`+pad+`","project":{"id":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7"},"sites":[],"jwt":"j"}`)
	}))
	t.Cleanup(server.Close)
	client := New(server.URL, "t", server.Client())
	ctx := context.Background()

	for name, call := range map[string]func() error{
		"columns": func() error { _, err := client.Columns(ctx, "loupe"); return err },
		"sites":   func() error { _, err := client.Sites(ctx); return err },
		"events":  func() error { _, err := client.Events(ctx); return err },
	} {
		if err := call(); err == nil || !strings.Contains(err.Error(), "larger than") {
			t.Fatalf("%s: err = %v, want the cap to refuse the body", name, err)
		}
	}
}

func TestColumnsNamesEachFailure(t *testing.T) {
	for _, tc := range []struct {
		status int
		body   string
		is     error
		text   string
	}{
		{http.StatusNotFound, `{"error":"project_not_found"}`, ErrProjectNotFound, "loupe"},
		{http.StatusNotFound, `{"error":"board_disabled"}`, ErrBoardDisabled, "loupe"},
		{http.StatusNotFound, `<html>Not Found</html>`, ErrEndpointMissing, "loupe"},
		{http.StatusNotFound, `{"message":"No route found"}`, ErrEndpointMissing, "loupe"},
		{http.StatusNotFound, ``, ErrEndpointMissing, "loupe"},
		{http.StatusConflict, `{"error":"ambiguous_project"}`, ErrProjectAmbiguous, "loupe"},
		{http.StatusForbidden, ``, nil, "agent scope"},
		{http.StatusInternalServerError, ``, nil, "HTTP 500"},
	} {
		server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			w.WriteHeader(tc.status)
			fmt.Fprint(w, tc.body)
		}))

		_, err := New(server.URL, "t", server.Client()).Columns(context.Background(), "loupe")
		server.Close()

		if err == nil || !strings.Contains(err.Error(), tc.text) {
			t.Fatalf("HTTP %d: err = %v", tc.status, err)
		}
		if tc.is != nil && !errors.Is(err, tc.is) {
			t.Fatalf("HTTP %d: err = %v, want %v", tc.status, err, tc.is)
		}
	}
}
