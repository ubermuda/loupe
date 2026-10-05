package rules

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
)

const (
	projectID = "0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7"
	cardID    = "0192f3a1-9999-7d3e-8f10-a2b3c4d5e6f7"
)

// file writes a rule file whose single project maps to a real directory. The
// {dir} marker stands for that directory.
func file(t *testing.T, body string) (string, string) {
	t.Helper()
	dir := t.TempDir()

	return strings.ReplaceAll(body, "{dir}", dir), dir
}

func parse(t *testing.T, body string) *Set {
	t.Helper()
	text, _ := file(t, body)
	s, err := Parse([]byte(text), Defaults{})
	if err != nil {
		t.Fatalf("Parse: %v", err)
	}

	return s
}

// oneRule is the smallest file the bridge runs: one project and one kind of
// work.
const oneRule = `
projects:
  loupe:
    dir: {dir}
work:
  implement:
    prompt: Implement card {cardNumber}.
`

func entry(t *testing.T, s *Set, kind string) WorkEntry {
	t.Helper()
	w, ok := s.WorkEntry(kind)
	if !ok {
		t.Fatalf("no entry for %s", kind)
	}

	return w
}

func TestParseFillsDefaults(t *testing.T) {
	text, dir := file(t, oneRule)
	s, err := Parse([]byte(text), Defaults{PermissionMode: "acceptEdits", Model: "sonnet"})
	if err != nil {
		t.Fatal(err)
	}

	if w := entry(t, s, "implement"); w.PermissionMode != "acceptEdits" || w.Model != "sonnet" {
		t.Fatalf("entry = %+v", w)
	}
	if got := s.Projects(); len(got) != 1 || got[0] != "loupe" || s.dirs["loupe"] != dir {
		t.Fatalf("projects = %v, dirs = %v", got, s.dirs)
	}
	if got := s.WorkKinds(); !slices.Equal(got, []string{"implement"}) {
		t.Fatalf("WorkKinds = %v", got)
	}
}

func TestAutoUpdateIsOffUnlessTheFileTurnsItOn(t *testing.T) {
	for _, tc := range []struct {
		line string
		want bool
	}{
		{line: "", want: false},
		{line: "autoUpdate: true\n", want: true},
		{line: "autoUpdate: false\n", want: false},
	} {
		if got := parse(t, tc.line+oneRule).AutoUpdate(); got != tc.want {
			t.Fatalf("%q: AutoUpdate = %v, want %v", tc.line, got, tc.want)
		}
	}
}

// An entry's own value wins, then the file's defaults, then the bridge flags.
func TestParseFillsAnEntryFromTheFileBeforeTheFlags(t *testing.T) {
	for name, tc := range map[string]struct{ file, entry, flag, want string }{
		"the file fills an empty entry":  {file: "plan", want: "plan"},
		"the entry beats the file":       {file: "plan", entry: "dontAsk", want: "dontAsk"},
		"the file beats the flag":        {file: "plan", flag: "acceptEdits", want: "plan"},
		"the flag fills when both empty": {flag: "acceptEdits", want: "acceptEdits"},
		"nothing sets a value":           {},
	} {
		t.Run(name, func(t *testing.T) {
			body := oneRule
			if tc.entry != "" {
				body = strings.Replace(body, "  implement:\n", "  implement:\n    permissionMode: "+tc.entry+"\n    model: "+tc.entry+"-model\n", 1)
			}
			if tc.file != "" {
				body = "defaults:\n  permissionMode: " + tc.file + "\n  model: " + tc.file + "-model\n" + body
			}
			flags := Defaults{}
			if tc.flag != "" {
				flags = Defaults{PermissionMode: tc.flag, Model: tc.flag + "-model"}
			}
			text, _ := file(t, body)
			s, err := Parse([]byte(text), flags)
			if err != nil {
				t.Fatal(err)
			}
			wantModel := ""
			if tc.want != "" {
				wantModel = tc.want + "-model"
			}
			if w := entry(t, s, "implement"); w.PermissionMode != tc.want || w.Model != wantModel {
				t.Fatalf("permissionMode = %q, model = %q, want %q and %q", w.PermissionMode, w.Model, tc.want, wantModel)
			}
		})
	}
}

