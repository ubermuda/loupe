#!/usr/bin/env bash
#
# Shared identifier derivation for the worktree tooling. Sourced by
# worktree-bootstrap.sh and worktree-teardown.sh so both agree on every name.
#
# One input (the worktree's path under .claude/worktrees/) produces one slug,
# and every artefact is derived from that slug:
#
#   hostname         <slug>.<base host>          e.g. my-branch.loupe.dev.localhost
#   dev database     app_wt_<slug with - as _>
#   test database    app_test_<slug with - as _>
#   compose project  <project>-wt-<slug>
#
# Deriving them separately is how a prune step ends up unable to find what
# bootstrap created, so there is exactly one sanitizer here and no other.
#

# Hostnames already taken by the main stack's own Traefik routes. A worktree
# claiming one would silently hijack it.
WORKTREE_RESERVED_SLUGS="mailpit mercure db www"

# Turn a worktree path fragment into a DNS label: lowercase, every run of
# non-alphanumerics collapsed to a single dash, no leading/trailing dash, and
# clamped to the 63-character limit for a single label.
worktree_slug() {
    printf '%s' "$1" \
        | tr '[:upper:]' '[:lower:]' \
        | sed -e 's/[^a-z0-9]\{1,\}/-/g' -e 's/^-*//' -e 's/-*$//' \
        | cut -c1-63
}

# Postgres identifiers can't contain dashes without quoting everywhere, so the
# database names use underscores. Derived from the slug — never from the raw
# name — so the two can't drift.
worktree_db_token() {
    printf '%s' "${1//-/_}"
}

# The worktree's path relative to <main>/.claude/worktrees, so a nested
# worktree (foo/bar) yields a single unambiguous slug (foo-bar).
worktree_relative_name() {
    local root=$1 main=$2
    printf '%s' "${root#"$main"/.claude/worktrees/}"
}

# Fails with a clear message rather than letting a bad slug reach Docker or
# Postgres, where the error is far less obvious.
worktree_assert_slug() {
    local slug=$1
    if [ -z "$slug" ]; then
        echo "worktree: name contains no alphanumeric characters — cannot build a hostname from it." >&2
        return 1
    fi
    for reserved in $WORKTREE_RESERVED_SLUGS; do
        if [ "$slug" = "$reserved" ]; then
            echo "worktree: '$slug' is reserved by the main stack's own routes — rename the worktree." >&2
            return 1
        fi
    done
}
