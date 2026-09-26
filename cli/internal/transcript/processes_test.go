package transcript

import (
	"errors"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"
)

func at(minute int) time.Time {
	return time.Date(2026, 9, 25, 10, minute, 0, 0, time.UTC)
}

func userLine(minute int) string {
	return `{"type":"user","timestamp":"` + at(minute).Add(time.Second).Format(time.RFC3339) + `","message":{"role":"user","content":"go"}}`
}

func assistantLine(minute int, id string, output int) string {
	return `{"type":"assistant","timestamp":"` + at(minute).Add(2*time.Second).Format(time.RFC3339) + `","message":{"id":"` + id + `","model":"claude-opus-5-5","usage":{"input_tokens":0,"output_tokens":` + strconv.Itoa(output) + `,"cache_read_input_tokens":0,"cache_creation_input_tokens":0}}}`
}

func costLine(output int, usd string) string {
	return `{"type":"cost-state","modelUsage":{"claude-opus-5-5":{"inputTokens":1,"outputTokens":` + strconv.Itoa(output) + `,"cacheReadInputTokens":0,"cacheCreationInputTokens":0,"costUSD":` + usd + `}}}`
}

// started is the windows of processes that have no end in the bridge log.
func started(starts ...time.Time) []Window {
	out := make([]Window, len(starts))
	for i, s := range starts {
		out[i] = Window{Start: s}
	}

	return out
}

