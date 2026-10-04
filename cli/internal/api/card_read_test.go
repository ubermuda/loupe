package api

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// checkProject is the project id the card read tests name.
const checkProject = "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7"

const readCardID = "0199a0e2-b1f3-7a44-9c11-2d3e4f506172"

func TestReadCardReadsTheColumn(t *testing.T) {
	var method, path, auth string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		method, path, auth = r.Method, r.URL.EscapedPath(), r.Header.Get("Authorization")
		fmt.Fprintf(w, `{"cardId":%q,"number":12,"column":"implementation","held":true}`, strings.ToUpper(readCardID))
	}))
	t.Cleanup(server.Close)

	card, err := New(server.URL, "secret", server.Client()).ReadCard(context.Background(), checkProject, readCardID)
	if err != nil {
		t.Fatal(err)
	}
	if method != http.MethodGet || path != "/api/projects/"+checkProject+"/board/cards/"+readCardID || auth != "Bearer secret" {
		t.Fatalf("method = %q, path = %q, auth = %q", method, path, auth)
	}
	if card.Column != "implementation" || !card.Held {
		t.Fatalf("card = %+v", card)
	}
}

// A server older than the hold sends no held key, and the card reads as free.
func TestReadCardReadsAMissingHoldAsFree(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		fmt.Fprintf(w, `{"cardId":%q,"number":12,"column":"implementation"}`, readCardID)
	}))
	t.Cleanup(server.Close)

	card, err := New(server.URL, "t", server.Client()).ReadCard(context.Background(), checkProject, readCardID)
	if err != nil || card.Column != "implementation" || card.Held {
		t.Fatalf("card = %+v, err = %v", card, err)
	}
}

// Every answer that does not name a column for this card is a failure. The
// caller resumes on any failure, so no kind is told apart.
func TestReadCardNamesEachFailure(t *testing.T) {
	for name, tc := range map[string]struct {
		status int
		body   string
		text   string
	}{
		"card not found": {http.StatusNotFound, `{"error":"card_not_found"}`, `HTTP 404): {"error":"card_not_found"}`},
		"old server":     {http.StatusNotFound, ``, "HTTP 404"},
		"server error":   {http.StatusInternalServerError, `boom`, "HTTP 500"},
		"not json":       {http.StatusOK, `<html>`, "decode"},
		"no column":      {http.StatusOK, `{"cardId":"` + readCardID + `","number":12}`, "names no column"},
		"another card":   {http.StatusOK, `{"cardId":"0199a0e2-b1f3-7a44-9c11-000000000000","number":12,"column":"done"}`, "another card"},
	} {
		t.Run(name, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(tc.status)
				fmt.Fprint(w, tc.body)
			}))
			t.Cleanup(server.Close)

			_, err := New(server.URL, "t", server.Client()).ReadCard(context.Background(), checkProject, readCardID)
			if err == nil || !strings.Contains(err.Error(), tc.text) {
				t.Fatalf("err = %v, want it to contain %q", err, tc.text)
			}
		})
	}
}
