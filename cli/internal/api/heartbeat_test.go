package api

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
)

const heartbeatBridgeID = "0192f3a1-7777-4d3e-8f10-a2b3c4d5e6f7"

func TestHeartbeatPutsTheProjectsAndTheVersion(t *testing.T) {
	var method, path, auth, contentType, body string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		method, path, auth, contentType, body = r.Method, r.URL.EscapedPath(), r.Header.Get("Authorization"), r.Header.Get("Content-Type"), string(raw)
		w.WriteHeader(http.StatusNoContent)
	}))
	t.Cleanup(server.Close)

	_, err := New(server.URL, "secret", server.Client()).Heartbeat(context.Background(), heartbeatBridgeID, Heartbeat{
		Projects:   []string{"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7"},
		CLIVersion: "b4e39aa7 (dirty)",
	})
	if err != nil {
		t.Fatal(err)
	}
	if method != http.MethodPut || path != "/api/bridges/"+heartbeatBridgeID+"/heartbeat" || auth != "Bearer secret" || contentType != "application/json" {
		t.Fatalf("method = %q, path = %q, auth = %q, content type = %q", method, path, auth, contentType)
	}
	if body != `{"projects":["0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7"],"cliVersion":"b4e39aa7 (dirty)"}` {
		t.Fatalf("body = %s", body)
	}
}

// The server refuses a missing list, so a bridge that follows no project sends
// an empty one.
func TestHeartbeatSendsAnEmptyListRatherThanNull(t *testing.T) {
	var body string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		body = string(raw)
		w.WriteHeader(http.StatusNoContent)
	}))
	t.Cleanup(server.Close)

	if _, err := New(server.URL, "t", server.Client()).Heartbeat(context.Background(), heartbeatBridgeID, Heartbeat{CLIVersion: "v"}); err != nil {
		t.Fatal(err)
	}
	if body != `{"projects":[],"cliVersion":"v"}` {
		t.Fatalf("body = %s", body)
	}
}

// The server keeps its stored rows when the key is absent, and clears them on
// an empty list. So nil sends no key, and an empty list sends [].
func TestHeartbeatSendsHooksOnlyWhenTheListIsSet(t *testing.T) {
	var body string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		body = string(raw)
		w.WriteHeader(http.StatusNoContent)
	}))
	t.Cleanup(server.Close)
	client := New(server.URL, "t", server.Client())

	if _, err := client.Heartbeat(context.Background(), heartbeatBridgeID, Heartbeat{CLIVersion: "v", Hooks: []HookReport{}}); err != nil {
		t.Fatal(err)
	}
	if body != `{"projects":[],"cliVersion":"v","hooks":[]}` {
		t.Fatalf("empty list: body = %s", body)
	}

	row := HookReport{Package: "acme/tool", Ref: "v1", Event: "busy", LastRunAt: "2026-09-24T10:00:00Z", Outcome: "failed", Error: "boom"}
	if _, err := client.Heartbeat(context.Background(), heartbeatBridgeID, Heartbeat{CLIVersion: "v", Hooks: []HookReport{row, {Package: "acme/tool", Ref: "v1", Event: "idle", Outcome: "never"}}}); err != nil {
		t.Fatal(err)
	}
	want := `{"projects":[],"cliVersion":"v","hooks":[` +
		`{"package":"acme/tool","ref":"v1","event":"busy","lastRunAt":"2026-09-24T10:00:00Z","outcome":"failed","error":"boom"},` +
		`{"package":"acme/tool","ref":"v1","event":"idle","outcome":"never"}]}`
	if body != want {
		t.Fatalf("body = %s", body)
	}
}

// The server refuses a row past its caps, so the client clips each field and
// the list before it sends them.
func TestHeartbeatClipsTheHooksToTheServerCaps(t *testing.T) {
	var sent Heartbeat
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &sent)
		w.WriteHeader(http.StatusNoContent)
	}))
	t.Cleanup(server.Close)

	rows := make([]HookReport, 150)
	for i := range rows {
		rows[i] = HookReport{Package: strings.Repeat("p", 400), Ref: strings.Repeat("é", 150), Event: "busy", Outcome: "failed", Error: "  " + strings.Repeat("e", 600) + "  "}
	}
	if _, err := New(server.URL, "t", server.Client()).Heartbeat(context.Background(), heartbeatBridgeID, Heartbeat{CLIVersion: "v", Hooks: rows}); err != nil {
		t.Fatal(err)
	}
	if len(sent.Hooks) != 100 {
		t.Fatalf("rows = %d", len(sent.Hooks))
	}
	got := sent.Hooks[0]
	if got.Package != strings.Repeat("p", 300) || got.Ref != strings.Repeat("é", 100) || got.Error != strings.Repeat("e", 500) {
		t.Fatalf("row = %+v", got)
	}
	if len(rows[0].Package) != 400 {
		t.Fatal("the caller's rows changed")
	}
}