// A mode outside the known list loads, and the set names it once for the
// bridge to warn about. A default fills an entry, so it counts too.
func TestUnknownPermissionModesAreListedNotRefused(t *testing.T) {
	text, _ := file(t, `
projects:
  loupe:
    dir: {dir}
work:
  a: {prompt: x, permissionMode: acceptedits}
  b: {prompt: x, permissionMode: acceptedits}
  c: {prompt: x, permissionMode: plan}
  d: {prompt: x}
`)
	s, err := Parse([]byte(text), Defaults{PermissionMode: "newMode"})
	if err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(s.UnknownPermissionModes(), " "); got != "acceptedits newMode" {
		t.Fatalf("UnknownPermissionModes = %q", got)
	}

	for _, mode := range PermissionModes {
		text, _ := file(t, strings.Replace(oneRule, "  implement:\n", "  implement:\n    permissionMode: "+mode+"\n", 1))
		if s, err := Parse([]byte(text), Defaults{}); err != nil || len(s.UnknownPermissionModes()) != 0 {
			t.Fatalf("%s: err = %v", mode, err)
		}
	}
}

func TestParseExpandsHomeInDir(t *testing.T) {
	home := t.TempDir()
	t.Setenv("HOME", home)
	if err := os.Mkdir(filepath.Join(home, "app"), 0o700); err != nil {
		t.Fatal(err)
	}

	s, err := Parse([]byte(strings.ReplaceAll(oneRule, "{dir}", "~/app")), Defaults{})
	if err != nil {
		t.Fatal(err)
	}
	if s.dirs["loupe"] != filepath.Join(home, "app") {
		t.Fatalf("dir = %q", s.dirs["loupe"])
	}
}

func TestParseRefusesAnInvalidFile(t *testing.T) {
	regular := filepath.Join(t.TempDir(), "file")
	if err := os.WriteFile(regular, []byte("x"), 0o600); err != nil {
		t.Fatal(err)
	}

	for name, tc := range map[string]struct {
		body string
		want string
	}{
		"no projects":             {"work:\n  implement: {prompt: x}\n", "maps no projects"},
		"no work":                 {"projects:\n  loupe:\n    dir: {dir}\n", "has no work"},
		"unknown top-level field": {oneRule + "extra: 1\n", "field extra not found"},
		"unknown entry field":     {oneRule + "    column: ready\n", "field column not found"},
		"unknown project field":   {"projects:\n  loupe:\n    dir: {dir}\n    path: x\nwork:\n  implement: {prompt: x}\n", "field path not found"},
		"project key not a slug":  {"projects:\n  Loupe App:\n    dir: {dir}\nwork:\n  implement: {prompt: x}\n", "a project key is a slug"},
		"project without dir":     {"projects:\n  loupe: {}\nwork:\n  implement: {prompt: x}\n", "dir is required"},
		"relative dir":            {strings.ReplaceAll(oneRule, "{dir}", "code/app"), "not an absolute path"},
		"missing dir":             {strings.ReplaceAll(oneRule, "{dir}", "/nonexistent/loupe-rules-test"), "no such file"},
		"dir is a file":           {strings.ReplaceAll(oneRule, "{dir}", regular), "is not a directory"},
		"second document":         {oneRule + "---\n" + oneRule, "second YAML document"},
		"default mode spaced":     {"defaults:\n  permissionMode: accept edits\n" + oneRule, `defaults.permissionMode "accept edits" holds whitespace`},
		"default model spaced":    {"defaults:\n  model: 'claude opus'\n" + oneRule, `defaults.model "claude opus" holds whitespace`},
		"unknown defaults field":  {"defaults:\n  maxChain: 2\n" + oneRule, "field maxChain not found"},
	} {
		t.Run(name, func(t *testing.T) {
			text, _ := file(t, tc.body)
			_, err := Parse([]byte(text), Defaults{})
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want it to contain %q", err, tc.want)
			}
		})
	}
}

