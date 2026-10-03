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
	"github.com/ubermuda/loupe/cli/internal/rules"
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
	serverRange                    func(ctx context.Context) (string, error)
}

// readServerRange reads the CLI range the server supports from GET
// /api/events, with the stored login, as a bridge does at start.
func readServerRange(ctx context.Context) (string, error) {
	cfg, err := config.Load()
	if err != nil {
		return "", err
	}
	events, err := apiClient(cfg).Events(ctx, cfg.BridgeID)
	if err != nil {
		return "", err
	}
	if events.CliRange == "" {
		return "", errors.New("the server sends no CLI range")
	}

	return events.CliRange, nil
}

// runningBridge is a bridge that holds its lock, named for the output.
type runningBridge struct {
	name, sock string
}

func newUpdateCmd() *cobra.Command {
	return newUpdateCmdWith(selfUpdate{
		version:     version,
		goos:        runtime.GOOS,
		goarch:      runtime.GOARCH,
		apiBase:     update.GitHubAPI,
		hc:          &http.Client{Timeout: updateTimeout},
		executable:  os.Executable,
		serverRange: readServerRange,
	})
}

func newUpdateCmdWith(self selfUpdate) *cobra.Command {
	var rulesPath string

	cmd := &cobra.Command{
		Use:   "update",
		Short: "Update the CLI now, through each running bridge or in place",
		Long: "Asks each running bridge to check for a CLI release now and to hand over " +
			"to it. The check ignores the skip list and the autoUpdate key. With no running " +
			"bridge, this command replaces the loupe binary in place. A binary that Homebrew " +
			"installed is left to brew: the command then asks no bridge, prints the brew " +
			"upgrade command, changes nothing and exits with status 1. A bridge that runs a " +
			"Homebrew binary refuses the request too. The command also exits with status 1 " +
			"when a bridge or the update in place fails. Automatic updates are off unless the rule " +
			"file holds autoUpdate: true; see loupe update auto.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			if installMethod(self.executable) == installHomebrew {
				return errors.New(homebrewRefusal)
			}
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
	cmd.AddCommand(newUpdateAutoCmd())

	return cmd
}

func newUpdateAutoCmd() *cobra.Command {
	var rulesPath string
	var keep bool

	cmd := &cobra.Command{
		Use:   "auto [on|off]",
		Short: "Show or set the autoUpdate key of the rule file",
		Long: "With no argument, shows whether a bridge updates the CLI on its own. Automatic " +
			"updates are off when the rule file has no autoUpdate key. With on or off, adds " +
			"the key as a new last line of the rule file, and creates the file when it is absent. " +
			"A key that holds the other value changes on its own line, and the rest of the file " +
			"stays. When that line cannot change alone, the command exits with status 1 and names " +
			"the line to edit. With --keep, a key that is there keeps its value, and the command " +
			"exits with status 0. A running bridge reads the change on loupe bridge reload.",
		Args:      cobra.MatchAll(cobra.MaximumNArgs(1), cobra.OnlyValidArgs),
		ValidArgs: []string{"on", "off"},
		RunE: func(cmd *cobra.Command, args []string) error {
			out := cmd.OutOrStdout()
			path, err := rulesPathOr(rulesPath)
			if err != nil {
				return err
			}
			if len(args) == 0 {
				on, present, err := rules.ReadAutoUpdate(path)
				if err != nil {
					return fmt.Errorf("rule file %s: %w", path, err)
				}
				state := onOff(on)
				if !present {
					state += " (default)"
				}
				fmt.Fprintln(out, "Automatic updates: "+state)

				return nil
			}

			on := args[0] == "on"
			kept, err := rules.SetAutoUpdate(path, on)
			switch {
			case err != nil:
				return fmt.Errorf("rule file %s: %w", path, err)
			case kept != nil && keep:
				fmt.Fprintf(out, "Automatic updates: %s (kept from %s)\n", onOff(*kept), path)

				return nil
			case kept != nil && *kept == on:
				fmt.Fprintln(out, "Automatic updates: "+onOff(on))

				return nil
			case kept != nil:
				err := rules.ReplaceAutoUpdate(path, on)
				if errors.Is(err, rules.ErrEditRefused) {
					return fmt.Errorf("%s already holds %s; edit that line to %s", path, rules.AutoUpdateLine(*kept), rules.AutoUpdateLine(on))
				}
				if err != nil {
					return fmt.Errorf("rule file %s: %w", path, err)
				}
			}
			fmt.Fprintln(out, "Automatic updates: "+onOff(on))
			fmt.Fprintf(out, "Wrote %s to %s. A running bridge reads it on loupe bridge reload.\n", rules.AutoUpdateLine(on), path)

			return nil
		},
	}
	cmd.Flags().StringVar(&rulesPath, "rules", "", "read and write the rule file at this `path`; empty uses rules.yaml in your config directory")
	cmd.Flags().BoolVar(&keep, "keep", false, "keep an autoUpdate key that the file holds, whatever its value")

	return cmd
}

