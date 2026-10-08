package codex

import (
	"bufio"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/ubermuda/loupe/cli/internal/transcript"
)

// launchSuffix ends the name of a launch record in the threads folder.
const launchSuffix = ".launch"

// pendingAge is the age past which an unmatched launch counts no more. Its
// terminal closed, or Codex never started in it. A launch that holds its place
// for this long can still push the next launch in its folder onto a later
// session.
const pendingAge = 24 * time.Hour

// launchSlack lets a session file start a little before the recorded launch
// time, because the two clocks are read at different moments.
const launchSlack = 2 * time.Second

// RecordLaunch keeps the folder and the time of an interactive launch. Codex
// picks the thread id itself, so locate finds the session from this record.
func (h Harness) RecordLaunch(runID, dir string, at time.Time) error {
	path, err := h.threadFile(runID + launchSuffix)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(h.threads, 0o700); err != nil {
		return err
	}
	body := strconv.FormatInt(at.UnixNano(), 10) + "\n" + dir + "\n"

	return os.WriteFile(path, []byte(body), 0o600)
}

// ForgetLaunch removes the record of a launch that opened no session.
func (h Harness) ForgetLaunch(runID string) {
	if path, err := h.threadFile(runID + launchSuffix); err == nil {
		_ = os.Remove(path)
	}
}

// launchRecord is what RecordLaunch kept.
func (h Harness) launchRecord(runID string) (dir string, at time.Time, err error) {
	path, err := h.threadFile(runID + launchSuffix)
	if err != nil {
		return "", time.Time{}, err
	}
	b, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return "", time.Time{}, transcript.ErrNotFound
	}
	if err != nil {
		return "", time.Time{}, err
	}
	first, rest, _ := strings.Cut(string(b), "\n")
	nanos, perr := strconv.ParseInt(first, 10, 64)
	dir = strings.TrimSuffix(rest, "\n")
	if perr != nil || dir == "" {
		return "", time.Time{}, transcript.ErrNotFound
	}

	return dir, time.Unix(0, nanos), nil
}

// claimed is the set of threads that a run already maps to.
func (h Harness) claimed() map[string]bool {
	set := map[string]bool{}
	entries, err := os.ReadDir(h.threads)
	if err != nil {
		return set
	}
	for _, e := range entries {
		if strings.HasSuffix(e.Name(), launchSuffix) || !e.Type().IsRegular() {
			continue
		}
		if b, err := os.ReadFile(filepath.Join(h.threads, e.Name())); err == nil {
			set[strings.TrimSpace(string(b))] = true
		}
	}

	return set
}

// meta reads the first line of a session file, which holds the thread id, the
// folder and the start of the session.
func meta(path string) (thread, cwd string, start time.Time, ok bool) {
	f, err := os.Open(path)
	if err != nil {
		return "", "", time.Time{}, false
	}
	defer f.Close()
	line, err := bufio.NewReaderSize(f, 1<<16).ReadBytes('\n')
	if err != nil && len(line) == 0 {
		return "", "", time.Time{}, false
	}
	var entry struct {
		Timestamp time.Time `json:"timestamp"`
		Type      string    `json:"type"`
		Payload   struct {
			ID  string `json:"id"`
			Cwd string `json:"cwd"`
		} `json:"payload"`
	}
	if json.Unmarshal(line, &entry) != nil || entry.Type != "session_meta" || !threadPattern.MatchString(entry.Payload.ID) {
		return "", "", time.Time{}, false
	}

	return entry.Payload.ID, entry.Payload.Cwd, entry.Timestamp, true
}

// pendingBefore counts the launches in dir before this one that no thread was
// matched to yet. They own the earliest sessions that started in dir, so this
// launch takes the session after them.
func (h Harness) pendingBefore(runID, dir string, at time.Time) int {
	entries, err := os.ReadDir(h.threads)
	if err != nil {
		return 0
	}
	n := 0
	for _, e := range entries {
		id, ok := strings.CutSuffix(e.Name(), launchSuffix)
		if !ok || id == runID {
			continue
		}
		other, otherAt, err := h.launchRecord(id)
		if err != nil || realPath(other) != realPath(dir) {
			continue
		}
		if otherAt.After(at) || (otherAt.Equal(at) && id > runID) || at.Sub(otherAt) > pendingAge {
			continue
		}
		if _, err := os.Stat(filepath.Join(h.threads, id)); errors.Is(err, os.ErrNotExist) {
			n++
		}
	}

	return n
}

// locate finds the thread of an interactive run among the sessions that no run
// claimed, which started in the launch folder at or after the launch. Sessions
// start in launch order, so a launch skips one session for each earlier launch
// in the folder that has none yet. A run with no launch record has no thread.
func (h Harness) locate(runID string) (string, error) {
	dir, at, err := h.launchRecord(runID)
	if err != nil {
		return "", err
	}
	home, err := h.homeDir()
	if err != nil {
		return "", err
	}
	files, err := filepath.Glob(filepath.Join(home, "sessions", "*", "*", "*", "rollout-*.jsonl"))
	if err != nil {
		return "", transcript.ErrNotFound
	}
	want := realPath(dir)
	taken := h.claimed()
	type found struct {
		id    string
		start time.Time
	}
	var matches []found
	for _, path := range files {
		if info, err := os.Stat(path); err != nil || info.ModTime().Before(at.Add(-launchSlack)) {
			continue
		}
		id, cwd, start, ok := meta(path)
		if !ok || taken[id] || start.Before(at.Add(-launchSlack)) || realPath(cwd) != want {
			continue
		}
		matches = append(matches, found{id, start})
	}
	slices.SortFunc(matches, func(a, b found) int { return a.start.Compare(b.start) })
	skip := h.pendingBefore(runID, dir, at)
	if skip >= len(matches) {
		return "", transcript.ErrNotFound
	}
	if err := h.remember(runID, matches[skip].id); err != nil {
		return "", err
	}

	return matches[skip].id, nil
}
