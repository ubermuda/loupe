#!/usr/bin/env bash
# Resolves the e2e target for the checkout this is run from, and prints four
# lines:
#
#     <url>
#     <worktree name, empty unless auto-detected>
#     <main checkout path>
#     <mailpit base url>
#
# One value per line, not tab-separated: tab is whitespace to IFS, so bash
# collapses the empty middle field and shifts the main path into it.
#
# Run from a worktree, `just e2e` used to fall through to the dedicated e2e
# target, which serves the MAIN checkout — so the suite passed while gating none
# of the branch. Resolving the target from the caller's own tree removes that.
#
# The second field drives the post-run re-seed: the suite's install-reset
# project truncates every table, taking a worktree's seeded dev user and project
# with it. It is deliberately empty when E2E_BASE_URL was set by hand, because
# an explicit target may be anything and re-seeding a tree we did not choose
# would be worse than leaving it alone.
set -euo pipefail

main=$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')
main=$(cd "$main" && pwd -P)
here=$(pwd -P)

. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/worktrees/slug.sh"

project=$(grep -E '^COMPOSE_PROJECT_NAME=' "$main/.env" | head -1 | cut -d= -f2-)
project=${project:-loupe}

worktree=""
default_mailpit="https://mailpit.$project.dev.localhost"
if [ "$here" != "$main" ]; then
    worktree=$(worktree_relative_name "$here" "$main")
    slug=$(worktree_slug "$worktree")
    default_url="https://$slug.$project.dev.localhost"
    # Each worktree gets its own Mailpit sidecar, which is what lets two suites
    # run at once. Falls back to the shared one for the main checkout.
    default_mailpit="https://mailpit-$slug.$project.dev.localhost"
else
    default_url="https://e2e.$project.dev.localhost"
fi

# An explicit target may be anything, so neither the worktree-scoped repair nor
# its Mailpit can be assumed to apply.
if [ -n "${E2E_BASE_URL:-}" ]; then
    worktree=""
    default_mailpit="https://mailpit.$project.dev.localhost"
fi

printf '%s\n%s\n%s\n%s\n' \
    "${E2E_BASE_URL:-$default_url}" "$worktree" "$main" "${MAILPIT_URL:-$default_mailpit}"
