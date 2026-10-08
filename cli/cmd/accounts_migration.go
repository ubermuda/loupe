package cmd

import (
	"log/slog"

	"github.com/ubermuda/loupe/cli/internal/rules"
)

// accountsMigration is the outcome of the accounts migration of one start. It
// runs before the rule file loads.
type accountsMigration struct {
	changed bool
	block   string
	err     error
}

// migrateAccounts gives a rule file with no accounts block its account. A
// failure leaves the file as it was, and the bridge still starts.
func migrateAccounts(path string) accountsMigration {
	changed, block, err := rules.MigrateAccounts(path)

	return accountsMigration{changed: changed, block: block, err: err}
}

// notable reports whether the migration has an outcome to log.
func (m accountsMigration) notable() bool {
	return m.changed || m.err != nil
}

func (m accountsMigration) log(log *slog.Logger, path string) {
	switch {
	case m.err != nil:
		log.Warn("accounts_migration_failed", "rules", path, "error", m.err.Error(), "block", m.block)
	case m.changed:
		log.Info("accounts_migration_done", "rules", path)
	}
}

// warnAgentsOff names why a set runs no agent.
func warnAgentsOff(log *slog.Logger, set *rules.Set) {
	if reason := set.AgentsOff(); reason != "" {
		log.Warn("agents_off", "reason", reason,
			"message", "The bridge runs no worker, interactive session or app prompt until rules.yaml has an accounts block. Command entries still run.")
	}
}
