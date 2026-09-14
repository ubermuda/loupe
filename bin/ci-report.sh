#!/usr/bin/env bash
# Downloads a report artifact from GitHub Actions into var/ci-reports/ and
# prints its summary. With no run id it takes the newest run on main that still
# holds the artifact, because a red run can hold one and a green run can lack it.
#
# Usage: bin/ci-report.sh <mutation|phpunit-coverage|e2e-coverage|e2e-timing> [run-id]
set -euo pipefail

report=${1:-}
run=${2:-}
# A glob rather than a name, for a report the e2e shards upload one of each.
# `gh run download --pattern` puts every match in its own subdirectory, so a
# pattern also changes the layout under $dir.
pattern=''

case "$report" in
    mutation) workflow='Mutation testing' artifact=infection-report ;;
    phpunit-coverage) workflow='Coverage report' artifact=phpunit-coverage ;;
    e2e-coverage) workflow='Coverage report' artifact=e2e-coverage ;;
    # The trailing `*` also matches `e2e-timing`, the single artifact a run
    # from before the e2e job was sharded holds.
    e2e-timing) workflow='CI' artifact=e2e-timing pattern='e2e-timing*' ;;
    *)
        echo "usage: $0 <mutation|phpunit-coverage|e2e-coverage|e2e-timing> [run-id]" >&2
        exit 2
        ;;
esac

repo=$(gh repo view --json nameWithOwner -q .nameWithOwner)

holds_artifact() {
    local name
    for name in $(gh api "repos/$repo/actions/runs/$1/artifacts" \
        -q '.artifacts[] | select(.expired | not) | .name'); do
        # shellcheck disable=SC2053
        [[ $name == ${pattern:-$artifact} ]] && return 0
    done

    return 1
}

if [ -z "$run" ]; then
    for id in $(gh run list --repo "$repo" --workflow "$workflow" --branch main \
        --status completed --limit 20 --json databaseId -q '.[].databaseId'); do
        if holds_artifact "$id"; then
            run=$id
            break
        fi
    done
    if [ -z "$run" ]; then
        echo "No completed '$workflow' run in the last 20 on main holds '${pattern:-$artifact}'." >&2
        exit 1
    fi
fi

dir="var/ci-reports/$artifact/$run"
if [ ! -d "$dir" ]; then
    rm -rf "$dir.part"
    mkdir -p "$(dirname "$dir")"
    if [ -n "$pattern" ]; then
        gh run download "$run" --repo "$repo" --pattern "$pattern" --dir "$dir.part"
    else
        gh run download "$run" --repo "$repo" --name "$artifact" --dir "$dir.part"
    fi
    mv "$dir.part" "$dir"
fi

echo "run $run: https://github.com/$repo/actions/runs/$run"
echo "files: $dir"
echo

case "$report" in
    mutation) cat "$dir/summary.log" ;;
    phpunit-coverage | e2e-coverage) head -12 "$dir/summary.txt" ;;
    e2e-timing) node bin/e2e-timing.mjs "$dir"/*/results.json ;;
esac
