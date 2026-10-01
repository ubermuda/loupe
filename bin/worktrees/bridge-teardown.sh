#!/usr/bin/env bash
# Bridge teardown command: remove the card's worktree, sidecars and databases.
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
others=$(worktree_slug_index "$main" | awk -v s="card-$number" '$1 == s && $2 != s {print $2}')
if [ -n "$others" ]; then
    echo "bridge-teardown: worktree '$others' shares the slug card-$number. Remove it by hand." >&2
    exit 1
fi

cd "$main"
just worktree-down "card-$number"
