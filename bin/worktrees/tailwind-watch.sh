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

main=${WORKTREE_TAILWIND_ROOT:-/var/www/html}
interval=${WORKTREE_TAILWIND_INTERVAL:-3}
trees="$main/.claude/worktrees"

# Committed and rarely touched, and big enough that scanning them every few
# seconds is the only real cost here.
prune=(-name vendor -o -name icons -o -name fonts -o -name node_modules)

is_stale() {
    local root=$1 built=$2 hit
    for dir in assets templates; do
        [ -d "$root/$dir" ] || continue
        hit=$(find "$root/$dir" \( "${prune[@]}" \) -prune -o -type f -newer "$built" -print -quit 2>/dev/null)
        [ -n "$hit" ] && return 0
    done

    return 1
}

echo "tailwind-watch: polling $trees every ${interval}s"

while true; do
    if [ -d "$trees" ]; then
        for root in "$trees"/*/; do
            root=${root%/}
            built="$root/var/tailwind/app.built.css"
            [ -f "$built" ] || continue
            is_stale "$root" "$built" || continue

            echo "tailwind-watch: rebuilding $(basename "$root")"
            # Failure is reported and the loop carries on. A worktree mid-rebase
            # can hold a template Twig cannot parse, and one broken tree must
            # not stop the others being served current CSS.
            if ( cd "$root" && php bin/console tailwind:build ); then
                # Tailwind leaves the file alone when the output is unchanged,
                # so its timestamp would stay behind the source that triggered
                # this and every pass would rebuild for ever. Stamping it is
                # what makes the comparison settle. Found by running it.
                touch "$built"
            else
                echo "tailwind-watch: $(basename "$root") failed, will retry"
            fi
        done
    fi
    sleep "$interval"
done
