package api

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

const (
	ackBridgeID  = "0192f3a1-7777-4d3e-8f10-a2b3c4d5e6f7"
	ackCommandID = "0199a0e2-0000-7c5e-9f2a-3b1c6d7e8f90"
)

func TestHeartbeatReadsThePauseAndTheCommands(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		fmt.Fprintf(w, `{"cliRange":"^1.0","paused":true,"commands":[{"type":"bridge.command","projectId":"0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7",`+
			`"subject":{"type":"bridge-command","id":%[1]q},"commandId":%[1]q,"kind":"stop-run","bridgeId":%[2]q,"runKey":null,"sessionId":null,`+
			`"subjectType":"card","subjectId":"0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7","cardNumber":42,"workRequestId":"0192f3a1-8888-7d3e-8f10-a2b3c4d5e6f7","workKind":"plan","ruleId":"plan-on-entry",`+
			`"harness":"claude-code","account":"claude-b","model":"opus","expiresAt":"2026-09-29T10:15:00+00:00"}]}`, ackCommandID, ackBridgeID)
	}))
	t.Cleanup(server.Close)

	reply, err := New(server.URL, "t", server.Client()).Heartbeat(context.Background(), heartbeatBridgeID, Heartbeat{CLIVersion: "v"})
	if err != nil {
		t.Fatal(err)
	}
	if reply.CLIRange != "^1.0" || reply.Paused == nil || !*reply.Paused || len(reply.Commands) != 1 {
		t.Fatalf("reply = %+v", reply)
	}
	c := reply.Commands[0]
	want := time.Date(2026, 9, 29, 10, 15, 0, 0, time.UTC)
	if c.Type != "bridge.command" || c.CommandID != ackCommandID || c.Subject.ID != ackCommandID || c.Kind != CommandStopRun ||
		c.BridgeID != ackBridgeID || c.RunKey != "" || c.SessionID != "" || c.CardNumber != 42 || c.SubjectType != SubjectCard || c.SubjectID != "0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7" || c.WorkKind != "plan" ||
		c.WorkRequestID != "0192f3a1-8888-7d3e-8f10-a2b3c4d5e6f7" || c.RuleID != "plan-on-entry" || !c.ExpiresAt.Equal(want) ||
		c.Harness != "claude-code" || c.Account != "claude-b" || c.Model != "opus" {
		t.Fatalf("command = %+v", c)
	}
}

// A server older than the pause sends no paused key, which keeps what the
// bridge holds.
func TestHeartbeatTellsAMissingPauseFromFalse(t *testing.T) {
	for body, want := range map[string]string{
		`{"cliRange":"^1.0"}`:                 "nil",
		`{"cliRange":"^1.0","paused":null}`:   "nil",
		`{"cliRange":"^1.0","paused":false}`:  "false",
		`{"cliRange":"^1.0","paused":true}`:   "true",
		`{"cliRange":"^1.0","commands":null}`: "nil",
	} {
		server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			_, _ = io.WriteString(w, body)
		}))
		reply, err := New(server.URL, "t", server.Client()).Heartbeat(context.Background(), heartbeatBridgeID, Heartbeat{CLIVersion: "v"})
		server.Close()

		got := "nil"
		if reply.Paused != nil {
			got = fmt.Sprint(*reply.Paused)
		}
		if err != nil || got != want || len(reply.Commands) != 0 {
			t.Fatalf("%s: paused = %s, commands = %v, err = %v", body, got, reply.Commands, err)
		}
	}
}

// A command whose fields have the wrong type must not pass as another command:
// a cardNumber of "x" would decode as 0, which names no card.
func TestHeartbeatDropsACommandItCannotDecode(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		fmt.Fprintf(w, `{"cliRange":"^1.0","commands":[{"commandId":"bad","cardNumber":"x"},{"commandId":%q,"kind":"resume-run"},7]}`, ackCommandID)
	}))
	t.Cleanup(server.Close)

	reply, err := New(server.URL, "t", server.Client()).Heartbeat(context.Background(), heartbeatBridgeID, Heartbeat{CLIVersion: "v"})
	if err != nil {
		t.Fatal(err)
	}
	if reply.CLIRange != "^1.0" || len(reply.Commands) != 1 || reply.Commands[0].CommandID != ackCommandID || reply.Commands[0].Kind != CommandResumeRun {
		t.Fatalf("reply = %+v", reply)
	}
}

