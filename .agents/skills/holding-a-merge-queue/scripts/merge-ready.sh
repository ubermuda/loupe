#!/usr/bin/env bash
# Prints one verdict line for a pull request: READY, or HOLD with every reason.
# Usage: merge-ready.sh <number>. OWNER=<login> overrides the approving reviewer.
# Exits 0 on READY, 1 on HOLD, 2 when GitHub cannot be read.
set -o pipefail
pr=${1:?usage: merge-ready.sh <number>}
owner=${OWNER:-$(gh repo view --json owner -q .owner.login 2>/dev/null)}
id=$(gh api repos/{owner}/{repo}/rulesets -q '.[]|select(.name=="main")|.id' 2>/dev/null)
n=$(gh api "repos/{owner}/{repo}/rulesets/$id" -q '[.rules[]|select(.type=="required_status_checks")|.parameters.required_status_checks[]]|length' 2>/dev/null)
fields=headRefOid,baseRefName,isDraft,mergeStateStatus,reviewDecision,changedFiles
view=$(gh pr view "$pr" --json "$fields" 2>/dev/null)
commits=$(gh api --paginate "repos/{owner}/{repo}/pulls/$pr/commits?per_page=100" 2>/dev/null | jq -sc '[.[][]|{oid:.sha,committedDate:.commit.committer.date,messageHeadline:(.commit.message|split("\n")[0])}]') || commits=
reviews=$(gh api --paginate "repos/{owner}/{repo}/pulls/$pr/reviews?per_page=100" 2>/dev/null | jq -sc '[.[][]|{author:{login:.user.login},state,submittedAt:.submitted_at}]') || reviews=
[ -n "$view" ] && [ -n "$commits" ] && [ -n "$reviews" ] && view=$(jq -c --argjson c "$commits" --argjson r "$reviews" '. + {commits:$c,reviews:$r}' <<<"$view")
checks=$(gh pr checks "$pr" --required --json bucket 2>&1)
head2=$(gh pr view "$pr" --json headRefOid -q .headRefOid 2>/dev/null)
if [ -z "$owner" ] || [ -z "$n" ] || [ -z "$commits" ] || [ -z "$reviews" ] || [ -z "$view" ] || [ -z "$head2" ]; then echo "#$pr UNREAD: GitHub read failed"; exit 2; fi

case "$checks" in
  [Nn]"o checks reported"*|[Nn]"o required checks reported"*) buckets='{}' ;;
  \[*) buckets=$(jq -c 'group_by(.bucket)|map({(.[0].bucket):length})|add // {}' <<<"$checks") ;;
  *) echo "#$pr UNREAD: gh pr checks said: ${checks%%$'\n'*}"; exit 2 ;;
esac

# A merge from main is a sync when git re-creates its tree with no conflict.
git fetch -q origin main "refs/pull/$pr/head" 2>/dev/null
merges=$(for c in $(jq -r '.commits[]|select(.messageHeadline|startswith("Merge "))|.oid' <<<"$view"); do
  git merge-base --is-ancestor "$c^2" origin/main 2>/dev/null || continue
  t=$(git merge-tree --write-tree "$c^1" "$c^2" 2>/dev/null) && [ "$t" = "$(git rev-parse "$c^{tree}")" ] && k=sync || k=conflict
  echo "{\"$c\":\"$k\"}"
done | jq -sc 'add // {}')
line=$(jq -r --arg owner "$owner" --argjson n "$n" --argjson b "$buckets" --arg head2 "$head2" --argjson merges "$merges" '
  ([.reviews[]|select(.author.login==$owner and (.state|IN("APPROVED","CHANGES_REQUESTED","DISMISSED")))]|last) as $last
  | (if $last.state == "APPROVED" then $last.submittedAt else null end) as $at
  | [.commits[]|select($at != null and .committedDate > $at)] as $unseen
  | [$unseen[]|select($merges[.oid] == "sync")] as $sync
  | [$unseen[]|select($merges[.oid] == "conflict")] as $conflict
  | [$unseen[]|select(.oid as $o|[$sync[],$conflict[]]|map(.oid)|index($o)|not)] as $new
  | ($b|to_entries|map("\(.key)=\(.value)")|join(" ")) as $counts
  | [
      (if .headRefOid != $head2 then "head moved during the read, run again" else empty end),
      (if (.commits|length) >= 250 then "250 commits or more, GitHub truncates the list, read it by hand" else empty end),
      (if .baseRefName != "main" then "targets \(.baseRefName), not main" else empty end),
      (if .isDraft then "draft" else empty end),
      (if ($b.pass // 0) != $n or ($b|length) != 1 then "checks \(if $counts == "" then "none" else $counts end), need pass=\($n)" else empty end),
      (if .mergeStateStatus == "BEHIND" then "BEHIND main, run gh pr update-branch"
       elif .mergeStateStatus == "DIRTY" then "DIRTY, conflicts with main"
       elif .mergeStateStatus == "UNKNOWN" then "merge state UNKNOWN, run again"
       elif (.mergeStateStatus|IN("CLEAN","HAS_HOOKS")|not) then "merge state \(.mergeStateStatus)"
       else empty end),
      (if $at == null then "no approval from \($owner)" else empty end),
      (if .reviewDecision != "APPROVED" then "review decision \(.reviewDecision // "none")" else empty end),
      (if ($new|length) > 0 then "commits after approval: \([$new[]|"\(.oid[0:8]) \(.messageHeadline)"]|join("; ")). A rebase is covered, new content needs the owner" else empty end),
      (if ($conflict|length) > 0 then "conflict resolution \([$conflict[]|.oid[0:8]]|join(",")), prove it with references/git-traps.md" else empty end)
    ] as $hold
  | if ($hold|length) == 0
    then "READY head=\(.headRefOid[0:8]) pass=\($n)/\($n) files=\(.changedFiles) sync-merges-after-approval=\($sync|length)"
    else "HOLD \($hold|join(" | "))"
    end' <<<"$view")
[ -n "$line" ] || { echo "#$pr UNREAD: jq failed"; exit 2; }
echo "#$pr $line"
[ "${line%% *}" = READY ]
