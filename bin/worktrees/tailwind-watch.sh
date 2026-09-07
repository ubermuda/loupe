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

# Committed and rarely touched, and big enough that scanning them is the only
# real cost here. `vendor` is not among them: app.css imports
# vendor/ubermuda/admin-bundle/assets/admin.css and scans two vendored template
# directories, so a composer install on a branch switch is a real reason to
# rebuild. Only that one vendor subtree is scanned, and it is 688 files.
prune=(-name icons -o -name fonts -o -name node_modules -o -name test-results)

# Where a stylesheet's inputs live, relative to a worktree root, kept in step
# with the @import and @source lines at the top of assets/styles/app.css.
#
# composer.lock stands in for the vendored inputs. app.css imports
# vendor/ubermuda/admin-bundle/assets/admin.css and scans two vendored template
# directories, so a composer install on a branch switch is a real reason to
# rebuild. Watching that subtree costs 688 stats per worktree per pass; the
# lockfile changes exactly when the vendored tree does, and costs one.
sources=(assets templates)
source_files=(composer.lock)

is_stale() {
    local root=$1 built=$2 hit
    for file in "${source_files[@]}"; do
        [ "$root/$file" -nt "$built" ] && return 0
    done

    for dir in "${sources[@]}"; do
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

# Every provisioned worktree, as globs rather than a walk. Three patterns, so a
# name carrying up to two path segments is found. A deeper one is not, and this
# is the deliberate limit rather than an oversight.
#
# An exhaustive find is what the limit buys off. A worktree holds about 28,000
# vendored files and `-path` filters what find prints rather than what it walks,
# so an unpruned walk cost 13.8 seconds a pass and a pruned one 1.7. These globs
# cost 0.3, because the shell stats named paths and walks nothing. A watcher
# loading the bind mount all day is a worse failure than a name nobody uses
# going unwatched: the shared php-fpm serves every worktree, and background load
# there skews the e2e timings enough to produce failures that read as real.
#
# `git worktree list` would be the authoritative source and cannot be used: it
# reports the paths a worktree was created with, which are the host's, and this
# runs in a container where those do not exist. It fails by returning an empty
# list rather than an error, which is worth knowing before reaching for it.
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
