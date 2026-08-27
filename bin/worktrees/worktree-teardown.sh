#!/usr/bin/env bash
#
# Remove a worktree under .claude/worktrees/<name> along with everything
# bootstrap created for it: the nginx sidecar (and its Traefik route), the
# per-worktree dev database, the test database, and the generated nginx config.
#
# Usage: worktree-teardown.sh <name>
#
# WORKTREE_TEARDOWN_KEEP_TREE=1 stops before `git worktree remove`, for callers
# that remove the tree themselves (the WorktreeRemove hook).
#
set -euo pipefail

name=${1:?usage: worktree-teardown.sh <name>}
main=$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')
root="$main/.claude/worktrees/$name"

# Sourced relative to THIS script, so the helper always comes from the same
# checkout as the script running it (bootstrap runs from a worktree, teardown
# usually from main).
# shellcheck source=bin/worktrees/slug.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/slug.sh"

slug=$(worktree_slug "$name")
token=$(worktree_db_token "$slug")
dev_db="app_wt_$token"
test_db="app_test_$token"

project=$(grep -E '^COMPOSE_PROJECT_NAME=' "$main/.env" | head -1 | cut -d= -f2-)
project=${project:-loupe}

# Prefer the worktree's own definition; fall back to main's when the worktree
# directory is already gone (the usual case when pruning).
compose_file="$root/docker/compose/worktree.yaml"
[ -f "$compose_file" ] || compose_file="$main/docker/compose/worktree.yaml"

# Stop the sidecar first so its Traefik route disappears before the code does —
# otherwise the route lingers and serves 502s. The compose file interpolates
# these even for `down`, so they must be set.
WT_MAIN="$main" \
WT_ROOT="$root" \
WT_SLUG="$slug" \
WT_BASE_HOST="$project.dev.localhost" \
WT_APP_NETWORK="${project}_default" \
    docker compose -f "$compose_file" -p "${project}-wt-$slug" down >/dev/null 2>&1 || true

# Kill anything still running against this worktree in the shared php-fpm — a
# messenger consumer or a tailwind watcher left over from an interrupted run.
# Matched by working directory, because every worktree's console process has an
# identical command line inside one container.
if docker compose ps --status running --services 2>/dev/null | grep -qx php-fpm; then
    docker compose exec -T php-fpm sh -c '
        target=$1
        for proc in /proc/[0-9]*; do
            [ "$(readlink "$proc/cwd" 2>/dev/null)" = "$target" ] || continue
            tr "\0" " " < "$proc/cmdline" 2>/dev/null | grep -q "bin/console" || continue
            kill "${proc#/proc/}" 2>/dev/null || true
        done
    ' sh "/var/www/html/${root#"$main"/}" || true
fi

# Drop both databases if the stack is up (best-effort). --force terminates any
# session still attached; without it a surviving connection makes dropdb fail
# and leaves an orphaned database behind a worktree that is already gone.
if docker compose ps --status running --services 2>/dev/null | grep -qx database; then
    docker compose exec -T database dropdb -U "${POSTGRES_USER:-app}" --force --if-exists "$dev_db" || true
    docker compose exec -T database dropdb -U "${POSTGRES_USER:-app}" --force --if-exists "$test_db" || true
fi

if [ "${WORKTREE_TEARDOWN_KEEP_TREE:-}" = "1" ]; then
    echo "Removed sidecars and databases $dev_db + $test_db for '$name'; left the worktree in place."
elif [ -d "$root" ]; then
    git worktree remove "$root" --force
    echo "Removed worktree '$name', its sidecars, and databases $dev_db + $test_db."
else
    echo "No worktree at $root; cleaned up sidecars, databases and generated config if present." >&2
fi
