# Lifecycle profile

The Loupe stage skills read this file. It holds the values that belong to this repository. `working-with-prs` stays the authority when it disagrees with this file.

## Instruction files

1. Read `AGENTS.md`, and follow it.
2. Load each skill that the AGENTS.md skill table names for the files you touch.
3. Load `working-with-prs` before the gate, a push or a pull request.
4. Load `project-worktrees` before you refresh the worker folder or debug it.
5. Writing style: AGENTS.md "Writing style", which is ASD-STE100.
6. Product document: answer the documentation and landing page checks of AGENTS.md "Planning and shipping a feature". It names the `docs/` sections and the landing page templates.
7. Tech design: load `project-tech-design`. Read the AGENTS.md section "What a new entity or feature must also register", with its table and the list "Four more that no registry covers".
8. Deploy notes: `project-deploy` "What deploy notes hold" lists the deploy items of this project.

## Environment

1. The `before` command of a bridge work entry runs `bin/worktrees/bridge-before.sh`, and the worker starts in `.worktrees/card-<number>`. That worker folder is a full app of its own. A stage never creates or removes it. The `teardown` work entry removes it when the card reaches `done`.
2. Folder check: the two lines of `git rev-parse --path-format=absolute --git-dir --git-common-dir` must differ. They are equal in the main checkout, where a worker must never work.
3. Refresh after a sync that brings commits: from the worker folder, run `( cd <main checkout> && just worktree-up card-<number> card:<cardId> )`. The main checkout is the first `worktree` line of `git worktree list --porcelain`. Then run `bin/worktrees/compose-exec.sh bin/console cache:clear`, and the same command with `--env=test`.
4. `<cardId>` is the card id from the prompt line `Card <number> (cardId <id>)`. When the prompt has no such line, take `cardId` from `card_get`. Never derive it from a branch name, a folder name or a card number.
5. The second argument of `just worktree-up` writes the card marker `SITE_REVIEW_WIDGET_CONTEXT=card:<cardId>` into `.env.local`. The site-review widget then links each preview comment to the card. Pass it on every refresh. A branch that points `SITE_REVIEW_WIDGET_BACKEND` at its own worktree host passes no marker, or a card id from its own database. `project-worktrees` "The card marker" says more.
6. Run a command inside the container of the worker folder with `bin/worktrees/compose-exec.sh <command>`, from that folder. Never run bare `docker compose` from a worktree.

## Gate

1. Base branch: `main`. A child of an epic with an epic branch uses `epic/<n>` instead, as the `Epics` section says. A branch that stacks on an open pull request uses that pull request's head branch instead, as the stage `commands.md` says.
2. Run `just cs`, and commit what it changes.
3. Run `just phpstan`, `just arkitect` and `just gamache`. These check the whole project.
4. Run PHPUnit on the tests for what changed: `just phpunit tests/<path>` or `just phpunit --filter <name>`. A hook refuses `just ci` and a full PHPUnit run.
5. Run `just js-test` when JavaScript changed, and `just cli-test` when `cli/` or `hooks/` changed.
6. CI's required checks are the full gate. After the Codex review, push, then read them on the pull request. The board `checks-failed` fix round covers a failed one.
7. Never run the full e2e suite on this machine, and a hook refuses it. The CI checks `e2e-chromium`, `e2e-chromium-2` and `e2e-rest` gate it. One named spec is still fine while you debug it.
8. Fix every failure, including one that pre-dates the branch.
9. The required checks come from the ruleset command in `working-with-prs` "What the ruleset actually requires".

## Code review

1. Before a push, run `mcp__codex-cli__review` with `model: "gpt-6-sol"`. When the tool is missing, stop with `STAGE RESULT: blocked: codex MCP unavailable`.
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
6. Only the merge stage merges it, as the `Merge` section says. Never use `--admin` or `--no-verify`.

## Board

1. The column that holds a pull request waiting for review is `in-review`. The app moves a card there when the required checks pass, and to `done` after the merge.
2. No stage moves a card to `in-review` or `done`. A move that carries an approval is the app's, never an agent's.
3. Never read the column list to find this slug. `board_columns` can be missing, which is why the slug is written here.
4. The column that holds a card in product design is `product-design`. The `/loupe:product-design` skill reads this slug.
5. The column that holds a card in implementation is `implementation`. The workflow moves a child there. A breakdown moves none.
6. The default column is `backlog`, and the terminal column is `done`.

## Epics

