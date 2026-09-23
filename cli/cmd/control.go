package cmd

import (
	"bufio"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"net"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/config"
)

const (
	// maxSocketPath is the sun_path size on macOS, the smallest of the hosts.
	maxSocketPath = 104
	// maxRequest bounds one request line, without its newline.
	maxRequest = 1024
	// requestTimeout bounds the read of a request and the write of its answer.
	requestTimeout = 5 * time.Second
	// reloadTimeout bounds a whole `loupe bridge reload`. The bridge checks the
	// rule file against the server, which can take several requests.
	reloadTimeout = 60 * time.Second
	// reloadBuildTimeout stays below reloadTimeout, so the bridge answers first.
	reloadBuildTimeout = 50 * time.Second
)

// rulesPathOr gives path, or the default rule file when path is empty.
func rulesPathOr(path string) (string, error) {
	if path != "" {
		return path, nil
	}

	return defaultRulesPath()
}

// socketPath names the control socket of the bridge that reads rulesPath. The
// name comes from the absolute path as given. A repointed symlink thus keeps
// the address that the running bridge listens on.
func socketPath(rulesPath string) (string, error) {
	abs, err := filepath.Abs(rulesPath)
	if err != nil {
		return "", err
	}

	return bridgeFile(abs, ".sock")
}

// lockPath names the lock file of the bridge that reads rulesPath. The name
// comes from the absolute path with symlinks resolved, so two paths to one
// file share the lock. A path that does not resolve stays as is.
func lockPath(rulesPath string) (string, error) {
	abs, err := filepath.Abs(rulesPath)
	if err != nil {
		return "", err
	}
	if real, err := filepath.EvalSymlinks(abs); err == nil {
		abs = real
	}

	return bridgeFile(abs, ".lock")
}

// bridgeFile names a file in the config directory from a hash of key.
func bridgeFile(key, ext string) (string, error) {
	dir, err := config.Dir()
	if err != nil {
		return "", err
	}
	sum := sha256.Sum256([]byte(key))

	return filepath.Join(dir, "bridge-"+hex.EncodeToString(sum[:])[:12]+ext), nil
}

// bridgeLock is the lock that a running bridge holds on the file that its
// rule path resolves to. A reload moves it, and runs before the bridge closes it.
type bridgeLock struct {
	rulesPath, sock, path string
	f                     *os.File
}

// lockBridge takes the lock of the bridge that reads rulesPath and writes sock
// into the lock file. The OS releases the lock when the file closes or the
// process dies.
func lockBridge(rulesPath, sock string) (*bridgeLock, error) {
	path, err := lockPath(rulesPath)
	if err != nil {
		return nil, err
	}
	f, err := lockFileAt(path, sock)
	if err != nil {
		return nil, err
	}

	return &bridgeLock{rulesPath: rulesPath, sock: sock, path: path, f: f}, nil
}

// follow takes the lock of the file that the rule path resolves to now, when
// that file changed. The caller then calls done with whether it applied the
// reload, and done releases the lock that the bridge no longer needs.
func (l *bridgeLock) follow() (done func(applied bool), err error) {
	path, err := lockPath(l.rulesPath)
	if err != nil {
		return nil, err
	}
	if path == l.path {
		return func(bool) {}, nil
	}
	f, err := lockFileAt(path, l.sock)
	if err != nil {
		return nil, err
	}

	return func(applied bool) {
		if !applied {
			f.Close()

			return
		}
		l.f.Close()
		l.f, l.path = f, path
	}, nil
}

func (l *bridgeLock) Close() error {
	return l.f.Close()
}

// lockFileAt opens the lock file at path, takes its lock and writes sock into it.
func lockFileAt(path, sock string) (*os.File, error) {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return nil, fmt.Errorf("create config dir: %w", err)
	}
	f, err := os.OpenFile(path, os.O_CREATE|os.O_RDWR, 0o600)
	if err != nil {
		return nil, fmt.Errorf("open the bridge lock: %w", err)
	}
	if err := tryLockFile(f); err != nil {
		f.Close()
		if !lockHeld(err) {
			return nil, fmt.Errorf("lock %s: %w", path, err)
		}
		where := ""
		// Windows refuses a read of the locked byte, so the socket stays unnamed there.
		if other, err := os.ReadFile(path); err == nil && len(other) > 0 {
			where = " and listens on " + string(other)
		}

		return nil, fmt.Errorf("another bridge reads the same rule file and holds %s%s: stop it first, or run `loupe bridge reload` to apply a change", path, where)
	}
	if err := f.Truncate(0); err == nil {
		f.WriteAt([]byte(sock), 0)
	}

	return f, nil
}