// A file of the old format names the work map, so the operator knows what to
// write in its place.
func TestParseRefusesTheOldFormat(t *testing.T) {
	for name, tc := range map[string]struct {
		body string
		want []string
	}{
		"a rules list": {
			oneRule + "rules:\n  - {on: board.card_moved, project: loupe, to: ready, prompt: x}\n",
			[]string{"the rules list is gone", "under work instead", "resume and maxResumes"},
		},
		"an empty rules list": {oneRule + "rules: []\n", []string{"the rules list is gone"}},
		"an experiments list": {
			oneRule + "experiments:\n  - {name: models, variants: [{name: a, weight: 1, model: opus}]}\n",
			[]string{"the experiments list is gone", "give a work entry its variants"},
		},
	} {
		t.Run(name, func(t *testing.T) {
			text, _ := file(t, tc.body)
			_, err := Parse([]byte(text), Defaults{})
			for _, want := range tc.want {
				if err == nil || !strings.Contains(err.Error(), want) {
					t.Fatalf("err = %v, want it to contain %q", err, want)
				}
			}
		})
	}
}

func TestLoadPrintsAnExampleWhenTheFileIsMissing(t *testing.T) {
	path := filepath.Join(t.TempDir(), FileName)
	_, err := Load(path, Defaults{})
	if !errors.Is(err, ErrMissing) {
		t.Fatalf("err = %v", err)
	}
	if !strings.Contains(err.Error(), Example) {
		t.Fatalf("the error carries no example: %v", err)
	}
	if strings.Contains(err.Error(), path) {
		t.Fatalf("the caller names the path, so Load must not: %v", err)
	}
}

// A file with keys and no work is as far from a working bridge as a missing
// one, so it shows the example once.
func TestParsePrintsAnExampleWhenTheFileHasNoWork(t *testing.T) {
	for name, body := range map[string]string{
		"autoUpdate only": "autoUpdate: false\n",
		"projects only":   "projects:\n  loupe:\n    dir: {dir}\n",
		"an empty map":    "autoUpdate: true\nwork: {}\n",
	} {
		text, _ := file(t, body)
		_, err := Parse([]byte(text), Defaults{})
		if err == nil || !strings.Contains(err.Error(), "has no work") || strings.Count(err.Error(), Example) != 1 {
			t.Fatalf("%s: err = %v, want the example once", name, err)
		}
	}
	if _, err := Parse([]byte("work:\n  implement: {}\n"), Defaults{}); err == nil || strings.Contains(err.Error(), Example) {
		t.Fatalf("a file with an entry must not show the example: %v", err)
	}
}

// An empty file is as far from a working bridge as a missing one, so it shows
// the example too.
func TestLoadPrintsAnExampleWhenTheFileIsEmpty(t *testing.T) {
	for name, body := range map[string]string{
		"empty":                  "",
		"blank":                  "\n  \n",
		"comments only":          "# work goes here\n",
		"a separator only":       "---\n",
		"two empty documents":    "---\n# work goes here\n---\n",
		"an explicit end marker": "---\n...\n",
	} {
		path := filepath.Join(t.TempDir(), FileName)
		if err := os.WriteFile(path, []byte(body), 0o600); err != nil {
			t.Fatal(err)
		}
		_, err := Load(path, Defaults{})
		if !errors.Is(err, ErrEmpty) || !strings.Contains(err.Error(), Example) {
			t.Fatalf("%s: err = %v, want ErrEmpty with the example", name, err)
		}
	}
}

// A second document would be dropped in silence, and its work with it. An
// empty document holds nothing, so one before or after the work passes.
func TestParseRefusesASecondDocument(t *testing.T) {
	for name, body := range map[string]string{
		"a full second document": oneRule + "---\n" + oneRule,
		"an empty mapping":       oneRule + "---\n{}\n",
		"after an empty one":     "---\n---\n" + oneRule + "---\n" + oneRule,
	} {
		text, _ := file(t, body)
		if _, err := Parse([]byte(text), Defaults{}); err == nil || !strings.Contains(err.Error(), "second YAML document") {
			t.Fatalf("%s: err = %v", name, err)
		}
	}
	for name, body := range map[string]string{
		"a trailing separator":        oneRule + "---\n",
		"a trailing comment document": oneRule + "---\n# nothing yet\n",
		"an empty first document":     "---\n---\n" + oneRule,
		"a comment first document":    "---\n# header\n---\n" + oneRule,
	} {
		text, _ := file(t, body)
		s, err := Parse([]byte(text), Defaults{})
		if err != nil {
			t.Fatalf("%s: %v", name, err)
		}
		if got := s.WorkKinds(); !slices.Equal(got, []string{"implement"}) {
			t.Fatalf("%s: kinds = %v", name, got)
		}
	}
}

