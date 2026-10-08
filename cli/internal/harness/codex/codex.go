// Package codex runs Codex as the harness of a worker.
package codex

import (
	"bytes"
	"encoding/json"
	"os"
	"os/exec"
	"path/filepath"
	"strings"

	"github.com/ubermuda/loupe/cli/internal/harness"
)

// The files a run keeps in its run directory. The bridge writes the schema
// before the process starts, and Codex writes the last message.
const (
	schemaFile      = "schema.json"
	lastMessageFile = "last-message.json"
)

// The sandbox modes of Codex.
const (
	modeReadOnly  = "read-only"
	modeWorkspace = "workspace-write"
	modeFull      = "danger-full-access"
)

const networkAccess = "sandbox_workspace_write.network_access=true"

// Harness is Codex. home is the Codex home folder and "" is the one of the
// bridge's own environment. profile names a <profile>.config.toml file in it,
// or is "". threads is the folder that maps the id of a run to a Codex thread.
type Harness struct {
	home    string
	profile string
	threads string
}

// New is the Codex harness of a home folder and a profile. threads is the
// folder that holds the map from a run id to a thread id.
func New(home, profile, threads string) Harness {
	return Harness{home: home, profile: profile, threads: threads}
}

func (Harness) Name() string { return "codex" }

func (Harness) Program() string { return "codex" }

func (h Harness) Worker(spec harness.Spec) harness.Command {
	args, files := h.options(spec, false)

	return harness.Command{Args: append(args, "--", spec.Prompt), Env: spec.Env, Files: files}
}

// Resume continues the thread that the run's id maps to. Codex reads the
// options of a resume only before the subcommand, and resume takes no sandbox
// flags, so a config override carries the sandbox.
func (h Harness) Resume(spec harness.Spec) harness.Command {
	args, files := h.options(spec, true)
	thread := spec.SessionID
	if id, err := h.thread(spec.SessionID); err == nil {
		thread = id
	}

	return harness.Command{Args: append(args, "resume", thread, "--", spec.Prompt), Env: spec.Env, Files: files}
}

// options builds the argv before the prompt, and the files it names.
func (h Harness) options(spec harness.Spec, resume bool) ([]string, map[string]string) {
	args := []string{"exec"}
	files := map[string]string{}
	if h.profile != "" {
		args = append(args, "-p", h.profile)
	}
	if spec.Model != "" {
		args = append(args, "-m", spec.Model)
	}
	args = append(args, sandboxArgs(spec, resume)...)
	args = append(args, "--json", "--skip-git-repo-check")
	if spec.Schema != "" && spec.RunDir != "" {
		path := filepath.Join(spec.RunDir, schemaFile)
		files[path] = spec.Schema
		args = append(args, "--output-schema", path)
	}
	if spec.RunDir != "" {
		args = append(args, "-o", filepath.Join(spec.RunDir, lastMessageFile))
	}

	return args, files
}

// sandboxArgs gives the flags of the sandbox mode. Workspace adds the main
// repository's git folder as a writable root, because a git worktree keeps its
// index and refs there, and turns the network on.
func sandboxArgs(spec harness.Spec, resume bool) []string {
	switch spec.PermissionMode {
	case "":
		return nil
	case modeFull:
		return []string{"--dangerously-bypass-approvals-and-sandbox"}
	case modeWorkspace:
		roots := gitRoots(spec.Dir)
		if resume {
			args := []string{"-c", `sandbox_mode="workspace-write"`}
			if len(roots) > 0 {
				args = append(args, "-c", "sandbox_workspace_write.writable_roots="+tomlArray(roots))
			}

			return append(args, "-c", networkAccess)
		}
		args := []string{"-s", modeWorkspace}
		for _, root := range roots {
			args = append(args, "--add-dir", root)
		}

		return append(args, "-c", networkAccess)
	}
	if resume {
		return []string{"-c", "sandbox_mode=" + tomlString(spec.PermissionMode)}
	}

	return []string{"-s", spec.PermissionMode}
}

// gitRoots is the git common directory of dir when it lies outside dir, and
// nothing when dir is no repository or the folder is inside dir.
func gitRoots(dir string) []string {
	cmd := exec.Command("git", "rev-parse", "--path-format=absolute", "--git-common-dir")
	cmd.Dir = dir
	out, err := cmd.Output()
	if err != nil {
		return nil
	}
	common := strings.TrimSpace(string(out))
	if common == "" || !filepath.IsAbs(common) {
		return nil
	}
	if rel, err := filepath.Rel(realPath(dir), realPath(common)); err == nil && !strings.HasPrefix(rel, "..") {
		return nil
	}

	return []string{common}
}

// realPath resolves symlinks, so /tmp and /private/tmp compare equal.
func realPath(path string) string {
	if resolved, err := filepath.EvalSymlinks(path); err == nil {
		return resolved
	}

	return path
}

func tomlString(s string) string {
	var b bytes.Buffer
	enc := json.NewEncoder(&b)
	enc.SetEscapeHTML(false)
	_ = enc.Encode(s)

	return strings.TrimSpace(b.String())
}

func tomlArray(items []string) string {
	quoted := make([]string, len(items))
	for i, item := range items {
		quoted[i] = tomlString(item)
	}

	return "[" + strings.Join(quoted, ",") + "]"
}

// shellQuote quotes s for a POSIX shell, so the shell reads it as one word.
func shellQuote(s string) string {
	return "'" + strings.ReplaceAll(s, "'", `'\''`) + "'"
}

// Interactive is a script that says interactive Codex sessions are not
// supported yet. A rule file with an interactive entry on a codex account does
// not load, so this only guards a run record from elsewhere.
func (Harness) Interactive(program string, spec harness.Spec) string {
	return "#!/bin/sh\nrm -f -- \"$0\"\necho " + shellQuote("Interactive Codex sessions are not supported yet.") + " >&2\nexit 1\n"
}

// Output reads no result line, because Codex prints its result in a file. The
// bridge reads a run through ReadRun.
func (Harness) Output([]byte) harness.Output { return harness.Output{} }

// homeDir is the Codex home folder.
func (h Harness) homeDir() (string, error) {
	if h.home != "" {
		return h.home, nil
	}
	if dir := os.Getenv("CODEX_HOME"); dir != "" {
		return dir, nil
	}
	home, err := os.UserHomeDir()
	if err != nil {
		return "", err
	}

	return filepath.Join(home, ".codex"), nil
}
