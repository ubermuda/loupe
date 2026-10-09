package api

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

// putToolCalls sends batch to a server that answers status and body, and
// returns the path and the raw body the server read.
func putToolCalls(t *testing.T, batch ToolCallBatch, status int, answer string) (string, string, error) {
	t.Helper()

	var gotPath, gotMethod, gotBody string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath, gotMethod = r.URL.EscapedPath(), r.Method
		b, _ := io.ReadAll(r.Body)
		gotBody = string(b)
		w.WriteHeader(status)
		_, _ = w.Write([]byte(answer))
	}))
	t.Cleanup(server.Close)

	err := New(server.URL, "t", server.Client()).ReportToolCalls(context.Background(), "my/project", testRunID, batch)
	if gotMethod != "" && gotMethod != http.MethodPut {
		t.Fatalf("method = %s, want PUT", gotMethod)
	}

	return gotPath, gotBody, err
}

func TestReportToolCallsSendsEveryKey(t *testing.T) {
	d, f, id, full := int64(157), false, "bg1", `{"command":"echo"}`
	tool, idle := int64(123), int64(0)
	batch := ToolCallBatch{
		Calls: []ToolCall{
			{Seq: 1, Tool: "Bash", Kind: "shell", StartedAt: time.Date(2026, 10, 6, 16, 32, 31, 0, time.FixedZone("x", 3600)), DurationMs: &d, IsError: &f, BackgroundID: &id, Signatures: []string{"echo"}, FullText: &full},
			{Seq: 2, Tool: "Read", StartedAt: time.Date(2026, 10, 6, 16, 32, 31, 797_000_000, time.UTC), InSubagent: true, WaitsOn: &id},
		},
		Timing: &ToolTiming{ToolTimeMs: &tool, IdleGapMs: &idle},
	}

	path, body, err := putToolCalls(t, batch, http.StatusOK, `{"stored":2}`)
	if err != nil {
		t.Fatal(err)
	}
	if path != "/api/projects/my%2Fproject/worker-runs/"+testRunID+"/tool-calls" {
		t.Fatalf("path = %s", path)
	}
	want := `{"calls":[` +
		`{"seq":1,"tool":"Bash","kind":"shell","startedAt":"2026-10-06T15:32:31.000Z","durationMs":157,"isError":false,"inSubagent":false,"backgroundId":"bg1","waitsOn":null,"signatures":["echo"],"fullText":"{\"command\":\"echo\"}"},` +
		`{"seq":2,"tool":"Read","kind":null,"startedAt":"2026-10-06T16:32:31.797Z","durationMs":null,"isError":null,"inSubagent":true,"backgroundId":null,"waitsOn":"bg1","signatures":[],"fullText":null}` +
		`],"timing":{"toolTimeMs":123,"idleGapMs":0}}`
	if body != want {
		t.Fatalf("body = %s\nwant %s", body, want)
	}
}

func TestReportToolCallsSendsAnEmptyBatchAsAList(t *testing.T) {
	_, body, err := putToolCalls(t, ToolCallBatch{}, http.StatusOK, `{}`)
	if err != nil {
		t.Fatal(err)
	}
	if body != `{"calls":[],"timing":null}` {
		t.Fatalf("body = %s", body)
	}
}

func TestReportToolCallsReadsEachAnswer(t *testing.T) {
	for _, tc := range []struct {
		status      int
		answer      string
		unsupported bool
		refused     bool
		ok          bool
	}{
		{status: http.StatusOK, ok: true},
		{status: http.StatusNoContent, ok: true},
		{status: http.StatusNotFound, answer: ``, unsupported: true},
		{status: http.StatusNotFound, answer: `<html>not found</html>`, unsupported: true},
		{status: http.StatusNotFound, answer: `{"error":"run_not_found"}`, refused: true},
		{status: http.StatusNotFound, answer: `{"error":"project_not_found"}`, refused: true},
		{status: http.StatusUnprocessableEntity, answer: `{"error":"x"}`, refused: true},
		{status: http.StatusForbidden, refused: true},
		{status: http.StatusTooManyRequests},
		{status: http.StatusRequestTimeout},
		{status: http.StatusBadGateway},
	} {
		_, _, err := putToolCalls(t, ToolCallBatch{}, tc.status, tc.answer)
		if (err == nil) != tc.ok || errors.Is(err, ErrToolCallsUnsupported) != tc.unsupported || errors.Is(err, ErrReportRefused) != tc.refused {
			t.Errorf("HTTP %d %s: err = %v", tc.status, tc.answer, err)
		}
	}
}

// A full text is cut to the server's cap on a rune boundary.
func TestReportToolCallsCutsTheFullText(t *testing.T) {
	long := ""
	for len(long) < maxFullText+10 {
		long += "é"
	}
	long = "x" + long
	batch := ToolCallBatch{Calls: []ToolCall{{Seq: 1, Tool: "Bash", StartedAt: time.Now(), Signatures: []string{}, FullText: &long}}}

	_, body, err := putToolCalls(t, batch, http.StatusOK, `{}`)
	if err != nil {
		t.Fatal(err)
	}
	var got struct {
		Calls []struct {
			FullText string `json:"fullText"`
		} `json:"calls"`
	}
	if err := json.Unmarshal([]byte(body), &got); err != nil {
		t.Fatal(err)
	}
	if n := len(got.Calls[0].FullText); n != maxFullText-1 {
		t.Fatalf("full text holds %d bytes", n)
	}
}

func TestSitesDecodesTheCollectionSettings(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte(`{"sites":[` +
			`{"id":"a","slug":"one","name":"One","collectFullText":true,"subcommandPrograms":["kubectl"]},` +
			`{"id":"b","slug":"two","name":"Two","subcommandPrograms":[]},` +
			`{"id":"c","slug":"three","name":"Three"}]}`))
	}))
	t.Cleanup(server.Close)

	sites, err := New(server.URL, "t", server.Client()).Sites(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if !sites[0].CollectFullText || len(sites[0].SubcommandPrograms) != 1 || sites[0].SubcommandPrograms[0] != "kubectl" {
		t.Fatalf("site one = %+v", sites[0])
	}
	if sites[1].CollectFullText || sites[1].SubcommandPrograms == nil || len(sites[1].SubcommandPrograms) != 0 {
		t.Fatalf("site two = %+v", sites[1])
	}
	if sites[2].CollectFullText || sites[2].SubcommandPrograms != nil {
		t.Fatalf("site three = %+v", sites[2])
	}
}
