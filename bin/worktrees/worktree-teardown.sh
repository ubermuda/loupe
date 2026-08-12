#!/usr/bin/env bash
#
# Remove a worktree under .claude/worktrees/<name> along with everything
# bootstrap created for it: the nginx sidecar (and its Traefik route), the
# per-worktree dev database, the test database, and the generated nginx config.
#
# Usage: worktree-teardown.sh <name>
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

# Drop both databases if the stack is up (best-effort).
if docker compose ps --status running --services 2>/dev/null | grep -qx database; then
    docker compose exec -T database dropdb -U "${POSTGRES_USER:-app}" --if-exists "$dev_db" || true
    docker compose exec -T database dropdb -U "${POSTGRES_USER:-app}" --if-exists "$test_db" || true
fi

if [ -d "$root" ]; then
    git worktree remove "$root" --force
    echo "Removed worktree '$name', its sidecar, and databases $dev_db + $test_db."
else
    echo "No worktree at $root; cleaned up sidecar, databases and generated config if present." >&2
fi
