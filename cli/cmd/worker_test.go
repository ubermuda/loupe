package cmd

import (
	"strings"
	"testing"
)

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
