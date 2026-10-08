package codex

import (
	"context"
	"errors"
	"fmt"
	"maps"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"time"

	"github.com/ubermuda/loupe/cli/internal/envfile"
	"github.com/ubermuda/loupe/cli/internal/harness"
)

// checkTimeout bounds the login command of a check.
const checkTimeout = 10 * time.Second

var (
	providerPattern = regexp.MustCompile(`(?m)^[ \t]*model_provider[ \t]*=[ \t]*"([^"]*)"`)
	envKeyPattern   = regexp.MustCompile(`(?m)^[ \t]*env_key[ \t]*=[ \t]*"([^"]*)"`)
)

// profileFile is the file of the profile in the home folder.
func profileFile(home, profile string) string {
	return filepath.Join(home, profile+".config.toml")
}

// topLevel is the part of a TOML file before its first table.
func topLevel(text string) string {
	offset := 0
	for line := range strings.SplitSeq(text, "\n") {
		if strings.HasPrefix(strings.TrimSpace(line), "[") {
			return text[:offset]
		}
		offset += len(line) + 1
	}

	return text
}

// configFiles are the files that set a profile, the profile first.
func configFiles(home, profile string) []string {
	return []string{profileFile(home, profile), filepath.Join(home, "config.toml")}
}

// configProvider is the model_provider a profile names, which falls back to the
// one of the base config.toml. It is "" when neither names one.
func configProvider(home, profile string) string {
	for _, path := range configFiles(home, profile) {
		b, err := os.ReadFile(path)
		if err != nil {
			continue
		}
		if m := providerPattern.FindStringSubmatch(topLevel(string(b))); m != nil {
			return m[1]
		}
	}

	return ""
}

// configEnvKey is the name of the variable that holds the API key of the
// profile's provider, and "" when the files name none.
func configEnvKey(home, profile string) string {
	for _, path := range configFiles(home, profile) {
		b, err := os.ReadFile(path)
		if err != nil {
			continue
		}
		if m := envKeyPattern.FindStringSubmatch(string(b)); m != nil && m[1] != "" {
			return m[1]
		}
	}

	return ""
}

// providerProblem says why a run did not run as its profile configures, or ""
// when it did or the bridge cannot tell.
func (h Harness) providerProblem(sess session, haveSession bool) string {
	if h.profile == "" {
		return ""
	}
	home, err := h.homeDir()
	if err != nil {
		return ""
	}
	name := h.profile + ".config.toml"
	if _, err := os.Stat(profileFile(home, h.profile)); errors.Is(err, os.ErrNotExist) {
		return "codex profile file " + name + " is missing"
	} else if err != nil {
		return "codex profile file " + name + " cannot be read"
	}
	want := configProvider(home, h.profile)
	if want == "" || !haveSession || sess.provider == "" || sess.provider == want {
		return ""
	}

	return fmt.Sprintf("codex ran on provider %s but profile %s names %s", sess.provider, h.profile, want)
}

// Check confirms that codex is on PATH, that the home folder and the profile
// exist, and that the account can sign in: the profile's API key variable is
// set, or codex is logged in when there is no profile. It reads no MCP config.
func (h Harness) Check(ctx context.Context, spec harness.CheckSpec) []harness.Problem {
	env := envfile.Overlay(os.Environ(), spec.Env)
	slugs := slices.Sorted(maps.Keys(spec.Projects))
	if len(slugs) == 0 {
		slugs = []string{""}
	}

	var problems []harness.Problem
	var binary, loginDir string
	for _, slug := range slugs {
		found, err := envfile.LookPath(h.Program(), env, spec.Projects[slug])
		if err != nil {
			reason := "codex is not on PATH"
			if slug != "" {
				reason += " for project " + slug
			}
			problems = append(problems, harness.Problem{Reason: reason, Detail: err.Error()})

			continue
		}
		if binary == "" {
			binary, loginDir = found, spec.Projects[slug]
		}
	}
	if binary == "" {
		return problems
	}

	home := strings.TrimSpace(envValue(env, "CODEX_HOME"))
	if spec.ConfigDir != "" {
		home = spec.ConfigDir
		if info, err := os.Stat(home); err != nil || !info.IsDir() {
			return append(problems, harness.Problem{Reason: "codex home not found", Detail: home + " is not a folder"})
		}
	}
	if home == "" {
		userHome, _ := os.UserHomeDir()
		home = filepath.Join(userHome, ".codex")
	}

	if h.profile != "" {
		return append(problems, profileProblems(home, h.profile, env)...)
	}
	if _, err := runCheck(ctx, binary, loginDir, env, "login", "status"); err != nil {
		if errors.Is(err, context.DeadlineExceeded) {
			return append(problems, harness.Problem{Reason: "login check timed out", Detail: "codex login status did not answer in " + checkTimeout.String()})
		}

		return append(problems, harness.Problem{Reason: "not logged in", Detail: "run `codex login`"})
	}

	return problems
}

// profileProblems checks the profile file and its API key variable.
func profileProblems(home, profile string, env []string) []harness.Problem {
	path := profileFile(home, profile)
	if _, err := os.Stat(path); err != nil {
		return []harness.Problem{{Reason: "codex profile not found", Detail: path + ": " + err.Error()}}
	}
	if key := configEnvKey(home, profile); key != "" && strings.TrimSpace(envValue(env, key)) == "" {
		return []harness.Problem{{Reason: "codex API key variable " + key + " is not set", Detail: "profile " + profile + " reads its key from " + key + "; set it in the envFile of the account"}}
	}

	return nil
}

// runCheck runs one codex command with a timeout.
func runCheck(ctx context.Context, binary, dir string, env []string, args ...string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(ctx, checkTimeout)
	defer cancel()
	cmd := exec.CommandContext(ctx, binary, args...)
	cmd.Dir, cmd.Env, cmd.WaitDelay = dir, env, time.Second

	out, err := cmd.Output()
	if err != nil && ctx.Err() != nil {
		err = errors.Join(err, ctx.Err())
	}

	return out, err
}

// envValue is the last value of key in env, and "" when env sets none.
func envValue(env []string, key string) string {
	value := ""
	for _, kv := range env {
		if v, ok := strings.CutPrefix(kv, key+"="); ok {
			value = v
		}
	}

	return value
}
