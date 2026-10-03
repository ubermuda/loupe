# Prints a shell command one segment per line, with quotes removed and
# here-document bodies dropped. A quoted newline prints as \036, which
# lib/segments.sh turns back into a newline before it re-reads a payload.
# The ctx stack holds U (unquoted), D (double), S (single) and E ($'...').
BEGIN { sp = 1; ctx[1] = "U"; paren[1] = 0; arith = 0; nq = 0; qi = 0; body = 0 }

function push(t) { ctx[++sp] = t; paren[sp] = 0 }

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

# bash also ends a here-document at `EOF)` or "EOF`", and the rest of that line is code.
body {
    line = $0
    if (strips[qi]) sub(/^\t+/, "", line)
    k = length(delim[qi]); after = substr(line, k + 1, 1)
    if (substr(line, 1, k) != delim[qi] || (after != "" && after != ")" && after != "`")) next
    if (++qi <= nq) next
    body = 0; nq = 0
    if (after == "") next
    $0 = substr(line, k + 1)
}

{
    line = $0; n = length(line); joined = 0; i = 1
    while (i <= n) {
        c = substr(line, i, 1); t = ctx[sp]
        if (t == "S") {
            if (c == "'") sp--; else printf "%s", c
        } else if (t == "E") {
            if (c == "'") sp--
            else if (c == "\\" && i < n) { i++; printf "%s", substr(line, i, 1) }
            else printf "%s", c
        } else if (c == "\\") {
            if (i == n) joined = 1; else { i++; printf "%s", substr(line, i, 1) }
        } else if (t == "D") {
            if (c == "\"") sp--
            else if (c == "$" && substr(line, i, 3) == "$((") { printf "$(("; i += 2 }
            else if (c == "$" && substr(line, i, 2) == "$(") { printf "$("; push("U"); i++ }
            else printf "%s", c
        } else if (c == "'") {
            push("S")
        } else if (c == "\"") {
            push("D")
        } else if (c == "$" && substr(line, i, 2) == "$'") {
            push("E"); i++
        } else if (c == ";" || c == "&" || c == "|") {
            printf "\n"
        } else if (c == "$" && substr(line, i, 3) == "$((") {
            printf "$(("; arith++; i += 2
        } else if (c == "(" && substr(line, i, 2) == "((") {
            printf "(("; arith++; i++
        } else if (c == ")" && arith && substr(line, i, 2) == "))") {
            printf "))"; arith--; i++
        } else if (c == "(") {
            printf "("; paren[sp]++
        } else if (c == ")") {
            printf ")"
            if (paren[sp]) paren[sp]--; else if (sp > 1) sp--
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
    printf "%s", (ctx[sp] == "U" ? "\n" : "\036")
    if (nq) { body = 1; qi = 1 }
}

END { printf "\n" }
