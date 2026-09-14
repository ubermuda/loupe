# Implementation stage commands

`SKILL.md` names each step. This file holds the detail. `project-worktrees` and `working-with-prs` stay the authority when they disagree with this file.

## Borrowed skills

1. Use `superpowers-extended-cc:writing-plans` for the plan format only. Submit the plan to Loupe. Save no file under `docs/superpowers`, and skip its question about how to execute.
2. Use `superpowers-extended-cc:subagent-driven-development` for the task loop only. Never invoke `superpowers-extended-cc:finishing-a-development-branch`. Never merge locally, and never remove the worktree.
3. Never call `AskUserQuestion`. Nobody answers it.

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
2. An open pull request on a branch that starts `card-<number>-` belongs to this card. Enter its worktree, and resume at the gate.
3. Before `gh pr create`, run `gh pr list --head <branch> --state open`. Link a pull request it lists, and create none.

## Long commands

A Bash call ends after 600000 ms. Run `just ci` and the CI wait with the Bash tool's `run_in_background`. Wait for the task to end with the `Monitor` tool. This path is untested.

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

Commit what `just cs` changes. Then run the Codex review with `mcp__codex-cli__review` and `model: "gpt-6-astra"`. Scope it to `origin/main` for a branch with one commit, and to the newest commit with `commit: "<sha>"` otherwise. Repeat until two passes in a row come back clean. Run `git status` after each pass. When the Codex MCP is missing, stop with `STAGE RESULT: blocked: codex MCP unavailable`.

## Open the pull request

```bash
git push -u origin HEAD
gh pr create --base main --title "<type>(<area>): <summary>" --body-file <file>
```

Keep the body to the shape `working-with-prs` gives. The card URL is `<instance>/projects/<projectId>/board/cards/<cardId>`, as `loupe-board` says. Take the instance and the project id from the prompt. When the prompt lacks them, write `Loupe card <number>` instead.

Then write `changelog.d/<pr number>.md` in the `working-with-prs` format. Run `php bin/changelog.php --check`, commit, and push.

## Wait for CI

First wait until checks exist for the pushed head:

```bash
git rev-parse HEAD
gh pr view <url> --json headRefOid,statusCheckRollup
```

The `headRefOid` must equal the local head, and `statusCheckRollup` must hold entries. Then watch, in the background, for 60 minutes at most:

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
