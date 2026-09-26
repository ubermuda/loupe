# Lifecycle profile

The Loupe stage skills read this file. It holds the values that belong to this repository. `working-with-prs` stays the authority when it disagrees with this file.

## Instruction files

1. Read `AGENTS.md`, and follow it.
2. Load each skill that the AGENTS.md skill table names for the files you touch.
3. Load `working-with-prs` before the gate, a push or a pull request.
4. Load `project-worktrees` before you create, refresh or remove a worktree.
5. Writing style: AGENTS.md "Writing style", which is ASD-STE100.
6. Product document: answer the documentation and landing page checks of AGENTS.md "Planning and shipping a feature". It names the `docs/` sections and the landing page templates.
7. Tech design: load `project-tech-design`. Read the AGENTS.md section "What a new entity or feature must also register", with its table and the list "Four more that no registry covers".

## Worktree

1. The card worktree is `.worktrees/card-<number>`. Run the worktree commands from the main checkout, which is the first `worktree` line of `git worktree list --porcelain`.
2. Create: after `git worktree add`, run `just worktree-up card-<number> card:<cardId>`. It bootstraps a registered worktree, and it never creates one.
3. `just worktree-up` copies `vendor/` from the main checkout, or runs `composer install` when the lock differs. It runs the migrations, the seed, the Tailwind build and a cache warmup. It clears no cache.
4. Refresh after a sync that brings commits: run `( cd <main checkout> && just worktree-up card-<number> card:<cardId> )`. Then, from the worktree, run `bin/worktrees/compose-exec.sh bin/console cache:clear` and the same command with `--env=test`.
5. `<cardId>` is the card id from the prompt line `Card <number> (cardId <id>)`. When the prompt has no such line, take `cardId` from `card_get`. Never derive it from a branch name, a worktree name or a card number.
6. The second argument writes `SITE_REVIEW_WIDGET_CONTEXT=card:<cardId>` into the `.env.local` of the worktree. The site-review widget then links each comment on the preview to the card. Bootstrap keeps the old marker when the argument is absent, so pass it on every create and every refresh. The marker must name a card that the widget backend holds. A branch that points `SITE_REVIEW_WIDGET_BACKEND` at its own worktree host passes no marker, or a card id from its own database. `project-worktrees` "The card marker" says more.
7. Run a command inside the container of the worktree with `bin/worktrees/compose-exec.sh <command>`, from the worktree. Never run bare `docker compose` from a worktree.
8. Remove: `just worktree-down card-<number>`. A stage never removes a worktree.

## Gate

1. Base branch: `main`. A branch that stacks on an open pull request uses that pull request's head branch instead, as the stage `commands.md` says.
2. Run `just cs`, and commit what it changes.
3. Run `just ci`. It is long, so run it as the harness adapter says for a long command.
4. Never start `just ci` again over a killed run. PHPUnit keeps running in the shared php-fpm container. Stop it as `project-worktrees` says.
5. Never run the full e2e suite on this machine, and a hook refuses it. The CI `e2e` check gates it. Read a failed one in its shard job, `e2e-chromium` or `e2e-rest`. One named spec is still fine while you debug it.
6. Fix every failure, including one that pre-dates the branch.
7. The required checks come from the ruleset command in `working-with-prs` "What the ruleset actually requires".

## Code review

1. Before a push, run `mcp__codex-cli__review` with `model: "gpt-6-astra"`. When the tool is missing, stop with `STAGE RESULT: blocked: codex MCP unavailable`.
2. Follow the pass and scope rules of `working-with-prs` "The gate, before you open anything": two clean passes in a row, and a commit scope once the branch has more than one commit.
3. Alternate the scope: one pass with `base: "origin/<base>"`, the next with `commit: "<sha>"` for the newest commit that carries work.
4. Count a pass as clean only against the current tree. Check that each summary covers the largest change.
5. Before you act on a finding, read the file at HEAD, and dismiss a finding that HEAD already fixes. Run `git status` after each pass.

## Changelog

1. Write one fragment per pull request at `changelog.d/<pr number>.md`, in the format of `changelog.d/README.md`.
2. Check it with `php bin/changelog.php --check` before the push.

## Pull request

1. Open it ready, never draft, against the base branch of the `Gate` section. Never open a stacked pull request against `main`.
2. Write the title as `<type>(<area>): <summary>`.
3. Keep the body and the `## Preview` section to the rules of `working-with-prs` "Keep the body brief" and "Make the branch testable, not just reviewable".
4. A branch that changes a page seeds one state per preview link, before the pull request is ready. "The tests cover it", "the seed holds no X" and "it shows after a bridge reports data" are excuses, and no substitute for the seed.
5. Prove each link with `working-with-prs` "Prove each preview link shows its state". Write the marker you found on the line of each link. When you cannot seed a state, or a marker is missing, stop with `STAGE RESULT: blocked: preview not seeded`. Do not move the card.
6. Never merge it, and never use `--admin` or `--no-verify`.

## Board

1. The column that holds a pull request waiting for review is `in-review`.
2. Move a card there yourself only when your own procedure says to. A move that carries an approval is the app's, never an agent's.
3. Never read the column list to find this slug. `board_columns` can be missing, which is why the slug is written here.
4. The column that holds a card in product design is `product-design`. The `/loupe:product-design` skill reads this slug.
5. The column that holds a card in implementation is `implementation`. A breakdown moves each child that can start there.
6. The default column is `backlog`, and the terminal column is `done`. A breakdown reads them to find the children that can start.
