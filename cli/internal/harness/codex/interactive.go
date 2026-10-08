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

// locate finds the thread of an interactive run: the first session that no run
// claimed, which started in the launch folder at or after the launch. A run
// with no launch record has no thread.
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
	var best string
	var bestStart time.Time
	for _, path := range slices.Sorted(slices.Values(files)) {
		if info, err := os.Stat(path); err != nil || info.ModTime().Before(at.Add(-launchSlack)) {
			continue
		}
		id, cwd, start, ok := meta(path)
		if !ok || taken[id] || start.Before(at.Add(-launchSlack)) || realPath(cwd) != want {
			continue
		}
		if best == "" || start.Before(bestStart) {
			best, bestStart = id, start
		}
	}
	if best == "" {
		return "", transcript.ErrNotFound
	}
	if err := h.remember(runID, best); err != nil {
		return "", err
	}

	return best, nil
}
