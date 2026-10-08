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
	"path"
	"path/filepath"
	"slices"
	"strconv"
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
	b.WriteString("accounts:\n  claude:\n    harness: claude-code\ndefaults:\n  account: claude\nprojects:\n")
	for _, slug := range slugs {
		b.WriteString("  " + slug + ":\n    dir: " + t.TempDir() + "\n")
	}
	b.WriteString("work:\n  plan:\n    prompt: go\n")

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
	set, err := rules.Parse([]byte("accounts:\n  claude:\n    harness: claude-code\ndefaults:\n  account: claude\nprojects:\n  loupe:\n    dir: "+t.TempDir()+"\nwork:\n  plan: {prompt: go}\n"), rules.Defaults{PermissionMode: "acceptedits"})
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
	if err := os.WriteFile(path, []byte("projects: {}\nwork: {}\nsite: loupe\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	if err := runBridge(t, "--rules", path); err == nil || !strings.Contains(err.Error(), "field site not found") {
		t.Fatalf("err = %v", err)
	}
}

// A hook the rule file lists and the machine has not installed stops the start.
func TestBridgeRunRefusesAHookThatIsNotInstalled(t *testing.T) {
	t.Setenv("XDG_CONFIG_HOME", t.TempDir())
	t.Setenv("HOME", t.TempDir())
	path := writeRules(t, "loupe")
	hook := "hooks:\n  - package: acme/notify\n    ref: main\n    sha: " + strings.Repeat("a", 40) + "\n"
	f, err := os.OpenFile(path, os.O_APPEND|os.O_WRONLY, 0)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := f.WriteString(hook); err != nil {
		t.Fatal(err)
	}
	f.Close()

	err = runBridge(t, "--rules", path)
	if err == nil || !strings.Contains(err.Error(), path) || !strings.Contains(err.Error(), "hook acme/notify: the package is not installed") {
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
	heartbeats  []string
	// flags is the raw flags object GET /api/events sends. Empty sends none.
	flags string
	// askState answers the ask check route. Empty answers 404.
	askState  string
	askChecks []string
	// heartbeatStatus answers each heartbeat. Zero answers 204.
	heartbeatStatus int
	// heartbeatFailures answers that many first heartbeats with a 502.
	heartbeatFailures int
	// hubDelay holds each hub connection back, and heartbeatsAtHub counts the
	// heartbeats that arrived before the last one went through.
	hubDelay        time.Duration
	heartbeatsAtHub int
	// cardColumn answers the card read route. Empty answers 404.
	cardColumn string
	cardReads  []string
	// head is the head GET /api/events sends, and "" sends none. replayRows
	// are the card numbers the replay route holds, each its own sequence.
	head         string
	replayRows   []int
	replayAfters []string
	lastEventIDs []string
	// calls lists the heartbeat and events requests in order, each events
	// request with its bridge header. refuseEventsFrom answers 426 from that
	// GET /api/events call on, and zero refuses none.
	calls            []string
	refuseEventsFrom int
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
		f.calls = append(f.calls, "events "+r.Header.Get(api.BridgeHeader))
		refused := f.refuseEventsFrom > 0 && n >= f.refuseEventsFrom
		f.mu.Unlock()
		if refused {
			w.WriteHeader(http.StatusUpgradeRequired)
			fmt.Fprint(w, `{"error":"This bridge runs no work requests. Upgrade the loupe CLI."}`)

			return
		}
		projects := fmt.Sprintf(`{"id":%q,"slug":"loupe","name":"Loupe"}`, testProject)
		if n == 1 {
			projects += fmt.Sprintf(`,{"id":%q,"slug":"other","name":"Other"}`, otherProject)
		}
		flags := ""
		if f.flags != "" {
			flags = `,"flags":` + f.flags
		}
		if f.head != "" {
			flags += `,"head":` + f.head
		}
		fmt.Fprintf(w, `{"hubUrl":"http://%s/hub","jwt":"jwt-%d","topic":%q,"projects":[%s]%s}`, r.Host, n, userTopic, projects, flags)
	case "/api/events/replay":
		after, _ := strconv.Atoi(r.URL.Query().Get("after"))
		f.mu.Lock()
		f.replayAfters = append(f.replayAfters, r.URL.Query().Get("after"))
		f.mu.Unlock()
		var rows []api.ReplayEvent
		for _, n := range f.replayRows {
			if n > after {
				rows = append(rows, row(n, "next"))
			}
		}
		_ = json.NewEncoder(w).Encode(api.Replay{Events: rows})
	case "/hub":
		f.mu.Lock()
		f.hubAuth = append(f.hubAuth, r.Header.Get("Authorization"))
		f.hubTopics = append(f.hubTopics, r.URL.Query()["topic"])
		f.lastEventIDs = append(f.lastEventIDs, r.Header.Get("Last-Event-ID"))
		attempt, delay := len(f.hubAuth), f.hubDelay
		f.mu.Unlock()
		time.Sleep(delay)
		f.mu.Lock()
		f.heartbeatsAtHub = len(f.heartbeats)
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
		if prefix := "/api/bridges/" + testBridgeID + "/work-requests/"; strings.HasPrefix(r.URL.Path, prefix) {
			id, action, _ := strings.Cut(strings.TrimPrefix(r.URL.Path, prefix), "/")
			req, ok := offeredRequest(id)
			switch {
			case !ok:
				w.WriteHeader(http.StatusNotFound)
				fmt.Fprint(w, `{"error":"work_request_not_found"}`)
			case action == "claim":
				req.State = api.WorkRequestClaimed
				_ = json.NewEncoder(w).Encode(map[string]any{"workRequestId": id, "claimToken": "token-" + id, "leaseUntil": time.Now().Add(2 * time.Minute), "workRequest": req})
			default:
				var body struct {
					State string `json:"state"`
				}
				_ = json.NewDecoder(r.Body).Decode(&body)
				_ = json.NewEncoder(w).Encode(map[string]string{"state": body.State})
			}

			return
		}
		if r.Method == http.MethodPut && r.URL.Path == "/api/bridges/"+testBridgeID+"/heartbeat" {
			raw, _ := io.ReadAll(r.Body)
			f.mu.Lock()
			f.heartbeats = append(f.heartbeats, string(raw))
			f.calls = append(f.calls, "heartbeat")
			status := cmp.Or(f.heartbeatStatus, http.StatusNoContent)
			if f.heartbeatFailures > 0 {
				f.heartbeatFailures--
				status = http.StatusBadGateway
			}
			f.mu.Unlock()
			w.WriteHeader(status)

			return
		}
		if r.Method == http.MethodGet && strings.Contains(r.URL.Path, "/board/cards/") {
			f.mu.Lock()
			f.cardReads = append(f.cardReads, r.URL.Path)
			f.mu.Unlock()
			if f.cardColumn != "" {
				fmt.Fprintf(w, `{"cardId":%q,"number":87,"column":%q}`, path.Base(r.URL.Path), f.cardColumn)

				return
			}
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
		offerPayloadIn(testProject, 87, "plan"),
		offerPayloadIn(otherProject, 88, "plan"),
		offerPayloadIn(newProject, 89, "plan"),
		offerPayloadIn(newProject, 90, "plan"),
	} {
		sse += "data: " + payload + "\n\n"
	}
	fake := &fakeLoupe{sse: sse}
	server := httptest.NewServer(http.HandlerFunc(fake.serve))
	t.Cleanup(server.Close)
	cfg := testLogin(server.URL)

	loupeDir, otherDir := t.TempDir(), t.TempDir()
	body := "accounts:\n  claude:\n    harness: claude-code\ndefaults:\n  account: claude\nprojects:\n  loupe:\n    dir: " + loupeDir + "\n  other:\n    dir: " + otherDir + "\nwork:\n" +
		"  plan:\n    prompt: go\n"
	set, err := rules.Parse([]byte(body), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	if err := set.Check(context.Background(), apiClient(cfg)); err != nil {
		t.Fatal(err)
	}

	worker := &fakeWorker{result: finishedRun}
	h := &harness{worker: worker, log: &syncBuffer{}}
	h.router = withRules(&router{log: newBridgeLogger(h.log), worker: worker.ops(), bridgeID: testBridgeID}, set)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	cmd := &cobra.Command{}
	cmd.SetContext(ctx)
	done := make(chan error, 1)
	go func() { done <- subscribe(cmd, cfg, h.router) }()

	deadline := time.After(8 * time.Second)
	for fake.connections() < 3 || len(worker.recorded()) < 2 || !strings.Contains(h.log.String(), `"event":"project_gone"`) {
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
	if str(t, gone, "project") != "other" || !strings.Contains(str(t, gone, "message"), "runs no more work for it") {
		t.Fatalf("project_gone = %v", gone)
	}
	if dead := h.only(t, "work_dead"); str(t, dead, "project_slug") != "other" || str(t, dead, "reason") != "project_gone" {
		t.Fatalf("work_dead = %v", dead)
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

// The bridge sends a heartbeat as soon as it has read GET /api/events, with the
// ids of the projects it maps and its build, and stops cleanly with the stream.
func TestTheBridgeSendsAHeartbeatAtStart(t *testing.T) {
	injectVersion(t, "1.0.0")
	fake := &fakeLoupe{flags: `{"bridge.heartbeat_interval_seconds":3600}`}
	server := httptest.NewServer(http.HandlerFunc(fake.serve))
	t.Cleanup(server.Close)
	cfg := testLogin(server.URL)

	body := "accounts:\n  claude:\n    harness: claude-code\ndefaults:\n  account: claude\nprojects:\n  loupe:\n    dir: " + t.TempDir() + "\n  other:\n    dir: " + t.TempDir() + "\nwork:\n" +
		"  plan:\n    prompt: go\n"
	set, err := rules.Parse([]byte(body), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	if err := set.Check(context.Background(), apiClient(cfg)); err != nil {
		t.Fatal(err)
	}
	log := &syncBuffer{}
	worker := &fakeWorker{result: finishedRun}
	r := withRules(&router{log: newBridgeLogger(log), worker: worker.ops(), bridgeID: testBridgeID}, set)

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

// The server reads the capabilities of the bridge from its last heartbeat, so
// the bridge sends one before its first GET /api/events, and names itself on
// each one.
func TestTheBridgeSendsAHeartbeatBeforeItReadsTheEvents(t *testing.T) {
	fake := &fakeLoupe{flags: `{"bridge.heartbeat_interval_seconds":3600}`}
	server := httptest.NewServer(http.HandlerFunc(fake.serve))
	t.Cleanup(server.Close)
	cfg := testLogin(server.URL)
	set, _ := loadRules(t, defaultRules, rules.Defaults{})
	log := &syncBuffer{}
	r := withRules(&router{log: newBridgeLogger(log), worker: (&fakeWorker{result: finishedRun}).ops(), bridgeID: testBridgeID}, set)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	cmd := &cobra.Command{}
	cmd.SetContext(ctx)
	done := make(chan error, 1)
	go func() { done <- subscribe(cmd, cfg, r) }()
	eventually(t, "a JWT refresh", func() bool {
		fake.mu.Lock()
		defer fake.mu.Unlock()

		return fake.eventsCalls >= 2
	})
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}

	fake.mu.Lock()
	defer fake.mu.Unlock()
	if len(fake.calls) < 2 || fake.calls[0] != "heartbeat" || fake.calls[1] != "events "+testBridgeID {
		t.Fatalf("calls = %q, want a heartbeat, then the events of %s", fake.calls, testBridgeID)
	}
	var first api.Heartbeat
	if err := json.Unmarshal([]byte(fake.heartbeats[0]), &first); err != nil {
		t.Fatal(err)
	}
	if !slices.Contains(first.Capabilities, "commands") {
		t.Fatalf("capabilities = %q, want the full list", first.Capabilities)
	}
	for _, call := range fake.calls {
		if strings.HasPrefix(call, "events ") && call != "events "+testBridgeID {
			t.Fatalf("calls = %q, want each events call to name the bridge", fake.calls)
		}
	}
}

// A server that refuses this bridge on a JWT refresh stops it, with one clear
// line, rather than a retry loop.
func TestAnUpgradeRefusalStopsTheBridge(t *testing.T) {
	for name, from := range map[string]int{"at start": 1, "on a refresh": 2} {
		t.Run(name, func(t *testing.T) {
			fake := &fakeLoupe{flags: `{"bridge.heartbeat_interval_seconds":3600}`, refuseEventsFrom: from}
			server := httptest.NewServer(http.HandlerFunc(fake.serve))
			t.Cleanup(server.Close)
			cfg := testLogin(server.URL)
			set, _ := loadRules(t, defaultRules, rules.Defaults{})
			log := &syncBuffer{}
			r := withRules(&router{log: newBridgeLogger(log), worker: (&fakeWorker{result: finishedRun}).ops(), bridgeID: testBridgeID}, set)

			ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
			defer cancel()
			cmd := &cobra.Command{}
			cmd.SetContext(ctx)

			err := subscribe(cmd, cfg, r)
			if !errors.Is(err, api.ErrUpgradeRequired) || ctx.Err() != nil {
				t.Fatalf("err = %v, ctx = %v", err, ctx.Err())
			}
			if !strings.Contains(err.Error(), "Upgrade the loupe CLI") || !strings.Contains(log.String(), `"event":"bridge_upgrade_required"`) {
				t.Fatalf("err = %v, log = %s", err, log.String())
			}
			fake.mu.Lock()
			defer fake.mu.Unlock()
			if fake.eventsCalls != from {
				t.Fatalf("events calls = %d, want %d", fake.eventsCalls, from)
			}
		})
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

// A service that still passes --max-workers must start, so the flag stays
// declared and deprecated.
func TestBridgeRunKeepsMaxWorkersAsADeprecatedFlag(t *testing.T) {
	flag := newBridgeRunCmd().Flags().Lookup("max-workers")
	if flag == nil {
		t.Fatal("--max-workers is not registered")
	}
	if !strings.Contains(flag.Deprecated, "maxWorkers in rules.yaml") {
		t.Fatalf("--max-workers deprecation = %q", flag.Deprecated)
	}
}

// --max-workers no longer bounds anything. A bridge started with it warns, and
// runs as many workers as maxWorkers in rules.yaml allows.
func TestTheMaxWorkersFlagIsIgnoredWithAWarning(t *testing.T) {
	h := newHarnessWith(t, withMaxWorkers(defaultRules, 4), rules.Defaults{})
	cmd := newBridgeRunCmd()
	cmd.SetOut(io.Discard)
	if err := cmd.ParseFlags([]string{"--max-workers", "3"}); err != nil {
		t.Fatal(err)
	}

	logBridgeStart(h.router.log, cmd, h.router.rules(), "rules.yaml", "bridge.log", testBridgeID)

	warned := h.events(t, "max_workers_flag_ignored")
	if len(warned) != 1 || warned[0]["level"] != "WARN" || num(t, warned[0], "flag_value") != 3 || num(t, warned[0], "max_workers") != 4 {
		t.Fatalf("max_workers_flag_ignored = %v", warned)
	}
	if msg, _ := warned[0]["message"].(string); !strings.Contains(msg, "maxWorkers") || !strings.Contains(msg, "not 3") {
		t.Fatalf("message = %q", msg)
	}
	started := h.events(t, "bridge_started")
	if len(started) != 1 || num(t, started[0], "max_workers") != 4 || started[0]["worker_pools"] != "default=4" {
		t.Fatalf("bridge_started = %v", started)
	}

	h.worker.block = make(chan struct{})
	for _, card := range []int{87, 88, 89, 90, 91} {
		h.router.onData([]byte(cardMoved(card)))
	}
	if got := h.used(); got != 4 {
		t.Fatalf("%d workers run, want the 4 of maxWorkers", got)
	}
	close(h.worker.block)
	h.router.wg.Wait()
}

func TestAnUnsetMaxWorkersFlagLogsNoWarning(t *testing.T) {
	h := newHarnessWith(t, poolRules, rules.Defaults{})
	cmd := newBridgeRunCmd()
	if err := cmd.ParseFlags(nil); err != nil {
		t.Fatal(err)
	}

	logBridgeStart(h.router.log, cmd, h.router.rules(), "rules.yaml", "bridge.log", testBridgeID)

	if warned := h.events(t, "max_workers_flag_ignored"); len(warned) != 0 {
		t.Fatalf("max_workers_flag_ignored = %v", warned)
	}
	started := h.events(t, "bridge_started")
	if len(started) != 1 || num(t, started[0], "max_workers") != 4 || started[0]["worker_pools"] != "default=3,quick=1" {
		t.Fatalf("bridge_started = %v", started)
	}
}

// The same value in the flag and in the file still warns, because the flag
// does nothing.
func TestAMatchingMaxWorkersFlagStillWarns(t *testing.T) {
	h := newHarnessWith(t, withMaxWorkers(defaultRules, 3), rules.Defaults{})
	cmd := newBridgeRunCmd()
	cmd.SetOut(io.Discard)
	if err := cmd.ParseFlags([]string{"--max-workers=3"}); err != nil {
		t.Fatal(err)
	}

	logBridgeStart(h.router.log, cmd, h.router.rules(), "rules.yaml", "bridge.log", testBridgeID)

	warned := h.events(t, "max_workers_flag_ignored")
	if len(warned) != 1 {
		t.Fatalf("max_workers_flag_ignored = %v", warned)
	}
	if msg, _ := warned[0]["message"].(string); !strings.Contains(msg, "maxWorkers") || strings.Contains(msg, "not 3") {
		t.Fatalf("message = %q", msg)
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

// A bridge with no cursor file starts from the head of GET /api/events. On
// connect it reads the outbox after the head, and the hub starts after it too.
// An event that both send runs once, and the cursor file keeps the last id.
// The bridge reads no card, because a work request is never stale.
func TestTheBridgeCatchesUpFromTheHead(t *testing.T) {
	fake := &fakeLoupe{
		head:       "10",
		replayRows: []int{11},
		sse:        "id: 11\ndata: " + cardMoved(11) + "\n\nid: 12\ndata: " + cardMoved(12) + "\n\n",
	}
	server := httptest.NewServer(http.HandlerFunc(fake.serve))
	t.Cleanup(server.Close)
	cfg := testLogin(server.URL)
	set, _ := loadRules(t, defaultRules, rules.Defaults{})
	log := &syncBuffer{}
	worker := &fakeWorker{result: finishedRun}
	r := withRules(&router{log: newBridgeLogger(log), worker: worker.ops(), bridgeID: testBridgeID}, set)
	r.cursorFile = filepath.Join(t.TempDir(), "cursor.json")

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	cmd := &cobra.Command{}
	cmd.SetContext(ctx)
	done := make(chan error, 1)
	go func() { done <- subscribe(cmd, cfg, r) }()

	eventually(t, "both cards and a third connection", func() bool {
		return len(worker.recorded()) == 2 && fake.connections() >= 3
	})
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}

	st, ok, err := readCursor(r.cursorFile)
	if err != nil || !ok || st.Cursor != 12 || st.Floor != 10 {
		t.Fatalf("cursor = %+v, ok = %v, err = %v", st, ok, err)
	}
	fake.mu.Lock()
	defer fake.mu.Unlock()
	if fake.lastEventIDs[0] != "10" || fake.replayAfters[0] != "10" {
		t.Fatalf("Last-Event-ID = %q, replay afters = %q", fake.lastEventIDs, fake.replayAfters)
	}
	if len(worker.recorded()) != 2 {
		t.Fatalf("workers = %+v, log = %s", worker.recorded(), log.String())
	}
}

func TestTheHeartbeatBodyCarriesTheRuleFileName(t *testing.T) {
	for value, want := range map[string]string{`"studio"`: "studio", `""`: ""} {
		body := "name: " + value + "\naccounts:\n  claude:\n    harness: claude-code\ndefaults:\n  account: claude\nprojects:\n  loupe:\n    dir: " + t.TempDir() + "\nwork:\n" +
			"  plan:\n    prompt: go\n"
		set, err := rules.Parse([]byte(body), rules.Defaults{})
		if err != nil {
			t.Fatal(err)
		}
		if hb := heartbeatBody(set, ""); hb.Name == nil || *hb.Name != want {
			t.Fatalf("name: %s gave %v, want %q", value, hb.Name, want)
		}
	}
}

// The server reads an absent pushLogin as no change, so the bridge always
// sends one, and "" when no agent account is checked.
func TestTheHeartbeatBodyCarriesThePushLogin(t *testing.T) {
	body := "accounts:\n  claude:\n    harness: claude-code\ndefaults:\n  account: claude\nprojects:\n  loupe:\n    dir: " + t.TempDir() + "\nwork:\n  plan:\n    prompt: go\n"
	set, err := rules.Parse([]byte(body), rules.Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	for login, want := range map[string]string{"bot": `"pushLogin":"bot"`, "": `"pushLogin":""`} {
		b, err := json.Marshal(heartbeatBody(set, login))
		if err != nil {
			t.Fatal(err)
		}
		if !strings.Contains(string(b), want) {
			t.Fatalf("heartbeat body %s, want %s", b, want)
		}
	}
}
