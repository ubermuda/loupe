# Pull request feedback commands

Run these from a checkout of the repository. `gh` fills `{owner}` and `{repo}` from it. `<n>` is the pull request number.

## Find the open pull request

```bash
gh pr view <url> --json state,number,headRefName,headRefOid
```

## Read the feedback

Inline review comments:

```bash
gh api repos/{owner}/{repo}/pulls/<n>/comments --paginate
```

Unresolved review threads. Act on a thread only when `isResolved` is `false`:

```bash
gh api graphql -F owner='{owner}' -F repo='{repo}' -F n=<n> -f query='query($owner:String!,$repo:String!,$n:Int!){repository(owner:$owner,name:$repo){pullRequest(number:$n){reviewThreads(first:100){nodes{isResolved comments(first:20){nodes{body path line}}}}}}}'
```

Review verdicts and top-level review text:

```bash
gh pr view <url> --json reviews,reviewDecision
```

## Read the checks

```bash
gh pr checks <url> --json name,bucket,link
```

A check's `link` holds `/actions/runs/<run id>/`. Read the failed steps of that run:

```bash
gh run view <run id> --log-failed
```

A failed `e2e` names no test. Read its shard job, `e2e-chromium` or `e2e-rest`.

## Set up the worktree

Run these from the main checkout when `.claude/worktrees/card-<number>` does not exist:

```bash
git fetch origin <headRefName>
git worktree add .claude/worktrees/card-<number> <headRefName>
just worktree-up card-<number>
```

When `git worktree add` finds no local branch, use `git worktree add --track -b <headRefName> .claude/worktrees/card-<number> origin/<headRefName>`. Then call `EnterWorktree` and verify it, as `loupe-stage-implementation/references/commands.md` says. The branch must be `<headRefName>`, and `git log` must hold the pushed head.
