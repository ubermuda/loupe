package cmd

import (
	"bytes"
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// runBridge parses args and runs the command far enough to reach its start
// checks, which come before anything that starts a process or opens a socket.
func runBridge(t *testing.T, args ...string) error {
	t.Helper()
	cmd := newBridgeRunCmd()
	cmd.SetArgs(args)
	cmd.SetOut(&bytes.Buffer{})
	cmd.SetErr(&bytes.Buffer{})
	cmd.SilenceUsage, cmd.SilenceErrors = true, true

	return cmd.Execute()
}

// writeRules writes a rule file mapping each slug to a real directory.
func writeRules(t *testing.T, slugs ...string) string {
	t.Helper()
	var b strings.Builder
	b.WriteString("projects:\n")
	for _, slug := range slugs {
		b.WriteString("  " + slug + ":\n    dir: " + t.TempDir() + "\n")
	}
	b.WriteString("rules:\n  - on: board.card_moved\n    project: " + slugs[0] + "\n    to: next\n    prompt: go\n")

	path := filepath.Join(t.TempDir(), "rules.yaml")
	if err := os.WriteFile(path, []byte(b.String()), 0o600); err != nil {
		t.Fatal(err)
	}

	return path
}

// The rule file is mandatory. The error shows an example, so the operator can
// start from it.
func TestBridgeRunRefusesToStartWithoutARuleFile(t *testing.T) {
	missing := filepath.Join(t.TempDir(), "rules.yaml")

	err := runBridge(t, "--rules", missing)
	if !errors.Is(err, rules.ErrMissing) {
		t.Fatalf("err = %v", err)
	}
	if strings.Count(err.Error(), missing) != 1 || !strings.Contains(err.Error(), rules.Example) {
		t.Fatalf("the error must name the path once and show the example: %v", err)
	}
}

// A malformed flag fails at start, before the rule file is read, and the error
// names the flag.
func TestBridgeRunRefusesAnInvalidDefault(t *testing.T) {
	for flag, want := range map[string]string{
		"--permission-mode=accept edits": `--permission-mode "accept edits" holds whitespace`,
		"--model=claude opus":            `--model "claude opus" holds whitespace`,
	} {
		err := runBridge(t, "--rules", writeRules(t, "loupe"), flag)
		if err == nil || !strings.Contains(err.Error(), want) || strings.Contains(err.Error(), "rule file") {
			t.Fatalf("%s: err = %v", flag, err)
		}
	}
}

// A mode this build does not know starts the bridge with a warning, so a newer
// claude keeps working and a typo still shows.
func TestAnUnknownPermissionModeIsLoggedAtStart(t *testing.T) {
	set, err := rules.Parse([]byte("projects:\n  loupe:\n    dir: "+t.TempDir()+"\nrules:\n  - {on: board.card_moved, project: loupe, to: next, prompt: go}\n"), rules.Defaults{PermissionMode: "acceptedits"})
	if err != nil {
		t.Fatal(err)
	}
	var out bytes.Buffer

	warnUnknownModes(newBridgeLogger(&out), set)

	var line map[string]any
	if err := json.Unmarshal(out.Bytes(), &line); err != nil {
		t.Fatalf("log = %q: %v", out.String(), err)
	}
	if line["event"] != "permission_mode_unknown" || line["mode"] != "acceptedits" || line["level"] != "WARN" {
		t.Fatalf("line = %v", line)
	}
}

// With no --rules, the file sits beside config.json.
func TestBridgeRunReadsRulesFromTheConfigDir(t *testing.T) {
	t.Setenv("XDG_CONFIG_HOME", t.TempDir())
	t.Setenv("HOME", t.TempDir())

	path, err := defaultRulesPath()
	if err != nil {
		t.Fatal(err)
	}
	if filepath.Base(path) != "rules.yaml" || filepath.Base(filepath.Dir(path)) != "loupe" {
		t.Fatalf("defaultRulesPath() = %q", path)
	}

	if err := runBridge(t); err == nil || !strings.Contains(err.Error(), path) {
		t.Fatalf("err = %v, want it to name %s", err, path)
	}
}

func TestBridgeRunRefusesAnInvalidRuleFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), "rules.yaml")
	if err := os.WriteFile(path, []byte("projects: {}\nrules: []\nsite: loupe\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	if err := runBridge(t, "--rules", path); err == nil || !strings.Contains(err.Error(), "field site not found") {
		t.Fatalf("err = %v", err)
	}
}

