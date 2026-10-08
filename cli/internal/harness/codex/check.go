package codex

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"maps"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"github.com/BurntSushi/toml"
	"github.com/ubermuda/loupe/cli/internal/envfile"
	"github.com/ubermuda/loupe/cli/internal/harness"
)

// checkTimeout bounds each codex command of a check.
var checkTimeout = 10 * time.Second

// profileFile is the file of the profile in the home folder.
func profileFile(home, profile string) string {
	return filepath.Join(home, profile+".config.toml")
}

// tomlConfig is the part of a Codex config file that the bridge reads.
type tomlConfig struct {
	ModelProvider  string                  `toml:"model_provider"`
	ModelProviders map[string]providerConf `toml:"model_providers"`
	McpServers     map[string]serverConf   `toml:"mcp_servers"`
}

type serverConf struct {
	DefaultToolsApprovalMode string `toml:"default_tools_approval_mode"`
}

type providerConf struct {
	EnvKey string `toml:"env_key"`
}

// configFile is a parsed config file: the profile file first, then config.toml.
type configFile struct {
	path string
	cfg  tomlConfig
	err  error
}

// loadConfigs parses the profile file and the base config.toml. A missing file
// is an empty config, and a file that does not parse keeps its error.
func loadConfigs(home, profile string) []configFile {
	return loadFiles(profileFile(home, profile), filepath.Join(home, "config.toml"))
}

// loadFiles parses the files in order.
func loadFiles(paths ...string) []configFile {
	files := make([]configFile, len(paths))
	for i, path := range paths {
		files[i].path = path
		_, err := toml.DecodeFile(path, &files[i].cfg)
		if err != nil && !errors.Is(err, os.ErrNotExist) {
			files[i].err = err
		}
	}

	return files
}

// provider is the model_provider the first file that names one sets.
func provider(files []configFile) string {
	for _, f := range files {
		if f.err == nil && f.cfg.ModelProvider != "" {
			return f.cfg.ModelProvider
		}
	}

	return ""
}

// configProvider is the model_provider a profile names, which falls back to the
// one of the base config.toml. It is "" when neither names one, and when a file
// does not parse, because the bridge then has no expectation.
func configProvider(home, profile string) string {
	files := loadConfigs(home, profile)
	for _, f := range files {
		if f.err != nil {
			return ""
		}
	}

	return provider(files)
}