// lockedSocket gives the socket that the bridge which holds the lock of
// rulesPath wrote into the lock file. It gives "" when no bridge holds the
// lock, or when the read fails, as it does on Windows.
func lockedSocket(rulesPath string) string {
	path, err := lockPath(rulesPath)
	if err != nil {
		return ""
	}
	f, err := os.Open(path)
	if err != nil {
		return ""
	}
	defer f.Close()
	if err := tryLockFile(f); !lockHeld(err) {
		return ""
	}
	sock, err := os.ReadFile(path)
	if err != nil {
		return ""
	}

	return string(sock)
}

// listenControl listens on path. The caller holds the bridge lock. A bridge
// that still answers there got the same path to a symlink that has since been
// repointed, so the second one refuses to start. A file that nothing answers
// on is left by a bridge that crashed, and listenControl removes it. The
// config directory is 0700, so only its owner can connect.
func listenControl(path string) (net.Listener, error) {
	if len(path) >= maxSocketPath {
		return nil, fmt.Errorf("the control socket path %s has %d bytes, and a socket path must be shorter than %d bytes", path, len(path), maxSocketPath)
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return nil, fmt.Errorf("create config dir: %w", err)
	}

	conn, err := net.DialTimeout("unix", path, time.Second)
	if err == nil {
		conn.Close()

		return nil, fmt.Errorf("another bridge got the same rule path and listens on %s: stop it first, or run `loupe bridge reload` to apply a change", path)
	}
	if !connRefused(err) && !errors.Is(err, fs.ErrNotExist) {
		return nil, fmt.Errorf("probe the control socket %s: %w", path, err)
	}
	if err := os.Remove(path); err != nil && !errors.Is(err, fs.ErrNotExist) {
		return nil, fmt.Errorf("remove the stale control socket: %w", err)
	}

	ln, err := net.Listen("unix", path)
	if err != nil {
		return nil, fmt.Errorf("listen on the control socket: %w", err)
	}
	if runtime.GOOS != "windows" {
		if err := os.Chmod(path, 0o600); err != nil {
			ln.Close()

			return nil, fmt.Errorf("restrict the control socket: %w", err)
		}
	}

	return ln, nil
}

// serveControl answers each connection on ln with handle until ctx ends. The
// channel closes when the listener and every connection are closed. Closing a
// Unix listener removes its socket file.
func serveControl(ctx context.Context, ln net.Listener, handle func(context.Context) reloadResult) <-chan struct{} {
	done := make(chan struct{})
	stop := context.AfterFunc(ctx, func() { ln.Close() })
	var wg sync.WaitGroup
	go func() {
		defer close(done)
		defer wg.Wait()
		defer stop()
		defer ln.Close()
		for {
			conn, err := ln.Accept()
			if errors.Is(err, net.ErrClosed) {
				return
			}
			if err != nil {
				time.Sleep(100 * time.Millisecond)

				continue
			}
			wg.Add(1)
			go func() {
				defer wg.Done()
				answer(ctx, conn, handle)
			}()
		}
	}()

	return done
}