func writeTranscript(t *testing.T, lines ...string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), workSession+".jsonl")
	if err := os.WriteFile(path, []byte(strings.Join(lines, "\n")+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	return path
}

// A process that ended writes a cost-state line with the session totals, so
// each process spent its line minus the line before it.
func TestProcessesSubtractTheLineBefore(t *testing.T) {
	path := writeTranscript(t,
		userLine(0), assistantLine(0, "a", 10), costLine(10, "1"),
		userLine(5), assistantLine(5, "b", 30), costLine(40, "3.5"),
	)
	got, err := Processes(path, started(at(0), at(5)))
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 2 || !got[0].Reported || !got[1].Reported {
		t.Fatalf("Processes = %+v", got)
	}
	assertUsage(t, got[0].Usage, Usage{"claude-opus-5-5": {InputTokens: 1, OutputTokens: 10, CostUSD: cost(1)}})
	assertUsage(t, got[1].Usage, Usage{"claude-opus-5-5": {OutputTokens: 30, CostUSD: cost(2.5)}})
}

// A killed process writes no cost-state line. It gets the sum of its own
// messages, and the next process counts from the line before the kill.
func TestProcessesEstimateAKilledProcess(t *testing.T) {
	path := writeTranscript(t,
		userLine(0), assistantLine(0, "a", 10), costLine(10, "1"),
		userLine(5), assistantLine(5, "k", 7),
		userLine(9), assistantLine(9, "c", 20), costLine(30, "2"),
	)
	got, err := Processes(path, started(at(0), at(5), at(9)))
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 3 || !got[0].Reported || got[1].Reported || !got[2].Reported {
		t.Fatalf("Processes = %+v", got)
	}
	assertUsage(t, got[1].Usage, Usage{"claude-opus-5-5": {OutputTokens: 7, CostUSD: cost(7 * 20 / 1e6)}})
	assertUsage(t, got[2].Usage, Usage{"claude-opus-5-5": {OutputTokens: 20, CostUSD: cost(1)}})
}

// A session the owner started by hand has cost-state lines before the first
// worker. They are the baseline, and no process spent them.
func TestProcessesTakeTheLinesBeforeTheFirstWorkerAsTheBaseline(t *testing.T) {
	path := writeTranscript(t,
		costLine(3, "0.1"),
		userLine(0), assistantLine(0, "h", 3), costLine(3, "0.2"),
		userLine(5), assistantLine(5, "w", 4), costLine(7, "0.5"),
	)
	got, err := Processes(path, started(at(5)))
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 1 || !got[0].Reported {
		t.Fatalf("Processes = %+v", got)
	}
	assertUsage(t, got[0].Usage, Usage{"claude-opus-5-5": {OutputTokens: 4, CostUSD: cost(0.3)}})
}

// A process can write a cost-state line before it ends. Its last line holds
// its totals.
func TestProcessesTakeTheLastLineOfAProcess(t *testing.T) {
	path := writeTranscript(t,
		userLine(0), costLine(10, "1"),
		userLine(1), costLine(20, "2"),
		userLine(5), costLine(25, "2.5"),
	)
	got, err := Processes(path, started(at(0), at(5)))
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 2 || !got[0].Reported || !got[1].Reported {
		t.Fatalf("Processes = %+v", got)
	}
	assertUsage(t, got[0].Usage, Usage{"claude-opus-5-5": {InputTokens: 1, OutputTokens: 20, CostUSD: cost(2)}})
	assertUsage(t, got[1].Usage, Usage{"claude-opus-5-5": {OutputTokens: 5, CostUSD: cost(0.5)}})
}

// A process that wrote more after its last line was killed. The next process
// counts from that line, because claude restarts its totals from it.
func TestProcessesEstimateAProcessKilledAfterALine(t *testing.T) {
	path := writeTranscript(t,
		userLine(0), assistantLine(0, "a", 10), costLine(10, "1"),
		assistantLine(1, "k", 7),
		userLine(5), costLine(25, "2.5"),
	)
	got, err := Processes(path, started(at(0), at(5)))
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 2 || got[0].Reported || !got[1].Reported {
		t.Fatalf("Processes = %+v", got)
	}
	assertUsage(t, got[0].Usage, Usage{"claude-opus-5-5": {OutputTokens: 17, CostUSD: cost(17 * 20 / 1e6)}})
	assertUsage(t, got[1].Usage, Usage{"claude-opus-5-5": {OutputTokens: 15, CostUSD: cost(1.5)}})
}

// A person can resume the session by hand after a worker ended. What that
// process spent belongs to no worker, and the next worker counts from its line.
func TestProcessesIgnoreAResumeByHandAfterAWorkerEnded(t *testing.T) {
	path := writeTranscript(t,
		userLine(0), assistantLine(0, "a", 10), costLine(10, "1"),
		userLine(3), assistantLine(3, "m", 5), costLine(15, "1.25"),
		userLine(5), assistantLine(5, "b", 10), costLine(25, "2"),
		userLine(8), costLine(40, "3"),
	)
	got, err := Processes(path, []Window{{Start: at(0), End: at(2)}, {Start: at(5), End: at(6)}})
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 2 || !got[0].Reported || !got[1].Reported {
		t.Fatalf("Processes = %+v", got)
	}
	assertUsage(t, got[0].Usage, Usage{"claude-opus-5-5": {InputTokens: 1, OutputTokens: 10, CostUSD: cost(1)}})
	assertUsage(t, got[1].Usage, Usage{"claude-opus-5-5": {OutputTokens: 10, CostUSD: cost(0.75)}})
}

// A killed worker with an end gets an estimate of its messages up to that end.
func TestProcessesEstimateAKilledProcessUpToItsEnd(t *testing.T) {
	path := writeTranscript(t,
		userLine(0), assistantLine(0, "k", 7),
		userLine(3), assistantLine(3, "m", 5), costLine(5, "1"),
	)
	got, err := Processes(path, []Window{{Start: at(0), End: at(2)}})
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 1 || got[0].Reported {
		t.Fatalf("Processes = %+v", got)
	}
	assertUsage(t, got[0].Usage, Usage{"claude-opus-5-5": {OutputTokens: 7, CostUSD: cost(7 * 20 / 1e6)}})
}

func TestProcessesRefuseTotalsThatGoDown(t *testing.T) {
	path := writeTranscript(t,
		userLine(0), costLine(10, "1"),
		userLine(5), costLine(4, "2"),
	)
	if _, err := Processes(path, started(at(0), at(5))); !errors.Is(err, ErrUnmappable) {
		t.Fatalf("Processes = %v, want ErrUnmappable", err)
	}
}

func TestBetweenStopsAtTheEnd(t *testing.T) {
	path := writeTranscript(t, assistantLine(0, "a", 10), assistantLine(5, "b", 30))
	got, err := Between(path, at(0), at(5))
	if err != nil {
		t.Fatal(err)
	}
	assertUsage(t, got, Usage{"claude-opus-5-5": {OutputTokens: 10, CostUSD: cost(10 * 20 / 1e6)}})
}
