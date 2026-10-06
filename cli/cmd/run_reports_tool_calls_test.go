package cmd

import (
	"context"
	"encoding/json"
	"errors"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/outbound"
	"github.com/ubermuda/loupe/cli/internal/rules"
	"github.com/ubermuda/loupe/cli/internal/stream"
)

// bashCalls makes n Bash calls that each run command. The command holds
// simple commands split by "; ", each of plain words.
func bashCalls(n int, command string) []stream.Call {
	input, _ := json.Marshal(map[string]string{"command": command})
	var commands []stream.Command
	for _, part := range strings.Split(command, "; ") {
		words := strings.Fields(part)
		c := stream.Command{Program: words[0]}
		if len(words) > 1 {
			c.Sub = words[1]
		}
		commands = append(commands, c)
	}
	start := time.Date(2026, 10, 6, 10, 0, 0, 0, time.UTC)
	calls := make([]stream.Call, n)
	for i := range calls {
		calls[i] = stream.Call{Seq: i + 1, Tool: "Bash", StartedAt: start.Add(time.Duration(i) * time.Second), Commands: commands, FullText: string(input)}
	}

	return calls
}

func sendAll(t *testing.T, reports []outbound.Report) {
	t.Helper()
	for _, r := range reports {
		if _, err := r.Send(context.Background()); err != nil {
			t.Fatalf("send: %v", err)
		}
	}
}

func ms(v int64) *int64 { return &v }

// 1,201 calls go out in three batches of at most 500, and only the last one
// carries the timing.
func TestToolCallsGoOutInBatchesWithTheTimingLast(t *testing.T) {
	client := &fakeRunClient{}
	reports, _ := newTestRunReports(client)

	batches := reports.toolCalls(context.Background(), testProject, "run-1", 87, "plan", bashCalls(1201, "git status"), stream.Timing{ToolTimeMs: ms(5), IdleGapMs: ms(0)})
	if len(batches) != 3 {
		t.Fatalf("got %d reports, want 3", len(batches))
	}
	if batches[0].Card != 87 || batches[0].Rule != "plan" {
		t.Fatalf("report names card %d and rule %q", batches[0].Card, batches[0].Rule)
	}
	sendAll(t, batches)

	var sizes []int
	for i, b := range client.batches {
		sizes = append(sizes, len(b.batch.Calls))
		if b.handle != testProject || b.runID != "run-1" {
			t.Fatalf("batch %d names %q and %q", i, b.handle, b.runID)
		}
		if (b.batch.Timing != nil) != (i == 2) {
			t.Fatalf("batch %d timing = %+v", i, b.batch.Timing)
		}
	}
	if !slices.Equal(sizes, []int{500, 500, 201}) {
		t.Fatalf("sizes = %v", sizes)
	}
	last := client.batches[2].batch
	if *last.Timing.ToolTimeMs != 5 || *last.Timing.IdleGapMs != 0 {
		t.Fatalf("timing = %+v", last.Timing)
	}
	if last.Calls[200].Seq != 1201 || !slices.Equal(last.Calls[200].Signatures, []string{"git status"}) || last.Calls[200].FullText != nil {
		t.Fatalf("last call = %+v", last.Calls[200])
	}
	if client.siteReads != 1 {
		t.Fatalf("read the projects %d times, want once for the cache", client.siteReads)
	}
}

// A run with no call still sends its timing in one empty batch.
func TestARunWithNoCallSendsOneEmptyBatch(t *testing.T) {
	client := &fakeRunClient{}
	reports, _ := newTestRunReports(client)

	sendAll(t, reports.toolCalls(context.Background(), testProject, "run-1", 87, "plan", nil, stream.Timing{}))
	if len(client.batches) != 1 || client.batches[0].batch.Calls == nil || len(client.batches[0].batch.Calls) != 0 || client.batches[0].batch.Timing == nil {
		t.Fatalf("batches = %+v", client.batches)
	}
}

