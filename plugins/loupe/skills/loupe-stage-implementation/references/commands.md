# Implementation stage detail

`SKILL.md` names each step. This file holds the detail. The repository profile and the two adapters hold every value that belongs to one repository, one harness or one forge.

## Load the adapters and the profile

1. Load the adapter for your harness from `harnesses/<harness>.md` in this directory. Use `harnesses/generic.md` when no exact adapter exists.
2. Read the repository profile at `.loupe/lifecycle.md` in the repository root. When the file, or a section a step needs, is missing, stop with `STAGE RESULT: blocked: no <section> in .loupe/lifecycle.md`.
3. Pick the forge adapter as the next section says.

The profile has these sections: `Instruction files`, `Worktree`, `Gate`, `Code review`, `Changelog` and `Pull request`. The harness adapter covers these steps: connect to the Loupe tools, load an instruction, bind writes to the worktree, run a long command, dispatch a sub-agent, write a plan, and run the plan task by task.

## Pick the forge adapter

The stage skills name forge operations: find and validate a pull request, list feedback items, reply to a thread, post a top-level comment, read checks and failed logs, create a pull request, and check mergeability. An adapter file maps them to commands for one forge.

1. Read the forge from `pullRequests[].forge` in `card_get`: `github`, `gitlab`, `bitbucket` or `other`.
2. Before a pull request exists, read the host of `git remote get-url origin`. `github.com` is `github`, a `gitlab` host is `gitlab`, `bitbucket.org` is `bitbucket`, and any other host is `other`.
3. Read `forges/<forge>.md` in this directory.
4. When no such file exists, stop with `STAGE RESULT: blocked: no forge adapter for <forge>`.

## Column check

Slug a column label from the prompt: lowercase, with hyphens for spaces. Compare the slug with the card `status`.

## Create the card worktree

The profile `Worktree` section names the card worktree path and the command that provisions it. `<base>` is the base branch from the profile `Gate` section. `<short-slug>` is two to four lowercase words from the card title, joined with hyphens. `<cardId>` is the card id from the prompt line `Card <number> (cardId <id>)`, or the `cardId` of `card_get` when the prompt has none. A profile command may use `<cardId>`. Pass it as the command says, and never derive it from a branch name, a worktree name or a card number. Run these from the main checkout:

```bash
git fetch origin
git worktree list --porcelain | grep -x "worktree $PWD/<card worktree>"
```

When the grep prints a line, the worktree exists. Skip `git worktree add`, and provision it only.

```bash
git worktree add -b card-<number>-<short-slug> <card worktree> origin/<base>
```

When the branch exists and the worktree does not, drop `-b` and name the branch: `git worktree add <card worktree> card-<number>-<short-slug>`. Then provision the worktree as the profile says.

## Bind writes and verify

Bind writes to the card worktree as the harness adapter says. The working directory must be the worktree, and `git branch --show-current` must print the card branch. Otherwise stop with `STAGE RESULT: blocked: worktree binding failed`.

## Reruns

1. A linked plan document whose `references` hold the tech design id is the plan. Reuse it, and create no second plan.
2. An open pull request on a branch that starts `card-<number>-` belongs to this card. Never cut a new branch from `origin/<base>` for it. Restore its head branch with "Set up or refresh the worktree" in `../../loupe-stage-fix-round/references/pull-request-feedback.md`. Then run `git branch --show-current`. When it differs from the head branch, stop with `STAGE RESULT: blocked: worktree is not on the PR branch`. Otherwise resume at the gate.
3. Before you create a pull request, list the open pull requests for the branch with the forge adapter. Link one it lists, and create none.

## The gate

Run these in the worktree, in order:

```bash
git fetch origin
git merge origin/<base>
```

When the merge conflicts, resolve it only when the conflict is mechanical and the gate then proves the result. Otherwise run `git merge --abort`, and stop with `STAGE RESULT: blocked: merge conflict with <base> in <files>`.

When the merge brings commits, refresh the worktree as the profile `Worktree` section says.

Then run the commands of the profile `Gate` section in order. Run each long command as the harness adapter says. Poll it in the foreground until it exits, and never end the turn while it runs. Run the check of the profile `Changelog` section. Then run the review of the profile `Code review` section, and follow its pass rule.

## Open the pull request

Push the branch, and create the pull request with the forge adapter. Follow the profile `Pull request` section for the title, the body and the ready state.

A branch that holds the commits of another open pull request stacks on it. The test is `git merge-base --is-ancestor origin/<parent branch> HEAD`, which exits 0, while that pull request is open. Then create the pull request with `<base>` set to the parent's head branch, never the profile base branch. Write `Stacks on #<parent>. Merge #<parent> first.` in the body.

The profile `Board` section names the column a ready pull request's card moves to. Read the slug there, never from `board_columns`, which can be missing.

Put the card URL in the body. The card page route is `/projects/{projectId}/board/cards/{cardId}`, so the URL is `<instance>/projects/<projectId>/board/cards/<cardId>`. Take the instance from the prompt line `Loupe instance <url>.`, and the project id from the prompt. When the prompt lacks either, write `Loupe card <number>` instead.

Then write the changelog entry that the profile `Changelog` section names, run its check, commit, and push.

## Wait for CI

Keep the SHA that you gated, reviewed and pushed. First wait until checks exist for that head. The head commit of the pull request must equal that SHA. Then watch the required checks with the forge adapter, as a long command, for 60 minutes at most. The profile `Gate` section says which checks are required. A stacked pull request has no required checks, because the ruleset covers the profile base branch only. Run the check commands of the forge adapter without `--required`, and count every check.

Poll in the foreground, one tool call at a time, as "Run a long command" in the harness adapter shows. Never end the turn to wait for a notice. The run ends with your turn, and the watch dies with it.

When the wait ends, read the head commit of the pull request again with the forge adapter. Accept green only when the head still equals the gated SHA, and every required check passes with none pending. When the head moved, sync the branch, run the gate and the code review again, and push. Read each failed log with the forge adapter.

After each fix, run the gate again before you push.

## Record a block

Read the card with `card_get`. Send its whole `body` back with `card_update`, plus one final paragraph that starts `Blocked:`. The paragraph names the failing check or the timeout, the branch, and the pull request URL.
