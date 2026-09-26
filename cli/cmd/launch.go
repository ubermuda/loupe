package cmd

import (
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// maxLaunchReason is the longest failure reason the server takes.
const maxLaunchReason = 1000

// staleScriptAge is the age past which the bridge deletes a launch script at
// start. A script that ran has deleted itself already.
const staleScriptAge = 24 * time.Hour

// launchWaitDelay bounds the wait for the output of a launcher that exited.
// Tests shorten it.
var launchWaitDelay = time.Second

// defaultScriptDir holds the launch scripts.
func defaultScriptDir() string {
	return filepath.Join(os.TempDir(), "loupe-sessions")
}

// resolveClaude is the absolute path of claude. The launch script changes
// directory before it runs claude, so a relative path would miss.
func resolveClaude() (string, error) {
	path, err := lookPath("claude")
	if err != nil {
		return "", errors.New("claude is not installed or not on PATH")
	}

	return filepath.Abs(path)
}

// shellQuote quotes s for a POSIX shell, so the shell reads it as one word.
func shellQuote(s string) string {
	return "'" + strings.ReplaceAll(s, "'", `'\''`) + "'"
}

// launchScript is the body of the script that a terminal runs. It deletes
// itself first, so the prompt does not stay on the disk.
func launchScript(claude string, spec workerSpec) string {
	var b strings.Builder
	b.WriteString("#!/bin/sh\n")
	b.WriteString("rm -f -- \"$0\"\n")
	b.WriteString("cd -- " + shellQuote(spec.dir) + " || exit 1\n")
	b.WriteString("exec " + shellQuote(claude) + " --session-id " + shellQuote(spec.sessionID))
	if spec.model != "" {
		b.WriteString(" --model " + shellQuote(spec.model))
	}
	if spec.permissionMode != "" {
		b.WriteString(" --permission-mode " + shellQuote(spec.permissionMode))
	}
	b.WriteString(" -- " + shellQuote(spec.prompt) + "\n")

	return b.String()
}

// writeLaunchScript writes the script of one session into root and returns its
// path. O_EXCL refuses a file or a link that is already there.
func writeLaunchScript(root, sessionID, body string) (string, error) {
	if err := os.MkdirAll(root, 0o700); err != nil {
		return "", fmt.Errorf("create the launch script directory: %w", err)
	}
	if err := os.Chmod(root, 0o700); err != nil {
		return "", fmt.Errorf("protect the launch script directory: %w", err)
	}
	path := filepath.Join(root, sessionID+".sh")
	f, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o700)
	if err != nil {
		return "", fmt.Errorf("create the launch script: %w", err)
	}
	_, err = f.WriteString(body)
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		os.Remove(path)

		return "", fmt.Errorf("write the launch script: %w", err)
	}

	return path, nil
}

// cleanLaunchScripts deletes each file in root older than staleScriptAge. Such
// a script belongs to a launch that never ran it.
func cleanLaunchScripts(root string, now time.Time, log *slog.Logger) {
	entries, err := os.ReadDir(root)
	if errors.Is(err, fs.ErrNotExist) {
		return
	}
	if err != nil {
		log.Warn("launch_scripts_unread", "dir", root, "error", err.Error())

		return
	}
	for _, entry := range entries {
		info, err := entry.Info()
		if err != nil || !info.Mode().IsRegular() || now.Sub(info.ModTime()) < staleScriptAge {
			continue
		}
		if err := os.Remove(filepath.Join(root, entry.Name())); err != nil && !errors.Is(err, fs.ErrNotExist) {
			log.Warn("launch_script_not_removed", "file", entry.Name(), "error", err.Error())
		}
	}
}