// The project's settings choose the full text and the programs that keep a
// subcommand. Another project, and a failed read, get the defaults.
func TestTheProjectSettingsShapeEachCall(t *testing.T) {
	sites := []api.Site{{ID: testProject, CollectFullText: true, SubcommandPrograms: []string{"kubectl"}}}
	client := &fakeRunClient{sites: func() ([]api.Site, error) { return sites, nil }}
	reports, _ := newTestRunReports(client)

	sendAll(t, reports.toolCalls(context.Background(), testProject, "run-1", 87, "plan", bashCalls(1, "kubectl get pods; git status"), stream.Timing{}))
	got := client.batches[0].batch.Calls[0]
	if !slices.Equal(got.Signatures, []string{"kubectl get", "git"}) {
		t.Fatalf("signatures = %q", got.Signatures)
	}
	if got.FullText == nil || *got.FullText != `{"command":"kubectl get pods; git status"}` {
		t.Fatalf("full text = %v", got.FullText)
	}

	sendAll(t, reports.toolCalls(context.Background(), "other-project", "run-2", 87, "plan", bashCalls(1, "git status"), stream.Timing{}))
	got = client.batches[1].batch.Calls[0]
	if got.FullText != nil || !slices.Equal(got.Signatures, []string{"git status"}) {
		t.Fatalf("other project call = %+v", got)
	}

	failing := &fakeRunClient{sites: func() ([]api.Site, error) { return nil, errors.New("down") }}
	reports, _ = newTestRunReports(failing)
	sendAll(t, reports.toolCalls(context.Background(), testProject, "run-3", 87, "plan", bashCalls(1, "git status"), stream.Timing{}))
	got = failing.batches[0].batch.Calls[0]
	if got.FullText != nil || !slices.Equal(got.Signatures, []string{"git status"}) {
		t.Fatalf("call after a failed read = %+v", got)
	}
}

// The cache holds the projects for ten minutes, and reads them again after.
func TestTheProjectSettingsAreCachedForTenMinutes(t *testing.T) {
	client := &fakeRunClient{}
	reports, _ := newTestRunReports(client)
	now := time.Date(2026, 10, 6, 10, 0, 0, 0, time.UTC)
	reports.now = func() time.Time { return now }

	send := func() {
		sendAll(t, reports.toolCalls(context.Background(), testProject, "run-1", 87, "plan", nil, stream.Timing{}))
	}
	send()
	now = now.Add(9 * time.Minute)
	send()
	if client.siteReads != 1 {
		t.Fatalf("read the projects %d times inside the window", client.siteReads)
	}
	now = now.Add(2 * time.Minute)
	send()
	if client.siteReads != 2 {
		t.Fatalf("read the projects %d times after the window", client.siteReads)
	}
}

// A server with no tool call endpoint drops each batch at once, says so once,
// and still gets the later batches, because agent push can come back on.
func TestAServerWithNoToolCallEndpointDropsTheBatches(t *testing.T) {
	client := &fakeRunClient{toolCalls: func(api.ToolCallBatch) error { return api.ErrToolCallsUnsupported }}
	reports, log := newTestRunReports(client)

	sendAll(t, reports.toolCalls(context.Background(), testProject, "run-1", 87, "plan", bashCalls(600, "ls"), stream.Timing{}))
	sendAll(t, reports.toolCalls(context.Background(), testProject, "run-2", 87, "plan", bashCalls(1, "ls"), stream.Timing{}))

	if len(client.batches) != 3 {
		t.Fatalf("sent %d batches, want 3", len(client.batches))
	}
	if n := countEvents(t, log, "tool_calls_unsupported"); n != 1 {
		t.Fatalf("logged tool_calls_unsupported %d times", n)
	}
}

// A refused or a failed batch goes back to the queue, which retries or gives up.
func TestAFailedBatchReturnsItsError(t *testing.T) {
	refused := errors.Join(api.ErrReportRefused, errors.New("HTTP 422"))
	client := &fakeRunClient{toolCalls: func(api.ToolCallBatch) error { return refused }}
	reports, _ := newTestRunReports(client)

	_, err := reports.toolCalls(context.Background(), testProject, "run-1", 87, "plan", nil, stream.Timing{})[0].Send(context.Background())
	if !errors.Is(err, api.ErrReportRefused) {
		t.Fatalf("err = %v", err)
	}
}

