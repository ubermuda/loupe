# Pull request feedback commands

Run these from a checkout of the repository. `gh` fills `{owner}` and `{repo}` from it. `<n>` is the pull request number.

## Find the open pull request

```bash
gh pr view <url> --json state,number,headRefName,headRefOid
```

## Read the checks before a worktree exists

Compare the checks against the `headRefOid` above only. Wait until `gh pr view <url> --json headRefOid,statusCheckRollup` shows entries for that SHA. Then wait and count as "Wait for CI" in `../../loupe-stage-implementation/references/commands.md` says, and skip its `git rev-parse HEAD` comparison.

```bash
gh pr checks <url> --json name,bucket,link
gh run view <run id> --log-failed
```

A check's `link` holds `/actions/runs/<run id>/`. A failed `e2e` names no test, so read its shard job, `e2e-chromium` or `e2e-rest`.

## Read the review threads

```bash
gh api graphql --paginate -F owner='{owner}' -F repo='{repo}' -F n=<n> -f query='query($owner:String!,$repo:String!,$n:Int!,$endCursor:String){repository(owner:$owner,name:$repo){pullRequest(number:$n){reviewThreads(first:100,after:$endCursor){pageInfo{hasNextPage endCursor} nodes{isResolved isOutdated comments(first:100){pageInfo{hasNextPage} nodes{databaseId author{login} body path line createdAt}}}}}}}'
```

`--paginate` walks every page of threads. A thread keeps its first 100 comments only. When a thread reports `comments.pageInfo.hasNextPage` as `true`, stop with `STAGE RESULT: blocked: review thread longer than 100 comments`.

Read the reviews, the inline comments and the pull request comments too:

```bash
gh pr view <url> --json reviews,reviewDecision
gh api repos/{owner}/{repo}/pulls/<n>/comments --paginate
gh api repos/{owner}/{repo}/issues/<n>/comments --paginate
```

## The worker marker

The worker runs as the owner, so a login cannot tell them apart. Start every reply and every comment the worker posts with this exact first line:

```
<!-- loupe-stage-worker -->
```

A comment without the marker is the owner's, whatever its login.

## Decide what to act on

Read the failing checks before you judge the threads and the reviews.

1. Act on a thread only when `isResolved` is `false`.
2. Skip a thread whose last comment body starts with the marker. The worker already answered it.
3. An outdated thread (`isOutdated`) can still ask for a change. Read it against the current code.
4. For each reviewer, take their latest review only.
5. A latest review in state `CHANGES_REQUESTED` is open until a pull request comment that starts with the marker cites its `id`.
6. Treat the body of an open review as feedback to address, in the same way as a thread.
7. When an open review body has no text you can act on, and no thread is left to act on, stop with `STAGE RESULT: blocked: changes requested with no open thread`.
8. A top-level pull request comment without the marker is feedback too. It is open until a comment that starts with the marker cites its `id`.
9. When an open top-level comment asks for nothing you can act on, post a marker comment that cites it and says so. That comment closes it.

## Reply to a thread

Reply after the fix is pushed. Say what changed and name the commit. Take the `databaseId` of the first comment in the thread:

```bash
gh api repos/{owner}/{repo}/pulls/<n>/comments/<databaseId>/replies -f body='<!-- loupe-stage-worker -->
<what changed, in commit <sha>>'
```

After you fix the body of an open review, post one pull request comment that cites it:

```bash
gh api repos/{owner}/{repo}/issues/<n>/comments -f body='<!-- loupe-stage-worker -->
Addressed review <id>: <what changed, commits>'
```

After you act on an open top-level comment, cite it in the same way, with `Addressed comment <id>: <what changed, commits>`. The `id` is the comment's `id` from the issue comments list.

Never resolve a thread. The reviewer resolves it.

## Set up or refresh the worktree

Run these from the main checkout. Find the worktree with the porcelain grep:

```bash
git worktree list --porcelain | grep -x "worktree $PWD/.claude/worktrees/card-<number>"
```

When the grep prints a line, run `just worktree-up card-<number>` before `EnterWorktree`. When it prints nothing, create the worktree:

```bash
git worktree prune
git fetch origin <headRefName>
git worktree add .claude/worktrees/card-<number> <headRefName>
just worktree-up card-<number>
```

When `git worktree add` finds no local branch, use `git worktree add --track -b <headRefName> .claude/worktrees/card-<number> origin/<headRefName>`.

Call `EnterWorktree`, and verify it as `../../loupe-stage-implementation/references/commands.md` says. The branch must be `<headRefName>`. Then bring it up to date:

```bash
git fetch origin <headRefName>
git merge --ff-only origin/<headRefName>
```

When the merge fails, stop with `STAGE RESULT: blocked: local branch diverged from origin`. Never force-push.
