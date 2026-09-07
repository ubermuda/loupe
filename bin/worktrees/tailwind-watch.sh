#!/usr/bin/env bash
#
# Keeps every worktree's compiled stylesheet current.
#
# The main checkout has a `tailwind` service watching it. A worktree had
# nothing: worktree-bootstrap.sh builds its sheet once and nothing rebuilt it
# afterwards, so a worktree that outlived a CSS or template edit served the CSS
# it was provisioned with while its Twig and PHP were current. That reads as a
# design regression rather than a stale asset, which is what makes it expensive.
#
# One container polls them all rather than one watcher per worktree, so a
# worktree created later needs no wiring and the cost does not grow with the
# number of trees.
#
# It rebuilds only a sheet that already EXISTS and is out of date. A missing
# sheet belongs to worktree-bootstrap.sh, which builds one as it provisions, and
# leaving that case alone is what stops the two building the same file at once.
set -uo pipefail
# An unmatched glob must expand to nothing rather than to itself.
shopt -s nullglob

main=${WORKTREE_TAILWIND_ROOT:-/var/www/html}
# Ten seconds, not one. This is the safety net for a stylesheet nobody rebuilt,
# not the tool for iterating on CSS: `just worktree-tailwind` is a real watcher
# on one tree. A longer interval is what makes an exhaustive scan affordable.
interval=${WORKTREE_TAILWIND_INTERVAL:-10}
trees="$main/.claude/worktrees"

# Tailwind's automatic detection scans the whole checkout, so the scan does too
# rather than guessing which directories hold classes. `config/packages/
# ubermuda_admin.yaml` carries `!bg-sunken`, and app.css excludes `.claude` and
# `docs` explicitly, which is what says the rest of the tree is in scope.
#
# Scanning the root also covers composer.lock for free. app.css imports
# vendor/ubermuda/admin-bundle/assets/admin.css and scans two vendored template
# directories, and CLAUDE.md tells you to run composer install after switching
# branches in a reused worktree, so a lockfile change is a real reason to
# rebuild.
#
# Two of these prunes are load-bearing rather than an optimisation. `var` holds
# the log and the cache, which change on every request, so without it the
# watcher would rebuild for ever. `.claude` is where worktrees live, so without
# it a worktree would scan its siblings. The rest are size: this walks 1,918
# entries per worktree instead of 28,000.
prune=(
    -name vendor -o -name node_modules -o -name .git -o -name var
    -o -name .claude -o -name docs -o -name icons -o -name fonts
    -o -name test-results -o -name build
)

is_stale() {
    local root=$1 built=$2
    [ -n "$(find "$root" \( "${prune[@]}" \) -prune -o -newer "$built" -print -quit 2>/dev/null)" ]
}

worktree_roots() {
    local sheet
    for sheet in "$trees"/*/var/tailwind/app.built.css \
                 "$trees"/*/*/var/tailwind/app.built.css \
                 "$trees"/*/*/*/var/tailwind/app.built.css; do
        [ -f "$sheet" ] && dirname "$(dirname "$(dirname "$sheet")")"
    done
}

echo "tailwind-watch: polling $trees every ${interval}s"

while true; do
    if [ -d "$trees" ]; then
        while read -r root; do
            [ -n "$root" ] || continue
            built="$root/var/tailwind/app.built.css"
            [ -f "$built" ] || continue
            is_stale "$root" "$built" || continue

            # Taken before the build, and stamped onto the sheet after it. A
            # file saved while Tailwind was already reading would otherwise end
            # up older than the output that missed it, and the edit would sit
            # unbuilt until the next one.
            started=$(mktemp)

            echo "tailwind-watch: rebuilding $(basename "$root")"
            # Failure is reported and the loop carries on. A worktree mid-rebase
            # can hold a template Twig cannot parse, and one broken tree must
            # not stop the others being served current CSS.
            if ( cd "$root" && php bin/console tailwind:build ); then
                # Tailwind leaves the file alone when the output is unchanged,
                # so its timestamp would stay behind the source that triggered
                # this and every pass would rebuild for ever. Stamping it is
                # what makes the comparison settle. Found by running it.
                touch -r "$started" "$built"
            else
                echo "tailwind-watch: $(basename "$root") failed, will retry"
            fi
            rm -f "$started"
        done < <(worktree_roots)
    fi
    sleep "$interval"
done
