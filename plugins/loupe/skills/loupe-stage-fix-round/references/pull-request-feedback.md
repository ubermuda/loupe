# Pull request feedback

This file describes the feedback model in forge terms: pull request, review thread, review body, top-level comment, check, head branch and base repository. The commands for a forge live in its adapter. Pick the adapter as "Pick the forge adapter" in `../../loupe-stage-implementation/references/commands.md` says. The round works in the folder the worker starts in, the worker folder. The bridge rules make that folder, and the round never creates or removes it.

## Find the open pull request

Before any other query or reply, find and validate the pull request with the adapter. When its base repository is not the repository of the checkout, or its head branch lives in another repository, stop with `STAGE RESULT: blocked: pull request outside this repository`.

## Read the mergeability first

Read the mergeability of the pull request as "Check mergeability" in the forge adapter says. A conflicting pull request runs no checks. For a conflicting pull request, skip the next section and read the feedback items. Resolve the conflict after you check the worker folder.

## Read the checks before you change the folder

Compare the checks against the head commit of the pull request only. Read them once, as "Read the checks" in the forge adapter says, and never wait for a pending check. The app reads the checks again after your push. Read the failed logs of each failing check with the adapter.

## Read the feedback items

List the feedback items with the adapter, and walk every page. When a thread is too long to read, stop with `STAGE RESULT: blocked: review thread too long to read`.

A feedback item is one of these:

1. A review with a non-empty body. Its id is the review id.
2. A review thread. Its id is the thread id.
3. A top-level pull request comment. Its id is the comment id.

The review state, such as approved or changes requested, plays no part.

## The worker marker

The worker runs as the owner, so a login cannot tell them apart. Start every reply and every comment the worker posts with this exact first line:

```
<!-- loupe-stage-worker -->
```

An item whose first line is the marker is the worker's own, and it is never feedback.

## Open and closed items

A thread is closed when it is resolved, or when its last comment is a marker reply. A newer reply without the marker opens the thread again.

A review body or a top-level comment cannot take a reply. It is closed when a marker comment cites its id in one of these forms:

```
Addressed <review|comment> <id>: <what changed, commits>
No change for <review|comment> <id>: <reason>
```

Every other item is open. With no open item and no failing check, stop with `STAGE RESULT: nothing to fix`.

## Close each item

Fix every open item and every failing check first. Then post one marker reply for each item you handled. Use `No change for` when the item asks for nothing you can act on.

1. For a thread, reply inside the thread with the adapter, with `Addressed thread <id>` or `No change for thread <id>`.
2. For a review body, post a top-level comment with `Addressed review <id>` or `No change for review <id>`.
3. For a top-level comment, post a top-level comment with `Addressed comment <id>` or `No change for comment <id>`.

Never resolve a thread. The reviewer resolves it.

## Check the worker folder

When the profile `Environment` section names a folder check, run it first. When the check fails, stop with `STAGE RESULT: blocked: no worker folder`. Then run this in the worker folder:

```bash
git branch --show-current
```

When it prints nothing, HEAD is detached. When it prints the base branch of the profile `Gate` section, treat it the same way. When it prints another `card-<number>-` branch and `git status --porcelain` prints nothing, treat it the same way too. Run `git fetch origin <head branch>` and `git switch <head branch>`. When no local branch has that name, run `git switch --track -c <head branch> origin/<head branch>` instead. When the current branch then differs from the head branch, change nothing, and stop with `STAGE RESULT: blocked: worker folder is not on the PR branch`.

Sync the branch:

```bash
git fetch origin <head branch>
git merge --ff-only origin/<head branch>
```

When the merge fails, stop with `STAGE RESULT: blocked: local branch diverged from origin`. Never force-push.

When the switch or the sync brought commits, run the refresh of the profile `Environment` section, when it names one. A profile command may name `<cardId>`. It is the card id from the prompt line `Card <number> (cardId <id>)`, or the `cardId` of `card_get` when the prompt has none. Never derive it from a branch name, a folder name or a card number.

## Resolve a conflict with the base

`<base>` is the base branch of the pull request, from the forge adapter. For a stacked pull request, it is the parent's branch. Run these in the worker folder:

```bash
git fetch origin
git merge origin/<base>
git diff --name-only --diff-filter=U
git log --oneline $(git merge-base HEAD origin/<base>)..origin/<base> -- <conflicting files>
```

The last command names the commits on the base that caused the conflict. Read them and the pull request body before you resolve. Keep the intent of both sides. This procedure replaces the rule of the gate that resolves only a mechanical conflict. The gate's own `git merge origin/<base>` then has nothing to merge.

Never rebase. A rebase drops the merge commits that the branch already holds, and asks for each earlier resolution again.

When the two sides change one behaviour in ways that cannot both hold, run `git merge --abort`. Then stop with `STAGE RESULT: blocked: merge conflict with <base> in <files>`.

Prove each resolved file `$F` in both directions:

```bash
comm -23 <(git show origin/<base>:$F | grep "^#" | sort) <(grep "^#" $F | sort)   # must be empty
comm -13 <(git show origin/<base>:$F | grep "^#" | sort) <(grep "^#" $F | sort)   # the branch's own additions
```

The first command must print nothing, because each line it prints is work of the base that the resolution dropped. The second must list only this branch's own additions. Choose the grep to suit the file: headings for prose, test method names for a test file, entry prefixes for a list. Then check the structure of the file. A union of both sides can leave two bodies and one closing brace, so absent markers prove nothing.

Keep the `# Conflicts:` block in the commit message, and add one plain line per file that says how it was resolved. `git commit -m` drops the block, and the queue holder reads it to verify the resolution. Commit like this:

```bash
m=$(git rev-parse --git-path merge-desc)
{ cat "$(git rev-parse --git-path MERGE_MSG)"; echo; echo "<file>: <how it was resolved>"; } > "$m"
git commit -F "$m" --cleanup=verbatim
```

Put the resolution and nothing else in the merge commit. The owner's approval covers a sync and a conflict resolution. It does not cover a rebase or new content. A fix for a check or a review goes in a later commit of its own, and it needs a new approval.

Then run the gate, and push without force. Never merge or approve the pull request.
