#!/usr/bin/env bash
# Downloads a report artifact from GitHub Actions into var/ci-reports/ and
# prints its summary. With no run id it takes the newest run on main that still
# holds the artifact, because a red run can hold one and a green run can lack it.
#
# Usage: bin/ci-report.sh <mutation|phpunit-coverage|e2e-coverage|e2e-timing> [run-id]
set -euo pipefail

report=${1:-}
run=${2:-}

case "$report" in
    mutation) workflow='Mutation testing' artifact=infection-report ;;
    phpunit-coverage) workflow='Coverage report' artifact=phpunit-coverage ;;
    e2e-coverage) workflow='Coverage report' artifact=e2e-coverage ;;
    e2e-timing) workflow='CI' artifact=e2e-timing ;;
    *)
        echo "usage: $0 <mutation|phpunit-coverage|e2e-coverage|e2e-timing> [run-id]" >&2
        exit 2
        ;;
esac

repo=$(gh repo view --json nameWithOwner -q .nameWithOwner)

if [ -z "$run" ]; then
    for id in $(gh run list --repo "$repo" --workflow "$workflow" --branch main \
        --status completed --limit 20 --json databaseId -q '.[].databaseId'); do
        if gh api "repos/$repo/actions/runs/$id/artifacts" \
            -q ".artifacts[] | select(.name == \"$artifact\" and (.expired | not)) | .id" | grep -q .; then
            run=$id
            break
        fi
    done
    if [ -z "$run" ]; then
        echo "No completed '$workflow' run in the last 20 on main holds '$artifact'." >&2
        exit 1
    fi
fi

dir="var/ci-reports/$artifact/$run"
if [ ! -d "$dir" ]; then
    rm -rf "$dir.part"
    mkdir -p "$(dirname "$dir")"
    gh run download "$run" --repo "$repo" --name "$artifact" --dir "$dir.part"
    mv "$dir.part" "$dir"
fi

echo "run $run: https://github.com/$repo/actions/runs/$run"
echo "files: $dir"
echo

case "$report" in
    mutation) cat "$dir/summary.log" ;;
    phpunit-coverage | e2e-coverage) head -12 "$dir/summary.txt" ;;
    e2e-timing) node bin/e2e-timing.mjs "$dir/results.json" ;;
esac
