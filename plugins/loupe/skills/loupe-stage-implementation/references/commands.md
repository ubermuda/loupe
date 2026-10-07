# Implementation stage detail

`SKILL.md` names each step. This file holds the detail. The repository profile and the two adapters hold every value that belongs to one repository, one harness or one forge.

## Load the adapters and the profile

1. Load the adapter for your harness from `harnesses/<harness>.md` in this directory. Use `harnesses/generic.md` when no exact adapter exists.
2. Read the repository profile at `.loupe/lifecycle.md` in the repository root. When the file, or a section a step needs, is missing, stop with `STAGE RESULT: blocked: no <section> in .loupe/lifecycle.md`.
3. Pick the forge adapter as the next section says.

The profile has these sections: `Instruction files`, `Environment`, `Gate`, `Code review`, `Changelog`, `Pull request`, `Board`, `Merge` and `Repair`. The harness adapter covers these steps: connect to the Loupe tools, load an instruction, run a long command, dispatch a sub-agent, write a plan, and run the plan task by task.

## Pick the forge adapter

The stage skills name forge operations. An adapter file maps them to commands for one forge. The operations are these: find and validate a pull request, list feedback items, reply to a thread, post a top-level comment, and post a refusal comment. Also read checks and failed logs, create a pull request, and check mergeability. The merge stage adds five more: read the merge state, check the approval covers the head, compare with the base, update the branch, and merge.

1. Read the forge from `pullRequests[].forge` in `card_get`: `github`, `gitlab`, `bitbucket` or `other`.
2. Before a pull request exists, read the host of `git remote get-url origin`. `github.com` is `github`, a `gitlab` host is `gitlab`, `bitbucket.org` is `bitbucket`, and any other host is `other`.
3. Read `forges/<forge>.md` in this directory.
4. When no such file exists, stop with `STAGE RESULT: blocked: no forge adapter for <forge>`.

## Column check

Slug a column label from the prompt: lowercase, with hyphens for spaces. Compare the slug with the card `status`.

## Find the base branch

`<base>` is the base branch from the profile `Gate` section, with one exception. A card whose `card_get` `parent` is not null can belong to an epic branch, when the profile has an `Epics` section. Fill the epic branch pattern of that section with `parent.number`, and run this:

```bash
git ls-remote --exit-code origin refs/heads/<epic branch>
```

Exit 0 means the epic branch exists, so `<base>` is the epic branch. Any other exit means the epic started before its branch existed, so `<base>` stays the profile base branch. A profile with no `Epics` section, and a card with no parent, keep the profile base branch.

## Check the worker folder

The worker folder is the folder the worker starts in. The bridge configuration makes it and removes it, and a stage never does. A worker with no `before` command starts in the project directory. `<base>` comes from "Find the base branch".

When the profile `Environment` section names a folder check, run it first. When the check fails, stop with `STAGE RESULT: blocked: no worker folder`. Then run this in the worker folder:

```bash
git branch --show-current
```

1. When it prints nothing, HEAD is detached. Run `git fetch origin <base>` and `git switch -c card-<number>-<short-slug> origin/<base>`. The bridge can detach a new folder on another branch than `<base>`, so the new branch starts from `origin/<base>`. When the switch moves HEAD, run the refresh of the profile `Environment` section, when it names one. `<short-slug>` is two to four lowercase words from the card title, joined with hyphens.
2. When it prints `<base>`, run the same `git switch -c` command.
3. When it prints a branch that starts `card-<number>-`, keep that branch.
4. When it prints any other branch, stop with `STAGE RESULT: blocked: worker folder on branch <branch>`.

## Reruns

1. A linked document tagged `plan` whose `references` hold the tech design id is the plan. Reuse it, and create no second plan.
2. An open pull request on a branch that starts `card-<number>-` belongs to this card. Never cut a new branch for it. `<base>` is the base branch of that pull request. Run `git branch --show-current`. When HEAD is detached, or on another `card-<number>-` branch and `git status --porcelain` prints nothing, run `git fetch origin <head>` and `git switch <head>`. When no local branch has that name, run `git switch --track -c <head> origin/<head>` instead. When the current branch then differs from the head branch, stop with `STAGE RESULT: blocked: worker folder is not on the PR branch`.
3. Sync that branch as "Sync with the pull request branch" says. When the switch brought commits, run the refresh of the profile `Environment` section, when it names one. Then resume at the gate.
4. Before you create a pull request, list the open pull requests for the branch with the forge adapter. Link one it lists, and create none.

