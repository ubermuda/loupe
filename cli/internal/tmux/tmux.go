// Package tmux drives a local tmux session: checking it exists, spawning one
// running `claude`, and injecting text into it.
package tmux

import (
	"fmt"
	"os/exec"
	"strings"
)

// Available reports whether the tmux binary is on PATH.
func Available() bool {
	_, err := exec.LookPath("tmux")

	return err == nil
}

// HasSession reports whether the session in target exists. target may be a bare
// session name or a "session:window.pane" target; only the session is checked.
func HasSession(target string) bool {
	session := target
	if i := strings.IndexAny(session, ":."); i >= 0 {
		session = session[:i]
	}

	return exec.Command("tmux", "has-session", "-t", session).Run() == nil
}

// Spawn creates a detached session named session, running `claude` in dir.
func Spawn(session, dir string) error {
	if err := exec.Command("tmux", "new-session", "-d", "-s", session, "-c", dir, "claude").Run(); err != nil {
		return fmt.Errorf("create tmux session: %w", err)
	}

	return nil
}

// Send injects text into target followed by Enter. text is sent literally (-l)
// and Enter separately, so arbitrary review content is never interpreted as a
// tmux key name (e.g. "Enter", ";", "C-c").
func Send(target, text string) error {
	if err := exec.Command("tmux", "send-keys", "-t", target, "-l", "--", text).Run(); err != nil {
		return fmt.Errorf("send text: %w", err)
	}
	if err := exec.Command("tmux", "send-keys", "-t", target, "Enter").Run(); err != nil {
		return fmt.Errorf("send Enter: %w", err)
	}

	return nil
}