// A field the format does not define still fails when an empty document comes
// first, so the second decoding pass keeps KnownFields.
func TestParseKeepsKnownFieldsAfterAnEmptyDocument(t *testing.T) {
	text, _ := file(t, "---\n---\n"+oneRule+"extra: 1\n")
	if _, err := Parse([]byte(text), Defaults{}); err == nil || !strings.Contains(err.Error(), "field extra not found") {
		t.Fatalf("err = %v", err)
	}
}

// A malformed default fails once at start, not in every worker. A mode this
// build does not know passes, because a later claude may add it.
func TestDefaultsCheckRefusesAMalformedValue(t *testing.T) {
	for _, d := range []Defaults{{}, {PermissionMode: "acceptEdits", Model: "sonnet"}, {PermissionMode: "someFutureMode", Model: "claude-opus-4-1"}} {
		if err := d.Check(); err != nil {
			t.Fatalf("Check(%+v) = %v", d, err)
		}
	}
	for d, want := range map[Defaults]string{
		{PermissionMode: "accept edits"}: `--permission-mode "accept edits" holds whitespace`,
		{PermissionMode: " "}:            `--permission-mode " " holds whitespace`,
		{Model: "sonnet 4"}:              `--model "sonnet 4" holds whitespace`,
		{Model: "sonnet\t"}:              "holds whitespace",
	} {
		if err := d.Check(); err == nil || !strings.Contains(err.Error(), want) {
			t.Fatalf("Check(%+v) = %v, want %q", d, err, want)
		}
	}
}

// The README shows the example as the file to start from, so the two must not
// drift.
func TestTheReadmeShowsTheExample(t *testing.T) {
	readme, err := os.ReadFile(filepath.Join("..", "..", "README.md"))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(readme), Example) {
		t.Fatal("cli/README.md does not carry rules.Example verbatim")
	}
}

// The example the bridge prints has to be a file the bridge accepts.
func TestTheExampleParses(t *testing.T) {
	home := t.TempDir()
	t.Setenv("HOME", home)
	if err := os.MkdirAll(filepath.Join(home, "Code", "my-app"), 0o700); err != nil {
		t.Fatal(err)
	}

	if _, err := Parse([]byte(Example), Defaults{}); err != nil {
		t.Fatalf("the example does not parse: %v", err)
	}
}

// columnsServer answers the column endpoint for the projects it knows, and a
// project_not_found 404 for any other handle.
func columnsServer(t *testing.T, projects map[string]string) *api.Client {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		handle := strings.TrimSuffix(strings.TrimPrefix(r.URL.Path, "/api/projects/"), "/board/columns")
		body, ok := projects[handle]
		if !ok {
			w.WriteHeader(http.StatusNotFound)
			fmt.Fprint(w, `{"error":"project_not_found"}`)

			return
		}
		fmt.Fprint(w, body)
	}))
	t.Cleanup(server.Close)

	return api.New(server.URL, "token", server.Client())
}

const loupeColumns = `{"project":{"id":"` + projectID + `","slug":"loupe"},"columns":[{"slug":"backlog"},{"slug":"ready"},{"slug":"review"},{"slug":"done","terminal":true}]}`

func TestCheckResolvesTheProjectID(t *testing.T) {
	s := parse(t, oneRule)

	if err := s.Check(context.Background(), columnsServer(t, map[string]string{"loupe": loupeColumns})); err != nil {
		t.Fatal(err)
	}
	if got := s.ProjectID("loupe"); got != projectID {
		t.Fatalf("ProjectID = %q", got)
	}
}

