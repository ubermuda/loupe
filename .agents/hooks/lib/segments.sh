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

# Skips assignments and the wrappers that run the command after them. It moves
# the caller's i along the caller's words.
skip_prefix() {
    while :; do
        case "${words[i]:-}" in
            -n|-u|timeout) i=$((i + 2)) ;;
            time|nice|env|'('|'{'|-*) i=$((i + 1)) ;;
            *) [[ "${words[i]:-}" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] || return; i=$((i + 1)) ;;
        esac
    done
}

# Sets wrapped to the command text that `eval` or a shell's -c flag runs. It
# sets a variable rather than printing, because a $(...) per segment is slow.
# A shell runs only the word after -c, and the words after that are $0 and on.
wrapped_command() {
    local IFS=' ' name="${1:-}"
    case "${name##*/}" in
        eval) shift; wrapped="$*" ;;
        bash|sh|zsh|dash|ksh)
            shift
            while [ "$#" -gt 0 ]; do
                case "$1" in
                    --*) ;;
                    -*c*) shift; break ;;
                esac
                shift
            done
            [ "$#" -gt 0 ] || return 1
            wrapped="$1" ;;
        *) return 1 ;;
    esac
    wrapped="${wrapped//$'\034'/}"
    wrapped="${wrapped//$'\035'/$'\t'}"
    wrapped="${wrapped//$'\036'/$'\n'}"
    wrapped="${wrapped//$'\037'/ }"
}
