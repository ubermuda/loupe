#!/usr/bin/env bash
#
# Claude Code WorktreeCreate hook: OWN the worktree, do not merely react to it.
#
# The event hands over a name and expects this script to produce the worktree
# and print its absolute path as the last line of stdout. A non-zero exit or a
# missing path fails the creation, so every other message goes to stderr.
#
# It exists so a worktree arrives provisioned — a bare `git worktree add` has no
# vendor/, no .env.local, no database and no sidecar, so an agent isolated in one
# can neither run `just ci` nor browse its own branch.
#
set -euo pipefail

# Resolved relative to THIS script, not to the main checkout, so the hook and
# the script it drives always come from the same commit.
bin=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../bin/worktrees" && pwd)

payload=$(cat)

read_field() {
    printf '%s' "$payload" | php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        echo is_array($d) ? (string) ($d[$argv[1]] ?? "") : "";
    ' -- "$1"
}

name=$(read_field name)
cwd=$(read_field cwd)
[ -n "$cwd" ] || cwd=$(pwd)

if [ -z "$name" ]; then
    echo "worktree-create hook: the event carried no name." >&2
    exit 1
fi

# The name becomes a path segment and a branch, so refuse anything that could
# climb out of .claude/worktrees/ or confuse git's ref parser.
case "$name" in
    /* | *..* | *' '* | -*)
        echo "worktree-create hook: refusing the worktree name '$name'." >&2
        exit 1
        ;;
esac

main=$(git -C "$cwd" worktree list --porcelain | awk '/^worktree /{print $2; exit}')
root="$main/.claude/worktrees/$name"

created_worktree=0
created_branch=0
branch_created_at=
if git -C "$main" worktree list --porcelain | grep -qxF "worktree $root"; then
    echo "worktree-create hook: '$name' already exists; re-provisioning it." >&2
elif [ -e "$root" ]; then
    echo "worktree-create hook: $root exists but is not a registered worktree." >&2
    echo "Remove the leftover directory and retry." >&2
    exit 1
elif git -C "$main" show-ref --verify --quiet "refs/heads/$name"; then
    # The branch predates this run, so only the worktree is ours to undo.
    git -C "$main" worktree add "$root" "$name" >&2
    created_worktree=1
else
    # Off main deliberately, never the current branch: a worktree cut from a
    # sibling feature branch inherits work it never meant to carry.
    git -C "$main" worktree add -b "$name" "$root" main >&2
    created_worktree=1
    created_branch=1
    branch_created_at=$(git -C "$main" rev-parse "refs/heads/$name")
fi

if ! "$bin/worktree-bootstrap.sh" "$name" >&2; then
    echo "worktree-create hook: could not provision '$name' (reason above)." >&2
    echo "Most often the stack is down — run 'just up' in $main, then retry." >&2
    # Undo strictly what this run created. Removing a worktree we added is
    # right; deleting a branch we merely checked out would destroy commits that
    # were never ours, so the branch goes only if we made it AND nothing has
    # moved it since.
    if [ "$created_worktree" = 1 ]; then
        git -C "$main" worktree remove "$root" --force >&2 || true
    fi
    if [ "$created_branch" = 1 ] \
        && [ "$(git -C "$main" rev-parse "refs/heads/$name" 2>/dev/null)" = "$branch_created_at" ]; then
        git -C "$main" branch -D "$name" >&2 || true
    fi
    exit 1
fi

printf '%s\n' "$root"
