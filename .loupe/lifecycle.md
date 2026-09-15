# Lifecycle profile

The Loupe stage skills read this file. It holds the values that belong to this repository. `working-with-prs` stays the authority when it disagrees with this file.

## Instruction files

1. Read `CLAUDE.md`, and follow it.
2. Load each skill that the CLAUDE.md skill table names for the files you touch.
3. Load `working-with-prs` before the gate, a push or a pull request.
4. Load `project-worktrees` before you create, refresh or remove a worktree.
5. Writing style: CLAUDE.md "Writing style", which is ASD-STE100.
6. Product document: answer the documentation and landing page checks of CLAUDE.md "Planning and shipping a feature". It names the `docs/` sections and the landing page templates.
7. Tech design: load `project-tech-design`. Read the CLAUDE.md section "What a new entity or feature must also register", with its table and the list "Four more that no registry covers".

## Worktree

1. The card worktree is `.claude/worktrees/card-<number>`. Run the worktree commands from the main checkout, which is the first `worktree` line of `git worktree list --porcelain`.
2. Create: after `git worktree add`, run `just worktree-up card-<number>`. It bootstraps a registered worktree, and it never creates one.
3. `just worktree-up` copies `vendor/` from the main checkout, or runs `composer install` when the lock differs. It runs the migrations, the seed, the Tailwind build and a cache warmup. It clears no cache.
4. Refresh after a sync that brings commits: run `( cd <main checkout> && just worktree-up card-<number> )`. Then, from the worktree, run `bin/worktrees/compose-exec.sh bin/console cache:clear` and the same command with `--env=test`.
5. Run a command inside the container of the worktree with `bin/worktrees/compose-exec.sh <command>`, from the worktree. Never run bare `docker compose` from a worktree.
6. Remove: `just worktree-down card-<number>`. A stage never removes a worktree.

## Gate

1. Base branch: `main`.
2. Run `just cs`, and commit what it changes.
3. Run `just ci`. It is long, so run it as the harness adapter says for a long command.
4. Never start `just ci` again over a killed run. PHPUnit keeps running in the shared php-fpm container. Stop it as `project-worktrees` says.
5. Never run the full e2e suite locally. The CI `e2e` check gates it. Read a failed one in its shard job, `e2e-chromium` or `e2e-rest`.
6. Fix every failure, including one that pre-dates the branch.
7. The required checks come from the ruleset command in `working-with-prs` "What the ruleset actually requires".

## Code review

1. Before a push, run `mcp__codex-cli__review` with `model: "gpt-6-astra"`. When the tool is missing, stop with `STAGE RESULT: blocked: codex MCP unavailable`.
2. Follow the pass and scope rules of `working-with-prs` "The gate, before you open anything": two clean passes in a row, and a commit scope once the branch has more than one commit.
3. Alternate the scope: one pass with `base: "origin/main"`, the next with `commit: "<sha>"` for the newest commit that carries work.
4. Count a pass as clean only against the current tree. Check that each summary covers the largest change.
5. Before you act on a finding, read the file at HEAD, and dismiss a finding that HEAD already fixes. Run `git status` after each pass.

## Changelog

1. Write one fragment per pull request at `changelog.d/<pr number>.md`, in the format of `changelog.d/README.md`.
2. Check it with `php bin/changelog.php --check` before the push.

## Pull request

1. Open it ready, never draft, against `main`.
2. Write the title as `<type>(<area>): <summary>`.
3. Keep the body and the `## Preview` section to the rules of `working-with-prs` "Keep the body brief" and "Make the branch testable, not just reviewable".
4. Never merge it, and never use `--admin` or `--no-verify`.
