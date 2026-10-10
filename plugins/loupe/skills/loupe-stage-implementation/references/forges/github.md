# GitHub adapter

The stage skills read this file when the forge is `github`. It maps each forge operation to `gh` commands. Run them from a checkout of the repository.

`<n>` is the pull request number. `<nameWithOwner>` is the repository of the checkout, such as `ubermuda/loupe`. Split it into `<owner>` and `<repo>` where a command needs both.

## Find and validate the pull request

Run this before any other query or reply:

```bash
gh repo view --json nameWithOwner
gh api graphql -F url=<url> -f query='query($url:URI!){resource(url:$url){... on PullRequest{number state headRefName headRefOid isCrossRepository baseRepository{nameWithOwner}}}}'
```

`state` is `OPEN`, `CLOSED` or `MERGED`. Report state: `open`, `closed` or `merged`. The head branch is `headRefName`, and the head commit is `headRefOid`. When `baseRepository.nameWithOwner` differs from the checkout, or `isCrossRepository` is `true`, the pull request is outside this repository.

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

## Post a refusal comment

The head commit is `headRefOid` from "Find and validate the pull request". List the bodies of the top-level comments:

```bash
gh api repos/<nameWithOwner>/issues/<n>/comments --paginate --jq '.[].body'
```

When the command fails, the post fails. Otherwise search its output for the marker line with `grep -F`. A match means the comment exists, so post nothing. With no match, write the body to a file with a file tool, not with the shell, because a reason can hold a quote. The first line is the marker, and the second line is `<reason>. <next step>`. Then post the comment:

```bash
gh api repos/<nameWithOwner>/issues/<n>/comments -F body=@<body file>
```

## Read the checks

Read the checks once, and never wait for them. The app reads them again after each push.

```bash
gh pr view <url> --json headRefOid -q .headRefOid
gh pr checks <url> --required --json name,bucket,link
```

`bucket` is `pass`, `fail`, `pending`, `skipping` or `cancel`. The reading describes the head only when `headRefOid` equals the commit you care about. A rollup can still describe an earlier head, when a check has not yet started on the new head. The repository profile says where the list of required checks comes from.

A stacked pull request has no required checks when no ruleset covers its base. For it, drop `--required`, and count every check. `--required` fails there with "No required checks reported".

A pull request into an epic branch takes the required checks of the base branch of the profile `Merge` section, by name. Read the names, then every check:

```bash
gh api repos/<nameWithOwner>/rules/branches/<merge base> --jq '.[]|select(.type=="required_status_checks")|.parameters.required_status_checks[].context'
gh pr checks <url> --json name,bucket,link
```

Read the JSON whatever the exit code. Each name must have a check with the `bucket` `pass`. A name with no check counts as pending.

A check's `link` holds `/actions/runs/<run id>/`. Read the failed steps of that run:

```bash
gh run view <run id> --log-failed
```

## Create a pull request

```bash
git push -u origin HEAD
gh pr list --head <branch> --state open --json url
gh pr create --base <base> --title "<title>" --body-file <file>
```

Link a pull request that `gh pr list` returns, and create none.

To open a pull request from a branch that the checkout is not on, such as an epic branch, skip the push, and name the head:

```bash
gh pr list --head <branch> --base <base> --state open --json url
gh pr create --head <branch> --base <base> --title "<title>" --body-file <file> [--draft]
```

When `gh pr create` fails because the pull request already exists, run `gh pr list` again, and link the one it returns. GitHub refuses a pull request with no commits between the two branches.

## Read the pull request body

```bash
gh pr view <url> --json body -q .body
```

## Replace the pull request body

```bash
gh pr edit <url> --body-file <file>
```

The new body replaces the whole body. Read it first, change only your part, and write the rest back unchanged.

## Compare with the base

```bash
gh api repos/<nameWithOwner>/compare/<base>...<head sha> --jq .behind_by
```

`behind_by` counts the commits of the base that the head does not hold. A value above 0 means the branch is behind. When no strict rule covers the base, GitHub reports `CLEAN` for a behind pull request. Read the compare in that case.

Report behind count: `behind_by`.

## Check mergeability

