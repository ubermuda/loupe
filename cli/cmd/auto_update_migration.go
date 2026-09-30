package cmd

import (
	"log/slog"
	"path/filepath"
	"slices"

	"github.com/ubermuda/loupe/cli/internal/rules"
	"github.com/ubermuda/loupe/cli/internal/update"
)

// migrateAutoUpdate runs at each start until update.json holds the defaultOff
// marker of the rule file. An older image took a missing autoUpdate key as on,
// so after a handover from one it writes autoUpdate: true to the rule file. A
// write that the file refuses stays pending, and each later start tries again.
// It reports whether this process takes a missing key as on.
func migrateAutoUpdate(log *slog.Logger, dir, rulesPath string, handover bool) bool {
	key := ruleFileKey(rulesPath)
	st, err := update.LoadState(dir)
	if err != nil {
		log.Warn("auto_update_migration_failed", "rules", rulesPath, "error", err.Error())
		// With no state to hold a pending entry, the key in the rule file is the only record that survives a restart.
		if handover {
			if _, err := rules.SetAutoUpdate(rulesPath, true); err != nil {
				log.Warn("auto_update_migration_failed", "rules", rulesPath, "error", err.Error(), "line", rules.AutoUpdateLine(true))
			}
		}

		return handover
	}
	if slices.Contains(st.DefaultOff, key) {
		return false
	}
	assume := false
	if handover || slices.Contains(st.AutoUpdatePending, key) {
		kept, err := rules.SetAutoUpdate(rulesPath, true)
		if err != nil {
			log.Warn("auto_update_migration_failed", "rules", rulesPath, "error", err.Error(), "line", rules.AutoUpdateLine(true))
			if !slices.Contains(st.AutoUpdatePending, key) {
				st.AutoUpdatePending = append(st.AutoUpdatePending, key)
			}
			saveMigration(log, st, dir, rulesPath)

			return true
		}
		if kept == nil {
			log.Info("auto_update_migrated", "rules", rulesPath)
			assume = true
		}
	}
	st.AutoUpdatePending = slices.DeleteFunc(st.AutoUpdatePending, func(p string) bool { return p == key })
	st.DefaultOff = append(st.DefaultOff, key)
	saveMigration(log, st, dir, rulesPath)

	return assume
}

func saveMigration(log *slog.Logger, st *update.State, dir, rulesPath string) {
	if err := st.Save(dir); err != nil {
		log.Warn("auto_update_migration_failed", "rules", rulesPath, "error", err.Error())
	}
}

// ruleFileKey names a rule file in update.json: its absolute path with the
// symlinks resolved, or the absolute path when it does not resolve.
func ruleFileKey(rulesPath string) string {
	if resolved, err := filepath.EvalSymlinks(rulesPath); err == nil {
		rulesPath = resolved
	}

	return absOr(rulesPath)
}

// autoUpdateOn reads the key of the set. With assume, a missing key is on.
func autoUpdateOn(set *rules.Set, assume bool) bool {
	return set.AutoUpdate() || (assume && !set.AutoUpdateSet())
}
