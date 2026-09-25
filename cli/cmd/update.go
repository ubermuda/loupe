package cmd

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"runtime"
	"slices"
	"strings"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/update"
)

// updateRequestTimeout bounds one `loupe update` request to a bridge. The
// bridge can wait for a check in flight, then download and hand over.
const updateRequestTimeout = 30 * time.Minute

// selfUpdate is what an update without a running bridge needs; tests replace it.
type selfUpdate struct {
	version, goos, goarch, apiBase string
	hc                             *http.Client
	executable                     func() (string, error)
}

// runningBridge is a bridge that holds its lock, named for the output.
type runningBridge struct {
	name, sock string
}

func newUpdateCmd() *cobra.Command {
	return newUpdateCmdWith(selfUpdate{
		version:    version,
		goos:       runtime.GOOS,
		goarch:     runtime.GOARCH,
		apiBase:    update.GitHubAPI,
		hc:         &http.Client{Timeout: updateTimeout},
		executable: os.Executable,
	})
}

func newUpdateCmdWith(self selfUpdate) *cobra.Command {
	var rulesPath string

	cmd := &cobra.Command{
		Use:   "update",
		Short: "Update the CLI now, through each running bridge or in place",
		Long: "Asks each running bridge to check for a CLI release now and to hand over " +
			"to it. The check ignores the skip list and the autoUpdate key. With no running " +
			"bridge, this command replaces the loupe binary in place. It exits with status 1 " +
			"when a bridge or the update in place fails.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			out := cmd.OutOrStdout()
			bridges, err := runningBridges(rulesPath)
			if err != nil {
				return err
			}
			if len(bridges) == 0 {
				if rulesPath != "" {
					return fmt.Errorf("no running bridge reads %s", absOr(rulesPath))
				}

				return self.run(cmd.Context(), out)
			}

			failed := false
			for _, b := range bridges {
				res, err := requestUpdate(b.sock)
				if err != nil {
					res = updateResult{Outcome: outcomeFailed, Problem: err.Error()}
				}
				if res.Outcome == "" {
					res.Outcome = outcomeFailed
				}
				if res.Problem == "" {
					res.Problem = strings.Join(res.Problems, "; ")
				}
				failed = failed || !res.OK
				fmt.Fprintln(out, updateLine(b.name, res))
			}
			if failed {
				return errors.New("a bridge did not update")
			}

			return nil
		},
	}
	cmd.Flags().StringVar(&rulesPath, "rules", "", "update only the bridge that reads this `path`")

	return cmd
}

// absOr gives the absolute rulesPath, or rulesPath when it has none.
func absOr(rulesPath string) string {
	if abs, err := filepath.Abs(rulesPath); err == nil {
		return abs
	}

	return rulesPath
}

// updateLine is one line of output for one bridge.
func updateLine(name string, res updateResult) string {
	line := name + ": " + res.Outcome
	switch {
	case res.To != "":
		line += fmt.Sprintf(", from %s to %s", orDev(res.From), res.To)
	case res.From != "":
		line += " at " + res.From
	}
	if res.Problem != "" {
		line += ": " + res.Problem
	}

	return line
}

func orDev(v string) string {
	if v == "" {
		return "a development build"
	}

	return v
}

// runningBridges finds the bridges whose lock is held, from the lock files in
// the config directory. Each lock file holds the socket of its bridge. A bridge
// in a reload can hold two lock files that name one socket. With rulesPath, it
// keeps the bridge on that path's socket, which a repointed symlink keeps.
func runningBridges(rulesPath string) ([]runningBridge, error) {
	want := ""
	if rulesPath != "" {
		var err error
		if want, err = socketPath(rulesPath); err != nil {
			return nil, err
		}
	}
	dir, err := config.Dir()
	if err != nil {
		return nil, err
	}
	locks, err := filepath.Glob(filepath.Join(dir, "bridge-*.lock"))
	if err != nil {
		return nil, err
	}

	var bridges []runningBridge
	for _, path := range locks {
		sock, err := lockHolder(path)
		if err != nil {
			return nil, err
		}
		if sock == "" || (want != "" && sock != want) || slices.ContainsFunc(bridges, func(b runningBridge) bool { return b.sock == sock }) {
			continue
		}
		name := sock
		if rulesPath != "" {
			name = absOr(rulesPath)
		}
		bridges = append(bridges, runningBridge{name: name, sock: sock})
	}

	return bridges, nil
}

