# Prints a shell command one segment per line, with quotes removed and
# here-document bodies dropped. A quoted newline prints as " ; ", so a hook
# that re-reads a `bash -c` payload still splits it there. Inside `((` or `$((`,
# `<<` is a shift, not a here-document.
BEGIN { sq = 0; dq = 0; nq = 0; qi = 0; body = 0; arith = 0 }

function word_end(c) {
    return c == " " || c == "\t" || c == ";" || c == "&" || c == "|" || c == "<" || c == ">" || c == ")"
}

# Reads the delimiter after `<<` at position j and queues it. Returns the next position.
function heredoc(line, j, n,    c, strip, word, found) {
    strip = 0
    if (substr(line, j, 1) == "-") { strip = 1; j++ }
    while (j <= n && (substr(line, j, 1) == " " || substr(line, j, 1) == "\t")) j++
    word = ""; found = 0
    while (j <= n) {
        c = substr(line, j, 1)
        if (word_end(c)) break
        found = 1
        if (c == "'") {
            j++
            while (j <= n && substr(line, j, 1) != "'") { word = word substr(line, j, 1); j++ }
        } else if (c == "\"") {
            j++
            while (j <= n && substr(line, j, 1) != "\"") {
                if (substr(line, j, 1) == "\\" && j < n) j++
                word = word substr(line, j, 1); j++
            }
        } else if (c == "\\" && j < n) {
            j++; word = word substr(line, j, 1)
        } else {
            word = word c
        }
        j++
    }
    if (found) { nq++; delim[nq] = word; strips[nq] = strip; printf "%s", word }
    return j
}

body {
    line = $0
    if (strips[qi]) sub(/^\t+/, "", line)
    if (line == delim[qi] && ++qi > nq) { body = 0; nq = 0 }
    next
}

{
    line = $0; n = length(line); joined = 0; i = 1
    while (i <= n) {
        c = substr(line, i, 1)
        if (sq) {
            if (c == "'") sq = 0; else printf "%s", c
        } else if (c == "\\") {
            if (i == n) joined = 1; else { i++; printf "%s", substr(line, i, 1) }
        } else if (dq) {
            if (c == "\"") dq = 0
            else if (c == "$" && substr(line, i, 3) == "$((") { printf "$(("; arith++; i += 2 }
            else if (c == ")" && arith && substr(line, i, 2) == "))") { printf "))"; arith--; i++ }
            else if (c == "<" && substr(line, i, 3) == "<<<") { printf "<<<"; i += 2 }
            else if (c == "<" && !arith && substr(line, i, 2) == "<<") { printf "<<"; i = heredoc(line, i + 2, n); continue }
            else printf "%s", c
        } else if (c == "'") {
            sq = 1
        } else if (c == "\"") {
            dq = 1
        } else if (c == ";" || c == "&" || c == "|") {
            printf "\n"
        } else if (c == "$" && substr(line, i, 3) == "$((") {
            printf "$(("; arith++; i += 2
        } else if (c == "(" && substr(line, i, 2) == "((") {
            printf "(("; arith++; i++
        } else if (c == ")" && arith && substr(line, i, 2) == "))") {
            printf "))"; arith--; i++
        } else if (c == "<" && substr(line, i, 3) == "<<<") {
            printf "<<<"; i += 2
        } else if (c == "<" && !arith && substr(line, i, 2) == "<<") {
            printf "<<"; i = heredoc(line, i + 2, n); continue
        } else {
            printf "%s", c
        }
        i++
    }
    if (joined) next
    if (sq || dq) printf " ; "; else printf "\n"
    if (nq) { body = 1; qi = 1 }
}

END { printf "\n" }
