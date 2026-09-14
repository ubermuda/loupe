# Implementation stage commands

`SKILL.md` names each step. This file holds the exact commands. `project-worktrees` and `working-with-prs` stay the authority when they disagree with this file.

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

## Long commands

A Bash call ends after 600000 ms. Pass that timeout to `just ci` and to each CI wait. When a call hits the limit, run it again, and keep count of the total time.

## Open the pull request

```bash
git push -u origin HEAD
gh pr create --base main --title "<type>(<area>): <summary>" --body-file <file>
```

Put the card URL in the body: `<instance>/projects/<projectId>/board/cards/<cardId>`. Keep the body to the shape `working-with-prs` gives. Then write `changelog.d/<pr number>.md` in the `working-with-prs` format, commit it, and push.

## Wait for CI

```bash
gh pr checks <url> --required --watch --fail-fast --interval 60
```

Repeat the call after each timeout, up to 60 minutes in total. Then confirm the result against the pushed head, as `working-with-prs` "Merging" item 8 says:

```bash
git rev-parse HEAD
gh pr view <url> --json headRefOid -q .headRefOid
gh pr checks <url> --required --json bucket -q 'group_by(.bucket)|map("\(.[0].bucket)=\(length)")|join(" ")'
```

The two SHAs must match. Green means `pass=<required count>` and no other bucket. Read a failed `e2e` in its shard job, `e2e-chromium` or `e2e-rest`.

## Record a block

Read the card with `card_get`. Send its whole `body` back with `card_update`, plus one final paragraph that starts `Blocked:`. The paragraph names the failing check or the timeout, the branch, and the pull request URL.
