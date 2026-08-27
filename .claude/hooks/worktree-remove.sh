#!/usr/bin/env bash
#
# Claude Code WorktreeRemove hook: tear down everything bootstrap created before
# the harness removes the tree itself.
#
# Without this, a harness-removed worktree leaves its nginx sidecar, its Mailpit
# sidecar and both databases behind — the sidecar then serves 502s on a route
# nothing owns, and `just worktree-prune` has to be remembered.
#
# The git removal stays with the harness (KEEP_TREE), so the two do not race.
#
set -euo pipefail

payload=$(cat)

read_field() {
    printf '%s' "$payload" | php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        echo is_array($d) ? (string) ($d[$argv[1]] ?? "") : "";
    ' -- "$1"
}

worktree_path=$(read_field worktree_path)
repo_path=$(read_field repo_path)
[ -n "$worktree_path" ] || exit 0
[ -n "$repo_path" ] || repo_path=$(pwd)

main=$(git -C "$repo_path" worktree list --porcelain | awk '/^worktree /{print $2; exit}')

case "$worktree_path" in
    "$main"/.claude/worktrees/*) ;;
    *) exit 0 ;;
esac

name=${worktree_path#"$main"/.claude/worktrees/}

WORKTREE_TEARDOWN_KEEP_TREE=1 "$main/bin/worktrees/worktree-teardown.sh" "$name"