// lockHolder gives the socket written in the lock file at path while a bridge
// holds its lock, and "" when no bridge holds it.
func lockHolder(path string) (string, error) {
	f, err := os.Open(path)
	if errors.Is(err, fs.ErrNotExist) {
		return "", nil
	}
	if err != nil {
		return "", err
	}
	defer f.Close()
	err = tryLockFile(f)
	if err == nil {
		return "", nil
	}
	if !lockHeld(err) {
		return "", fmt.Errorf("lock %s: %w", path, err)
	}
	sock, err := io.ReadAll(f)
	if err != nil {
		return "", err
	}

	return string(sock), nil
}

// requestUpdate sends one update request to the socket and reads the answer.
func requestUpdate(sock string) (updateResult, error) {
	var res updateResult
	deadline := time.Now().Add(updateRequestTimeout)
	conn, err := (&net.Dialer{Deadline: deadline}).Dial("unix", sock)
	if err != nil {
		return res, err
	}
	defer conn.Close()
	conn.SetDeadline(deadline)

	if _, err := conn.Write([]byte(`{"op":"update"}` + "\n")); err != nil {
		return res, fmt.Errorf("send the update: %w", err)
	}
	line, err := bufio.NewReader(conn).ReadString('\n')
	if err != nil {
		return res, fmt.Errorf("the bridge closed the connection with no answer: %w", err)
	}
	if err := json.Unmarshal([]byte(line), &res); err != nil {
		return res, fmt.Errorf("read the answer of the bridge: %w", err)
	}

	return res, nil
}

// run replaces the running binary with a release, as no bridge runs to hand
// over. With no server range to read, it takes the highest release of the
// running major version, or the highest release for a development build.
func (s selfUpdate) run(ctx context.Context, out io.Writer) error {
	dir, err := config.Dir()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return fmt.Errorf("create config dir: %w", err)
	}
	u := newUpdater(slog.New(slog.DiscardHandler), s.version, dir, nil, nil)
	u.goos, u.goarch, u.apiBase, u.executable = s.goos, s.goarch, s.apiBase, s.executable
	if s.hc != nil {
		u.hc = s.hc
	}

	running, semver := update.ParseVersion(s.version)
	keep := func(update.Version) bool { return true }
	rule := "the highest release, as this is a development build"
	if semver {
		keep = func(v update.Version) bool { return v.Major == running.Major }
		rule = fmt.Sprintf("the highest %d.x release", running.Major)
	}
	fmt.Fprintln(out, "no running bridge: updating this binary to "+rule)

	releases, err := update.FetchReleases(ctx, u.hc, u.apiBase)
	if err != nil {
		return err
	}
	c, found := update.PickWhere(releases, u.goos, u.goarch, keep)
	if !found {
		return fmt.Errorf("no release is %s", rule)
	}
	to := c.Version.String()
	if semver && update.Compare(c.Version, running) <= 0 {
		fmt.Fprintln(out, "current at "+s.version)

		return nil
	}
	if err := u.writable(); err != nil {
		return fmt.Errorf("blocked: the directory of the binary takes no new file: %w", err)
	}
	st, err := update.LoadState(dir)
	if err != nil {
		st = &update.State{Staged: map[string]string{}}
	}
	staged, err := u.stage(ctx, st, c)
	if err != nil {
		return fmt.Errorf("stage %s: %w", to, err)
	}
	exe, err := s.executable()
	if err != nil {
		return err
	}
	swapped, err := swapBinary(dir, staged, exe)
	if err != nil {
		return fmt.Errorf("install %s over %s: %w", to, exe, err)
	}
	if !swapped {
		fmt.Fprintf(out, "%s already holds %s\n", exe, to)

		return nil
	}
	fmt.Fprintf(out, "installed %s over %s\n", to, exe)

	return nil
}
