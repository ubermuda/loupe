#!/usr/bin/env bash
#
# Claude Code WorktreeCreate hook: provision the worktree the harness just made.
#
# A harness-created worktree is a bare `git worktree add` — no vendor/, no
# .env.local, no database, no sidecar — so an agent isolated in one cannot run
# `just ci` and cannot browse its own branch. This closes that gap, which is
# what makes `isolation: "worktree"` usable at all.
#
# Reads the hook payload on stdin and blocks creation when bootstrap fails: a
# worktree nobody can gate is the failure this exists to prevent, so it is
# reported now rather than discovered an hour in.
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

# Only worktrees under .claude/worktrees/ are ours to provision; anything else
# is someone's own `git worktree add` and must be left alone.
case "$worktree_path" in
    "$main"/.claude/worktrees/*) ;;
    *) exit 0 ;;
esac

name=${worktree_path#"$main"/.claude/worktrees/}

if ! "$main/bin/worktrees/worktree-bootstrap.sh" "$name"; then
    echo "worktree-create hook: could not provision '$name' (reason above)." >&2
    echo "Most often the stack is down — run 'just up' in $main, then retry." >&2
    exit 1
fi