func onOff(on bool) string {
	if on {
		return "on"
	}

	return "off"
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
// keeps one bridge: the one on the socket of the path as given, which a
// repointed symlink keeps, or else the one on the lock of the resolved file.
func runningBridges(rulesPath string) ([]runningBridge, error) {
	all, err := heldBridges()
	if err != nil {
		return nil, err
	}
	if rulesPath == "" {
		bridges := make([]runningBridge, 0, len(all))
		for _, b := range all {
			bridges = append(bridges, runningBridge{name: b.sock, sock: b.sock})
		}

		return bridges, nil
	}
	wantSock, err := socketPath(rulesPath)
	if err != nil {
		return nil, err
	}
	wantLock, err := lockPath(rulesPath)
	if err != nil {
		return nil, err
	}
	for _, match := range []func(heldBridge) bool{
		func(b heldBridge) bool { return b.sock == wantSock },
		func(b heldBridge) bool { return slices.Contains(b.locks, wantLock) },
	} {
		if i := slices.IndexFunc(all, match); i >= 0 {
			return []runningBridge{{name: absOr(rulesPath), sock: all[i].sock}}, nil
		}
	}

	return nil, nil
}

// heldBridge is a running bridge, with each lock file that names its socket.
type heldBridge struct {
	sock  string
	locks []string
}

// heldBridges lists the running bridges, named by their socket.
func heldBridges() ([]heldBridge, error) {
	dir, err := config.Dir()
	if err != nil {
		return nil, err
	}
	locks, err := filepath.Glob(filepath.Join(dir, "bridge-*.lock"))
	if err != nil {
		return nil, err
	}

	var bridges []heldBridge
	for _, path := range locks {
		sock, err := lockHolder(path)
		if err != nil {
			return nil, err
		}
		if sock == "" {
			continue
		}
		if i := slices.IndexFunc(bridges, func(b heldBridge) bool { return b.sock == sock }); i >= 0 {
			bridges[i].locks = append(bridges[i].locks, path)

			continue
		}
		bridges = append(bridges, heldBridge{sock: sock, locks: []string{path}})
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
	rd := bufio.NewReader(conn)
	line, err := rd.ReadString('\n')
	if err != nil {
		return res, fmt.Errorf("the bridge closed the connection with no answer: %w", err)
	}
	if err := json.Unmarshal([]byte(line), &res); err != nil {
		return res, fmt.Errorf("read the answer of the bridge: %w", err)
	}
	if res.Outcome != outcomeHandingOver {
		return res, nil
	}
	// The exec closes the connection. A second line means the exec failed.
	line, err = rd.ReadString('\n')
	if errors.Is(err, io.EOF) && line == "" {
		return res, nil
	}
	if err != nil {
		return res, fmt.Errorf("the bridge announced a handover and then broke the connection: %w", err)
	}
	var final updateResult
	if err := json.Unmarshal([]byte(line), &final); err != nil {
		return final, fmt.Errorf("read the answer of the bridge: %w", err)
	}

	return final, nil
}

// run replaces the running binary with a release, as no bridge runs to hand
// over. It takes the highest release in the range the server supports. When it
// cannot read that range, it takes the highest release of the running major
// version, or the highest release for a development build.
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
	cliRange, rangeErr := s.serverRange(ctx)
	var rule string
	var keep func(update.Version) bool
	switch {
	case rangeErr == nil:
		rule = "the highest release in the range " + cliRange + " that the server supports"
		keep = func(v update.Version) bool { return update.Satisfies(v.String(), cliRange) }
	case semver:
		rule = fmt.Sprintf("the highest %d.x release", running.Major)
		keep = func(v update.Version) bool { return v.Major == running.Major }
	default:
		rule = "the highest release, as this is a development build"
		keep = func(update.Version) bool { return true }
	}
	if rangeErr != nil {
		fmt.Fprintf(out, "could not read the CLI range of the server (%v)\n", rangeErr)
	}
	fmt.Fprintln(out, "no running bridge: updating this binary to "+rule)

	releases, err := update.FetchReleases(ctx, u.hc, u.apiBase)
	if err != nil {
		return err
	}
	c, found := update.PickWhere(releases, u.goos, u.goarch, keep)
	inRange := rangeErr == nil && update.Satisfies(s.version, cliRange)
	if !found && !inRange {
		return fmt.Errorf("no release is %s", rule)
	}
	current := false
	switch {
	case !semver:
	case rangeErr == nil:
		current = !found || !update.ShouldInstall(s.version, c.Version, cliRange)
	default:
		current = update.Compare(c.Version, running) <= 0
	}
	if current {
		fmt.Fprintln(out, "current at "+s.version)

		return nil
	}
	to := c.Version.String()
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
