package cmd

import (
	"fmt"

	"github.com/ubermuda/loupe/cli/internal/envfile"
	harn "github.com/ubermuda/loupe/cli/internal/harness"
	"github.com/ubermuda/loupe/cli/internal/harness/claude"
)

// harnessByName is the harness a name gives, reading the sessions in
// configDir. "" is Claude Code, so a run record of an older image names it.
func harnessByName(name, configDir string) (harn.Harness, error) {
	switch name {
	case "", "claude-code":
		return claude.New(configDir), nil
	}

	return nil, fmt.Errorf("unknown harness %q", name)
}

func defaultHarness() harn.Harness {
	return claude.New("")
}

// harnessOf is the harness of a name and a config folder, and the default one
// when this image does not know the name.
func harnessOf(name, configDir string) harn.Harness {
	h, err := harnessByName(name, configDir)
	if err != nil {
		return defaultHarness()
	}

	return h
}

// recordHarness is the harness of a run record.
func recordHarness(rec runRecord) harn.Harness {
	return harnessOf(rec.Harness, rec.ConfigDir)
}

// adapter is the harness of the spec's account.
func (s workerSpec) adapter() harn.Harness {
	return harnessOf(s.harnessName, s.configDir)
}

func (s workerSpec) harnessSpec(env []string) harn.Spec {
	return harn.Spec{
		Dir: s.dir, Model: s.model, PermissionMode: s.permissionMode, Schema: s.schema,
		SessionID: s.sessionID, Prompt: s.prompt, Env: env,
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

// accountEnv is what the run's account adds to the environment: the env
// files in order, then CLAUDE_CONFIG_DIR when the account has a config folder.
func (s workerSpec) accountEnv() ([]string, error) {
	env, err := envfile.ReadAll(s.envFiles)
	if err != nil {
		return nil, err
	}
	if s.configDir != "" {
		env = envfile.Overlay(env, []string{"CLAUDE_CONFIG_DIR=" + s.configDir})
	}

	return env, nil
}
