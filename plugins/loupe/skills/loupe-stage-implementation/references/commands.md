# Implementation stage detail

`SKILL.md` names each step. This file holds the detail. The repository profile and the two adapters hold every value that belongs to one repository, one harness or one forge.

## Load the adapters and the profile

1. Load the adapter for your harness from `harnesses/<harness>.md` in this directory. Use `harnesses/generic.md` when no exact adapter exists.
2. Read the repository profile at `.loupe/lifecycle.md` in the repository root. When the file, or a section a step needs, is missing, stop with `STAGE RESULT: blocked: no <section> in .loupe/lifecycle.md`.
3. Pick the forge adapter as the next section says.

The profile has these sections: `Instruction files`, `Environment`, `Gate`, `Code review`, `Changelog`, `Pull request`, `Board` and `Merge`. The harness adapter covers these steps: connect to the Loupe tools, load an instruction, run a long command, dispatch a sub-agent, write a plan, and run the plan task by task.

## Pick the forge adapter

The stage skills name forge operations. An adapter file maps them to commands for one forge. The operations are these: find and validate a pull request, list feedback items, reply to a thread, post a top-level comment, and post a refusal comment. Also read checks and failed logs, create a pull request, and check mergeability. The merge stage adds four more: read the merge state, check the approval covers the head, update the branch, and merge.

1. Read the forge from `pullRequests[].forge` in `card_get`: `github`, `gitlab`, `bitbucket` or `other`.
2. Before a pull request exists, read the host of `git remote get-url origin`. `github.com` is `github`, a `gitlab` host is `gitlab`, `bitbucket.org` is `bitbucket`, and any other host is `other`.
3. Read `forges/<forge>.md` in this directory.
4. When no such file exists, stop with `STAGE RESULT: blocked: no forge adapter for <forge>`.

## Column check

Slug a column label from the prompt: lowercase, with hyphens for spaces. Compare the slug with the card `status`.

## Check the worker folder

The worker folder is the folder the worker starts in. The bridge rules make it and remove it, and a stage never does. A worker with no rule for its folder starts in the project directory. `<base>` is the base branch from the profile `Gate` section.

When the profile `Environment` section names a folder check, run it first. When the check fails, stop with `STAGE RESULT: blocked: no worker folder`. Then run this in the worker folder:

```bash
git branch --show-current
```

1. When it prints nothing, HEAD is detached. Run `git switch -c card-<number>-<short-slug>`. `<short-slug>` is two to four lowercase words from the card title, joined with hyphens.
2. When it prints `<base>`, run the same `git switch -c` command.
3. When it prints a branch that starts `card-<number>-`, keep that branch.
4. When it prints any other branch, stop with `STAGE RESULT: blocked: worker folder on branch <branch>`.

## Reruns

1. A linked plan document whose `references` hold the tech design id is the plan. Reuse it, and create no second plan.
2. An open pull request on a branch that starts `card-<number>-` belongs to this card. Never cut a new branch for it. Run `git branch --show-current`. When HEAD is detached, or on another `card-<number>-` branch and `git status --porcelain` prints nothing, run `git fetch origin <head>` and `git switch <head>`. When no local branch has that name, run `git switch --track -c <head> origin/<head>` instead. When the current branch then differs from the head branch, stop with `STAGE RESULT: blocked: worker folder is not on the PR branch`.
3. Sync that branch with `git fetch origin <head>` and `git merge --ff-only origin/<head>`. When the merge fails, stop with `STAGE RESULT: blocked: local branch diverged from origin`. Never force-push. When the switch or the sync brought commits, run the refresh of the profile `Environment` section, when it names one. Then resume at the gate.
4. Before you create a pull request, list the open pull requests for the branch with the forge adapter. Link one it lists, and create none.

## The gate

Run these in the worker folder, in order:

```bash
git fetch origin
git merge origin/<base>
```

When the merge conflicts, resolve it only when the conflict is mechanical and the gate then proves the result. Otherwise run `git merge --abort`, and stop with `STAGE RESULT: blocked: merge conflict with <base> in <files>`.

When the merge brings commits, run the refresh of the profile `Environment` section, when it names one. A profile command may use `<cardId>`. Take it from the prompt line `Card <number> (cardId <id>)`, or from the `cardId` of `card_get` when the prompt has none. Never derive it from a branch name, a folder name or a card number.

Then run the commands of the profile `Gate` section in order. Run each long command as the harness adapter says. Poll it in the foreground until it exits, and never end the turn while it runs. Run the check of the profile `Changelog` section. Then run the review of the profile `Code review` section, and follow its pass rule.

## Open the pull request

Push the branch, and create the pull request with the forge adapter. Follow the profile `Pull request` section for the title, the body and the ready state.

A branch that holds the commits of another open pull request stacks on it. The test is `git merge-base --is-ancestor origin/<parent branch> HEAD`, which exits 0, while that pull request is open. Then create the pull request with `<base>` set to the parent's head branch, never the profile base branch. Write `Stacks on #<parent>. Merge #<parent> first.` in the body.

Put the card URL in the body. The card page route is `/projects/{projectId}/board/cards/{cardId}`, so the URL is `<instance>/projects/<projectId>/board/cards/<cardId>`. Take the instance from the prompt line `Loupe instance <url>.`, and the project id from the prompt. When the prompt lacks either, write `Loupe card <number>` instead.

Then write the changelog entry that the profile `Changelog` section names, run its check, commit, and push.

## After the push

The stage ends at the push. The app reads the pull request again after each push, so no stage waits for CI. A failed check or a conflict reaches a fix round as a fix request. Green checks move the card, and an approved pull request with green checks reaches the merge stage. Report `waiting`, and name the pull request URL.

## Record a block

Read the card with `card_get`. Send its whole `body` back with `card_update`, plus one final paragraph that starts `Blocked:`. The paragraph names the reason, the branch, and the pull request URL when one exists.

## Post a refusal comment

A run that acts on a pull request posts one comment when it ends `STAGE RESULT: not ready <url>: <reason>` or `STAGE RESULT: blocked: <reason>`. The comment tells a person on the pull request why the work stopped.

1. Post no comment for `waiting`. Post none for a fault that an approver cannot fix on the pull request: `no worker folder`, `codex MCP unavailable` or `preview not seeded`. `loupe MCP unavailable` is not a `blocked:` form, so it posts none either. Post none for a state that clears with no person: `not behind`, a head that moved after the event, or a check that is still pending.
2. Make the reason key. Lowercase the reason, and turn each run of characters outside `a-z` and `0-9` into one hyphen. Remove a hyphen at the start or the end.
3. Read the head commit with the forge adapter. The marker line is `<!-- loupe-refusal: <head sha> <reason key> -->`.
4. List the top-level comments with the forge adapter. When a comment holds the same marker, post nothing. A new head or a new reason posts again.
5. Post the comment with the forge adapter. Start the body with the marker line. Then write the reason, and the next step from the first sentence after the result line.
6. Never edit, hide or delete a refusal comment when the block clears.
7. When the post fails, keep the same result line. Say in the sentences after it that the comment failed.
