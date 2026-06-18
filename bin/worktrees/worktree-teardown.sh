#!/usr/bin/env bash
#
# Remove a worktree under .claude/worktrees/<name> and drop its per-worktree
# test database from the shared Postgres. Usage: worktree-teardown.sh <name>
#
set -euo pipefail

name=${1:?usage: worktree-teardown.sh <name>}
main=$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')
root="$main/.claude/worktrees/$name"
db="app_test_$(printf '%s' "$name" | tr -c 'a-zA-Z0-9' '_')"

# Drop the per-worktree test DB if the stack is up (best-effort).
if docker compose ps --status running --services 2>/dev/null | grep -qx database; then
    docker compose exec -T database dropdb -U "${POSTGRES_USER:-app}" --if-exists "$db" || true
fi

if [ -d "$root" ]; then
    git worktree remove "$root" --force
    echo "Removed worktree '$name' and dropped test DB '$db'."
else
    echo "No worktree at $root; dropped test DB '$db' if it existed." >&2
fi
