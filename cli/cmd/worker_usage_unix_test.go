//go:build unix

package cmd

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/transcript"
)

// The stand-in claude ends the way claude does: it writes a cost-state line
// with the session's new totals, and prints the same totals. The bridge read
// the baseline before the process started, so it reports the difference.
func TestRunWorkerReportsWhatAResumeAdded(t *testing.T) {
	shortConfigHome(t)
	dir := claudeHome(t, testSession, costState)
	totals := `{"claude-opus-5-5":{"inputTokens":150,"outputTokens":30,"cacheReadInputTokens":1500,"cacheCreationInputTokens":50,"costUSD":2}}`
	fakeClaude(t, "echo '{\"type\":\"cost-state\",\"modelUsage\":"+totals+"}' >> '"+dir+"/projects/-work/"+testSession+".jsonl'\n"+
		"echo '{\"result\":\"ok\",\"modelUsage\":"+totals+"}'\n")

	res := runWorker(context.Background(), workerSpec{dir: t.TempDir(), sessionID: testSession, resume: true, prompt: "go"}, nil)
	want := &api.Usage{Source: api.UsageReported, Models: map[string]api.ModelUsage{
		"claude-opus-5-5": {InputTokens: 50, OutputTokens: 20, CacheReadTokens: 500, CostUSD: ptr(0.75)},
	}}
	if res.err != nil || !sameUsage(res.usage, want) {
		t.Fatalf("usage = %s, want %s (%+v)", usageText(res.usage), usageText(want), res)
	}
	rec, err := readRunRecord(res.dir)
	if err != nil || rec.Baseline == nil || (*rec.Baseline)["claude-opus-5-5"].InputTokens != 100 {
		t.Fatalf("run record = %+v, %v", rec, err)
	}
}

// A killed process before the resume left messages after the last cost-state
// line. The line may not count them, so the difference is only an estimate.
func TestRunWorkerEstimatesAResumeAfterAKilledProcess(t *testing.T) {
	shortConfigHome(t)
	claudeHome(t, testSession, costState, streamed2)
	totals := `{"claude-opus-5-5":{"inputTokens":150,"outputTokens":30,"cacheReadInputTokens":1500,"cacheCreationInputTokens":50,"costUSD":2}}`
	fakeClaude(t, "echo '{\"result\":\"ok\",\"modelUsage\":"+totals+"}'\n")

	res := runWorker(context.Background(), workerSpec{dir: t.TempDir(), sessionID: testSession, resume: true, prompt: "go"}, nil)
	want := &api.Usage{Source: api.UsageEstimated, Models: map[string]api.ModelUsage{
		"claude-opus-5-5": {InputTokens: 50, OutputTokens: 20, CacheReadTokens: 500, CostUSD: ptr(0.75)},
	}}
	if !sameUsage(res.usage, want) {
		t.Fatalf("usage = %s, want %s", usageText(res.usage), usageText(want))
	}
	if rec, err := readRunRecord(res.dir); err != nil || !rec.BaselineIncomplete {
		t.Fatalf("run record = %+v, %v", rec, err)
	}
}

// A run a former image started keeps its baseline in its run record, so the
// adopter reports the same difference.
func TestAdoptWorkerReportsWhatAResumeAdded(t *testing.T) {
	claudeHome(t, testSession)
	done := exec.Command("true")
	if err := done.Run(); err != nil {
		t.Fatal(err)
	}
	dir := t.TempDir()
	baseline := transcript.Usage{"claude-opus-5-5": {InputTokens: 100, CostUSD: ptr(1.0)}}
	rec := runRecord{PID: done.Process.Pid, StartedAt: time.Now(), SessionID: testSession, Resume: true, Baseline: &baseline}
	if err := writeRunRecord(dir, rec); err != nil {
		t.Fatal(err)
	}
	files := map[string]string{
		"stdout":      `{"result":"ok","modelUsage":{"claude-opus-5-5":{"inputTokens":130,"costUSD":1.5}}}`,
		"stderr":      "",
		"status.exit": "0\n",
	}
	for name, body := range files {
		if err := os.WriteFile(filepath.Join(dir, name), []byte(body), 0o600); err != nil {
			t.Fatal(err)
		}
	}

	res := adoptWorker(context.Background(), dir)
	want := &api.Usage{Source: api.UsageReported, Models: map[string]api.ModelUsage{"claude-opus-5-5": {InputTokens: 30, CostUSD: ptr(0.5)}}}
	if res.exitCode != 0 || !sameUsage(res.usage, want) {
		t.Fatalf("usage = %s (%+v)", usageText(res.usage), res)
	}
}

// A new session has nothing before it, so the bridge reads no baseline.
func TestRunWorkerReportsANewSessionWhole(t *testing.T) {
	shortConfigHome(t)
	claudeHome(t, testSession)
	fakeClaude(t, `echo '{"result":"ok","modelUsage":{"claude-sonnet-5":{"inputTokens":1,"outputTokens":2,"cacheReadInputTokens":3,"cacheCreationInputTokens":4,"costUSD":0.1}}}'`)

	res := runWorker(context.Background(), workerSpec{dir: t.TempDir(), sessionID: testSession, prompt: "go"}, nil)
	want := &api.Usage{Source: api.UsageReported, Models: map[string]api.ModelUsage{
		"claude-sonnet-5": {InputTokens: 1, OutputTokens: 2, CacheReadTokens: 3, CacheWriteTokens: 4, CostUSD: ptr(0.1)},
	}}
	if !sameUsage(res.usage, want) {
		t.Fatalf("usage = %s", usageText(res.usage))
	}
	if rec, err := readRunRecord(res.dir); err != nil || rec.Baseline != nil {
		t.Fatalf("run record = %+v, %v", rec, err)
	}
}

// A killed claude prints nothing, so the bridge counts the messages it wrote
// to the transcript since it started, each message once.
func TestRunWorkerEstimatesAKilledRun(t *testing.T) {
	shortConfigHome(t)
	dir := claudeHome(t, testSession, early)
	path := dir + "/projects/-work/" + testSession + ".jsonl"
	fakeClaude(t, "printf '%s\\n' '"+streamed1+"' '"+streamed2+"' '"+second+"' >> '"+path+"'\nexec sleep 30\n")

	ctx, cancel := context.WithTimeout(context.Background(), 500*time.Millisecond)
	defer cancel()
	res := runWorker(ctx, workerSpec{dir: t.TempDir(), sessionID: testSession, resume: true, prompt: "go"}, nil)
	if !res.killed || !sameUsage(res.usage, estimated) {
		t.Fatalf("usage = %s, killed = %v", usageText(res.usage), res.killed)
	}
}