// fakeLoupe serves the columns check, GET /api/events and a Mercure hub. The
// first hub connection sends its events, and the first two close, so the
// bridge refreshes its JWT twice. From the second call on, GET /api/events no
// longer lists the other project, as if it had been deleted.
type fakeLoupe struct {
	mu          sync.Mutex
	eventsCalls int
	hubAuth     []string
	hubTopics   [][]string
	sse         string
	reports     []string
	heartbeats  []string
	// flags is the raw flags object GET /api/events sends. Empty sends none.
	flags string
	// askState answers the ask check route. Empty answers 404.
	askState  string
	askChecks []string
	// heartbeatStatus answers each heartbeat. Zero answers 204.
	heartbeatStatus int
}

const (
	userTopic = "https://loupe.test/users/0192f3a1-4b2c-7d3e-8f10-0000000000aa/events"
	// newProject is created after the bridge starts, so GET /api/events never
	// lists it, and the file does not map it.
	newProject = "0192f3a1-4b2c-7d3e-8f10-000000000003"
)

func (f *fakeLoupe) serve(w http.ResponseWriter, r *http.Request) {
	switch r.URL.Path {
	case "/api/projects/loupe/board/columns":
		fmt.Fprint(w, `{"project":{"id":"`+testProject+`","slug":"loupe"},"columns":[{"slug":"next"}]}`)
	case "/api/projects/other/board/columns":
		fmt.Fprint(w, `{"project":{"id":"`+otherProject+`","slug":"other"},"columns":[{"slug":"next"}]}`)
	case "/api/events":
		f.mu.Lock()
		f.eventsCalls++
		n := f.eventsCalls
		f.mu.Unlock()
		projects := fmt.Sprintf(`{"id":%q,"slug":"loupe","name":"Loupe"}`, testProject)
		if n == 1 {
			projects += fmt.Sprintf(`,{"id":%q,"slug":"other","name":"Other"}`, otherProject)
		}
		flags := ""
		if f.flags != "" {
			flags = `,"flags":` + f.flags
		}
		fmt.Fprintf(w, `{"hubUrl":"http://%s/hub","jwt":"jwt-%d","topic":%q,"projects":[%s]%s}`, r.Host, n, userTopic, projects, flags)
	case "/hub":
		f.mu.Lock()
		f.hubAuth = append(f.hubAuth, r.Header.Get("Authorization"))
		f.hubTopics = append(f.hubTopics, r.URL.Query()["topic"])
		attempt := len(f.hubAuth)
		f.mu.Unlock()
		w.Header().Set("Content-Type", "text/event-stream")
		w.WriteHeader(http.StatusOK)
		switch attempt {
		case 1:
			fmt.Fprint(w, f.sse)
		case 2:
		default:
			<-r.Context().Done()
		}
	default:
		if r.Method == http.MethodPut && strings.HasSuffix(r.URL.Path, "/bridges/"+testBridgeID+"/rules") {
			raw, _ := io.ReadAll(r.Body)
			f.mu.Lock()
			f.reports = append(f.reports, r.URL.Path+" "+string(raw))
			f.mu.Unlock()
			w.WriteHeader(http.StatusNoContent)

			return
		}
		if r.Method == http.MethodPut && r.URL.Path == "/api/bridges/"+testBridgeID+"/heartbeat" {
			raw, _ := io.ReadAll(r.Body)
			f.mu.Lock()
			f.heartbeats = append(f.heartbeats, string(raw))
			status := cmp.Or(f.heartbeatStatus, http.StatusNoContent)
			f.mu.Unlock()
			w.WriteHeader(status)

			return
		}
		if r.Method == http.MethodGet && strings.Contains(r.URL.Path, "/inbox/asks/") {
			f.mu.Lock()
			f.askChecks = append(f.askChecks, r.URL.Path)
			f.mu.Unlock()
			if f.askState != "" {
				fmt.Fprint(w, f.askState)

				return
			}
		}
		w.WriteHeader(http.StatusNotFound)
	}
}