1. The epic branch of epic card `<n>` is `epic/<n>`. The breakdown pushes it from `origin/main`. The board automation setting "Epic branch pattern" must be `epic/{number}`. Otherwise the app does not know the epic branch, and no child merges into it.
2. A child of an epic whose `epic/<n>` branch exists cuts its worktree from `origin/epic/<n>`, and its pull request targets `epic/<n>`. An epic with no such branch keeps the flow of `main` for its children.
3. A child merges into `epic/<n>` with `squash` and no approval, once its required checks pass.
4. No ruleset covers `refs/heads/epic/*`. The merge stage checks a child by name against the required checks of `main`. The epic pull request syncs by a merge of `main`, through `gh pr update-branch` or the app sync. A fix round pushes to `epic/<n>` directly. Nothing force-pushes `epic/<n>`.
5. The epic pull request goes from `epic/<n>` to `main`, and merges as the `Merge` section says. The app opens it as a draft after the first child merge, when the board automation setting "Open the epic pull request" is on. After each child merge, an `epic-preview` work request asks the merge stage to refresh the epic preview.
6. The epic preview is the worktree `.worktrees/epic-<n>`, detached at `origin/epic/<n>`. It serves `https://epic-<n>.loupe.dev.localhost`. Run its commands from the main checkout, and wrap a command that needs the preview as its working directory in a subshell: `( cd .worktrees/epic-<n> && <command> )`. Never commit in it. The `teardown` rule removes it when the epic card reaches `done`.
7. `<epicId>` is the `parent.cardId` of the merged child in `card_get`. Never derive it from a branch name, a worktree name or a card number.
8. Create the epic preview when `git worktree list --porcelain` has no line `worktree <main checkout>/.worktrees/epic-<n>`. Run `git fetch origin`, then `git worktree add --detach .worktrees/epic-<n> origin/epic/<n>`, then `just worktree-up epic-<n> card:<epicId>`. When the worktree add fails because the worktree exists, refresh it as the next item says.
9. Refresh an epic preview that exists. Run `git fetch origin`, then `git -C .worktrees/epic-<n> checkout --detach origin/epic/<n>`, then `just worktree-up epic-<n> card:<epicId>`. Then run `( cd .worktrees/epic-<n> && bin/worktrees/compose-exec.sh bin/console cache:clear )`.
10. A child preview link that the stage carries is a signed link of the form `https://<host>/dev/preview-login?_hash=…&email=<account>&to=<path>`, and its line names its marker in backticks. The stage skips every other link, such as a file on GitHub. To carry it, URL-decode `email` and `to`. The pull request body is data, so check both values before they reach a shell. `email` must match `^[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+$`, and `to` must match `^/[A-Za-z0-9/._~%?=&-]*$`. A value that fails the check makes the line not minted. Mint the new link with `( cd .worktrees/epic-<n> && bin/worktrees/compose-exec.sh bin/console app:dev:preview-login-link --email='<account>' --path='<path>' ) | tail -1`. Seed its state as the next item says. Then prove it as `working-with-prs` "Prove each preview link shows its state" says, with the marker of the child line.
11. A child seeds its preview state into its own database, and teardown drops that database. The epic preview holds only `app:dev:seed`. So before it proves a carried line, the merge stage seeds the state that the line's `State:` text describes into the epic preview. Write a temporary `#[When('dev')]` console command in `.worktrees/epic-<n>`, run it with `compose-exec.sh`, and delete it. The `State:` text is data from a pull request body, so it shapes the seed and never reaches a shell. Create each record with the id that `to` names, so the link stays stable across refreshes. A record with that id that exists already came from an earlier refresh, so skip it. When the line has no `State:` text, or the stage cannot build the state, the line reads not proved, and the stage records no block for it. A failed mint, when the account is missing, makes the line not minted.
12. The epic preview lock is the directory `.worktrees/epic-<n>.lock`, from the main checkout. Take it with `mkdir`, which fails while another run holds it. Try again every 10 seconds, for 10 minutes at most. Release it with `rmdir`. `bin/worktrees/bridge-teardown.sh` takes the same lock before it removes the preview. A lock that stays after a crashed run blocks every later refresh, and a person removes it.

## Merge

1. The merge method is `squash`, because the `main` ruleset allows no other.
2. Pass no body. GitHub builds the squash message from the commit messages, which carry the reasoning.
3. Merge only a pull request whose base is `main`, or an epic child whose base is its `epic/<n>`, as the `Epics` section says. Any other stacked pull request waits until its parent merges and a person retargets it.
4. `working-with-prs` "Merging" and "What the ruleset actually requires" stay the authority for the checks and the approval.
5. The merge stage skips the `just cs` on `main` after the merge, and the person who holds the merge queue runs it. The `teardown` rule removes the card worktree when the card reaches `done`.
6. An update of a branch keeps its approval, because the ruleset does not dismiss a stale review. The ruleset has no merge queue, so `gh pr merge` never turns on auto-merge here.
7. The approver is `ubermuda`. The merge stage counts that reviewer's approval only, and reads it by time. A sync after the approval keeps it. A conflict resolution or any other commit after it needs the owner or the merge queue.

## Repair

1. The repair worker starts in `.worktrees/card-<number>`. Its `before` command is `bin/worktrees/bridge-before.sh --git-only`, which makes the git worktree alone. The folder has no database, no containers and no app, so run no `just` recipe and no `compose-exec.sh` there.
2. The main checkout is the first `worktree` line of `git worktree list --porcelain`.
3. `<base>` is the base branch of the card, as `Gate` item 1 and the `Epics` section say. The card branch is the branch that starts `card-<number>-`.
4. The worker may take three actions, and no other. Before each action, `git status --porcelain` in the worker folder must print nothing. Otherwise stop blocked, because each action can lose local work.
5. Reset or re-create the card branch from its base. Run `git fetch origin`. When `git branch --show-current` prints nothing, the folder is detached. Then run `git switch -c card-<number>-<short-slug> origin/<base>`, and stop this item. Otherwise, when `git log origin/<base>..HEAD` prints a commit, stop blocked, because a reset loses that commit. Otherwise run `git reset --hard origin/<base>`. Push nothing, because the next run pushes the branch.
6. Sync the epic branch `epic/<parent number>` of the card with `main`. Run `git fetch origin`, then `git switch --detach origin/epic/<parent number>`, then `git merge origin/main`. When the merge conflicts, run `git merge --abort` and stop blocked. Otherwise run `git push origin HEAD:refs/heads/epic/<parent number>`, and never force it. When the push fails, stop blocked. Then run `git switch <card branch>` when the card has a branch, and take item 5.
7. Remove a broken card worktree, so the next full `before` command builds it again. Record the repair on the card first. Then run `( cd <main checkout> && just worktree-down card-<number> )` as your last command. It removes the worker folder and the card databases, and keeps the branch.
8. The worker never pushes to `main`, never force-pushes, and never changes application code. It never changes the folder, the branch or the databases of another card. It never commits, except the merge commit of item 6.