## Sync with the pull request branch

The implementation stage and the fix round sync the worker folder with this procedure. Run it in the worker folder, on the head branch `<head>` of the pull request. `<base>` is the base branch of that pull request.

1. Run `git fetch origin <head> <base>` and `git merge --ff-only origin/<head>`. When the merge succeeds, go on at item 7.
2. The branches diverged. When `git status --porcelain` prints a line, stop with `STAGE RESULT: blocked: local branch diverged from origin`. An earlier run left that work half done, and a person must judge it.
3. The commits that `git rev-list HEAD ^origin/<head> ^origin/<base>` lists are the work of earlier runs in this worker folder. Push them as your own. The list leaves out the base commits that a sync brought in.
4. Test each of these commits with the script below. A commit is a sync when it has two parents and one of them is an ancestor of `origin/<base>`. The script prints one `content` line for each commit that is not a sync.
5. When the script prints nothing, every local commit is a sync of the base. Run `git reset --hard origin/<head>`. The gate merges the base again, and the approval of the pull request still covers the head.
6. Otherwise run `git merge origin/<head>`. The fix round resolves a conflict as "Resolve a conflict with the base" in `../../loupe-stage-fix-round/references/pull-request-feedback.md` says, with `origin/<head>` in place of `origin/<base>`. The implementation stage resolves only a mechanical conflict, as the gate does. When a conflict cannot be resolved, run `git merge --abort`, and stop with `STAGE RESULT: blocked: local branch diverged from origin`.
7. When the sync brought commits, run the refresh of the profile `Environment` section, when it names one.

```bash
for c in $(git rev-list HEAD ^origin/<head> ^origin/<base>); do
  set -- $(git rev-list --parents -n 1 "$c")
  if [ $# -eq 3 ] && { git merge-base --is-ancestor "$2" origin/<base> || git merge-base --is-ancestor "$3" origin/<base>; }; then :; else echo "content $c"; fi
done
```

Never rebase, and never force-push. The reset changes only the local branch, and the merge loses no commit of the pull request branch.

## Push without force

1. Push with the command of the stage. Never pass `--force`, `--force-with-lease` or `--no-verify`.
2. When the forge rejects the push because the remote branch holds commits that HEAD does not, run "Sync with the pull request branch" again. Then run the gate again, and push again.
3. Push three times at most. When the third push is rejected for that reason, stop with `STAGE RESULT: blocked: local branch diverged from origin`.
4. A push that a hook or a branch protection rejects is not a divergence. Never route around it.

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

Push the branch as "Push without force" says, and create the pull request with the forge adapter, against `<base>`. Follow the profile `Pull request` section for the title, the body and the ready state.

A pull request into an epic branch is not a stacked pull request. Write `Child of epic #<parent number>, merges into <base>.` in the body. The merge stage merges it into the epic branch, and the epic pull request carries the work to the profile base branch.

A branch that holds the commits of another open pull request stacks on it. The test is `git merge-base --is-ancestor origin/<parent branch> HEAD`, which exits 0, while that pull request is open. Then create the pull request with `<base>` set to the parent's head branch, never the profile base branch. Write `Stacks on #<parent>. Merge #<parent> first.` in the body.

Put the card URL in the body. The card page route is `/projects/{projectId}/board/cards/{cardId}`, so the URL is `<instance>/projects/<projectId>/board/cards/<cardId>`. Take the instance from the prompt line `Loupe instance <url>.`, and the project id from the prompt. When the prompt lacks either, write `Loupe card <number>` instead.

Then write the changelog entry that the profile `Changelog` section names, run its check, commit, and push as "Push without force" says.

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
