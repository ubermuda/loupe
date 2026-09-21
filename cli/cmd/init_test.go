package cmd

import (
	"bytes"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/mcpjson"
	"github.com/ubermuda/loupe/cli/internal/projectfile"
)

const firstProject = "01a0c0d9-905c-7922-a586-ccc8ce043704"
const secondProject = "01a0c0e1-1111-7922-a586-ccc8ce043705"

// projectsServer answers GET /api/projects with sites.
func projectsServer(t *testing.T, sites string) *httptest.Server {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/projects" {
			t.Errorf("unexpected path %s", r.URL.Path)
		}
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprintf(w, `{"sites":[%s]}`, sites)
	}))
	t.Cleanup(server.Close)

	return server
}

// inRepo runs the test in a fresh directory that stands in for a repository,
// with a login stored for baseURL.
func inRepo(t *testing.T, baseURL string) string {
	t.Helper()
	useLoginConfigHome(t)
	if baseURL != "" {
		if err := config.Save(config.Config{BaseURL: baseURL, Token: "t0ken"}); err != nil {
			t.Fatalf("save config: %v", err)
		}
	}

	dir := t.TempDir()
	previous, err := os.Getwd()
	if err != nil {
		t.Fatalf("getwd: %v", err)
	}
	if err := os.Chdir(dir); err != nil {
		t.Fatalf("chdir: %v", err)
	}
	t.Cleanup(func() { _ = os.Chdir(previous) })

	return dir
}

// runInit runs `loupe init` with args and the given answer on stdin.
func runInit(t *testing.T, answer string, args ...string) (string, error) {
	t.Helper()
	var out bytes.Buffer
	cmd := newInitCmd()
	cmd.SetOut(&out)
	cmd.SetErr(&out)
	cmd.SetIn(strings.NewReader(answer))
	cmd.SetArgs(args)
	err := cmd.Execute()

	return out.String(), err
}

func TestInitWritesTheOneProjectTheLoginCoversWithNoQuestion(t *testing.T) {
	server := projectsServer(t, `{"id":"`+firstProject+`","slug":"dev","name":"Dev Project"}`)
	dir := inRepo(t, server.URL)

	out, err := runInit(t, "")
	if err != nil {
		t.Fatalf("init: %v", err)
	}
	if !strings.Contains(out, "Dev Project") {
		t.Fatalf("output must name the project, got %q", out)
	}

	file, err := projectfile.Load(dir)
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if file.Project != firstProject {
		t.Fatalf("Project: got %q, want %q", file.Project, firstProject)
	}
}

func TestInitAsksWhichProjectWhenTheLoginCoversSeveral(t *testing.T) {
	server := projectsServer(t, `{"id":"`+firstProject+`","slug":"one","name":"One"},{"id":"`+secondProject+`","slug":"two","name":"Two"}`)
	dir := inRepo(t, server.URL)

	if _, err := runInit(t, "2\n"); err != nil {
		t.Fatalf("init: %v", err)
	}

	file, err := projectfile.Load(dir)
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if file.Project != secondProject {
		t.Fatalf("Project: got %q, want the second project", file.Project)
	}
}

func TestInitRefusesAnAnswerThatIsNotOnTheList(t *testing.T) {
	server := projectsServer(t, `{"id":"`+firstProject+`","slug":"one","name":"One"},{"id":"`+secondProject+`","slug":"two","name":"Two"}`)
	dir := inRepo(t, server.URL)

	if _, err := runInit(t, "9\n"); err == nil {
		t.Fatal("init with an answer off the list: got no error")
	}
	if _, err := projectfile.Load(dir); err == nil {
		t.Fatal("init must write no file when the answer is refused")
	}
}

func TestInitRefusesToReplaceAnExistingFileWithoutForce(t *testing.T) {
	dir := inRepo(t, "")
	if err := projectfile.Write(dir, firstProject); err != nil {
		t.Fatalf("Write: %v", err)
	}

	_, err := runInit(t, "", "--project", secondProject)
	if err == nil {
		t.Fatal("init over an existing file: got no error")
	}
	if !strings.Contains(err.Error(), "--force") {
		t.Fatalf("error must say how to replace it, got %v", err)
	}

	file, _ := projectfile.Load(dir)
	if file.Project != firstProject {
		t.Fatalf("Project: got %q, want the file left alone", file.Project)
	}
}

