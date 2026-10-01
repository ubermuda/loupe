#!/usr/bin/env bash
# Bridge `before` command: create or repair the card's worktree at .worktrees/card-<n>.
# Usage: bridge-before.sh <cardNumber> <cardId> [<pullRequestNumber>]
# The bridge reads the last stdout line as the worker's folder, so all other output goes to stderr.
set -euo pipefail

exec 3>&1 1>&2

number=${1:-}
card_id=${2:-}
pr=${3:-}

if ! [[ $number =~ ^[1-9][0-9]*$ ]]; then
    echo "bridge-before: the card number '$number' is not a positive integer." >&2
    exit 1
fi
# The recipe interpolates the marker into a shell line unquoted.
if ! [[ $card_id =~ ^[A-Za-z0-9-]+$ ]]; then
    echo "bridge-before: the card id '$card_id' is empty or has characters other than letters, digits and '-'." >&2
    exit 1
fi
if [ -n "$pr" ] && ! [[ $pr =~ ^[1-9][0-9]*$ ]]; then
    echo "bridge-before: the pull request number '$pr' is not a positive integer." >&2
    exit 1
fi

main=$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')
name="card-$number"
root="$main/.worktrees/$name"

git -C "$main" fetch origin
# A tree deleted from disk stays registered until a prune, and holds its branch.
git -C "$main" worktree prune

created_worktree=0
registered=$(git -C "$main" worktree list --porcelain)
if grep -qxF "worktree $root" <<<"$registered"; then
    echo "bridge-before: $name already exists; re-provisioning it." >&2
elif [ -e "$root" ]; then
    echo "bridge-before: $root exists but is not a registered worktree. Remove it and retry." >&2
    exit 1
else
    # The '-' after the number keeps card-4 from matching card-40-*.
    branch=$(git -C "$main" for-each-ref --sort=-committerdate --count=1 \
        --format='%(refname:short)' "refs/heads/$name-*")
    if [ -n "$pr" ]; then
        head=$(cd "$main" && gh pr view "$pr" --json headRefName -q .headRefName)
        if [ -z "$head" ]; then
            echo "bridge-before: pull request $pr has no head branch." >&2
            exit 1
        fi
        git -C "$main" fetch origin "$head"
        if git -C "$main" show-ref --verify --quiet "refs/heads/$head"; then
            git -C "$main" worktree add "$root" "$head"
        else
            git -C "$main" worktree add --track -b "$head" "$root" "origin/$head"
        fi
    elif [ -n "$branch" ]; then
        git -C "$main" worktree add "$root" "$branch"
    else
        git -C "$main" worktree add --detach "$root" origin/main
    fi
    created_worktree=1
fi

# From main: bootstrap's bare `docker compose` calls resolve their file from the cwd.
if ! (cd "$main" && just worktree-up "$name" "card:$card_id"); then
    echo "bridge-before: could not provision $name (reason above)." >&2
    # Remove only the tree this run added, with its sidecars and databases.
    # The teardown never deletes a branch.
    if [ "$created_worktree" = 1 ]; then
        (cd "$main" && just worktree-down "$name") || true
    fi
    exit 1
fi

printf '%s\n' "$root" >&3