func (f *fakeLoupe) sentReports() []string {
	f.mu.Lock()
	defer f.mu.Unlock()

	return append([]string(nil), f.reports...)
}

func (f *fakeLoupe) connections() int {
	f.mu.Lock()
	defer f.mu.Unlock()

	return len(f.hubAuth)
}

// Two mapped projects share the one user topic, and each starts workers in its
// own directory. A project created after the start reaches the bridge, and the
// file does not map it, so it is ignored and logged once. The bridge reads
// GET /api/events once to start, and again for the JWT of each reconnect. A
// mapped project that a refresh no longer lists is logged once, with its rules.
func TestOneTopicServesEveryProject(t *testing.T) {
	sse := ""
	for _, payload := range []string{
		movedPayload(87, "backlog", "next", "human"),
		strings.Replace(movedPayload(88, "backlog", "next", "human"), testProject, otherProject, 1),
		strings.Replace(movedPayload(89, "backlog", "next", "human"), testProject, newProject, 1),
		strings.Replace(movedPayload(90, "backlog", "next", "human"), testProject, newProject, 1),
	} {
		sse += "data: " + payload + "\n\n"
	}
	fake := &fakeLoupe{sse: sse}
	server := httptest.NewServer(http.HandlerFunc(fake.serve))
	t.Cleanup(server.Close)
	cfg := testLogin(server.URL)

	loupeDir, otherDir := t.TempDir(), t.TempDir()
	body := "projects:\n  loupe:\n    dir: " + loupeDir + "\n  other:\n    dir: " + otherDir + "\nrules:\n" +
		"  - on: board.card_moved\n    project: loupe\n    to: next\n    prompt: go\n" +
		"  - name: other-plan\n    on: board.card_moved\n    project: other\n    to: next\n    prompt: go\n"
	set, err := rules.Parse([]byte(body), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	if err := set.Check(context.Background(), apiClient(cfg)); err != nil {
		t.Fatal(err)
	}

	worker := &fakeWorker{}
	h := &harness{worker: worker, log: &syncBuffer{}}
	h.router = withRules(&router{log: newBridgeLogger(h.log), maxWorkers: defaultMaxWorkers, worker: worker.ops(), bridgeID: testBridgeID}, set)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	cmd := &cobra.Command{}
	cmd.SetContext(ctx)
	done := make(chan error, 1)
	go func() { done <- subscribe(cmd, cfg, h.router) }()

	gonePath := "/api/projects/" + otherProject + "/bridges/" + testBridgeID + "/rules "
	goneReport := gonePath + `{"rules":[{"name":"other-plan","on":"board.card_moved","columns":["next"],"state":"dead","reason":"project_gone"}]}`
	deadline := time.After(8 * time.Second)
	for fake.connections() < 3 || len(worker.recorded()) < 2 || !slices.Contains(fake.sentReports(), goneReport) {
		select {
		case <-deadline:
			t.Fatalf("connections = %d, workers = %+v, log = %s", fake.connections(), worker.recorded(), h.log.String())
		case <-time.After(20 * time.Millisecond):
		}
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}

	var dirs []string
	for _, call := range worker.recorded() {
		dirs = append(dirs, call.dir)
	}
	slices.Sort(dirs)
	want := []string{loupeDir, otherDir}
	slices.Sort(want)
	if !slices.Equal(dirs, want) {
		t.Fatalf("worker dirs = %v, want %v", dirs, want)
	}
	if got := str(t, h.only(t, "project_unmapped"), "project"); got != newProject {
		t.Fatalf("project_unmapped = %q", got)
	}
	gone := h.only(t, "project_gone")
	if str(t, gone, "project") != "other" || fmt.Sprint(gone["rules"]) != "[other-plan]" || !strings.Contains(str(t, gone, "message"), "rules other-plan stop working") {
		t.Fatalf("project_gone = %v", gone)
	}
	if dead := h.only(t, "rule_dead"); str(t, dead, "rule") != "other-plan" || str(t, dead, "project_slug") != "other" || str(t, dead, "reason") != "project_gone" {
		t.Fatalf("rule_dead = %v", dead)
	}

	fake.mu.Lock()
	defer fake.mu.Unlock()
	for i, topics := range fake.hubTopics {
		if !slices.Equal(topics, []string{userTopic}) {
			t.Fatalf("connection %d asked for topics %q, want the user topic alone", i+1, topics)
		}
	}
	if fake.eventsCalls < 3 || fake.hubAuth[0] != "Bearer jwt-1" || fake.hubAuth[1] != "Bearer jwt-2" || fake.hubAuth[2] != "Bearer jwt-3" {
		t.Fatalf("GET /api/events ran %d times, hub auth = %q", fake.eventsCalls, fake.hubAuth)
	}
}

// The bridge reports every mapped project once at start, by project id, and
// again for the project whose column a live rename takes away.
func TestTheBridgeReportsRuleHealthAtStartAndOnAChange(t *testing.T) {
	rename := fmt.Sprintf(`{"type":"board.column_renamed","projectId":%q,"subject":{"type":"board_column","id":"0192f3a1-5555-7d3e-8f10-a2b3c4d5e6f7"},"actor":"human","fromSlug":"next","toSlug":"ready"}`, testProject)
	fake := &fakeLoupe{sse: "data: " + rename + "\n\n"}
	server := httptest.NewServer(http.HandlerFunc(fake.serve))
	t.Cleanup(server.Close)
	cfg := testLogin(server.URL)

	body := "projects:\n  loupe:\n    dir: " + t.TempDir() + "\n  other:\n    dir: " + t.TempDir() + "\nrules:\n" +
		"  - name: plan\n    on: board.card_moved\n    project: loupe\n    to: next\n    prompt: SECRET go\n" +
		"  - name: other-plan\n    on: board.card_moved\n    project: other\n    to: next\n    prompt: go\n"
	set, err := rules.Parse([]byte(body), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	if err := set.Check(context.Background(), apiClient(cfg)); err != nil {
		t.Fatal(err)
	}
	log := &syncBuffer{}
	worker := &fakeWorker{}
	r := withRules(&router{log: newBridgeLogger(log), maxWorkers: defaultMaxWorkers, worker: worker.ops(), bridgeID: testBridgeID}, set)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	cmd := &cobra.Command{}
	cmd.SetContext(ctx)
	done := make(chan error, 1)
	go func() { done <- subscribe(cmd, cfg, r) }()

	loupePath := "/api/projects/" + testProject + "/bridges/" + testBridgeID + "/rules "
	otherPath := "/api/projects/" + otherProject + "/bridges/" + testBridgeID + "/rules "
	live := `{"rules":[{"name":"plan","on":"board.card_moved","columns":["next"],"state":"live","reason":null}]}`
	dead := `{"rules":[{"name":"plan","on":"board.card_moved","columns":["next"],"state":"dead","reason":"column_renamed"}]}`
	otherLive := `{"rules":[{"name":"other-plan","on":"board.card_moved","columns":["next"],"state":"live","reason":null}]}`

	eventually(t, "the dead report and the other project's report", func() bool {
		sent := fake.sentReports()
		return slices.Contains(sent, loupePath+dead) && slices.Contains(sent, otherPath+otherLive)
	})
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}

	var loupeReports []string
	sawOther := false
	for _, report := range fake.sentReports() {
		switch {
		case strings.HasPrefix(report, loupePath):
			loupeReports = append(loupeReports, strings.TrimPrefix(report, loupePath))
		case report == otherPath+otherLive:
			sawOther = true
		default:
			t.Fatalf("unexpected report %s", report)
		}
	}
	// The start report can still wait when the rename arrives, and then the
	// dead report replaces it. The last report must be the dead one.
	if !sawOther || len(loupeReports) == 0 || loupeReports[len(loupeReports)-1] != dead || (len(loupeReports) == 2 && loupeReports[0] != live) {
		t.Fatalf("reports = %v", fake.sentReports())
	}
	if strings.Contains(strings.Join(fake.sentReports(), ""), "SECRET") {
		t.Fatal("a report carries prompt text")
	}
}

// The bridge sends a heartbeat as soon as it has read GET /api/events, with the
// ids of the projects it maps and its build, and stops cleanly with the stream.
func TestTheBridgeSendsAHeartbeatAtStart(t *testing.T) {
	injectVersion(t, "1.0.0")
	fake := &fakeLoupe{flags: `{"bridge.heartbeat_interval_seconds":3600}`}
	server := httptest.NewServer(http.HandlerFunc(fake.serve))
	t.Cleanup(server.Close)
	cfg := testLogin(server.URL)

	body := "projects:\n  loupe:\n    dir: " + t.TempDir() + "\n  other:\n    dir: " + t.TempDir() + "\nrules:\n" +
		"  - name: plan\n    on: board.card_moved\n    project: loupe\n    to: next\n    prompt: go\n"
	set, err := rules.Parse([]byte(body), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	if err := set.Check(context.Background(), apiClient(cfg)); err != nil {
		t.Fatal(err)
	}
	log := &syncBuffer{}
	worker := &fakeWorker{}
	r := withRules(&router{log: newBridgeLogger(log), maxWorkers: defaultMaxWorkers, worker: worker.ops(), bridgeID: testBridgeID}, set)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	cmd := &cobra.Command{}
	cmd.SetContext(ctx)
	done := make(chan error, 1)
	go func() { done <- subscribe(cmd, cfg, r) }()

	eventually(t, "the start heartbeat", func() bool {
		return strings.Contains(log.String(), `"event":"heartbeat_sent"`)
	})
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}

	fake.mu.Lock()
	defer fake.mu.Unlock()
	var sent api.Heartbeat
	if err := json.Unmarshal([]byte(fake.heartbeats[0]), &sent); err != nil {
		t.Fatal(err)
	}
	if !slices.Equal(sent.Projects, []string{testProject, otherProject}) && !slices.Equal(sent.Projects, []string{otherProject, testProject}) {
		t.Fatalf("projects = %v", sent.Projects)
	}
	if sent.CLIVersion != "1.0.0" {
		t.Fatalf("cliVersion = %q, want 1.0.0", sent.CLIVersion)
	}
	if !strings.Contains(log.String(), `"event":"heartbeat_sent"`) || !strings.Contains(log.String(), `"interval_seconds":3600`) {
		t.Fatalf("log = %s", log.String())
	}
}

// A mapped project that GET /api/events does not list could never fire, so the
// bridge refuses to start rather than look healthy.
func TestMissingProjectsNamesAMappedProjectTheServerDoesNotList(t *testing.T) {
	set, _ := loadRules(t, defaultRules, rules.Defaults{})

	if got := missingProjects(set, api.Events{Projects: []api.EventsProject{{ID: otherProject}}}); !slices.Equal(got, []string{"loupe"}) {
		t.Fatalf("missing = %v", got)
	}
	if got := missingProjects(set, api.Events{Projects: []api.EventsProject{{ID: strings.ToUpper(testProject)}}}); len(got) != 0 {
		t.Fatalf("an upper-case id must still match: %v", got)
	}
}

// TestBridgeRunFailsFastWithoutClaude keeps the missing-binary error at the
// start of the run. Reaching it also proves a valid rule file passes its check.
func TestBridgeRunFailsFastWithoutClaude(t *testing.T) {
	original := lookPath
	lookPath = func(string) (string, error) { return "", errors.New("not found") }
	t.Cleanup(func() { lookPath = original })

	err := runBridge(t, "--rules", writeRules(t, "loupe"))
	if err == nil || !strings.Contains(err.Error(), "claude is not installed") {
		t.Fatalf("err = %v", err)
	}
}

// The projects map replaces --site and --dir, so a stale invocation fails
// instead of running with flags the binary ignores.
func TestBridgeRunHasNoSiteOrDirFlags(t *testing.T) {
	flags := newBridgeRunCmd().Flags()
	for _, name := range []string{"site", "dir", "session", "attach"} {
		if flags.Lookup(name) != nil {
			t.Fatalf("--%s is still registered", name)
		}
	}
	if err := runBridge(t, "--dir", t.TempDir()); err == nil || !strings.Contains(err.Error(), "unknown flag: --dir") {
		t.Fatalf("err = %v", err)
	}
}

// --permission-mode and --model are defaults for rules. Empty passes no flag,
// which keeps the operator opting in.
func TestBridgeRunDefaultFlagsAreEmpty(t *testing.T) {
	for _, name := range []string{"permission-mode", "model", "rules", "log-file"} {
		flag := newBridgeRunCmd().Flags().Lookup(name)
		if flag == nil {
			t.Fatalf("--%s is not registered", name)
		}
		if flag.DefValue != "" {
			t.Fatalf("--%s defaults to %q, want empty", name, flag.DefValue)
		}
	}
}

// One worker at a time surprises a person who drags several cards, and no bound
// starts twenty agents by accident.
func TestBridgeRunBoundsWorkersByDefault(t *testing.T) {
	flag := newBridgeRunCmd().Flags().Lookup("max-workers")
	if flag == nil {
		t.Fatal("--max-workers is not registered")
	}
	if flag.DefValue != "3" {
		t.Fatalf("--max-workers defaults to %q, want 3", flag.DefValue)
	}
}

// A bound below 1 runs nothing and looks healthy, so it fails at startup.
func TestBridgeRunRejectsABoundBelowOne(t *testing.T) {
	for _, bound := range []string{"0", "-1"} {
		err := runBridge(t, "--rules", writeRules(t, "loupe"), "--max-workers", bound)
		if err == nil || !strings.Contains(err.Error(), "--max-workers must be at least 1") {
			t.Fatalf("--max-workers %s: err = %v", bound, err)
		}
	}
}

func TestDefaultLogPathSitsUnderTheConfigDir(t *testing.T) {
	got := defaultLogPath()
	if filepath.Base(got) != "bridge.log" || filepath.Base(filepath.Dir(got)) != "loupe" {
		t.Fatalf("defaultLogPath() = %q", got)
	}
}

// A supervisor's log is a history. Truncating it per run loses the record of
// every worker the previous run reported.
func TestOpenLogFileAppends(t *testing.T) {
	path := filepath.Join(t.TempDir(), "nested", "bridge.log")

	for _, line := range []string{"first\n", "second\n"} {
		f, err := openLogFile(path)
		if err != nil {
			t.Fatal(err)
		}
		if _, err := f.WriteString(line); err != nil {
			t.Fatal(err)
		}
		f.Close()
	}

	got, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if string(got) != "first\nsecond\n" {
		t.Fatalf("log = %q, want both runs", got)
	}
}

// brokenPipe stands in for a stdout whose reader has left, such as a `jq` the
// operator stopped.
type brokenPipe struct{}

func (brokenPipe) Write([]byte) (int, error) { return 0, errors.New("broken pipe") }

// A reader that leaves must not take the history with it. io.MultiWriter stops
// at the first writer that fails, so the file has to come first.
func TestTheLogFileOutlivesAFailedStdout(t *testing.T) {
	var file bytes.Buffer

	newBridgeLogger(bridgeLogWriter(&file, brokenPipe{})).Info("worker_queued", "card", 87)

	if file.Len() == 0 {
		t.Fatal("a failed stdout took the log file with it")
	}
}

// The log reaches stdout and the file alike, so a terminal and a history never
// disagree about what happened.
func TestTheBridgeLoggerWritesJSONToEveryWriter(t *testing.T) {
	var stdout, file bytes.Buffer

	newBridgeLogger(bridgeLogWriter(&file, &stdout)).Info("worker_queued", "card", 87)

	if stdout.String() != file.String() {
		t.Fatalf("stdout = %q, file = %q", stdout.String(), file.String())
	}
	var line map[string]any
	if err := json.Unmarshal(stdout.Bytes(), &line); err != nil {
		t.Fatalf("line %q is not JSON: %v", stdout.String(), err)
	}
	if line["event"] != "worker_queued" || line["card"] != float64(87) {
		t.Fatalf("line = %v", line)
	}
}