func TestInitReplacesAnExistingFileWithForce(t *testing.T) {
	dir := inRepo(t, "")
	if err := projectfile.Write(dir, firstProject); err != nil {
		t.Fatalf("Write: %v", err)
	}

	if _, err := runInit(t, "", "--project", secondProject, "--force"); err != nil {
		t.Fatalf("init --force: %v", err)
	}

	file, err := projectfile.Load(dir)
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if file.Project != secondProject {
		t.Fatalf("Project: got %q, want the replacement", file.Project)
	}
}

func TestInitWithAProjectFlagAsksTheServerNothing(t *testing.T) {
	dir := inRepo(t, "")

	if _, err := runInit(t, "", "--project", firstProject); err != nil {
		t.Fatalf("init --project: %v", err)
	}

	file, err := projectfile.Load(dir)
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if file.Project != firstProject {
		t.Fatalf("Project: got %q, want %q", file.Project, firstProject)
	}
}

func TestInitSaysSoWhenTheLoginCoversNoProject(t *testing.T) {
	server := projectsServer(t, "")
	inRepo(t, server.URL)

	_, err := runInit(t, "")
	if err == nil {
		t.Fatal("init with no project: got no error")
	}
	if !strings.Contains(err.Error(), "no project") {
		t.Fatalf("error must say the login covers no project, got %v", err)
	}
}

func TestInitRefusesToReplaceAMalformedFileWithoutForce(t *testing.T) {
	dir := inRepo(t, "")
	if err := os.WriteFile(filepath.Join(dir, projectfile.Name), []byte("project: [broken\n"), 0o644); err != nil {
		t.Fatalf("write fixture: %v", err)
	}

	_, err := runInit(t, "", "--project", firstProject)
	if err == nil {
		t.Fatal("init over a malformed file: got no error")
	}
	if !strings.Contains(err.Error(), "--force") {
		t.Fatalf("error must say how to replace it, got %v", err)
	}
}

// mcpState reads what .mcp.json in dir says about the loupe server.
func mcpState(t *testing.T, dir string) mcpjson.State {
	t.Helper()
	state, _, err := mcpjson.Read(dir)
	if err != nil {
		t.Fatalf("mcpjson.Read: %v", err)
	}

	return state
}

func TestInitWritesTheMcpEntryWhenAskedByFlag(t *testing.T) {
	dir := inRepo(t, "")

	out, err := runInit(t, "", "--project", firstProject, "--mcp")
	if err != nil {
		t.Fatalf("init --mcp: %v", err)
	}
	if got := mcpState(t, dir); mcpjson.Current != got {
		t.Fatalf("mcp state: got %v, want Current. Output was %q", got, out)
	}
}

func TestInitLeavesTheMcpFileAloneWhenTold(t *testing.T) {
	dir := inRepo(t, "")

	// A yes on stdin, so an ignored --no-mcp writes the file rather than
	// falling through to a prompt nobody answers.
	if _, err := runInit(t, "y\n", "--project", firstProject, "--no-mcp"); err != nil {
		t.Fatalf("init --no-mcp: %v", err)
	}
	if _, err := os.Stat(filepath.Join(dir, mcpjson.Name)); !os.IsNotExist(err) {
		t.Fatalf("--no-mcp must write no %s, stat gave %v", mcpjson.Name, err)
	}
}

// A script pipes stdin, so nothing answers the prompt. Writing a file the
// script never asked for is the failure to avoid.
func TestInitLeavesTheMcpFileAloneWhenNobodyAnswers(t *testing.T) {
	dir := inRepo(t, "")

	if _, err := runInit(t, "", "--project", firstProject); err != nil {
		t.Fatalf("init: %v", err)
	}
	if _, err := os.Stat(filepath.Join(dir, mcpjson.Name)); !os.IsNotExist(err) {
		t.Fatalf("an unanswered prompt must write no %s, stat gave %v", mcpjson.Name, err)
	}
}

