#!/usr/bin/env bash
#
# Claude Code WorktreeRemove hook: tear down everything bootstrap created.
#
# Without this, a harness-removed worktree leaves its nginx sidecar, its Mailpit
# sidecar and both databases behind — the sidecar then serves 502s on a route
# nothing owns, and `just worktree-prune` has to be remembered.
#
# Best-effort by contract: this event cannot block, and a non-zero exit is only
# logged in debug mode. So it never fails the run, and it stays idempotent —
# teardown handles an already-removed tree, so it is safe whether the harness
# removes the tree before or after this runs.
#
# The git removal is the harness's (KEEP_TREE), so the two cannot race.
#
set -uo pipefail

# Resolved relative to THIS script, not to the main checkout, so the hook and
# the script it drives always come from the same commit — a teardown that does
# not understand KEEP_TREE would remove the tree out from under the harness.
bin=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../bin/worktrees" && pwd)

payload=$(cat)

read_field() {
    printf '%s' "$payload" | php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        echo is_array($d) ? (string) ($d[$argv[1]] ?? "") : "";
    ' -- "$1"
}

worktree_path=$(read_field worktree_path)
cwd=$(read_field cwd)
[ -n "$cwd" ] || cwd=$(pwd)
[ -n "$worktree_path" ] || exit 0

main=$(git -C "$cwd" worktree list --porcelain | awk '/^worktree /{print $2; exit}')

case "$worktree_path" in
    "$main"/.claude/worktrees/*) ;;
    *) exit 0 ;;
esac

name=${worktree_path#"$main"/.claude/worktrees/}

WORKTREE_TEARDOWN_KEEP_TREE=1 "$bin/worktree-teardown.sh" "$name" \
    || echo "worktree-remove hook: teardown of '$name' did not complete; 'just worktree-prune' will finish it." >&2

exit 0
