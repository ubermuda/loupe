package api

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"slices"
	"strings"
	"testing"
)

func TestCardHoldsReadsTheHeldCards(t *testing.T) {
	var method, path, auth string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		method, path, auth = r.Method, r.URL.EscapedPath(), r.Header.Get("Authorization")
		fmt.Fprint(w, `{"holds":[{"projectId":"p1","cardId":"c1"},{"projectId":"p2","cardId":"c2"}]}`)
	}))
	t.Cleanup(server.Close)

	holds, err := New(server.URL, "secret", server.Client()).CardHolds(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if method != http.MethodGet || path != "/api/card-holds" || auth != "Bearer secret" {
		t.Fatalf("method = %q, path = %q, auth = %q", method, path, auth)
	}
	if want := []CardHold{{ProjectID: "p1", CardID: "c1"}, {ProjectID: "p2", CardID: "c2"}}; !slices.Equal(holds, want) {
		t.Fatalf("holds = %+v", holds)
	}
}

func TestCardHoldsReadsAnEmptyList(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		fmt.Fprint(w, `{"holds":[]}`)
	}))
	t.Cleanup(server.Close)

	holds, err := New(server.URL, "t", server.Client()).CardHolds(context.Background())
	if err != nil || len(holds) != 0 {
		t.Fatalf("holds = %+v, err = %v", holds, err)
	}
}

// An older server has no held list, and answers 404.
func TestCardHoldsOfAnOlderServer(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusNotFound)
	}))
	t.Cleanup(server.Close)

	_, err := New(server.URL, "t", server.Client()).CardHolds(context.Background())
	if !errors.Is(err, ErrNoCardHolds) {
		t.Fatalf("err = %v, want ErrNoCardHolds", err)
	}
}

func TestCardHoldsNamesEachFailure(t *testing.T) {
	for name, tc := range map[string]struct {
		status int
		body   string
		text   string
	}{
		"forbidden":    {http.StatusForbidden, ``, "agent scope"},
		"server error": {http.StatusInternalServerError, `boom`, "HTTP 500): boom"},
		"not json":     {http.StatusOK, `<html>`, "decode"},
	} {
		t.Run(name, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(tc.status)
				fmt.Fprint(w, tc.body)
			}))
			t.Cleanup(server.Close)

			_, err := New(server.URL, "t", server.Client()).CardHolds(context.Background())
			if err == nil || errors.Is(err, ErrNoCardHolds) || !strings.Contains(err.Error(), tc.text) {
				t.Fatalf("err = %v, want it to contain %q", err, tc.text)
			}
		})
	}
}