```bash
gh pr view <url> --json mergeable,mergeStateStatus
```

`mergeable` is `MERGEABLE`, `CONFLICTING` or `UNKNOWN`. `UNKNOWN` means GitHub still computes it, so read it again after a short wait.

Report mergeability, as the next section says.

## Read the merge state

```bash
gh pr view <url> --json state,isDraft,headRefOid,baseRefName,reviewDecision,mergeable,mergeStateStatus
gh pr checks <url> --required --json bucket -q 'group_by(.bucket)|map("\(.[0].bucket)=\(length)")|join(" ")'
```

`reviewDecision` is `APPROVED`, `CHANGES_REQUESTED`, `REVIEW_REQUIRED` or empty. `mergeStateStatus` is `CLEAN`, `HAS_HOOKS`, `UNSTABLE`, `BEHIND`, `BLOCKED`, `DIRTY` or `UNKNOWN`. Green means `pass=<required count>` and no other bucket.

Report state: `OPEN` is `open`, `MERGED` is `merged`, and `CLOSED` is `closed`.

Report draft: `isDraft` `true` is `yes`, and `false` is `no`.

Report review: `APPROVED` is `approved`, `CHANGES_REQUESTED` is `changes-requested`, `REVIEW_REQUIRED` is `required`, and empty is `none`.

Report checks: `passed` when green. `failed` when the `fail` or `cancel` bucket holds a check. Otherwise `pending`.

Report mergeability, in this order:

1. `conflicting` when `mergeable` is `CONFLICTING` or `mergeStateStatus` is `DIRTY`.
2. `behind` when `mergeStateStatus` is `BEHIND`.
3. `blocked` when `mergeStateStatus` is `BLOCKED`.
4. `unknown` when `mergeable` is `UNKNOWN`.
5. `mergeable` when `mergeStateStatus` is `CLEAN`, `HAS_HOOKS` or `UNSTABLE`.
6. `unknown` otherwise.

For an epic child, replace the second command with the by-name read of "Read the checks". Green means that every name passes, and that no name fails, waits or has no check.

## Check the approval covers the head

GitHub keeps `reviewDecision` at `APPROVED` after a push when the branch rules do not dismiss a stale review. A review's `commit_id` does not show what the reviewer saw either, because GitHub moves it onto the head that a later merge creates. So read the approval by time with the script that sits next to this file. `<forge dir>` is the directory of this file:

```bash
APPROVER=<login> <forge dir>/github-approval-covers.sh <url> <sha>
```

Set `APPROVER` when the profile `Merge` section names an approver. Then only that reviewer's approval counts. Otherwise omit it, and every reviewer counts.

The script takes each reviewer's last approving or blocking review. The earliest approval that is still current sets the time, so every current approver must have seen each commit. The script reads the pushes to the head branch, and takes the head of the last push before that time. A commit in that head is covered. The script sorts every other commit. A push time is used because a commit made before the approval can reach the branch after it. The script prints one line:

| Line | Exit | Meaning |
|---|---|---|
| `COVERED ...` | 0 | Every later commit is a merge from the base, with or without a conflict resolution. |
| `HOLD commits after approval: ...` | 1 | A later commit adds new content. |
| `HOLD head moved`, `HOLD no approval`, `HOLD no push ...`, `HOLD 250 commits or more ...` | 1 | The item fails for that reason. |
| `UNREAD: ...` | 2 | A read failed. |

Report approval: the script line is the report, as it is.

A rebase or a force push rewrites commits, so they hold as commits after approval.

## Update the branch

```bash
gh pr update-branch <url>
```

This merges the base into the head branch on the forge. Never pass `--rebase`. Whether an update keeps an approval depends on the branch rules, and the repository profile says it.

## Merge

```bash
gh pr merge <url> --<method> --match-head-commit <sha>
```

`<method>` comes from the profile `Merge` section. `--match-head-commit` refuses the merge when the head moved. Never pass `--admin` or `--auto`. On a branch with a merge queue, `gh pr merge` turns on auto-merge by itself, so the state stays `OPEN`. After the merge, read `state` again, and accept only `MERGED`, which is the state `merged`.
