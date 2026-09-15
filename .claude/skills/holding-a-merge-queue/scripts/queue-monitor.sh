#!/usr/bin/env bash
# Prints a line whenever an open pull request changes. Run it from the repository,
# as the command of a persistent Monitor. ONCE=1 runs a single pass.
prev=$(mktemp); cur=$(mktemp)
trap 'rm -f "$prev" "$cur"' EXIT
while true; do
  id=$(gh api repos/{owner}/{repo}/rulesets -q '.[]|select(.name=="main")|.id' 2>/dev/null)
  n=$(gh api "repos/{owner}/{repo}/rulesets/$id" -q '[.rules[]|select(.type=="required_status_checks")|.parameters.required_status_checks[]]|length' 2>/dev/null)
  prs=$(gh pr list --state open --limit 100 --json number,headRefOid,baseRefName,isDraft,mergeStateStatus,latestReviews 2>/dev/null)
  if [ -z "$n" ] || [ -z "$prs" ]; then echo "monitor: GitHub read failed at $(date -u +%H:%M:%SZ)"; [ -n "$ONCE" ] && exit 1; sleep 60; continue; fi
  : > "$cur"
  for pr in $(jq -r '.[].number' <<<"$prs"); do
    out=$(gh pr checks "$pr" --required --json bucket 2>&1)
    case "$out" in
      [Nn]"o required checks reported"*) c=none ;;
      \[*) c=$(jq -r --argjson n "$n" 'group_by(.bucket)|map({(.[0].bucket):length})|add // {}
             | if (.fail // 0) + (.cancel // 0) > 0 then "fail"
               elif (.pass // 0) == $n and length == 1 then "pass=\($n)/\($n)"
               elif length == 0 then "none" else "running" end' <<<"$out") ;;
      *) c=unread ;;
    esac
    m=$(jq -r --argjson p "$pr" '.[]|select(.number==$p)|.mergeStateStatus' <<<"$prs")
    case "$m" in
      DIRTY|BEHIND) mg=$m ;;
      UNKNOWN) mg=$(grep "^#$pr " "$prev" | sed -n 's/.* merge=\([^ ]*\).*/\1/p'); mg=${mg:--} ;;
      *) mg=- ;;
    esac
    jq -r --argjson p "$pr" --arg c "$c" --arg mg "$mg" '.[]|select(.number==$p)
      | "#\(.number) head=\(.headRefOid[0:8]) base=\(.baseRefName) draft=\(.isDraft) checks=\($c)"
        + " merge=\($mg)"
        + " reviews=\([.latestReviews[]|.author.login+":"+.state+"@"+.submittedAt]|join(","))"' <<<"$prs" >> "$cur"
  done
  sort -o "$cur" "$cur"
  comm -13 "$prev" "$cur"
  for gone in $(comm -23 <(cut -d' ' -f1 "$prev") <(cut -d' ' -f1 "$cur")); do echo "$gone left the open list: read its merged state"; done
  cp "$cur" "$prev"
  [ -n "$ONCE" ] && break
  sleep 60
done
