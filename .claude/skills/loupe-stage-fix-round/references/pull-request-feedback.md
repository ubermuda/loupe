# Pull request feedback

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

## Read the feedback items

```bash
gh api graphql --paginate -F owner='{owner}' -F repo='{repo}' -F n=<n> -f query='query($owner:String!,$repo:String!,$n:Int!,$endCursor:String){repository(owner:$owner,name:$repo){pullRequest(number:$n){reviewThreads(first:100,after:$endCursor){pageInfo{hasNextPage endCursor} nodes{id isResolved comments(first:100){pageInfo{hasNextPage} nodes{databaseId author{login} body}}}}}}}'
gh api repos/{owner}/{repo}/pulls/<n>/reviews --paginate
gh api repos/{owner}/{repo}/issues/<n>/comments --paginate
```

When a thread reports `comments.pageInfo.hasNextPage` as `true`, stop with `STAGE RESULT: blocked: review thread longer than 100 comments`.

A feedback item is one of these:

1. A review with a non-empty `body`. Its id is the review `id`.
2. A review thread. Its id is the thread `id`.
3. A top-level pull request comment. Its id is the comment `id`.

The review state, such as `APPROVED` or `CHANGES_REQUESTED`, plays no part.

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

For a thread, reply inside the thread, with `Addressed thread <id>` or `No change for thread <id>`. Take the `databaseId` of its first comment:

```bash
gh api repos/{owner}/{repo}/pulls/<n>/comments/<databaseId>/replies -f body='<!-- loupe-stage-worker -->
Addressed thread <id>: <what changed, commits>'
```

For a review body or a top-level comment, post a top-level comment. Name the kind of the item, `review` or `comment`:

```bash
gh api repos/{owner}/{repo}/issues/<n>/comments -f body='<!-- loupe-stage-worker -->
Addressed review <id>: <what changed, commits>'
gh api repos/{owner}/{repo}/issues/<n>/comments -f body='<!-- loupe-stage-worker -->
No change for comment <id>: <reason>'
```

The other two forms follow the same shape: `No change for review <id>` and `Addressed comment <id>`.

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
