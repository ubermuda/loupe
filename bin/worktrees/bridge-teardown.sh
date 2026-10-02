#!/usr/bin/env bash
# Bridge teardown command: remove the card's worktree, and an epic's preview, with sidecars and databases.
# Usage: bridge-teardown.sh <cardNumber>
set -euo pipefail

number=${1:-}
if ! [[ $number =~ ^[1-9][0-9]*$ ]]; then
    echo "bridge-teardown: the card number '$number' is not a positive integer." >&2
    exit 1
fi

main=$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')

# shellcheck source=bin/worktrees/slug.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/slug.sh"

# The teardown names its resources by slug, so a sibling with the same slug would lose them.
owned_once() {
    local owners
    owners=$(worktree_slug_index "$main" | awk -v s="$1" '$1 == s {n++; if ($2 != s) n += 1} END {print n + 0}')
    if [ "$owners" -gt 1 ]; then
        echo "bridge-teardown: another worktree owns the slug $1. Remove it by hand." >&2
        exit 1
    fi
}

owned_once "card-$number"
owned_once "epic-$number"

cd "$main"
just worktree-down "card-$number"

# The merge stage refreshes the epic preview under this lock, and the last child merge can finish the epic.
lock="$main/.worktrees/epic-$number.lock"
locked=0
for _ in $(seq 60); do
    if mkdir "$lock" 2>/dev/null; then
        locked=1
        break
    fi
    sleep 10
done
if [ "$locked" -eq 0 ]; then
    echo "bridge-teardown: $lock stayed for 10 minutes, so epic-$number stays. Remove the lock and run again." >&2
    exit 1
fi
trap 'rmdir "$lock"' EXIT
just worktree-down "epic-$number"
