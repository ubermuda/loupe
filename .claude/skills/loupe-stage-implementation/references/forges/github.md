# GitHub adapter

The stage skills read this file when the forge is `github`. It maps each forge operation to `gh` commands. Run them from a checkout of the repository.

`<n>` is the pull request number. `<nameWithOwner>` is the repository of the checkout, such as `ubermuda/loupe`. Split it into `<owner>` and `<repo>` where a command needs both.

## Find and validate the pull request

Run this before any other query or reply:

```bash
gh repo view --json nameWithOwner
gh api graphql -F url=<url> -f query='query($url:URI!){resource(url:$url){... on PullRequest{number state headRefName headRefOid isCrossRepository baseRepository{nameWithOwner}}}}'
```

`state` is `OPEN`, `CLOSED` or `MERGED`. The head branch is `headRefName`, and the head commit is `headRefOid`. When `baseRepository.nameWithOwner` differs from the checkout, or `isCrossRepository` is `true`, the pull request is outside this repository.

## List the feedback items

```bash
gh api graphql --paginate -F owner='<owner>' -F repo='<repo>' -F n=<n> -f query='query($owner:String!,$repo:String!,$n:Int!,$endCursor:String){repository(owner:$owner,name:$repo){pullRequest(number:$n){reviewThreads(first:100,after:$endCursor){pageInfo{hasNextPage endCursor} nodes{id isResolved comments(first:100){pageInfo{hasNextPage} nodes{databaseId author{login} body}}}}}}}'
gh api repos/<nameWithOwner>/pulls/<n>/reviews --paginate
gh api repos/<nameWithOwner>/issues/<n>/comments --paginate
```

1. A review thread is a node of `reviewThreads`. Its id is the node `id`, and it is resolved when `isResolved` is `true`.
2. A review body is the `body` of an entry in the reviews list. Its id is the review `id`.
3. A top-level comment is an entry in the issue comments list. Its id is the comment `id`.
4. `--paginate` walks every page of threads. A thread keeps its first 100 comments. When `comments.pageInfo.hasNextPage` is `true`, the thread is too long to read.

## Reply to a thread

Take the `databaseId` of the first comment in the thread:

```bash
gh api repos/<nameWithOwner>/pulls/<n>/comments/<databaseId>/replies -f body='<!-- loupe-stage-worker -->
Addressed thread <id>: <what changed, commits>'
```

## Post a top-level comment

```bash
gh api repos/<nameWithOwner>/issues/<n>/comments -f body='<!-- loupe-stage-worker -->
Addressed review <id>: <what changed, commits>'
```

## Read the checks

Wait until checks exist for the head commit, then watch them:

```bash
gh pr view <url> --json headRefOid,statusCheckRollup
gh pr checks <url> --required --watch --fail-fast --interval 60
gh pr checks <url> --required --json bucket -q 'group_by(.bucket)|map("\(.[0].bucket)=\(length)")|join(" ")'
```

Green means `pass=<required count>` and no other bucket. The repository profile says where the list of required checks comes from.

A check's `link` holds `/actions/runs/<run id>/`. Read the failed steps of that run:

```bash
gh pr checks <url> --json name,bucket,link
gh run view <run id> --log-failed
```

## Create a pull request

```bash
git push -u origin HEAD
gh pr list --head <branch> --state open --json url
gh pr create --base <base> --title "<title>" --body-file <file>
```

Link a pull request that `gh pr list` returns, and create none.

## Check mergeability

```bash
gh pr view <url> --json mergeable,mergeStateStatus
```

`mergeable` is `MERGEABLE`, `CONFLICTING` or `UNKNOWN`. `UNKNOWN` means GitHub still computes it, so read it again after a short wait.