func TestCheckRefusesWhatTheServerDoesNotHave(t *testing.T) {
	for name, tc := range map[string]struct {
		projects map[string]string
		want     string
	}{
		"unknown project":                {map[string]string{}, `project "loupe": no project of yours has this slug`},
		"a slug that resolves elsewhere": {map[string]string{"loupe": `{"project":{"id":"` + projectID + `","slug":"loupe-2"},"columns":[]}`}, `slug "loupe-2"`},
		"an invalid project id":          {map[string]string{"loupe": `{"project":{"id":"not a uuid","slug":"loupe"},"columns":[{"slug":"ready"}]}`}, "invalid id"},
	} {
		t.Run(name, func(t *testing.T) {
			s := parse(t, oneRule)
			err := s.Check(context.Background(), columnsServer(t, tc.projects))
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want it to contain %q", err, tc.want)
			}
			if s.ProjectID("loupe") != "" {
				t.Fatal("a failed check still mapped the project")
			}
		})
	}
}

// Each refusal the server can give at start gets its own message, so the
// operator knows whether to fix the file or the server version.
func TestCheckNamesEachRefusal(t *testing.T) {
	for name, tc := range map[string]struct {
		status int
		body   string
		want   string
		not    string
	}{
		"unknown project": {http.StatusNotFound, `{"error":"project_not_found"}`, "no project of yours has this slug", "too old"},
		"server too old":  {http.StatusNotFound, `<!DOCTYPE html><title>Not Found</title>`, "the server is too old for this bridge version", "no project"},
		"ambiguous":       {http.StatusConflict, `{"error":"ambiguous_project"}`, "names more than one of your projects", "too old"},
	} {
		t.Run(name, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(tc.status)
				fmt.Fprint(w, tc.body)
			}))
			t.Cleanup(server.Close)

			err := parse(t, oneRule).Check(context.Background(), api.New(server.URL, "t", server.Client()))
			if err == nil || !strings.Contains(err.Error(), tc.want) || strings.Contains(err.Error(), tc.not) {
				t.Fatalf("err = %v, want %q and not %q", err, tc.want, tc.not)
			}
		})
	}
}

// An unknown project names the slugs the caller does own, read once from
// GET /api/projects, so the fix is a copy. A project with no slug is left out.
func TestCheckListsTheValidSlugsForAnUnknownProject(t *testing.T) {
	sitesCalls := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/projects" {
			sitesCalls++
			fmt.Fprint(w, `{"sites":[{"id":"a","slug":"zeta","name":"Zeta"},{"id":"b","slug":null,"name":"Old"},{"id":"c","slug":"alpha","name":"Alpha"}]}`)

			return
		}
		w.WriteHeader(http.StatusNotFound)
		fmt.Fprint(w, `{"error":"project_not_found"}`)
	}))
	t.Cleanup(server.Close)
	s := parse(t, strings.ReplaceAll(oneRule, "projects:\n", "projects:\n  other:\n    dir: "+t.TempDir()+"\n"))

	err := s.Check(context.Background(), api.New(server.URL, "t", server.Client()))
	for _, slug := range []string{"loupe", "other"} {
		want := `project "` + slug + `": no project of yours has this slug; your projects are alpha, zeta`
		if err == nil || !strings.Contains(err.Error(), want) {
			t.Fatalf("err = %v, want %q", err, want)
		}
	}
	if sitesCalls != 1 {
		t.Fatalf("GET /api/projects ran %d times, want once", sitesCalls)
	}
}

// Without slugs, two keys can resolve to one project, such as its name and its
// id. Only one key would then ever match, so the check refuses the pair.
func TestCheckRefusesTwoKeysForOneProject(t *testing.T) {
	s := parse(t, strings.ReplaceAll(oneRule, "projects:\n", "projects:\n  "+projectID+":\n    dir: {dir}\n"))
	body := `{"project":{"id":"` + projectID + `","slug":null},"columns":[{"slug":"ready"}]}`

	err := s.Check(context.Background(), columnsServer(t, map[string]string{"loupe": body, projectID: body}))
	if err == nil || !strings.Contains(err.Error(), "the same project as") {
		t.Fatalf("err = %v", err)
	}
}

