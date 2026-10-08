package cmd

import (
	"fmt"

	harn "github.com/ubermuda/loupe/cli/internal/harness"
	"github.com/ubermuda/loupe/cli/internal/harness/claude"
)

// harnessByName is the harness a name gives. "" is Claude Code, so a run
// record of an older image names it.
func harnessByName(name string) (harn.Harness, error) {
	switch name {
	case "", "claude-code":
		return claude.New(), nil
	}

	return nil, fmt.Errorf("unknown harness %q", name)
}

func defaultHarness() harn.Harness {
	return claude.New()
}

// recordHarness is the harness of a run record, and the default one when the
// record names one this image does not know.
func recordHarness(rec runRecord) harn.Harness {
	h, err := harnessByName(rec.Harness)
	if err != nil {
		return defaultHarness()
	}

	return h
}

// adapter is the harness of the spec, and the default one when it has none.
func (s workerSpec) adapter() harn.Harness {
	if s.harness == nil {
		return defaultHarness()
	}

	return s.harness
}

func (s workerSpec) harnessSpec(env []string) harn.Spec {
	return harn.Spec{
		Dir: s.dir, Model: s.model, Effort: s.effort, PermissionMode: s.permissionMode, Schema: s.schema,
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
