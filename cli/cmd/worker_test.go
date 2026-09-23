package cmd

import (
	"regexp"
	"slices"
	"strings"
	"testing"
)

const ceilingVar = "CLAUDE_CODE_PRINT_BG_WAIT_CEILING_MS="

// claude -p ends a worker at its background wait ceiling and exits 0. The
// bridge lifts the ceiling unless the operator set it.
func TestWorkerEnvLiftsTheWaitCeiling(t *testing.T) {
	base := make([]string, 1, 2)
	base[0] = "HOME=/home/a"

	got := workerEnv(base)
	if !slices.Equal(got, []string{"HOME=/home/a", ceilingVar + "0"}) {
		t.Fatalf("workerEnv = %q", got)
	}
	if extended := base[:2]; extended[1] != "" {
		t.Fatalf("workerEnv wrote into the caller's array: %q", extended)
	}
}

func TestWorkerEnvKeepsTheOperatorsCeiling(t *testing.T) {
	for _, set := range []string{ceilingVar + "5000", ceilingVar + "0", ceilingVar} {
		base := []string{"HOME=/home/a", set}
		if got := workerEnv(base); !slices.Equal(got, base) {
			t.Fatalf("workerEnv(%q) = %q", base, got)
		}
	}
}

func scan(chunks ...string) bool {
	s := &resultScanner{}
	for _, c := range chunks {
		if n, err := s.Write([]byte(c)); n != len(c) || err != nil {
			panic("short write")
		}
	}

	return s.matched
}

func TestResultScannerFindsAResultLine(t *testing.T) {
	long := strings.Repeat("x", 4100) + "\n"
	for name, chunks := range map[string][]string{
		"first line":            {"STAGE RESULT: done"},
		"later line":            {"working\nSTAGE RESULT: done\n"},
		"split in two":          {"work\nSTAGE RES", "ULT: done"},
		"split byte by byte":    strings.Split("a\nSTAGE RESULT: x", ""),
		"split at the newline":  {"work", "\n", "STAGE RESULT:"},
		"after the output cap":  {long, long, "STAGE RESULT: done"},
		"after a miss":          {"xSTAGE RESULT: no\nSTAGE RESULT: yes"},
		"after a partial reset": {"STAGE", "\nSTAGE RESULT: x"},
		"after an indented one": {" STAGE RESULT: no\nSTAGE RESULT: yes"},
		"stays matched":         {"STAGE RESULT: x\n", "more output"},
	} {
		if !scan(chunks...) {
			t.Errorf("%s: no result found in %q", name, chunks)
		}
	}
}

func TestResultScannerIgnoresAnythingElse(t *testing.T) {
	for name, chunks := range map[string][]string{
		"empty":             {},
		"mid-line":          {"xSTAGE RESULT: done"},
		"indented":          {" STAGE RESULT: done"},
		"tab-indented":      {"\tSTAGE RESULT: done"},
		"mid-line, split":   {"work STAGE RES", "ULT: done"},
		"partial then line": {"STAGE RES\nULT: done"},
		"lower case":        {"stage result: done"},
		"no colon":          {"STAGE RESULT done"},
	} {
		if scan(chunks...) {
			t.Errorf("%s: found a result in %q", name, chunks)
		}
	}
}

// TestCapWriterBoundsWhatItKeeps pins the memory bound: a chatty worker must
// not be buffered whole just to report 4 KB of it.
func TestCapWriterBoundsWhatItKeeps(t *testing.T) {
	w := &capWriter{limit: 8}

	n, err := w.Write([]byte("0123456789"))
	if n != 10 || err != nil {
		t.Fatalf("Write = %d, %v; a short write would stop the worker", n, err)
	}
	if w.buf.Len() != 8 {
		t.Fatalf("kept %d bytes, want 8", w.buf.Len())
	}
	if !strings.HasPrefix(w.text(), "01234567") || !strings.HasSuffix(w.text(), "(truncated)") {
		t.Fatalf("text = %q", w.text())
	}
}

// A write that arrives after the cap is reached still reports its full length,
// so the worker keeps running rather than failing on a short write.
func TestCapWriterAcceptsWritesPastTheCap(t *testing.T) {
	w := &capWriter{limit: 4}
	_, _ = w.Write([]byte("abcd"))

	n, err := w.Write([]byte("efgh"))
	if n != 4 || err != nil {
		t.Fatalf("Write = %d, %v", n, err)
	}
	if w.buf.Len() != 4 {
		t.Fatalf("kept %d bytes, want 4", w.buf.Len())
	}
}

// A rule's permission mode and model reach claude as flags, and an empty value
// passes none. The session id always follows -p, and the prompt is always the
// last argument.
func TestWorkerArgsCarryTheRulesSettings(t *testing.T) {
	for _, tc := range []struct {
		spec workerSpec
		want string
	}{
		{workerSpec{sessionID: testSession, prompt: "go"}, "-p --session-id " + testSession + " -- go"},
		{workerSpec{sessionID: testSession, permissionMode: "plan", prompt: "go"}, "--permission-mode plan -p --session-id " + testSession + " -- go"},
		{workerSpec{sessionID: testSession, model: "opus", prompt: "go"}, "--model opus -p --session-id " + testSession + " -- go"},
		{workerSpec{sessionID: testSession, permissionMode: "plan", model: "opus", prompt: "go"}, "--permission-mode plan --model opus -p --session-id " + testSession + " -- go"},
	} {
		if got := strings.Join(workerArgs(tc.spec), " "); got != tc.want {
			t.Fatalf("workerArgs(%+v) = %q, want %q", tc.spec, got, tc.want)
		}
	}
}

var v4UUID = regexp.MustCompile(`\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z`)

// claude refuses a session id that is not a uuid, and two workers must never
// share one, so the real generator is pinned apart from the fake in the tests.
func TestTheDefaultOpsGiveEachWorkerANewV4SessionID(t *testing.T) {
	ops := defaultWorkerOps()

	first, second := ops.sessionID(), ops.sessionID()
	for _, id := range []string{first, second} {
		if !v4UUID.MatchString(id) {
			t.Fatalf("session id %q is not a lower-case version 4 uuid", id)
		}
	}
	if first == second {
		t.Fatalf("two workers got the same session id %q", first)
	}
}

// claude reads `-p "- x"` as the unknown option "- x". After --, any prompt
// text is the prompt.
func TestAPromptThatLooksLikeAnOptionFollowsTheSeparator(t *testing.T) {
	for _, prompt := range []string{"- x", "--version", "-p"} {
		args := workerArgs(workerSpec{model: "opus", sessionID: testSession, prompt: prompt})
		if len(args) < 2 || args[len(args)-2] != "--" || args[len(args)-1] != prompt {
			t.Fatalf("workerArgs(%q) = %q, want the prompt right after --", prompt, args)
		}
	}
}

func TestCapWriterKeepsShortOutputWhole(t *testing.T) {
	w := &capWriter{limit: maxOutput}
	_, _ = w.Write([]byte("all done\n"))

	if got := w.text(); got != "all done" {
		t.Fatalf("text = %q", got)
	}
}
