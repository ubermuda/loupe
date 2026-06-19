#!/usr/bin/env bash
#
# Run a command in the shared php-fpm container, targeting the current git
# worktree's code.
#
# There is a single compose stack. The php-fpm container bind-mounts the main
# checkout at /var/www/html, and git worktrees live under
# <main>/.claude/worktrees/<name>, so they are visible inside the container.
# When invoked from a worktree we add `--workdir` so the command runs against
# the worktree's files (and its rsynced vendor/) instead of main's. From the
# main checkout this is a plain `docker compose exec php-fpm ...`.
#
# `-T` (no pseudo-TTY) is added automatically when stdin is not a terminal — so
# CI and piped invocations work, while interactive `just shell` keeps its TTY.
#
set -euo pipefail

root=$(git rev-parse --show-toplevel)
main=$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')

opts=()
[ -t 0 ] || opts+=(-T)
if [ "$root" != "$main" ]; then
    opts+=(--workdir "/var/www/html/${root#"$main"/}")
fi

exec docker compose exec "${opts[@]+"${opts[@]}"}" php-fpm "$@"
