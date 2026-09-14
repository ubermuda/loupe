# Implementation stage commands

`SKILL.md` names each step. This file holds the detail. `project-worktrees` and `working-with-prs` stay the authority when they disagree with this file.

## Borrowed skills

1. Use `superpowers-extended-cc:writing-plans` for the plan format only. Submit the plan to Loupe. Save no file under `docs/superpowers`, and skip its question about how to execute.
2. Use `superpowers-extended-cc:subagent-driven-development` for the task loop only. Never invoke `superpowers-extended-cc:finishing-a-development-branch`. Never merge the pull request, and never merge into `main`. Merging `origin/main` into the card branch is required by the gate. Never remove the worktree.
3. Never call `AskUserQuestion`. Nobody answers it.
4. Nobody approves the plan. Start the work as soon as it is linked.

## Column check

Slug a column label from the prompt: lowercase, with hyphens for spaces. Compare the slug with the card `status`.

## Provision the card worktree

Run these from the main checkout, before `EnterWorktree`. `<short-slug>` is two to four lowercase words from the card title, joined with hyphens.

```bash
git fetch origin
git worktree list --porcelain | grep -x "worktree $PWD/.claude/worktrees/card-<number>"
```

When the grep prints a line, the worktree exists. Skip the `git worktree add` and run `just worktree-up` only.

```bash
git worktree add -b card-<number>-<short-slug> .claude/worktrees/card-<number> origin/main
just worktree-up card-<number>
```

When the branch exists and the worktree does not, drop `-b` and name the branch: `git worktree add .claude/worktrees/card-<number> card-<number>-<short-slug>`. `just worktree-up NAME` only bootstraps a registered worktree, so it never creates one.

## Enter and verify the worktree

Call `EnterWorktree` with the absolute path. Then check both of these:

```bash
pwd
git worktree list --porcelain | grep -qx "worktree $(pwd)" && git branch --show-current
```

The first must print the worktree path. The second must print the card branch. Otherwise stop with `STAGE RESULT: blocked: worktree binding failed`.

## Reruns

1. A linked plan document whose `references` hold the tech design id is the plan. Reuse it, and create no second plan.
2. An open pull request on a branch that starts `card-<number>-` belongs to this card. Never cut a new branch from `origin/main` for it. Restore its `headRefName` with "Set up or refresh the worktree" in `../../loupe-stage-fix-round/references/pull-request-feedback.md`: fetch, prune, add the worktree on that branch, the fast-forward sync, `just worktree-up`, and `EnterWorktree`. Then run `git branch --show-current`. When it differs from `headRefName`, stop with `STAGE RESULT: blocked: worktree is not on the PR branch`. Otherwise resume at the gate.
3. Before `gh pr create`, run `gh pr list --head <branch> --state open`. Link a pull request it lists, and create none.

## Refresh after a sync

`just worktree-up NAME` runs `bin/worktrees/worktree-bootstrap.sh`. It copies the `vendor/` of the main checkout when `composer.json` and `composer.lock` match it, and runs `composer install` in the container when they differ. It also runs the migrations, `app:dev:seed`, `tailwind:build` and `cache:warmup`, and starts the sidecars. It clears no cache. The test database needs nothing, because `tests/bootstrap.php` rebuilds it on each run.

After any sync that brings commits, run it again from the main checkout. The main checkout is the first `worktree` line of `git worktree list --porcelain`. Then clear both caches from the worktree:

```bash
( cd <main checkout> && just worktree-up card-<number> )
bin/worktrees/compose-exec.sh bin/console cache:clear
bin/worktrees/compose-exec.sh bin/console cache:clear --env=test
```

## Long commands

A Bash call ends after 600000 ms. Start `just ci`, or the CI watch, with the Bash tool's `run_in_background`. Write its output to a log file, and append an exit marker when it ends:

```bash
just ci > <log> 2>&1; echo "EXIT=$?" >> <log>
```

Then wait in the foreground with this loop, and a Bash timeout of 600000:

```bash
for i in $(seq 1 57); do grep -q '^EXIT=' <log> && break; sleep 10; done; grep '^EXIT=' <log> || echo still-running; tail -25 <log>
```

One loop waits 570 seconds at most. When it prints `still-running`, run it again. `EXIT=0` means the command passed. This loop ran successfully once, for a full `just ci` in a worktree.

Never start `just ci` again over a run you killed. A killed host wrapper leaves PHPUnit running in the shared php-fpm container, and a second run collides with it. Find and stop it as `project-worktrees` says.

## The gate

Run these in the worktree, in order, as `working-with-prs` "The gate, before you open anything" says:

```bash
git fetch origin
git merge origin/main
just cs
just ci
php bin/changelog.php --check
```

When `git merge origin/main` conflicts, resolve it only when the conflict is mechanical and the gate then proves the result. Otherwise run `git merge --abort`, and stop with `STAGE RESULT: blocked: merge conflict with main in <files>`.

When `git merge origin/main` brings commits, refresh the worktree as "Refresh after a sync" says, before `just cs`.

Commit what `just cs` changes. Then run the Codex review with `mcp__codex-cli__review` and `model: "gpt-6-astra"`. `working-with-prs` asks for two clean passes in a row, and for a commit scope once the branch has more than one commit. Alternate the scope: one pass with `base: "origin/main"`, the next with `commit: "<sha>"` for the newest commit that carries work. Count a pass as clean only against the current tree. Read each summary, and check that it covers the largest change. Before you act on a finding, read the file at HEAD, and dismiss a finding that HEAD already fixes. Run `git status` after each pass. When the Codex MCP is missing, stop with `STAGE RESULT: blocked: codex MCP unavailable`.

## Open the pull request

```bash
git push -u origin HEAD
gh pr create --base main --title "<type>(<area>): <summary>" --body-file <file>
```

Keep the body to the shape `working-with-prs` gives. The card page route is `/projects/{projectId}/board/cards/{cardId}`, in `src/Module/Board/Controller/ShowCardController.php`. The card URL is therefore `<instance>/projects/<projectId>/board/cards/<cardId>`. Take the instance from the prompt line `Loupe instance <url>.`, and the project id from the prompt. When the prompt lacks either, write `Loupe card <number>` instead.

Then write `changelog.d/<pr number>.md` in the `working-with-prs` format. Run `php bin/changelog.php --check`, commit, and push.

## Wait for CI

First wait until checks exist for the pushed head:

```bash
git rev-parse HEAD
gh pr view <url> --json headRefOid,statusCheckRollup
```

The `headRefOid` must equal the local head, and `statusCheckRollup` must hold entries. Then watch in the background, with the loop from "Long commands", for 60 minutes at most:

```bash
gh pr checks <url> --required --watch --fail-fast --interval 60
```

Confirm the result as `working-with-prs` "Merging" item 8 says:

```bash
gh pr checks <url> --required --json bucket -q 'group_by(.bucket)|map("\(.[0].bucket)=\(length)")|join(" ")'
```

Green means `pass=<required count>` and no other bucket. Read the required count from the ruleset command in `working-with-prs` "What the ruleset actually requires". Read a failed `e2e` in its shard job, `e2e-chromium` or `e2e-rest`.

After each fix, run the gate again before you push.

## Record a block

Read the card with `card_get`. Send its whole `body` back with `card_update`, plus one final paragraph that starts `Blocked:`. The paragraph names the failing check or the timeout, the branch, and the pull request URL.
