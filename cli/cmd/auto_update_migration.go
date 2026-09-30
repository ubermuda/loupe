package cmd

import (
	"log/slog"

	"github.com/ubermuda/loupe/cli/internal/rules"
	"github.com/ubermuda/loupe/cli/internal/update"
)

// migrateAutoUpdate runs at each start until the config dir holds the
// defaultOff marker. An older image took a missing autoUpdate key as on, so
// after a handover from one it writes autoUpdate: true to the rule file. It
// reports whether this process takes a missing key as on.
func migrateAutoUpdate(log *slog.Logger, dir, rulesPath string, handover bool) bool {
	st, err := update.LoadState(dir)
	if err != nil {
		log.Warn("auto_update_migration_failed", "rules", rulesPath, "error", err.Error())

		return handover
	}
	if st.DefaultOff {
		return false
	}
	assume := false
	if handover {
		kept, err := rules.SetAutoUpdate(rulesPath, true)
		if err != nil {
			// The next handover tries again, so the marker stays unset.
			log.Warn("auto_update_migration_failed", "rules", rulesPath, "error", err.Error(), "line", rules.AutoUpdateLine(true))

			return true
		}
		if kept == nil {
			log.Info("auto_update_migrated", "rules", rulesPath)
			assume = true
		}
	}
	st.DefaultOff = true
	if err := st.Save(dir); err != nil {
		log.Warn("auto_update_migration_failed", "rules", rulesPath, "error", err.Error())
	}

	return assume
}

// autoUpdateOn reads the key of the set. With assume, a missing key is on.
func autoUpdateOn(set *rules.Set, assume bool) bool {
	return set.AutoUpdate() || (assume && !set.AutoUpdateSet())
}