// A call with no start time has no row the server takes, so it stays out.
func TestACallWithNoStartStaysOut(t *testing.T) {
	client := &fakeRunClient{}
	reports, _ := newTestRunReports(client)
	calls := bashCalls(2, "ls")
	calls[0].StartedAt = time.Time{}

	sendAll(t, reports.toolCalls(context.Background(), testProject, "run-1", 87, "plan", calls, stream.Timing{}))
	if got := client.batches[0].batch.Calls; len(got) != 1 || got[0].Seq != 2 {
		t.Fatalf("calls = %+v", got)
	}
}

// streamedRun is a claude run whose stdout held one tool call.
var streamedRun = workerResult{
	hasResult: true, status: "finished", output: "done", streamed: true,
	calls:  bashCalls(1, "git status"),
	timing: stream.Timing{ToolTimeMs: ms(10), IdleGapMs: ms(0)},
}

// The tool calls of a finished run go out after its outcome, under its run id.
func TestAFinishedRunSendsItsToolCallsAfterItsOutcome(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.worker.result = streamedRun

	h.send(cardMoved(87))

	if got := rec.names(); !slices.Equal(got, []string{api.RunQueued, api.RunRunning, api.RunSucceeded, "tool-calls"}) {
		t.Fatalf("sent %v", got)
	}
	sent := rec.toolCalls()[0]
	if sent.handle != testProject || sent.runID != rec.states()[0].runID {
		t.Fatalf("tool calls name %q and %q", sent.handle, sent.runID)
	}
	if len(sent.batch.Calls) != 1 || sent.batch.Timing == nil || *sent.batch.Timing.ToolTimeMs != 10 {
		t.Fatalf("batch = %+v", sent.batch)
	}
}

// collect: false sends no tool call and no timing.
func TestCollectFalseSendsNoToolCall(t *testing.T) {
	h := newHarnessWith(t, "collect: false\n"+defaultRules, rules.Defaults{})
	rec := h.states()
	h.worker.result = streamedRun

	h.send(cardMoved(87))

	if got := rec.names(); !slices.Equal(got, []string{api.RunQueued, api.RunRunning, api.RunSucceeded}) {
		t.Fatalf("sent %v", got)
	}
}

// A run whose stdout the bridge never read, such as one that did not start,
// sends no tool call.
func TestARunWithNoStreamSendsNoToolCall(t *testing.T) {
	h := newHarness(t)
	rec := h.states()
	h.worker.result = workerResult{err: errors.New("fork/exec claude: permission denied")}

	h.send(cardMoved(87))

	if len(rec.toolCalls()) != 0 {
		t.Fatalf("sent %v", rec.names())
	}
}

// The bridge builds each row when it queues the batch, so a queued batch holds
// no raw input. The project settings are those it read at that time.
func TestABatchIsBuiltWhenItIsQueued(t *testing.T) {
	sites := []api.Site{{ID: testProject, CollectFullText: true}}
	client := &fakeRunClient{sites: func() ([]api.Site, error) { return sites, nil }}
	reports, _ := newTestRunReports(client)

	batches := reports.toolCalls(context.Background(), testProject, "run-1", 87, "plan", bashCalls(1, "git status"), stream.Timing{})
	if client.siteReads != 1 {
		t.Fatalf("read the projects %d times before the send, want once", client.siteReads)
	}
	sites = []api.Site{{ID: testProject}}
	reports.now = func() time.Time { return time.Now().Add(time.Hour) }
	sendAll(t, batches)

	got := client.batches[0].batch.Calls[0]
	if client.siteReads != 1 || got.FullText == nil || *got.FullText != `{"command":"git status"}` {
		t.Fatalf("reads = %d, call = %+v", client.siteReads, got)
	}
}
