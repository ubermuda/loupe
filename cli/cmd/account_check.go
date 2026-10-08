package cmd

import (
	"cmp"
	"context"
	"log/slog"
	"strings"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	harn "github.com/ubermuda/loupe/cli/internal/harness"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// accountCheckTimeout bounds the checks of all the accounts of a set.
const accountCheckTimeout = 60 * time.Second

// maxAccountReason and maxAccountRows are the limits of the server. A row
// past either fails the whole heartbeat.
const (
	maxAccountReason = 200
	maxAccountRows   = 50
)

// accountResult is the check of one account, which is ready when it has no
// problem.
type accountResult struct {
	name, harness string
	problems      []harn.Problem
}

// reason joins the reasons of the problems, and is "" for a ready account.
func (a accountResult) reason() string {
	return a.join(func(p harn.Problem) string { return p.Reason })
}

func (a accountResult) detail() string {
	return a.join(func(p harn.Problem) string { return p.Detail })
}

func (a accountResult) join(field func(harn.Problem) string) string {
	parts := make([]string, 0, len(a.problems))
	for _, p := range a.problems {
		parts = append(parts, field(p))
	}

	return strings.Join(parts, "; ")
}

// checkAccounts checks each account that the set uses with its harness, in
// every project of the set, with the environment a worker of the account gets.
func checkAccounts(ctx context.Context, set *rules.Set) []accountResult {
	ctx, cancel := context.WithTimeout(ctx, accountCheckTimeout)
	defer cancel()
	projects := map[string]string{}
	for _, slug := range set.Projects() {
		projects[slug] = set.Dir(slug)
	}
	var out []accountResult
	for _, name := range set.UsedAccounts() {
		run, _ := set.Account(name, "")
		res := accountResult{name: name, harness: harnessNameOf(run)}
		h, err := harnessByName(run.Harness, run.ConfigDir)
		if err != nil {
			res.problems = []harn.Problem{{Reason: "unknown harness", Detail: err.Error()}}
			out = append(out, res)

			continue
		}
		env, err := workerSpec{envFiles: run.EnvFiles, configDir: run.ConfigDir}.accountEnv()
		if err != nil {
			res.problems = []harn.Problem{{Reason: "env file does not read", Detail: err.Error()}}
		} else {
			res.problems = h.Check(ctx, harn.CheckSpec{Account: name, ConfigDir: run.ConfigDir, Env: env, Projects: projects})
		}
		out = append(out, res)
	}

	return out
}

// harnessNameOf names the harness of an account, and Claude Code when the
// account names none.
func harnessNameOf(run rules.RunSettings) string {
	return cmp.Or(run.Harness, defaultHarness().Name())
}

// accountsOff maps each failing account to its reason.
func accountsOff(results []accountResult) map[string]string {
	off := map[string]string{}
	for _, a := range results {
		if len(a.problems) > 0 {
			off[a.name] = a.reason()
		}
	}

	return off
}

// warnAccountsOff logs each failing account.
func warnAccountsOff(log *slog.Logger, results []accountResult) {
	for _, a := range results {
		if len(a.problems) > 0 {
			log.Warn("account_failed", "account", a.name, "harness", a.harness, "reason", a.reason(), "detail", a.detail())
		}
	}
}

// accountReports gives a row for each account the set uses, and nil when no
// check ran on the set.
func accountReports(set *rules.Set) []api.AccountReport {
	off := set.AccountsOff()
	if off == nil {
		return nil
	}
	reports := []api.AccountReport{}
	for _, name := range set.UsedAccounts() {
		run, _ := set.Account(name, "")
		row := api.AccountReport{Name: name, Harness: harnessNameOf(run), State: api.AccountReady}
		if reason := off[name]; reason != "" {
			row.State, row.Reason = api.AccountFailing, cutReason(reason)
		}
		reports = append(reports, row)
		if len(reports) == maxAccountRows {
			break
		}
	}

	return reports
}

// cutReason keeps a reason within maxAccountReason characters.
func cutReason(reason string) string {
	runes := []rune(reason)
	if len(runes) <= maxAccountReason {
		return reason
	}

	return string(runes[:maxAccountReason-1]) + "…"
}
