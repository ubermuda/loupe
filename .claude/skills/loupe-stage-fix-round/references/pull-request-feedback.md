# Pull request feedback

This file describes the feedback model in forge terms: pull request, review thread, review body, top-level comment, check, head branch and base repository. The commands for a forge live in its adapter. Pick the adapter as "Pick the forge adapter" in `../../loupe-stage-implementation/references/commands.md` says.

## Find the open pull request

Before any other query or reply, find and validate the pull request with the adapter. When its base repository is not the repository of the checkout, or its head branch lives in another repository, stop with `STAGE RESULT: blocked: pull request outside this repository`.

## Read the checks before a worktree exists

Compare the checks against the head commit of the pull request only. Wait until checks exist for that commit. Then wait and count as "Wait for CI" in `../../loupe-stage-implementation/references/commands.md` says, and skip its comparison with the local head. Read the failed logs of each failing check with the adapter.

## Read the feedback items

List the feedback items with the adapter, and walk every page. When a thread is too long to read, stop with `STAGE RESULT: blocked: review thread longer than 100 comments`.

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

## Set up or refresh the worktree

Run these from the main checkout. Find the worktree with the porcelain grep:

```bash
git worktree list --porcelain | grep -x "worktree $PWD/.claude/worktrees/card-<number>"
```

When the grep prints nothing, create the worktree on the head branch:

```bash
git worktree prune
git fetch origin <head branch>
git worktree add .claude/worktrees/card-<number> <head branch>
```

When `git worktree add` finds no local branch, use `git worktree add --track -b <head branch> .claude/worktrees/card-<number> origin/<head branch>`.

For an existing worktree, first run `git -C .claude/worktrees/card-<number> branch --show-current`. When it differs from the head branch, change nothing, and stop with `STAGE RESULT: blocked: worktree is not on the PR branch`.

Sync the branch before you provision it, for a new or an existing worktree:

```bash
git -C .claude/worktrees/card-<number> fetch origin <head branch>
git -C .claude/worktrees/card-<number> merge --ff-only origin/<head branch>
```

When the merge fails, stop with `STAGE RESULT: blocked: local branch diverged from origin`. Never force-push.

Then provision it with `just worktree-up card-<number>`. When the sync brought commits, also clear both caches, as "Refresh after a sync" in `../../loupe-stage-implementation/references/commands.md` says. Call `EnterWorktree`, and verify it as that file says. The branch must be the head branch.
