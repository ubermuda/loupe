# Shared by the PreToolUse hooks that judge a Bash command.

hooks_lib="$(dirname "${BASH_SOURCE[0]}")"

segments() {
    printf '%s\n' "$1" | awk -f "$hooks_lib/segments.awk"
}

# Calls the hook's judge_command with the words of each segment. The locals
# keep a recursive call from overwriting the caller's words.
judge_text() {
    local segment
    local -a words
    while IFS= read -r segment; do
        read -ra words <<< "$segment"
        [ "${#words[@]}" -gt 0 ] && judge_command "${words[@]}"
    done < <(segments "$1")
}

# Prints the command text that `eval` or a shell's -c flag runs.
wrapped_command() {
    local IFS=' ' name="${1:-}"
    case "${name##*/}" in
        eval) shift; printf '%s' "$*"; return 0 ;;
        bash|sh|zsh|dash|ksh)
            shift
            while [ "$#" -gt 0 ]; do
                case "$1" in
                    --*) ;;
                    -*c*) shift; printf '%s' "$*"; return 0 ;;
                esac
                shift
            done ;;
    esac

    return 1
}
