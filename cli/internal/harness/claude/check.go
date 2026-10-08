package claude

import (
	"cmp"
	"context"
	"encoding/json"
	"errors"
	"maps"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"sync"
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
	binary, err := exec.LookPath(h.Program())
	if err != nil {
		return []harness.Problem{{Reason: "claude is not on PATH", Detail: err.Error()}}
	}
	env := append(os.Environ(), spec.Env...)
	var problems []harness.Problem
	if _, err := runCheck(ctx, binary, "", env, "auth", "status"); err != nil {
		problems = append(problems, loginProblem(err, spec.ConfigDir))
	}
	skillsHome := userSkills(spec.ConfigDir)
	for _, slug := range slices.Sorted(maps.Keys(spec.Projects)) {
		dir := spec.Projects[slug]
		// A project whose own files answer both questions runs no plugin list.
		plugins := sync.OnceValue(func() []plugin { return listPlugins(ctx, binary, dir, env) })
		got, err := claudecode.Effective(dir, mcpjson.ServerKey, spec.ConfigDir)
		if !got.Declared() && !slices.ContainsFunc(plugins(), servesLoupe) {
			detail := "declare it with `loupe init --mcp` in " + dir
			if err != nil {
				detail = err.Error()
			}
			problems = append(problems, harness.Problem{Reason: "loupe MCP server not declared for project " + slug, Detail: detail})
		}
		if !hasSkills(filepath.Join(dir, ".claude", "skills")) && !hasSkills(skillsHome) && !slices.ContainsFunc(plugins(), isLoupePlugin) {
			problems = append(problems, harness.Problem{Reason: "Loupe skills not found for project " + slug, Detail: "no " + skillsGlob + " in " + filepath.Join(dir, ".claude", "skills") + " or " + skillsHome + ", and no enabled loupe plugin"})
		}
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

// listPlugins gives the plugins claude lists in dir, and nil when the list
// fails or does not decode.
func listPlugins(ctx context.Context, binary, dir string, env []string) []plugin {
	out, err := runCheck(ctx, binary, dir, env, "plugin", "list", "--json")
	if err != nil {
		return nil
	}
	var plugins []plugin
	if json.Unmarshal(out, &plugins) != nil {
		return nil
	}

	return plugins
}

func servesLoupe(p plugin) bool {
	_, ok := p.McpServers[mcpjson.ServerKey]

	return p.Enabled && ok
}

func isLoupePlugin(p plugin) bool {
	return p.Enabled && strings.HasPrefix(p.ID, "loupe@")
}

func hasSkills(dir string) bool {
	found, _ := filepath.Glob(filepath.Join(dir, skillsGlob))

	return len(found) > 0
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