func TestHeartbeatSortsEachAnswer(t *testing.T) {
	for _, tc := range []struct {
		status  int
		body    string
		missing bool
		ok      bool
	}{
		{status: http.StatusNoContent, ok: true},
		{status: http.StatusNotFound, missing: true},
		{status: http.StatusNotFound, body: `{"error":"something_else"}`, missing: true},
		{status: http.StatusUnauthorized},
		{status: http.StatusForbidden, body: `{"error":"insufficient_scope"}`},
		{status: http.StatusUnprocessableEntity},
		{status: http.StatusTooManyRequests},
		{status: http.StatusInternalServerError},
	} {
		server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			w.WriteHeader(tc.status)
			_, _ = io.WriteString(w, tc.body)
		}))

		_, err := New(server.URL, "t", server.Client()).Heartbeat(context.Background(), heartbeatBridgeID, Heartbeat{CLIVersion: "v"})
		server.Close()

		if tc.ok != (err == nil) || tc.missing != errors.Is(err, ErrHeartbeatMissing) {
			t.Fatalf("HTTP %d %s: err = %v", tc.status, tc.body, err)
		}
		if !tc.ok && !strings.Contains(err.Error(), strconv.Itoa(tc.status)) && !tc.missing {
			t.Fatalf("HTTP %d: the error does not name the status: %v", tc.status, err)
		}
	}
}

func TestHeartbeatReturnsTheRangeOfTheReply(t *testing.T) {
	for _, tc := range []struct {
		status int
		body   string
		want   string
	}{
		{status: http.StatusOK, body: `{"cliRange":"^1.0"}`, want: "^1.0"},
		{status: http.StatusOK, body: ``},
		{status: http.StatusOK, body: `not json`},
		{status: http.StatusNoContent},
	} {
		server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			w.WriteHeader(tc.status)
			_, _ = io.WriteString(w, tc.body)
		}))

		got, err := New(server.URL, "t", server.Client()).Heartbeat(context.Background(), heartbeatBridgeID, Heartbeat{CLIVersion: "v"})
		server.Close()

		if err != nil || got != tc.want {
			t.Fatalf("HTTP %d %q: range = %q, err = %v", tc.status, tc.body, got, err)
		}
	}
}

func TestHeartbeatSendsTheUpdateState(t *testing.T) {
	var body string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		body = string(raw)
		w.WriteHeader(http.StatusNoContent)
	}))
	t.Cleanup(server.Close)

	hb := Heartbeat{CLIVersion: "1.0.0", Update: &HeartbeatUpdate{State: "updating", Version: "1.2.0"}}
	if _, err := New(server.URL, "t", server.Client()).Heartbeat(context.Background(), heartbeatBridgeID, hb); err != nil {
		t.Fatal(err)
	}
	if body != `{"projects":[],"cliVersion":"1.0.0","update":{"state":"updating","version":"1.2.0"}}` {
		t.Fatalf("body = %s", body)
	}
}

// JSON numbers decode as float64, so a whole number arrives with no fraction
// and anything else is not a number of seconds.
func TestSecondsReadsAPositiveWholeNumber(t *testing.T) {
	for _, tc := range []struct {
		flags map[string]any
		want  int
		ok    bool
	}{
		{flags: map[string]any{HeartbeatIntervalFlag: float64(90)}, want: 90, ok: true},
		{flags: map[string]any{HeartbeatIntervalFlag: float64(1)}, want: 1, ok: true},
		{flags: nil},
		{flags: map[string]any{}},
		{flags: map[string]any{HeartbeatIntervalFlag: float64(0)}},
		{flags: map[string]any{HeartbeatIntervalFlag: float64(-5)}},
		{flags: map[string]any{HeartbeatIntervalFlag: 60.5}},
		{flags: map[string]any{HeartbeatIntervalFlag: "60"}},
		{flags: map[string]any{HeartbeatIntervalFlag: true}},
		{flags: map[string]any{HeartbeatIntervalFlag: 1e300}},
	} {
		got, ok := Events{Flags: tc.flags}.Seconds(HeartbeatIntervalFlag)
		if got != tc.want || ok != tc.ok {
			t.Fatalf("flags %v: got %d, %v", tc.flags, got, ok)
		}
	}
}
