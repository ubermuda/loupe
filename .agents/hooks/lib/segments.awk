# Prints a shell command one segment per line, with quotes removed and
# here-document bodies dropped. Inside quotes a newline prints as \036, a
# blank as \037 and a tab as \035, so each quoted argument stays one word.
# An empty quoted word prints as \034. lib/segments.sh undoes all four.
# The ctx stack holds U (unquoted or `$(`), D (double), S (single) and E ($'...').
BEGIN { sp = 1; ctx[1] = "U"; paren[1] = 0; arith = 0; tick = 0; cont = 0; nq = 0; qi = 0; body = 0 }

function push(t) { ctx[++sp] = t; paren[sp] = 0; qline[sp] = NR; qpos[sp] = i }

# A joined line continues the word when no blank came before its backslash.
function word_start(p) { return p == 1 ? !cont : index(" \t;&|()", substr(line, p - 1, 1)) > 0 }

# Prints \034 for empty quotes that make a whole word, so read keeps that word.
function pop(    j, c) {
    if (qline[sp] == NR && qpos[sp] == i - 1 && word_start(qpos[sp] - (ctx[sp] == "E"))) {
        j = i + 1
        while (substr(line, j, 2) == "''" || substr(line, j, 2) == "\"\"") j += 2
        c = substr(line, j, 1)
        if (c == "" || index(" \t;&|()<>", c)) printf "\034"
    }
    sp--
}

function out(c) {
    if (c == " ") printf "\037"; else if (c == "\t") printf "\035"; else printf "%s", c
}

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
    if (found) {
        nq++; delim[nq] = word; strips[nq] = strip; in_subst[nq] = sp > 1; in_tick[nq] = tick
        printf "%s", word
    }
    return j
}

# Inside `$(` bash also ends a here-document at `EOF)`, and inside backticks at
# "EOF`". The rest of that line is code.
body {
    line = $0
    if (strips[qi]) sub(/^\t+/, "", line)
    k = length(delim[qi]); after = substr(line, k + 1, 1)
    if (substr(line, 1, k) != delim[qi]) next
    if (after != "" && !(after == ")" && in_subst[qi]) && !(after == "`" && in_tick[qi])) next
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
            if (c == "'") pop(); else out(c)
        } else if (t == "E") {
            if (c == "'") pop()
            else if (c == "\\" && i < n) { i++; out(substr(line, i, 1)) }
            else out(c)
        } else if (c == "\\") {
            if (i == n) joined = 1; else { i++; out(substr(line, i, 1)) }
        } else if (t == "D") {
            if (c == "\"") pop()
            else if (c == "$" && substr(line, i, 3) == "$((") { printf "$(("; i += 2 }
            else if (c == "$" && substr(line, i, 2) == "$(") { printf "$("; push("U"); i++ }
            else out(c)
        } else if (c == "#" && word_start(i)) {
            break
        } else if (c == "`") {
            printf "`"; tick = !tick
        } else if (c == "'") {
            push("S")
        } else if (c == "\"") {
            push("D")
        } else if (c == "$" && substr(line, i, 2) == "$'") {
            i++; push("E")
        } else if (c == ";" || c == "&" || c == "|") {
            printf "\n"
        } else if (c == "$" && substr(line, i, 3) == "$((") {
            printf "$(("; arith++; i += 2
        } else if (c == "$" && substr(line, i, 2) == "$(") {
            printf "$("; i++; push("U")
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
    if (joined) { if (n > 1) cont = !index(" \t", substr(line, n - 1, 1)); next }
    cont = 0
    printf "%s", (ctx[sp] == "U" ? "\n" : "\036")
    if (nq) { body = 1; qi = 1 }
}

END { printf "\n" }