func TestHeartbeatSendsThePauseAndTheCapabilities(t *testing.T) {
	var body string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		body = string(raw)
		w.WriteHeader(http.StatusNoContent)
	}))
	t.Cleanup(server.Close)

	paused := false
	hb := Heartbeat{CLIVersion: "v", Paused: &paused, Capabilities: []string{"commands"}}
	if _, err := New(server.URL, "t", server.Client()).Heartbeat(context.Background(), heartbeatBridgeID, hb); err != nil {
		t.Fatal(err)
	}
	if body != `{"projects":[],"cliVersion":"v","paused":false,"capabilities":["commands"]}` {
		t.Fatalf("body = %s", body)
	}
}

func TestAckCommandPutsTheState(t *testing.T) {
	var method, path, auth, contentType, body string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		method, path, auth, contentType, body = r.Method, r.URL.EscapedPath(), r.Header.Get("Authorization"), r.Header.Get("Content-Type"), string(raw)
		fmt.Fprintf(w, `{"commandId":%q,"state":"expired"}`, ackCommandID)
	}))
	t.Cleanup(server.Close)

	stored, err := New(server.URL, "secret", server.Client()).AckCommand(context.Background(), ackBridgeID, ackCommandID, CommandDone, "")
	if err != nil || stored != "expired" {
		t.Fatalf("stored = %q, err = %v", stored, err)
	}
	if method != http.MethodPut || path != "/api/bridges/"+ackBridgeID+"/commands/"+ackCommandID || auth != "Bearer secret" || contentType != "application/json" {
		t.Fatalf("method = %q, path = %q, auth = %q, content type = %q", method, path, auth, contentType)
	}
	if body != `{"state":"done"}` {
		t.Fatalf("body = %s", body)
	}
}

// The server trims the reason and then counts its characters, so the client
// does the same before the cut.
func TestAckCommandClipsTheReason(t *testing.T) {
	var body string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		body = string(raw)
		fmt.Fprintf(w, `{"commandId":%q,"state":"refused"}`, ackCommandID)
	}))
	t.Cleanup(server.Close)

	reason := "  " + strings.Repeat("é", 1200)
	if _, err := New(server.URL, "t", server.Client()).AckCommand(context.Background(), ackBridgeID, ackCommandID, CommandRefused, reason); err != nil {
		t.Fatal(err)
	}
	if body != `{"state":"refused","reason":"`+strings.Repeat("é", 1000)+`"}` {
		t.Fatalf("body = %s", body)
	}
}

// A command the server no longer holds is settled for the caller. A 404 with
// no code comes from a server that predates commands, which is another fault.
func TestAckCommandNamesEachFailure(t *testing.T) {
	for name, tc := range map[string]struct {
		status   int
		body     string
		notFound bool
		text     string
	}{
		"command gone": {http.StatusNotFound, `{"error":"command_not_found"}`, true, ""},
		"old server":   {http.StatusNotFound, ``, false, "HTTP 404"},
		"other code":   {http.StatusNotFound, `{"error":"project_not_found"}`, false, "HTTP 404"},
		"invalid":      {http.StatusUnprocessableEntity, `{"error":"invalid_state"}`, false, "invalid_state"},
		"server error": {http.StatusInternalServerError, `boom`, false, "HTTP 500"},
	} {
		t.Run(name, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(tc.status)
				_, _ = io.WriteString(w, tc.body)
			}))
			t.Cleanup(server.Close)

			_, err := New(server.URL, "t", server.Client()).AckCommand(context.Background(), ackBridgeID, ackCommandID, CommandDone, "")
			if err == nil || errors.Is(err, ErrCommandNotFound) != tc.notFound || !strings.Contains(err.Error(), tc.text) {
				t.Fatalf("err = %v", err)
			}
		})
	}
}
