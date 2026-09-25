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

Wait for the checks in the foreground, with a Bash timeout of 600000. Never start this wait as a background command. Put the gated SHA in place of `<sha>`, and the number of required checks in place of `<required>`:

```bash
f="${TMPDIR:-/tmp}/loupe-ci-wait-<sha>"; [ -s "$f" ] || echo $(( $(date +%s) + 3600 )) > "$f"
for i in $(seq 1 9); do
  if [ "$(date +%s)" -ge "$(cat "$f")" ]; then b=timeout; break; fi
  o=$(gh pr checks <url> --required --json bucket -q '([.[].bucket]|unique|join(",")) + " " + (length|tostring)' 2>&1)
  if printf '%s\n' "$o" | grep -Eqx '((pass|fail|pending|skipping|cancel),?)+ [0-9]+'; then b=${o% *} n=${o##* }
  elif [ "$o" = " 0" ] || printf '%s\n' "$o" | grep -Eqi 'no (required )?checks reported'; then b=none n=0
  else b="error: $o"; break; fi
  case ",$b," in *,fail,*|*,cancel,*) break ;; *,pending,*|,none,) sleep 60 ;; *) [ "$n" -ge <required> ] && break; sleep 60 ;; esac
done; echo "buckets: $b checks: ${n:-0}/<required>"
```

One call waits about nine minutes at most. The file holds a deadline 60 minutes after the first call for that SHA, so repeated calls share one limit.

- `fail` or `cancel` in the list: a check failed. Read its log.
- `timeout`: the wait timed out. Record a block. A `CONFLICTING` pull request runs no checks, so it ends here too.
- `error:`: `gh` failed. Read the message, then fix the cause or record a block.
- Every required check present, with no `pending`: the checks concluded. Count them as below.
- Anything else: `pending`, `none`, or fewer checks than required. Run the loop again.

A stacked pull request has no required checks, so `--required` reads `none` until the wait times out. For a stacked pull request, delete `--required` from the loop and from the count below. Keep the required count of the profile base branch in place of `<required>`, as the least number of checks to wait for. Green then means `pass` and no other bucket, with at least that many checks.

Then read the head again, and count the checks:

```bash
gh pr view <url> --json headRefOid -q .headRefOid
gh pr checks <url> --required --json bucket -q 'group_by(.bucket)|map("\(.[0].bucket)=\(length)")|join(" ")'
```

The count describes the gated head only when `headRefOid` still equals it. A rollup can still describe an earlier head, as `working-with-prs` "Merging" item 8 says. Green means `pass=<required count>` and no other bucket. The repository profile says where the list of required checks comes from.

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
