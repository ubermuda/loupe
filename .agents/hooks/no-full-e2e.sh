#!/usr/bin/env bash
# Refuses a Bash command that runs the whole Playwright suite on this machine.
# CI's `e2e` check is the gate. A local full run is slower, destructive and
# less truthful, and it fights every other worktree for one php-fpm container.
# One named spec stays allowed, because a selector change has no other check.
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

full_run="A full local e2e run is not the gate, and CI's e2e check is. Push and read that check. To debug one spec, name it: just e2e tests/<area>/<spec>.spec.ts"
coverage="just e2e-coverage runs the whole suite. CI's e2e check is the gate, and 'just ci-report e2e-coverage' fetches the report."

# A spec argument is what separates debugging one spec from running the suite.
names_a_spec() {
    for word in "$@"; do
        case "$word" in *.spec.ts|*tests/*) return 0 ;; esac
    done

    return 1
}

# Each segment of a compound command is judged on its own, so
# `just e2e-up && just e2e` is refused for its second half. The filter drops
# here-document bodies and quotes before the split, so a quoted `just e2e` is
# data. The quoted text of a shell wrapper is a command.
judge_command() {
    local -a words=("$@")
    local i=0
    skip_prefix
    case "${words[i]:-} ${words[i+1]:-}" in
        "just exec") i=$((i + 2)) ;;
        "bin/worktrees/compose-exec.sh "*|"./bin/worktrees/compose-exec.sh "*) i=$((i + 1)) ;;
    esac
    skip_prefix
    if wrapped_command "${words[@]:i}"; then
        judge_text "$wrapped"
        return
    fi

    case "${words[i]:-} ${words[i+1]:-}" in
        "just e2e-coverage") deny "$coverage" ;;
        "just e2e-up"|"just e2e-down") ;;
        "just e2e") names_a_spec "${words[@]:i+2}" || deny "$full_run" ;;
        "npx playwright"|"pnpm playwright"|"yarn playwright")
            [ "${words[i+2]:-}" = test ] && { names_a_spec "${words[@]:i+3}" || deny "$full_run"; } ;;
        "playwright test") names_a_spec "${words[@]:i+2}" || deny "$full_run" ;;
    esac
}

judge_text "$command"

exit 0
