#!/usr/bin/env bash
# Refuses a Bash command that runs `just ci` or the whole PHPUnit suite here.
# CI's required checks are the gate. A local full run is slow and fights every
# other worktree for one php-fpm container. A targeted PHPUnit run stays allowed.
set -uo pipefail

command="$(jq -r '.tool_input.command // empty')"
[ -n "$command" ] || exit 0
. "$(dirname "$0")/lib/segments.sh"

deny() {
    jq -nc --arg reason "$1" '{
        hookSpecificOutput: {
            hookEventName: "PreToolUse",
            permissionDecision: "deny",
            permissionDecisionReason: $reason,
        },
    }'
    exit 0
}

full_gate="A full local just ci is not the gate, and CI's required checks are. Run just cs, then just phpstan, just arkitect and just gamache, then just phpunit tests/<path> or just phpunit --filter <name>, then just js-test or just cli-test when those changed. Then push."
full_phpunit="A full local PHPUnit run is not the gate, and CI's phpunit check is. Name the tests: just phpunit tests/<path> or just phpunit --filter <name>"
coverage="A full local PHPUnit coverage run is not the gate. Name the tests: just phpunit-coverage tests/<path>, or fetch the full report with just ci-report phpunit-coverage"

# --testsuite does not count, because the only suite is the whole suite, and
# neither does the tests root, nor the file an output option writes.
targeted() {
    local skip=0
    for word in "$@"; do
        [ "$skip" = 1 ] && { skip=0; continue; }
        case "${word%/}" in
            tests|./tests|*/.|*/..|*/./*|*/../*) ;;
            -c|--configuration|--bootstrap|--cache-directory|--log-junit|--log-otr|--log-teamcity|--log-events-text|\
                --log-events-verbose-text|--testdox-html|--testdox-text|--coverage-clover|--coverage-cobertura|\
                --coverage-crap4j|--coverage-html|--coverage-openclover|--coverage-php|--coverage-xml|--coverage-filter) skip=1 ;;
            tests/*|*/tests/*|*Test.php|--filter|--filter=*|--group|--group=*) return 0 ;;
        esac
    done

    return 1
}

is_phpunit() {
    case "$1" in vendor/bin/phpunit|./vendor/bin/phpunit|vendor/bin/paratest|./vendor/bin/paratest) return 0 ;; esac

    return 1
}

# Recipe words end at the first recipe that takes arguments, so its arguments
# are never read as recipe names.
judge_just() {
    local -a words=("$@")
    local i=0
    while [ "$i" -lt "${#words[@]}" ]; do
        case "${words[i]}" in
            -f|--justfile|-d|--working-directory) i=$((i + 2)) ;;
            --set) i=$((i + 3)) ;;
            -*) i=$((i + 1)) ;;
            ci) deny "$full_gate" ;;
            phpunit) targeted "${words[@]:i+1}" || deny "$full_phpunit"; return ;;
            phpunit-coverage) targeted "${words[@]:i+1}" || deny "$coverage"; return ;;
            exec) judge_command "${words[@]:i+1}"; return ;;
            ci-report|e2e|e2e-coverage|js-test|composer|mutation|docs) return ;;
            *) i=$((i + 1)) ;;
        esac
    done
}

judge_command() {
    local -a words=("$@")
    local i=0
    skip_prefix

    case "${words[i]:-}" in
        just) judge_just "${words[@]:i+1}"; return ;;
        bin/worktrees/compose-exec.sh|./bin/worktrees/compose-exec.sh) i=$((i + 1)) ;;
    esac
    skip_prefix
    if wrapped_command "${words[@]:i}"; then
        judge_text "$wrapped"
        return
    fi
    [ "${words[i]:-}" = php ] && i=$((i + 1))
    if is_phpunit "${words[i]:-}"; then
        targeted "${words[@]:i+1}" || deny "$full_phpunit"
    fi
}

# The filter drops here-document bodies and quotes before the split, so a
# quoted `just ci` is data. The quoted text of a shell wrapper is a command.
judge_text "$command"

exit 0