func TestInitWritesTheMcpEntryWhenTheAnswerIsYes(t *testing.T) {
	dir := inRepo(t, "")

	if _, err := runInit(t, "y\n", "--project", firstProject); err != nil {
		t.Fatalf("init: %v", err)
	}
	if got := mcpState(t, dir); mcpjson.Current != got {
		t.Fatalf("mcp state: got %v, want Current", got)
	}
}

func TestInitLeavesTheMcpFileAloneWhenTheAnswerIsNo(t *testing.T) {
	dir := inRepo(t, "")

	out, err := runInit(t, "n\n", "--project", firstProject)
	if err != nil {
		t.Fatalf("init: %v", err)
	}
	if _, err := os.Stat(filepath.Join(dir, mcpjson.Name)); !os.IsNotExist(err) {
		t.Fatalf("a no must write no %s, stat gave %v", mcpjson.Name, err)
	}
	if !strings.Contains(out, "--mcp") {
		t.Fatalf("output must say how to write it later, got %q", out)
	}
}

func TestInitKeepsAnotherServerWhenItWritesTheMcpEntry(t *testing.T) {
	dir := inRepo(t, "")
	body := `{"mcpServers":{"other":{"command":"other","args":["serve"]}}}`
	if err := os.WriteFile(filepath.Join(dir, mcpjson.Name), []byte(body), 0o644); err != nil {
		t.Fatalf("write fixture: %v", err)
	}

	if _, err := runInit(t, "", "--project", firstProject, "--mcp"); err != nil {
		t.Fatalf("init --mcp: %v", err)
	}

	written, err := os.ReadFile(filepath.Join(dir, mcpjson.Name))
	if err != nil {
		t.Fatalf("read %s: %v", mcpjson.Name, err)
	}
	if !strings.Contains(string(written), `"other"`) {
		t.Fatalf("init dropped the other server, file is %s", written)
	}
}

func TestInitSaysNothingToDoWhenTheEntryIsAlreadyThere(t *testing.T) {
	dir := inRepo(t, "")
	if err := mcpjson.Write(dir); err != nil {
		t.Fatalf("mcpjson.Write: %v", err)
	}

	out, err := runInit(t, "", "--project", firstProject)
	if err != nil {
		t.Fatalf("init: %v", err)
	}
	if !strings.Contains(out, "already starts") {
		t.Fatalf("output must say the entry is already there, got %q", out)
	}
}

// The message printed when somebody declines the offer tells them to run this.
// It must reach the offer rather than stopping at the existing project file.
func TestInitWithMcpOnAnInitialisedRepositoryWritesTheEntry(t *testing.T) {
	dir := inRepo(t, "")
	if err := projectfile.Write(dir, firstProject); err != nil {
		t.Fatalf("projectfile.Write: %v", err)
	}

	if _, err := runInit(t, "", "--mcp"); err != nil {
		t.Fatalf("init --mcp on an initialised repository: %v", err)
	}
	if got := mcpState(t, dir); mcpjson.Current != got {
		t.Fatalf("mcp state: got %v, want Current", got)
	}

	file, err := projectfile.Load(dir)
	if err != nil {
		t.Fatalf("projectfile.Load: %v", err)
	}
	if file.Project != firstProject {
		t.Fatalf("Project: got %q, want the file left alone", file.Project)
	}
}

func TestMcpAndNoMcpTogetherAreRefused(t *testing.T) {
	inRepo(t, "")

	if _, err := runInit(t, "", "--project", firstProject, "--mcp", "--no-mcp"); err == nil {
		t.Fatal("init --mcp --no-mcp: got no error")
	}
}

func TestConfirmReadsTheAnswer(t *testing.T) {
	for answer, want := range map[string]bool{
		"\n":     true,
		"y\n":    true,
		"Y\n":    true,
		"yes\n":  true,
		"n\n":    false,
		"N\n":    false,
		"no\n":   false,
		"":       false,
		"what\n": false,
	} {
		var out bytes.Buffer
		if got := confirm(&out, strings.NewReader(answer), "Add it?"); got != want {
			t.Errorf("confirm(%q): got %v, want %v", answer, got, want)
		}
	}
}
