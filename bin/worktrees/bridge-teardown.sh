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

cd "$main"
just worktree-down "card-$number"
