// Package tmux drives a local tmux session: checking it exists, spawning one
// running `claude`, and injecting text into it.
package tmux

import (
	"fmt"
	"os"
	"os/exec"
	"strings"
)

// run executes tmux with args. Tests replace it to capture the argv.
var run = func(args ...string) error {
	return exec.Command("tmux", args...).Run()
}

// SpawnOptions carries the launch flags handed to `claude`. An empty field
// means the flag is not passed at all.
type SpawnOptions struct {
	// PermissionMode maps to `claude --permission-mode`.
	PermissionMode string
	// Prompt is claude's initial prompt, given as its positional argument.
	Prompt string
}

// Available reports whether the tmux binary is on PATH.
func Available() bool {
	_, err := exec.LookPath("tmux")

	return err == nil
}

// SessionName returns the session portion of a "session:window.pane" target.
func SessionName(target string) string {
	if i := strings.IndexAny(target, ":."); i >= 0 {
		return target[:i]
	}

	return target
}

// HasSession reports whether the session in target exists. target may be a bare
// session name or a "session:window.pane" target; only the session is checked.
func HasSession(target string) bool {
	return run("has-session", "-t", SessionName(target)) == nil
}

// Spawn creates a detached session named session, running `claude` in dir.
//
// claude is launched through an interactive shell so the user's aliases, shell
// functions, and rc-defined PATH are honored.
//
// Arguments are quoted into the command string rather than forwarded through
// "$@". Positional forwarding is Bourne-only: fish puts them in $argv and has
// no $@, so a fish user would get a session running claude with no name, no
// permission mode and no prompt, silently. The command word stays unquoted,
// because quoting any part of it suppresses alias expansion, which is what the
// interactive shell is for.
func Spawn(session, dir string, opts SpawnOptions) error {
	shell := os.Getenv("SHELL")
	if shell == "" {
		shell = "/bin/sh"
	}

	command := "claude " + shellQuote("--name") + " " + shellQuote(session)
	if opts.PermissionMode != "" {
		command += " " + shellQuote("--permission-mode") + " " + shellQuote(opts.PermissionMode)
	}
	if opts.Prompt != "" {
		command += " " + shellQuote(opts.Prompt)
	}

	if err := run("new-session", "-d", "-s", session, "-c", dir, shell, "-i", "-c", command); err != nil {
		return fmt.Errorf("create tmux session: %w", err)
	}

	return nil
}

// shellQuote wraps a value so every shell that takes -c reads it as one literal
// word. The '\'' idiom closes the quote, emits an escaped quote and reopens,
// which sh, bash, zsh and fish all read the same way.
func shellQuote(value string) string {
	return "'" + strings.ReplaceAll(value, "'", `'\''`) + "'"
}

// Send injects text into target followed by Enter. text is sent literally (-l)
// and Enter separately, so arbitrary content is never interpreted as a tmux key
// name (e.g. "Enter", ";", "C-c").
func Send(target, text string) error {
	if err := run("send-keys", "-t", target, "-l", "--", text); err != nil {
		return fmt.Errorf("send text: %w", err)
	}
	if err := run("send-keys", "-t", target, "Enter"); err != nil {
		return fmt.Errorf("send Enter: %w", err)
	}

	return nil
}
