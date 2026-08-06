# Branch progress: chore/comment-budget-check-v2

Works in `.claude/worktrees/comment-budget`, branched from `origin/main`.
The first attempt ran in the main checkout while another agent had it, and
its commits landed on that agent's branch; this tree is the clean redo.

Scratch tracker for this branch only. **Delete before opening the PR.**

## Done

- gamache PR #31 — `CommentBudgetCheck` (advisory, all comment syntaxes).
- Pinned gamache to `dev-feat/comment-budget-check#75ebfa3 as dev-main`.
- Registered `CommentBudgetCheck` + `SelfContainedCommentsCheck` in `gamache.php`.
- CLAUDE.md: removed a duplicated Gamache-checks table and paragraph; added the
  comment-length bullet.
- `project-comments` skill: documented the new check and its advisory nature.

## Done — the 78 phpstan errors surfaced by the pin bump

All cleared (owner chose to fix rather than baseline them). `just ci` is
green: phpstan clean, gamache 11 passed / 0 failed / 2 advisory, 1273
tests / 5042 assertions.

## Still to do

- The comment cleanup itself: 144 over-budget blocks reported by
  `vendor/bin/gamache`. Legitimate long headers (`compose.prod.yaml`, `.env`,
  Twig file headers) get `@comment-budget-ignore`, not a rewrite.
- Repoint the gamache pin to `dev-main#<merge-sha>` once PR #31 merges, and drop
  the `as dev-main` alias.

## Gate status

- [x] `just cs`
- [x] `just ci` (exit 0)
- [ ] `just e2e` — needs `just e2e-up` and a consumer; not run yet
- [ ] Codex review against `origin/main`
- [ ] Push + open PR

## Worktree notes

`just worktree-up comment-budget` OOM'd during Twig cache warmup, so the tree
was finished by hand: `composer install`, `npm install` in `e2e/`,
`cache:warmup` with a raised memory limit, and `bin/console tailwind:build`.
Skipping that last one is what made ~69 controller tests fail with
"Unable to find asset tailwindcss" — environmental, not a regression.