// answer reads one JSON line and writes one JSON line back. A connection that
// sends no complete line gets no answer.
func answer(ctx context.Context, conn net.Conn, handle func(context.Context) reloadResult) {
	defer conn.Close()
	stop := context.AfterFunc(ctx, func() { conn.Close() })
	defer stop()

	conn.SetReadDeadline(time.Now().Add(requestTimeout))
	line, err := bufio.NewReaderSize(io.LimitReader(conn, maxRequest+1), maxRequest+1).ReadString('\n')
	if err != nil && len(line) <= maxRequest {
		return
	}

	var res reloadResult
	var req struct {
		Op string `json:"op"`
	}
	switch {
	case err != nil:
		res = refused(fmt.Sprintf("the request is longer than %d bytes", maxRequest))
	case json.Unmarshal([]byte(line), &req) != nil:
		res = refused("the request is not one JSON object")
	case req.Op != "reload":
		res = refused(fmt.Sprintf("unknown op %q: only reload is known", req.Op))
	default:
		// A reload can take up to a minute, which is longer than the deadline.
		conn.SetDeadline(time.Time{})
		res = handle(ctx)
	}

	b, err := json.Marshal(res)
	if err != nil {
		return
	}
	conn.SetWriteDeadline(time.Now().Add(requestTimeout))
	conn.Write(append(b, '\n'))
}

func refused(problem string) reloadResult {
	return reloadResult{Problems: []string{problem}}
}

func newBridgeReloadCmd() *cobra.Command {
	var rulesPath string

	cmd := &cobra.Command{
		Use:   "reload",
		Short: "Apply a changed rule file to the running bridge",
		Long: "Tells the bridge that reads the rule file to read it again. The bridge " +
			"parses the file, checks it against the server, and applies it only when " +
			"every check passes. Otherwise it keeps its rules, and this command prints " +
			"each problem and exits with status 1.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			path, err := rulesPathOr(rulesPath)
			if err != nil {
				return err
			}
			abs, err := filepath.Abs(path)
			if err != nil {
				return err
			}
			sock, err := socketPath(abs)
			if err != nil {
				return err
			}
			res, err := requestReload(sock)
			if connRefused(err) || errors.Is(err, fs.ErrNotExist) {
				if other := lockedSocket(abs); other != "" && other != sock {
					res, err = requestReload(other)
				}
			}
			if connRefused(err) || errors.Is(err, fs.ErrNotExist) {
				return fmt.Errorf("no running bridge reads %s", abs)
			}
			if err != nil {
				return err
			}

			if !res.OK {
				for _, problem := range res.Problems {
					if res.Stage != "" {
						problem = res.Stage + ": " + problem
					}
					fmt.Fprintln(cmd.ErrOrStderr(), problem)
				}

				return errors.New("the bridge did not apply the rule file")
			}
			printReload(cmd.OutOrStdout(), abs, res)

			return nil
		},
	}
	cmd.Flags().StringVar(&rulesPath, "rules", "", "reload the bridge that reads this `path`; empty uses rules.yaml in your config directory")

	return cmd
}

// requestReload sends one reload request to the socket and reads the answer.
func requestReload(sock string) (reloadResult, error) {
	var res reloadResult
	deadline := time.Now().Add(reloadTimeout)
	conn, err := (&net.Dialer{Deadline: deadline}).Dial("unix", sock)
	if err != nil {
		return res, err
	}
	defer conn.Close()
	conn.SetDeadline(deadline)

	if _, err := conn.Write([]byte(`{"op":"reload"}` + "\n")); err != nil {
		return res, fmt.Errorf("send the reload: %w", err)
	}
	line, err := bufio.NewReader(conn).ReadString('\n')
	if err != nil {
		return res, fmt.Errorf("read the answer of the bridge: %w", err)
	}
	if err := json.Unmarshal([]byte(line), &res); err != nil {
		return res, fmt.Errorf("read the answer of the bridge: %w", err)
	}

	return res, nil
}

// printReload names the rule file, the rules and dirs the reload changed and
// the projects the bridge now maps.
func printReload(w io.Writer, path string, res reloadResult) {
	fmt.Fprintln(w, "reloaded "+path)
	for _, part := range []struct {
		label string
		names []string
	}{{"added", res.Added}, {"removed", res.Removed}, {"changed", res.Changed}, {"dir changed", res.Dirs}} {
		if len(part.names) > 0 {
			fmt.Fprintln(w, part.label+": "+strings.Join(part.names, ", "))
		}
	}
	if len(res.Added)+len(res.Removed)+len(res.Changed)+len(res.Dirs) == 0 {
		fmt.Fprintln(w, "no rule changed")
	}
	fmt.Fprintln(w, "projects: "+strings.Join(res.Projects, ", "))
}
