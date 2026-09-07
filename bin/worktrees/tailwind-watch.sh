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
interval=${WORKTREE_TAILWIND_INTERVAL:-3}
trees="$main/.claude/worktrees"

# Committed and rarely touched, and big enough that scanning them every few
# seconds is the only real cost here.
prune=(-name vendor -o -name icons -o -name fonts -o -name node_modules)

is_stale() {
    local root=$1 built=$2 hit
    for dir in assets templates; do
        [ -d "$root/$dir" ] || continue
        # Directories count as well as files. Deleting the last template that
        # used a class leaves no surviving file newer than the sheet, and the
        # class would sit in the output for ever; the parent directory's own
        # timestamp is what records the removal.
        hit=$(find "$root/$dir" \( "${prune[@]}" \) -prune -o -newer "$built" -print -quit 2>/dev/null)
        [ -n "$hit" ] && return 0
    done

    return 1
}

# Every provisioned worktree, as a glob rather than a walk. The second pattern
# is a name carrying one path segment, such as `foo/bar`. A name with two is not
# found, and nothing in this repository uses one.
#
# The cost of getting this wrong is why it is spelled out. A worktree holds a
# real vendor/ of about 28,000 files, so a find for the sheets took 14.6 seconds
# a pass against a three-second interval, because `-path` filters what is
# printed rather than what is walked. Pruning the big directories brought that
# to 0.9s. These globs answer in 23ms, because the shell stats named paths and
# walks nothing.
#
# `git worktree list` would be the authoritative source and cannot be used: it
# reports the paths a worktree was created with, which are the host's, and this
# runs in a container where those do not exist.
worktree_roots() {
    local sheet
    for sheet in "$trees"/*/var/tailwind/app.built.css \
                 "$trees"/*/*/var/tailwind/app.built.css; do
        [ -f "$sheet" ] && dirname "$(dirname "$(dirname "$sheet")")"
    done
}

echo "tailwind-watch: polling $trees every ${interval}s"

while true; do
    if [ -d "$trees" ]; then
        while read -r root; do
            [ -n "$root" ] || continue
            built="$root/var/tailwind/app.built.css"
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
