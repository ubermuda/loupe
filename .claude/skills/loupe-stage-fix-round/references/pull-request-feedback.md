# Pull request feedback commands

Run these from a checkout of the repository. `gh` fills `{owner}` and `{repo}` from it. `<n>` is the pull request number.

## Find the open pull request

```bash
gh pr view <url> --json state,number,headRefName,headRefOid
```

## Read the checks before a worktree exists

Compare the checks against the `headRefOid` above, never against a local head. Wait until `gh pr view <url> --json headRefOid,statusCheckRollup` shows entries for that SHA. Then wait and count as "Wait for CI" in `../../loupe-stage-implementation/references/commands.md` says.

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

Read the reviews and the inline comments too:

```bash
gh pr view <url> --json reviews,reviewDecision
gh api repos/{owner}/{repo}/pulls/<n>/comments --paginate
```

## Decide which threads to act on

1. Act on a thread only when `isResolved` is `false`.
2. Read the worker login with `gh api user -q .login`.
3. Read the date of the head commit with `gh api repos/{owner}/{repo}/commits/<headRefOid> -q .commit.committer.date`.
4. Skip a thread whose last comment comes from the worker login and is newer than that date. The worker already answered it.
5. An outdated thread (`isOutdated`) can still ask for a change. Read it against the current code.
6. When a review still requests changes and no thread is left to act on, stop with `STAGE RESULT: blocked: changes requested with no open thread`.

## Reply to a thread

Reply after the fix is pushed. Say what changed and name the commit. Take the `databaseId` of the first comment in the thread:

```bash
gh api repos/{owner}/{repo}/pulls/<n>/comments/<databaseId>/replies -f body='<what changed, in commit <sha>>'
```

Never resolve a thread. The reviewer resolves it.

## Set up or refresh the worktree

Run these from the main checkout. Find the worktree with the porcelain grep:

```bash
git worktree list --porcelain | grep -x "worktree $PWD/.claude/worktrees/card-<number>"
```

When the grep prints nothing, create it:

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
