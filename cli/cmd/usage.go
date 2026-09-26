package cmd

import (
	"bufio"
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"strings"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/transcript"
)

func newUsageCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "usage",
		Short: "Work with the token usage of worker runs",
	}
	cmd.AddCommand(newUsageBackfillCmd())

	return cmd
}

// backfillOptions are the flags of `loupe usage backfill`.
type backfillOptions struct {
	logFile, project string
	dryRun           bool
}

func newUsageBackfillCmd() *cobra.Command {
	var o backfillOptions

	cmd := &cobra.Command{
		Use:   "backfill",
		Short: "Send the token usage of past worker runs to Loupe",
		Long: "Reads the worker_started lines of the bridge log and the Claude Code transcript " +
			"of each session, and sends the usage of each worker process of the session to Loupe. " +
			"A process that ended on its own sends claude's own counts and dollars as reported. " +
			"A killed process sends the priced sum of its messages as estimated.\n\n" +
			"Run it on the machine that ran the bridge. Claude Code deletes a transcript after " +
			"about 30 days, and the command skips a session with no transcript. It also skips a " +
			"session whose transcript does not fit its processes. It prints one line for each " +
			"session, and exits with status 1 when Loupe refused a session or a request failed.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			return backfill(cmd, o)
		},
	}
	cmd.Flags().StringVar(&o.logFile, "log-file", "", "read the bridge log at this `path`; empty uses bridge.log in your config directory")
	cmd.Flags().StringVar(&o.project, "project", "", "send only the sessions of the project with this `id`, as the bridge log names it")
	cmd.Flags().BoolVar(&o.dryRun, "dry-run", false, "print what the command would send, and send nothing")

	return cmd
}

// workerSession is one session of the bridge log, with the start time of each
// of its worker processes in log order.
type workerSession struct {
	id, project string
	card        int
	starts      []time.Time
	// skip says why the session cannot be sent, whatever its transcript holds.
	skip string
}

func (s *workerSession) label() string {
	if s.card == 0 {
		return s.id + " (no card)"
	}

	return fmt.Sprintf("%s (card %d)", s.id, s.card)
}

func backfill(cmd *cobra.Command, o backfillOptions) error {
	path := o.logFile
	if path == "" {
		path = defaultLogPath()
	}
	sessions, err := readWorkerSessions(path, o.project)
	if err != nil {
		return err
	}
	dir, err := transcript.ConfigDir()
	if err != nil {
		return err
	}
	var client *api.Client
	if !o.dryRun {
		cfg, err := config.Load()
		if err != nil {
			return err
		}
		client = apiClient(cfg)
	}

	out := cmd.OutOrStdout()
	failed := 0
	for _, s := range sessions {
		processes, skip, err := sessionUsage(dir, s)
		switch {
		case err != nil:
			fmt.Fprintf(out, "failed %s: %v\n", s.label(), err)
			failed++

			continue
		case skip != "":
			fmt.Fprintf(out, "skipped %s: %s\n", s.label(), skip)

			continue
		case o.dryRun:
			fmt.Fprintf(out, "would send %s: %s\n", s.label(), describeProcesses(processes))

			continue
		}

		result, err := client.ReportSessionUsage(cmd.Context(), s.project, s.id, processes)
		var refused *api.UsageRefused
		switch {
		case errors.As(err, &refused):
			fmt.Fprintf(out, "refused %s: %s\n", s.label(), refused)
			failed++
		case err != nil:
			fmt.Fprintf(out, "failed %s: %v\n", s.label(), err)
			failed++
		default:
			fmt.Fprintf(out, "sent %s: runs %d, updated %d\n", s.label(), result.Runs, result.Updated)
		}
	}
	if failed > 0 {
		return fmt.Errorf("%d of the sessions failed", failed)
	}

	return nil
}

// readWorkerSessions groups the worker_started lines of the log by session, in
// the order each session first appears. A line with no session id comes from
// an older bridge, and the command cannot find its transcript.
func readWorkerSessions(path, project string) ([]*workerSession, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, fmt.Errorf("read the bridge log: %w", err)
	}
	defer f.Close()

	var sessions []*workerSession
	byID := map[string]*workerSession{}
	r := bufio.NewReaderSize(f, 1<<16)
	for {
		line, err := r.ReadBytes('\n')
		if bytes.Contains(line, []byte(`"worker_started"`)) {
			var entry struct {
				Time      time.Time `json:"time"`
				Event     string    `json:"event"`
				Card      int       `json:"card"`
				Project   string    `json:"project"`
				SessionID string    `json:"session_id"`
			}
			if json.Unmarshal(line, &entry) == nil && entry.Event == "worker_started" && entry.SessionID != "" &&
				(project == "" || strings.EqualFold(entry.Project, project)) {
				s := byID[entry.SessionID]
				if s == nil {
					s = &workerSession{id: entry.SessionID, project: entry.Project, card: entry.Card}
					byID[entry.SessionID] = s
					sessions = append(sessions, s)
				}
				s.starts = append(s.starts, entry.Time)
				switch {
				case entry.Card == 0:
					s.skip = "a run with no card has no record in Loupe"
				case entry.Project != s.project || entry.Card != s.card:
					s.skip = "the processes of the session name different cards or projects"
				}
			}
		}
		if errors.Is(err, io.EOF) {
			return sessions, nil
		}
		if err != nil {
			return nil, fmt.Errorf("read the bridge log: %w", err)
		}
	}
}

// sessionUsage is the usage of each process of the session, or why the
// session is skipped.
func sessionUsage(dir string, s *workerSession) ([]api.Usage, string, error) {
	if s.skip != "" {
		return nil, s.skip, nil
	}
	path, err := transcript.Find(dir, s.id)
	if errors.Is(err, transcript.ErrNotFound) {
		return nil, err.Error(), nil
	}
	if err != nil {
		return nil, "", err
	}
	processes, err := transcript.Processes(path, s.starts)
	if errors.Is(err, transcript.ErrUnmappable) {
		return nil, err.Error(), nil
	}
	if err != nil {
		return nil, "", err
	}

	out := make([]api.Usage, len(processes))
	for i, p := range processes {
		source := api.UsageEstimated
		if p.Reported {
			source = api.UsageReported
		}
		out[i] = *apiUsage(source, p.Usage)
		if err := out[i].Check(); err != nil {
			return nil, fmt.Sprintf("process %d: %v", i+1, err), nil
		}
	}

	return out, "", nil
}

// describeProcesses names the source and the dollars of each process.
func describeProcesses(processes []api.Usage) string {
	parts := make([]string, len(processes))
	for i, p := range processes {
		total, known := 0.0, true
		for _, m := range p.Models {
			if m.CostUSD == nil {
				known = false
			} else {
				total += *m.CostUSD
			}
		}
		if known {
			parts[i] = fmt.Sprintf("%s $%.4f", p.Source, total)
		} else {
			parts[i] = p.Source + " unknown cost"
		}
	}

	return strings.Join(parts, ", ")
}