// configEnvKey is the name of the variable that holds the API key of the
// provider the profile selects. It reads env_key from that provider's table in
// the profile file, then in config.toml, and is "" when neither names one.
func configEnvKey(home, profile string) string {
	files := loadConfigs(home, profile)
	name := provider(files)
	for _, f := range files {
		if key := f.cfg.ModelProviders[name].EnvKey; f.err == nil && name != "" && key != "" {
			return key
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
// set, or codex is logged in when there is no profile. Then, for each project,
// it checks that the loupe MCP server is the `loupe mcp` command, is enabled,
// is found on PATH, and approves its tools without a prompt.
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
		if found := profileProblems(home, h.profile, env); len(found) > 0 {
			return append(problems, found...)
		}
	} else if _, err := runCheck(ctx, binary, loginDir, env, "login", "status"); err != nil {
		if errors.Is(err, context.DeadlineExceeded) {
			return append(problems, harness.Problem{Reason: "login check timed out", Detail: "codex login status did not answer in " + checkTimeout.String()})
		}

		return append(problems, harness.Problem{Reason: "not logged in", Detail: "run `codex login`"})
	}

	mcpEnv := envfile.Overlay(env, []string{"CODEX_HOME=" + home})
	for _, slug := range slugs {
		if slug == "" {
			continue
		}
		if found, err := envfile.LookPath(h.Program(), env, spec.Projects[slug]); err == nil {
			problems = append(problems, h.mcpProblems(ctx, found, home, slug, spec.Projects[slug], mcpEnv)...)
		}
	}

	return problems
}

// mcpProblems checks the loupe MCP server of one project.
func (h Harness) mcpProblems(ctx context.Context, binary, home, slug, dir string, env []string) []harness.Problem {
	fix := "run CODEX_HOME=" + home + " codex mcp add loupe -- loupe mcp"
	fail := func(reason, detail string) []harness.Problem {
		return []harness.Problem{{Reason: reason + " for project " + slug, Detail: detail}}
	}

	args := []string{"mcp", "get", "loupe", "--json"}
	if h.profile != "" {
		args = append([]string{"-p", h.profile}, args...)
	}
	out, err := runCheck(ctx, binary, dir, env, args...)
	if errors.Is(err, context.DeadlineExceeded) {
		return fail("MCP check timed out", "codex mcp get did not answer in "+checkTimeout.String())
	}
	if err != nil {
		return fail("loupe MCP server not declared", fix)
	}
	var got struct {
		Enabled   bool `json:"enabled"`
		Transport struct {
			Type    string   `json:"type"`
			Command string   `json:"command"`
			Args    []string `json:"args"`
		} `json:"transport"`
	}
	if err := json.Unmarshal(out, &got); err != nil {
		return fail("MCP check could not read the loupe MCP server", "codex mcp get --json did not print JSON; "+fix)
	}
	tr := got.Transport
	if tr.Type != "stdio" || filepath.Base(tr.Command) != "loupe" || len(tr.Args) != 1 || tr.Args[0] != "mcp" {
		shown := "type " + tr.Type
		if tr.Type == "stdio" {
			shown = "command " + filepath.Base(tr.Command)
		}

		return fail("loupe MCP server is not loupe mcp", "the server runs "+shown+"; "+fix)
	}
	if !got.Enabled {
		return fail("loupe MCP server is disabled", "set enabled = true under [mcp_servers.loupe] in "+filepath.Join(home, "config.toml"))
	}
	if _, err := envfile.LookPath(tr.Command, env, dir); err != nil {
		return fail("loupe is not on PATH", err.Error())
	}

	return h.approvalProblems(home, slug, dir)
}

// approvalProblems checks that the loupe MCP server approves its tools. The
// first file that sets the mode wins: the project, the profile, then config.toml.
func (h Harness) approvalProblems(home, slug, dir string) []harness.Problem {
	paths := []string{filepath.Join(dir, ".codex", "config.toml")}
	target := filepath.Join(home, "config.toml")
	if h.profile != "" {
		target = profileFile(home, h.profile)
		paths = append(paths, target)
	}
	paths = append(paths, filepath.Join(home, "config.toml"))
	for _, f := range loadFiles(paths...) {
		if f.err != nil {
			return []harness.Problem{{Reason: "codex config does not parse for project " + slug, Detail: f.path + ": " + f.err.Error()}}
		}
		switch f.cfg.McpServers["loupe"].DefaultToolsApprovalMode {
		case "":
		case "approve":
			return nil
		default:
			return []harness.Problem{approvalProblem(slug, f.path)}
		}
	}

	return []harness.Problem{approvalProblem(slug, target)}
}

func approvalProblem(slug, file string) harness.Problem {
	return harness.Problem{
		Reason: "loupe MCP tools need approval for project " + slug,
		Detail: `add default_tools_approval_mode = "approve" under [mcp_servers.loupe] in ` + file,
	}
}

// profileProblems checks the profile file and its API key variable.
func profileProblems(home, profile string, env []string) []harness.Problem {
	path := profileFile(home, profile)
	if _, err := os.Stat(path); err != nil {
		return []harness.Problem{{Reason: "codex profile not found", Detail: path + ": " + err.Error()}}
	}
	files := loadConfigs(home, profile)
	for i, f := range files {
		if f.err != nil {
			reason := "codex profile does not parse"
			if i == 1 {
				reason = "codex config.toml does not parse"
			}

			return []harness.Problem{{Reason: reason, Detail: f.path + ": " + f.err.Error()}}
		}
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
