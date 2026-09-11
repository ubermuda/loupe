package tmux

import (
	"errors"
	"reflect"
	"testing"
)

// record swaps the exec seam for the duration of a test and returns the calls
// made through it.
func record(t *testing.T, err error) *[][]string {
	t.Helper()
	var calls [][]string
	original := run
	run = func(args ...string) error {
		calls = append(calls, args)

		return err
	}
	t.Cleanup(func() { run = original })

	return &calls
}

func TestSessionName(t *testing.T) {
	for target, want := range map[string]string{
		"loupe":            "loupe",
		"loupe:0":          "loupe",
		"loupe:0.1":        "loupe",
		"card-87":          "card-87",
		"card-87:window.2": "card-87",
	} {
		if got := SessionName(target); got != want {
			t.Fatalf("SessionName(%q) = %q, want %q", target, got, want)
		}
	}
}

func TestHasSessionChecksSessionOnly(t *testing.T) {
	calls := record(t, nil)
	if !HasSession("card-87:0.1") {
		t.Fatal("expected HasSession to report true")
	}
	want := []string{"has-session", "-t", "card-87"}
	if !reflect.DeepEqual((*calls)[0], want) {
		t.Fatalf("argv = %q, want %q", (*calls)[0], want)
	}
}

func TestHasSessionFalseWhenTmuxFails(t *testing.T) {
	record(t, errors.New("no such session"))
	if HasSession("card-87") {
		t.Fatal("expected HasSession to report false")
	}
}

func TestSpawnArgv(t *testing.T) {
	t.Setenv("SHELL", "/bin/zsh")
	calls := record(t, nil)

	if err := Spawn("card-87", "/src/app", SpawnOptions{}); err != nil {
		t.Fatalf("Spawn: %v", err)
	}
	want := []string{
		"new-session", "-d", "-s", "card-87", "-c", "/src/app",
		"/bin/zsh", "-i", "-c", `claude "$@"`, "claude", "--name", "card-87",
	}
	if !reflect.DeepEqual((*calls)[0], want) {
		t.Fatalf("argv = %q, want %q", (*calls)[0], want)
	}
}

// TestSpawnOmitsEmptyOptions keeps the operator in control: with no
// --permission-mode, claude prompts for permissions as it normally does.
func TestSpawnOmitsEmptyOptions(t *testing.T) {
	t.Setenv("SHELL", "/bin/zsh")
	calls := record(t, nil)

	if err := Spawn("loupe", "/src/app", SpawnOptions{}); err != nil {
		t.Fatalf("Spawn: %v", err)
	}
	for _, arg := range (*calls)[0] {
		if arg == "--permission-mode" {
			t.Fatalf("argv carries --permission-mode with no mode set: %q", (*calls)[0])
		}
	}
}

func TestSpawnWithPermissionModeAndPrompt(t *testing.T) {
	t.Setenv("SHELL", "/bin/bash")
	calls := record(t, nil)

	opts := SpawnOptions{PermissionMode: "acceptEdits", Prompt: "Card 87 moved to next."}
	if err := Spawn("card-87", "/src/app", opts); err != nil {
		t.Fatalf("Spawn: %v", err)
	}
	want := []string{
		"new-session", "-d", "-s", "card-87", "-c", "/src/app",
		"/bin/bash", "-i", "-c", `claude "$@"`,
		"claude", "--name", "card-87", "--permission-mode", "acceptEdits", "Card 87 moved to next.",
	}
	if !reflect.DeepEqual((*calls)[0], want) {
		t.Fatalf("argv = %q, want %q", (*calls)[0], want)
	}
}

// TestSpawnPassesArgumentsPositionally proves no value is spliced into the
// shell command string, which stays a fixed literal whatever the prompt says.
func TestSpawnPassesArgumentsPositionally(t *testing.T) {
	t.Setenv("SHELL", "/bin/zsh")
	calls := record(t, nil)

	hostile := `"; rm -rf / #`
	if err := Spawn("card-87", "/src/app", SpawnOptions{Prompt: hostile}); err != nil {
		t.Fatalf("Spawn: %v", err)
	}
	argv := (*calls)[0]
	if argv[9] != `claude "$@"` {
		t.Fatalf("shell command string is not a fixed literal: %q", argv[9])
	}
	if argv[len(argv)-1] != hostile {
		t.Fatalf("prompt did not travel as its own argv entry: %q", argv)
	}
}

func TestSpawnFallsBackToShWithNoShellSet(t *testing.T) {
	t.Setenv("SHELL", "")
	calls := record(t, nil)

	if err := Spawn("card-87", "/src/app", SpawnOptions{}); err != nil {
		t.Fatalf("Spawn: %v", err)
	}
	if (*calls)[0][6] != "/bin/sh" {
		t.Fatalf("argv = %q, want /bin/sh as the shell", (*calls)[0])
	}
}

func TestSpawnWrapsError(t *testing.T) {
	record(t, errors.New("boom"))
	if err := Spawn("card-87", "/src/app", SpawnOptions{}); err == nil {
		t.Fatal("expected an error")
	}
}

// TestSendSeparatesTextFromEnter keeps arbitrary content from being read as a
// tmux key name.
func TestSendSeparatesTextFromEnter(t *testing.T) {
	calls := record(t, nil)

	if err := Send("loupe", "Enter; C-c"); err != nil {
		t.Fatalf("Send: %v", err)
	}
	if len(*calls) != 2 {
		t.Fatalf("expected 2 tmux calls, got %d", len(*calls))
	}
	wantText := []string{"send-keys", "-t", "loupe", "-l", "--", "Enter; C-c"}
	if !reflect.DeepEqual((*calls)[0], wantText) {
		t.Fatalf("argv = %q, want %q", (*calls)[0], wantText)
	}
	wantEnter := []string{"send-keys", "-t", "loupe", "Enter"}
	if !reflect.DeepEqual((*calls)[1], wantEnter) {
		t.Fatalf("argv = %q, want %q", (*calls)[1], wantEnter)
	}
}

func TestSendWrapsError(t *testing.T) {
	record(t, errors.New("boom"))
	if err := Send("loupe", "hello"); err == nil {
		t.Fatal("expected an error")
	}
}
