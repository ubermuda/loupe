#!/usr/bin/env bash
# Refuses a Bash command that runs `just ci` or the whole PHPUnit suite here.
# CI's required checks are the gate. A local full run is slow and fights every
# other worktree for one php-fpm container. A targeted PHPUnit run stays allowed.
set -uo pipefail

command="$(jq -r '.tool_input.command // empty')"
[ -n "$command" ] || exit 0

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

full_gate="A full local just ci is not the gate, and CI's required checks are. Run just cs, then just phpstan, just arkitect and just gamache, then just phpunit tests/<path> or just phpunit --filter <name>, then just js-test or just cli-test when those changed. Then run the Codex review and push."
full_phpunit="A full local PHPUnit run is not the gate, and CI's phpunit check is. Name the tests: just phpunit tests/<path> or just phpunit --filter <name>"
coverage="A full local PHPUnit coverage run is not the gate. Name the tests: just phpunit-coverage tests/<path>, or fetch the full report with just ci-report phpunit-coverage"

# --testsuite does not count, because the only suite is the whole suite, and
# neither does the tests root itself.
targeted() {
    for word in "$@"; do
        case "${word%/}" in
            tests|./tests) ;;
            tests/*|*/tests/*|*Test.php|--filter|--filter=*|--group|--group=*) return 0 ;;
        esac
    done

    return 1
}

is_phpunit() {
    case "$1" in vendor/bin/phpunit|./vendor/bin/phpunit) return 0 ;; esac

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
    while [[ "${words[i]:-}" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]]; do i=$((i + 1)); done

    case "${words[i]:-}" in
        just) judge_just "${words[@]:i+1}"; return ;;
        bin/worktrees/compose-exec.sh|./bin/worktrees/compose-exec.sh) i=$((i + 1)) ;;
    esac
    [ "${words[i]:-}" = env ] && i=$((i + 1))
    while [[ "${words[i]:-}" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]]; do i=$((i + 1)); done
    [ "${words[i]:-}" = php ] && i=$((i + 1))
    if is_phpunit "${words[i]:-}"; then
        targeted "${words[@]:i+1}" || deny "$full_phpunit"
    fi
}

# Matching is on the first word of each segment, so a grep that quotes
# `just ci` is left alone.
while IFS= read -r segment; do
    read -ra words <<< "$segment"
    [ "${#words[@]}" -gt 0 ] && judge_command "${words[@]}"
done < <(printf '%s\n' "$command" | tr ';&|' '\n')

exit 0
