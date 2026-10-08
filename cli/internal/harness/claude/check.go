package claude

import (
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"github.com/ubermuda/loupe/cli/internal/envfile"
	"maps"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"github.com/ubermuda/loupe/cli/internal/claudecode"
	"github.com/ubermuda/loupe/cli/internal/harness"
	"github.com/ubermuda/loupe/cli/internal/mcpjson"
)

// checkTimeout bounds each claude command of a check.
const checkTimeout = 10 * time.Second

// skillsGlob matches the Loupe skills in a skills folder.
const skillsGlob = "loupe-*"

// plugin is the part of a `claude plugin list --json` row that a check reads.
type plugin struct {
	ID         string                     `json:"id"`
	Enabled    bool                       `json:"enabled"`
	McpServers map[string]json.RawMessage `json:"mcpServers"`
}

// Check confirms that claude is on PATH, that the account is logged in, and
// that each project sees the loupe MCP server and the Loupe skills. It reads
// no field of auth status, because those name the person.
func (h Harness) Check(ctx context.Context, spec harness.CheckSpec) []harness.Problem {
	env := envfile.Overlay(os.Environ(), spec.Env)
	slugs := slices.Sorted(maps.Keys(spec.Projects))
	// A worker starts in its project folder, which a relative PATH entry
	// counts from, so each project finds its own claude.
	binaries, loginDir := map[string]string{}, ""
	var lookErr error
	for _, slug := range slugs {
		binary, err := envfile.LookPath(h.Program(), env, spec.Projects[slug])
		if err != nil {
			lookErr = err

			continue
		}
		if len(binaries) == 0 {
			loginDir = spec.Projects[slug]
		}
		binaries[slug] = binary
	}
	if len(slugs) == 0 {
		binary, err := envfile.LookPath(h.Program(), env, "")
		if err != nil {
			return []harness.Problem{{Reason: "claude is not on PATH", Detail: err.Error()}}
		}
		binaries[""] = binary
	}
	if len(binaries) == 0 {
		return []harness.Problem{{Reason: "claude is not on PATH", Detail: lookErr.Error()}}
	}
	var problems []harness.Problem
	if _, err := runCheck(ctx, binaries[firstKey(binaries, slugs)], loginDir, env, "auth", "status"); err != nil {
		problems = append(problems, loginProblem(err, spec.ConfigDir))
	}
	skillsHome := userSkills(spec.ConfigDir)
	for _, slug := range slugs {
		binary, ok := binaries[slug]
		if !ok {
			problems = append(problems, harness.Problem{Reason: "claude is not on PATH for project " + slug, Detail: "no claude on the PATH of the account from " + spec.Projects[slug]})

			continue
		}
		problems = append(problems, projectProblems(ctx, binary, env, spec.ConfigDir, skillsHome, slug, spec.Projects[slug])...)
	}

	return problems
}

// firstKey is the first slug, in order, that has a binary.
func firstKey(binaries map[string]string, slugs []string) string {
	for _, slug := range slugs {
		if _, ok := binaries[slug]; ok {
			return slug
		}
	}

	return ""
}

// projectProblems checks that the project sees the loupe MCP server and the
// Loupe skills. A project whose own files answer both runs no plugin list. A
// plugin list that fails is one problem, because it answers neither question.
func projectProblems(ctx context.Context, binary string, env []string, configDir, skillsHome, slug, dir string) []harness.Problem {
	got, mcpErr := claudecode.Effective(dir, mcpjson.ServerKey, configDir)
	skills := hasSkills(filepath.Join(dir, ".claude", "skills")) || hasSkills(skillsHome)
	var problems []harness.Problem
	// A declared entry wins over a plugin, so a wrong one fails whatever the
	// plugins serve.
	if got.Declared() && !got.Correct() {
		problems = append(problems, harness.Problem{Reason: "loupe MCP server is not loupe mcp for project " + slug, Detail: got.Where() + " starts " + got.Entry.Summary() + "; run `loupe init --mcp` in " + dir})
	}
	if got.Declared() && skills {
		return problems
	}
	plugins, err := listPlugins(ctx, binary, dir, env)
	if err != nil {
		reason := "plugin list failed"
		if errors.Is(err, context.DeadlineExceeded) {
			reason = "check timed out"
		}

		return append(problems, harness.Problem{Reason: reason + " for project " + slug, Detail: "claude plugin list --json in " + dir + ": " + err.Error()})
	}
	if !got.Declared() && !slices.ContainsFunc(plugins, servesLoupe) {
		detail := "declare it with `loupe init --mcp` in " + dir
		if mcpErr != nil {
			detail = mcpErr.Error()
		}
		problems = append(problems, harness.Problem{Reason: "loupe MCP server not declared for project " + slug, Detail: detail})
	}
	if !skills && !slices.ContainsFunc(plugins, isLoupePlugin) {
		problems = append(problems, harness.Problem{Reason: "Loupe skills not found for project " + slug, Detail: "no " + skillsGlob + " in " + filepath.Join(dir, ".claude", "skills") + " or " + skillsHome + ", and no enabled loupe plugin"})
	}

	return problems
}

// loginProblem tells a login check that ran out of time from a logged-out
// account, because a person fixes the two in different ways.
func loginProblem(err error, configDir string) harness.Problem {
	if errors.Is(err, context.DeadlineExceeded) {
		return harness.Problem{Reason: "login check timed out", Detail: "claude auth status did not answer in " + checkTimeout.String()}
	}

	return harness.Problem{Reason: "not logged in", Detail: loginDetail(configDir)}
}

// runCheck runs one claude command with a timeout, and gives its stdout.
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

// listPlugins gives the plugins claude lists in dir.
func listPlugins(ctx context.Context, binary, dir string, env []string) ([]plugin, error) {
	out, err := runCheck(ctx, binary, dir, env, "plugin", "list", "--json")
	if err != nil {
		return nil, err
	}
	var plugins []plugin
	if err := json.Unmarshal(out, &plugins); err != nil {
		return nil, err
	}

	return plugins, nil
}

func servesLoupe(p plugin) bool {
	_, ok := p.McpServers[mcpjson.ServerKey]

	return p.Enabled && ok
}

func isLoupePlugin(p plugin) bool {
	return p.Enabled && strings.HasPrefix(p.ID, "loupe@")
}

// hasSkills reports whether dir holds a Loupe skill that claude can read.
func hasSkills(dir string) bool {
	found, _ := filepath.Glob(filepath.Join(dir, skillsGlob, "SKILL.md"))
	for _, path := range found {
		if f, err := os.Open(path); err == nil {
			_ = f.Close()

			return true
		}
	}

	return false
}

// userSkills is the skills folder of the config folder claude reads.
func userSkills(configDir string) string {
	if dir := cmp.Or(configDir, strings.TrimSpace(os.Getenv("CLAUDE_CONFIG_DIR"))); dir != "" {
		return filepath.Join(dir, "skills")
	}
	home, _ := os.UserHomeDir()

	return filepath.Join(home, ".claude", "skills")
}

func loginDetail(configDir string) string {
	if configDir == "" {
		return "run `claude auth login`"
	}

	return "run `CLAUDE_CONFIG_DIR=" + shellQuote(configDir) + " claude auth login`"
}
