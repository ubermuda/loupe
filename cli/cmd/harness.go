package cmd

import (
	"fmt"
	"slices"
	"strings"

	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/envfile"
	harn "github.com/ubermuda/loupe/cli/internal/harness"
	"github.com/ubermuda/loupe/cli/internal/harness/claude"
	"github.com/ubermuda/loupe/cli/internal/harness/codex"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// harnessByName is the harness a name gives, reading the sessions in
// configDir, which is the Codex home folder for Codex. profile is the Codex
// profile. "" is Claude Code, so a run record of an older image names it.
func harnessByName(name, configDir, profile string) (harn.Harness, error) {
	switch name {
	case "", rules.HarnessClaudeCode:
		return claude.New(configDir), nil
	case rules.HarnessCodex:
		// A config folder the bridge cannot find leaves the map empty, so
		// no Codex session is known and a run starts a new one.
		threads, _ := config.CodexThreadsDir()

		return codex.New(configDir, profile, threads), nil
	}

	return nil, fmt.Errorf("unknown harness %q", name)
}

func defaultHarness() harn.Harness {
	return claude.New("")
}

// harnessOf is the harness of a name, a config folder and a profile, and the
// default one when this image does not know the name.
func harnessOf(name, configDir, profile string) harn.Harness {
	h, err := harnessByName(name, configDir, profile)
	if err != nil {
		return defaultHarness()
	}

	return h
}

// recordHarness is the harness of a run record.
func recordHarness(rec runRecord) harn.Harness {
	return harnessOf(rec.Harness, rec.ConfigDir, rec.Profile)
}

// adapter is the harness of the spec's account.
func (s workerSpec) adapter() harn.Harness {
	return harnessOf(s.harnessName, s.configDir, s.profile)
}

func (s workerSpec) harnessSpec(env []string) harn.Spec {
	return harn.Spec{
		Dir: s.dir, Model: s.model, Effort: s.effort, PermissionMode: s.permissionMode, Schema: s.schema,
		SessionID: s.sessionID, Prompt: s.prompt, Env: env, RunDir: s.runDir,
	}
}

// harnessCommand is the command of the spec's worker, a new session or a
// resume.
func (s workerSpec) harnessCommand(env []string) harn.Command {
	if s.resume {
		return s.adapter().Resume(s.harnessSpec(env))
	}

	return s.adapter().Worker(s.harnessSpec(env))
}

// configDirEnv names the variable that holds the config folder of a harness:
// CLAUDE_CONFIG_DIR for Claude Code and CODEX_HOME for Codex. Only an account's
// configDir or codexHome sets it, so the bridge reads the sessions where the
// harness writes them.
func configDirEnv(harnessName string) string {
	if harnessName == rules.HarnessCodex {
		return "CODEX_HOME"
	}

	return "CLAUDE_CONFIG_DIR"
}

// configDirKey is the account key that sets the config folder.
func configDirKey(harnessName string) string {
	if harnessName == rules.HarnessCodex {
		return "codexHome"
	}

	return "configDir"
}

// accountEnv is what the run's account adds to the environment: the env
// files in order, then the config folder variable of the harness when the
// account has a config folder.
func (s workerSpec) accountEnv() ([]string, error) {
	configEnv := configDirEnv(s.harnessName)
	var env []string
	for _, path := range s.envFiles {
		pairs, err := envfile.Read(path)
		if err != nil {
			return nil, err
		}
		if slices.ContainsFunc(pairs, func(p string) bool { return strings.HasPrefix(p, configEnv+"=") }) {
			return nil, fmt.Errorf("%s: %s is not allowed in an env file; set the %s of the account", path, configEnv, configDirKey(s.harnessName))
		}
		env = envfile.Overlay(env, pairs)
	}
	if s.configDir != "" {
		env = envfile.Overlay(env, []string{configEnv + "=" + s.configDir})
	}

	return env, nil
}