// runLauncher runs the launch command and returns why it failed, or "" when it
// launched. A command still running at the timeout counts as launched. The
// bridge never kills it, because it may be the terminal the session runs in.
func runLauncher(argv []string, timeout time.Duration) string {
	cmd := exec.Command(argv[0], argv[1:]...)
	out := &capWriter{limit: 4 * maxLaunchReason}
	cmd.Stdout, cmd.Stderr = out, out
	// A launcher can leave a child that holds its output open after it exits.
	cmd.WaitDelay = launchWaitDelay
	newProcessGroup(cmd)
	if err := cmd.Start(); err != nil {
		return err.Error()
	}
	// The channel has room, so the reaper never blocks after the timeout.
	done := make(chan error, 1)
	go func() { done <- cmd.Wait() }()
	timer := time.NewTimer(timeout)
	defer timer.Stop()

	var err error
	select {
	case err = <-done:
	case <-timer.C:
		return ""
	}
	var exitErr *exec.ExitError
	switch {
	case err == nil, errors.Is(err, exec.ErrWaitDelay):
		return ""
	case errors.As(err, &exitErr) && exitErr.ExitCode() >= 0:
		reason := "exit code " + strconv.Itoa(exitErr.ExitCode())
		if text := strings.TrimSpace(strings.ToValidUTF8(out.buf.String(), "")); text != "" {
			reason += ": " + text
		}

		return head(reason, maxLaunchReason)
	}

	return err.Error()
}

// head is the first n characters of s.
func head(s string, n int) string {
	r := []rune(s)
	if len(r) <= n {
		return s
	}

	return string(r[:n])
}

// launch is one interactive session to open, with what its run needs.
type launch struct {
	p       pending
	claude  string
	command rules.Launch
	dir     string
}

// launchLocked opens an interactive session for p on its own goroutine. It
// takes no worker slot and no card key, so a worker of the card runs beside
// it. A shut router opens nothing. The caller holds mu.
func (r *router) launchLocked(p pending) {
	if r.shut() {
		return
	}
	p.spec.sessionID = r.worker.sessionID()
	l := launch{p: p, claude: r.claude, command: p.set.Launch(), dir: r.scriptDir}
	if l.dir == "" {
		l.dir = defaultScriptDir()
	}
	r.launching++
	r.log.Info("session_launching", append(about(p.event, p.rule), "session_id", p.spec.sessionID)...)
	r.wg.Add(1)
	go func() {
		defer r.wg.Done()
		r.runLaunch(l)
	}()
}

// runLaunch writes the script, runs the launch command and reports how it went.
// The count drops under mu with the enqueue, so a drain never misses the report.
func (r *router) runLaunch(l launch) {
	p := l.p
	reason := ""
	path, err := writeLaunchScript(l.dir, p.spec.sessionID, launchScript(l.claude, p.spec))
	if err != nil {
		reason = err.Error()
	} else {
		_, number := cardOf(p.event)
		reason = runLauncher(l.command.Argv(rules.LaunchValues{
			Script: path, Dir: p.spec.dir, SessionID: p.spec.sessionID, CardNumber: strconv.Itoa(number), Project: p.project,
		}), l.command.Timeout)
		if reason != "" {
			os.Remove(path)
		}
	}

	report := api.InteractiveLaunchReport{State: api.RunRunning}
	if reason != "" {
		report.State, report.FailureReason = api.RunNotStarted, reason
		r.log.Error("session_launch_failed", append(about(p.event, p.rule), "session_id", p.spec.sessionID, "reason", reason)...)
	} else {
		r.log.Info("session_launched", append(about(p.event, p.rule), "session_id", p.spec.sessionID)...)
	}

	r.mu.Lock()
	defer r.mu.Unlock()
	r.launching--
	cardID, number := cardOf(p.event)
	if !r.reporting() || number < 1 {
		return
	}
	report.BridgeID, report.CardID, report.CardNumber, report.RuleName, report.At = r.bridgeID, cardID, number, p.rule, time.Now()
	r.reports.Enqueue(r.runs.launch(p.event.ProjectID, p.spec.sessionID, report))
}
