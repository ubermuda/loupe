#!/usr/bin/env bash
# Tells whether the current approvals of a GitHub pull request cover every commit up to <sha>.
# Usage: github-approval-covers.sh <pull request url> <sha>. Run it from a checkout of the repository.
# Prints COVERED, or HOLD with the reason. Exits 0 on COVERED, 1 on HOLD, 2 when a read fails.
# AS_OF=<ISO time> ignores the reviews submitted after that time, to replay a past decision.
set -o pipefail
url=${1:?usage: github-approval-covers.sh <pull request url> <sha>}
sha=${2:?usage: github-approval-covers.sh <pull request url> <sha>}
[[ $url =~ ^https://github\.com/([^/]+)/([^/]+)/pull/([0-9]+)/?$ ]] || { echo "UNREAD: not a GitHub pull request URL: $url"; exit 2; }
repo="${BASH_REMATCH[1]}/${BASH_REMATCH[2]}" pr=${BASH_REMATCH[3]}
as_of=${AS_OF:-9999}

view=$(gh pr view "$url" --json headRefOid,baseRefName 2>/dev/null) || { echo "UNREAD: gh pr view failed"; exit 2; }
head=$(jq -r .headRefOid <<<"$view") base=$(jq -r .baseRefName <<<"$view")
[ "$head" = "$sha" ] || { echo "HOLD head moved: ${head:0:8}, not ${sha:0:8}"; exit 1; }
commits=$(gh api --paginate "repos/$repo/pulls/$pr/commits?per_page=100" 2>/dev/null | jq -sc '[.[][]|{oid:.sha,at:.commit.committer.date,parents:(.parents|length),headline:(.commit.message|split("\n")[0])}]') || { echo "UNREAD: commit list failed"; exit 2; }
reviews=$(gh api --paginate "repos/$repo/pulls/$pr/reviews?per_page=100" 2>/dev/null | jq -sc '[.[][]|{login:.user.login,state,at:.submitted_at}]') || { echo "UNREAD: review list failed"; exit 2; }
[ "$(jq length <<<"$commits")" -lt 250 ] || { echo "HOLD 250 commits or more, GitHub truncates the list"; exit 1; }

# Each author's last approving or blocking review counts. The earliest current approval sets the time,
# so every current approver has seen every commit before it.
at=$(jq -r --arg asof "$as_of" '[.[]|select(.at <= $asof and (.state|IN("APPROVED","CHANGES_REQUESTED","DISMISSED")))]
  | group_by(.login) | map(max_by(.at)) | map(select(.state == "APPROVED") | .at) | min // ""' <<<"$reviews")
[ -n "$at" ] || { echo "HOLD no approval"; exit 1; }

git fetch -q origin "$base" "refs/pull/$pr/head" 2>/dev/null || { echo "UNREAD: git fetch failed"; exit 2; }
sync=0 conflict=() new=()
while IFS=$'\t' read -r oid parents headline; do
  [ -n "$oid" ] || continue
  if [ "$parents" -gt 1 ] && git merge-base --is-ancestor "$oid^2" "origin/$base" 2>/dev/null; then
    # A merge from the base is a sync when git re-creates its tree with no conflict.
    if t=$(git merge-tree --write-tree "$oid^1" "$oid^2" 2>/dev/null) && [ "$t" = "$(git rev-parse "$oid^{tree}")" ]; then
      sync=$((sync + 1))
    else
      conflict+=("${oid:0:8}")
    fi
  else
    new+=("${oid:0:8} $headline")
  fi
done < <(jq -r --arg at "$at" '.[]|select(.at > $at)|[.oid,(.parents|tostring),.headline]|@tsv' <<<"$commits")

if [ ${#new[@]} -gt 0 ]; then
  list=$(printf '%s; ' "${new[@]}"); echo "HOLD commits after approval: ${list%; }"; exit 1
fi
if [ ${#conflict[@]} -gt 0 ]; then
  echo "HOLD conflict resolution after approval: ${conflict[*]}"; exit 1
fi
echo "COVERED approval=$at head=${head:0:8} sync-merges-after-approval=$sync"