// Until projects have slugs, the server resolves the handle by name and sends
// a null slug. The check accepts that and still reads the id.
func TestCheckAcceptsANullSlug(t *testing.T) {
	s := parse(t, oneRule)
	body := `{"project":{"id":"` + projectID + `","slug":null},"columns":[{"slug":"ready"}]}`

	if err := s.Check(context.Background(), columnsServer(t, map[string]string{"loupe": body})); err != nil {
		t.Fatal(err)
	}
	if s.ProjectID("loupe") != projectID {
		t.Fatalf("ProjectID = %q", s.ProjectID("loupe"))
	}
}

// checked parses body and resolves the loupe project, as a bridge does at start.
func checked(t *testing.T, body string) *Set {
	t.Helper()
	s := parse(t, body)
	if err := s.Check(context.Background(), columnsServer(t, map[string]string{"loupe": loupeColumns})); err != nil {
		t.Fatal(err)
	}

	return s
}

func TestParseGivesTheDefaultPoolTheWholeBudget(t *testing.T) {
	s := checked(t, oneRule)
	if s.MaxWorkers() != DefaultMaxWorkers {
		t.Fatalf("MaxWorkers = %d", s.MaxWorkers())
	}
	if got := s.Pools(); len(got) != 1 || got[DefaultPool] != DefaultMaxWorkers {
		t.Fatalf("Pools = %v", got)
	}
	if m := s.MatchWork(workRequest("implement")); m.Pool != DefaultPool {
		t.Fatalf("Match = %+v", m)
	}
}

const pooledRules = `
maxWorkers: 4
workerPools:
  quick:
    size: 1
projects:
  loupe:
    dir: {dir}
work:
  plan:
    workerPool: quick
    prompt: plan
  review:
    prompt: review
`

func TestParseSplitsTheBudgetIntoPools(t *testing.T) {
	s := checked(t, pooledRules)
	if s.MaxWorkers() != 4 {
		t.Fatalf("MaxWorkers = %d", s.MaxWorkers())
	}
	if got := s.Pools(); len(got) != 2 || got["quick"] != 1 || got[DefaultPool] != 3 {
		t.Fatalf("Pools = %v", got)
	}
	if m := s.MatchWork(workRequest("plan")); m.Pool != "quick" {
		t.Fatalf("Match = %+v", m)
	}
	if m := s.MatchWork(workRequest("review")); m.Pool != DefaultPool {
		t.Fatalf("Match = %+v", m)
	}

	s.Pools()["quick"] = 9
	if s.Pools()["quick"] != 1 {
		t.Fatal("Pools returns the set's own map")
	}
}

func TestParseRefusesAnInvalidPool(t *testing.T) {
	twoPools := "maxWorkers: 2\nworkerPools:\n  a:\n    size: 1\n  b:\n    size: 1\n"

	for name, tc := range map[string]struct {
		body string
		want string
	}{
		"maxWorkers zero":     {"maxWorkers: 0\n" + oneRule, "maxWorkers must be at least 1, got 0"},
		"maxWorkers negative": {"maxWorkers: -2\n" + oneRule, "maxWorkers must be at least 1, got -2"},
		"size missing":        {"workerPools:\n  quick: {}\n" + oneRule, "workerPools.quick: size is required"},
		"null pool":           {"workerPools:\n  quick:\n" + oneRule, "workerPools.quick: size is required"},
		"size zero":           {"workerPools:\n  quick:\n    size: 0\n" + oneRule, "workerPools.quick: size must be at least 1, got 0"},
		"name not a pattern":  {"workerPools:\n  Quick:\n    size: 1\n" + oneRule, `workerPools.Quick: a pool name`},
		"default reserved":    {"workerPools:\n  default:\n    size: 1\n" + oneRule, "workerPools.default: the name default is reserved"},
		"unknown pool field":  {"workerPools:\n  quick:\n    size: 1\n    weight: 2\n" + oneRule, "field weight not found"},
		"sizes over budget":   {"maxWorkers: 2\nworkerPools:\n  a:\n    size: 2\n  b:\n    size: 1\n" + oneRule, "the worker pools take more than the 2 slots of maxWorkers"},
		"one pool over":       {"maxWorkers: 2\nworkerPools:\n  a:\n    size: 3\n" + oneRule, "workerPools.a: size 3 is more than maxWorkers, which is 2"},
		"unknown entry pool":  {strings.Replace(pooledRules, "workerPool: quick", "workerPool: slow", 1), `work "plan": workerPool "slow" is not in workerPools, which declares default, quick`},
		"no room for default": {twoPools + oneRule, `work "implement": the default pool has no slot`},
	} {
		t.Run(name, func(t *testing.T) {
			text, _ := file(t, tc.body)
			_, err := Parse([]byte(text), Defaults{})
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want it to contain %q", err, tc.want)
			}
		})
	}
}

// before is a worker entry whose before block holds FIELDS.
const beforeRule = `
projects:
  loupe:
    dir: {dir}
work:
  implement:
    prompt: Card {cardNumber}.
    before:
FIELDS`

func withBefore(fields string) string {
	return strings.Replace(beforeRule, "FIELDS", "      "+strings.ReplaceAll(strings.TrimSpace(fields), "\n", "\n      ")+"\n", 1)
}

func TestParseReadsTheBeforeTimeout(t *testing.T) {
	for name, tc := range map[string]struct {
		fields string
		want   time.Duration
	}{
		"the default":      {"run: [prepare]", DefaultBeforeTimeout},
		"an explicit time": {"run: [prepare]\ntimeout: 90s", 90 * time.Second},
		"the maximum":      {"run: [prepare]\ntimeout: 60m", MaxBeforeTimeout},
	} {
		t.Run(name, func(t *testing.T) {
			w := entry(t, parse(t, withBefore(tc.fields)), "implement")
			if w.Before == nil || w.beforeTimeout != tc.want {
				t.Fatalf("before = %+v, timeout = %s, want %s", w.Before, w.beforeTimeout, tc.want)
			}
		})
	}
}

func TestParseRefusesAnInvalidBefore(t *testing.T) {
	for name, tc := range map[string]struct {
		body string
		want string
	}{
		"a timeout over the maximum": {withBefore("run: [prepare]\ntimeout: 61m"), "before.timeout is 61m, and the most it takes is 1h0m0s"},
		"a zero timeout":             {withBefore("run: [prepare]\ntimeout: 0s"), "before.timeout must be positive"},
		"an unparsable timeout":      {withBefore("run: [prepare]\ntimeout: soon"), `before.timeout "soon" is not a duration`},
		"no run":                     {withBefore("timeout: 1m"), "before.run is required"},
		"a blank program":            {withBefore("run: ['  ', x]"), "before.run is required"},
		"an unknown placeholder":     {withBefore("run: [prepare, '{title}']"), "before.run: unknown placeholder {title}"},
		"an unknown field":           {withBefore("run: [prepare]\nshell: sh"), "field shell not found"},
	} {
		t.Run(name, func(t *testing.T) {
			text, _ := file(t, tc.body)
			_, err := Parse([]byte(text), Defaults{})
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want it to contain %q", err, tc.want)
			}
		})
	}
}

// Each element of before.run is filled on its own, with no prompt footer, so a
// value never splits into two arguments.
func TestMatchRendersTheBeforeCommand(t *testing.T) {
	s := checked(t, withBefore("run: [prepare, '--card={cardNumber}', '{cardId} {kind}']\ntimeout: 2m"))

	m := s.MatchWork(workRequest("implement"))
	want := []string{"prepare", "--card=87", cardID + " implement"}
	if m.Before == nil || !slices.Equal(m.Before.Argv, want) || m.Before.Timeout != 2*time.Minute {
		t.Fatalf("before = %+v, want argv %q", m.Before, want)
	}
	m.Before.Argv[0] = "changed"
	if again := s.MatchWork(workRequest("implement")); again.Before.Argv[0] != "prepare" {
		t.Fatalf("a change to one match reached the entry: %q", again.Before.Argv)
	}
}
