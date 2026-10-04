# Prints a shell command one segment per line, with quotes removed and
# here-document bodies dropped. Inside quotes a newline prints as \036, a
# blank as \037 and a tab as \035, so each quoted argument stays one word.
# An empty quoted word prints as \034. lib/segments.sh undoes all four.
# The ctx stack holds U (unquoted or `$(`), D (double), S (single) and E ($'...').
BEGIN {
    sp = 1; floor = 1; ctx[1] = "U"; paren[1] = 0; quoted[1] = 0
    arith = 0; tick = 0; cont = 0; lv = 0; qn[0] = 0; pstart[0] = 1; bd = 0
    st = 0; pend = ""; wd = ""; inw = 0; pshell = 0
}

function push(t) {
    ctx[++sp] = t; paren[sp] = 0; qline[sp] = NR; qpos[sp] = i
    quoted[sp] = quoted[sp - 1] || t != "U"
}

# A joined line continues the word when no blank came before its backslash.
# The `)` that ends `$(` or `$((` is part of a word, but a subshell's is not.
function word_start(p) {
    if (p == 1) return !cont
    return p - 1 != wclose && index(" \t;&|()", substr(line, p - 1, 1)) > 0
}

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

# Inside quotes a nested `$(` keeps blanks and separators, so the argument stays one word.
function plain(c) { if (quoted[sp]) out(c); else printf "%s", c }

function sep(c) { if (quoted[sp]) printf "%s", c; else printf "\n" }

# Word tracking finds a segment that runs a shell with no -c flag. In st 0 the
# segment waits for its command word, st 2 means a shell, and st 3 means done.
# It skips the same wrappers as skip_prefix in lib/segments.sh, plus `just exec`.
# pend says what the next word is: a value to skip, a timeout option, or `exec`.
function wc(c) { if (st < 3 && length(wd) < 64) wd = wd c; inw = 1 }

function we(    base) {
    if (!inw) return
    if (st == 0 && pend == "skip") {
        pend = ""
    } else if (st == 0 && pend == "value") {
        pend = "timeout"
    } else if (st == 0 && pend == "timeout") {
        if (wd ~ /^(-k|-s|--kill-after|--signal)$/) pend = "value"; else if (wd !~ /^-/) pend = ""
    } else if (st == 0 && pend == "just") {
        pend = ""; if (wd != "exec") st = 3
    } else if (st == 0 && (wd == "-n" || wd == "-u")) {
        pend = "skip"
    } else if (st == 0 && (wd == "timeout" || wd == "just")) {
        pend = wd
    } else if (st == 0 && wd !~ /^(time|nice|env|\(|\{|-.*|[A-Za-z_][A-Za-z0-9_]*=.*|(\.\/)?bin\/worktrees\/compose-exec\.sh)$/) {
        base = wd; sub(/.*\//, "", base)
        st = (base ~ /^(bash|sh|zsh|dash|ksh)$/) ? 2 : 3
    } else if (st == 2 && wd ~ /^-[^-]*c/) {
        st = 3
    }
    wd = ""; inw = 0
}

function seg_end() { we(); if (st == 2) pshell = 1; st = 0; pend = "" }

# A pipeline that runs a shell reads the here-documents it opened as code.
function pipe_end(    k) {
    seg_end()
    if (pshell) for (k = pstart[lv]; k <= qn[lv]; k++) code[lv, k] = 1
    pshell = 0; pstart[lv] = qn[lv] + 1
}

# A `$(` starts a new command, so it keeps its own word state until its `)`.
function sub_open() {
    sst[sp] = st; spend[sp] = pend; swd[sp] = wd; spsh[sp] = pshell
    st = 0; pend = ""; wd = ""; inw = 0; pshell = 0
}

function sub_close() { pipe_end(); st = sst[sp]; pend = spend[sp]; wd = swd[sp]; pshell = spsh[sp]; inw = 1 }

# A code body is a script of its own: level lv + 1, with a fresh context and
# its own here-document queue. The outer state comes back after its closing line.
function begin_code() {
    printf "\n"; lv++; ssp[lv] = sp; sarith[lv] = arith; stick[lv] = tick; sfloor[lv] = floor
    arith = 0; tick = 0; cont = 0; qn[lv] = 0; pstart[lv] = 1
    ctx[++sp] = "U"; paren[sp] = 0; quoted[sp] = 0; floor = sp
}

function end_code() {
    printf "\n"; sp = ssp[lv]; arith = sarith[lv]; tick = stick[lv]; floor = sfloor[lv]; lv--
    st = 0; pend = ""; wd = ""; inw = 0; pshell = 0
}

function word_end(c) {
    return c == " " || c == "\t" || c == ";" || c == "&" || c == "|" || c == "<" || c == ">" || c == ")"
}

# Reads the delimiter after `<<` at position j and queues it. Returns the next position.
function heredoc(line, j, n,    c, strip, word, found, k) {
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
        k = ++qn[lv]; delim[lv, k] = word; strips[lv, k] = strip
        in_subst[lv, k] = sp > floor; in_tick[lv, k] = tick; code[lv, k] = 0
        printf "%s", word
    }
    return j
}

# Inside `$(` bash also ends a here-document at `EOF)`, and inside backticks at
# "EOF`". The rest of that line is code. A body that a shell reads is code too.
# The bd stack holds the queues being read, innermost last, as (level, index).
bd {
    L = blv[bd]; q = bqi[bd]; line = $0
    if (strips[L, q]) sub(/^\t+/, "", line)
    k = length(delim[L, q]); after = substr(line, k + 1, 1)
    if (substr(line, 1, k) != delim[L, q] || \
        (after != "" && !(after == ")" && in_subst[L, q]) && !(after == "`" && in_tick[L, q]))) {
        if (!code[L, q]) next
    } else {
        if (code[L, q]) end_code()
        if (++bqi[bd] <= qn[L]) { if (code[L, bqi[bd]]) begin_code(); next }
        qn[L] = 0; pstart[L] = 1; bd--
        if (after == "") next
        $0 = substr(line, k + 1)
    }
}

{
    line = $0; n = length(line); joined = 0; i = 1; wclose = 0
    while (i <= n) {
        c = substr(line, i, 1); t = ctx[sp]
        if (t == "S") {
            if (c == "'") pop(); else out(c)
        } else if (t == "E") {
            if (c == "'") pop()
            else if (c == "\\" && i < n) { i++; out(substr(line, i, 1)) }
            else out(c)
        } else if (c == "\\") {
            if (i == n) joined = 1; else { i++; out(substr(line, i, 1)); if (t == "U") wc(substr(line, i, 1)) }
        } else if (t == "D") {
            if (c == "\"") pop()
            else if (c == "$" && substr(line, i, 3) == "$((") { printf "$(("; i += 2 }
            else if (c == "$" && substr(line, i, 2) == "$(") { printf "$("; push("U"); sub_open(); i++ }
            else out(c)
        } else if (c == "#" && word_start(i)) {
            break
        } else if (c == "`") {
            printf "`"; tick = !tick; wc(c)
        } else if (c == "'") {
            push("S"); inw = 1
        } else if (c == "\"") {
            push("D"); inw = 1
        } else if (c == "$" && substr(line, i, 2) == "$'") {
            i++; push("E"); inw = 1
        } else if (c == ";") {
            sep(c); pipe_end()
        } else if (c == "&") {
            sep(c)
            if (index("<>", substr(line, i - 1, 1)) || substr(line, i + 1, 1) == ">") we(); else pipe_end()
        } else if (c == "|") {
            sep(c)
            if (substr(line, i + 1, 1) == "|" || substr(line, i - 1, 1) == "|") pipe_end(); else seg_end()
        } else if (c == "$" && substr(line, i, 3) == "$((") {
            printf "$(("; akind[++arith] = "$"; i += 2; wc("$((")
        } else if (c == "$" && substr(line, i, 2) == "$(") {
            printf "$("; wc("$("); i++; push("U"); sub_open()
        } else if (c == "(" && substr(line, i, 2) == "((") {
            printf "(("; akind[++arith] = "("; i++; wc("((")
        } else if (c == ")" && arith && substr(line, i, 2) == "))") {
            printf "))"; i++; if (akind[arith--] == "$") wclose = i
        } else if (c == "(") {
            printf "("; paren[sp]++; we()
        } else if (c == ")") {
            printf ")"; we()
            if (paren[sp]) paren[sp]--; else if (sp > floor) { sub_close(); sp--; wclose = i }
        } else if (c == "<" && substr(line, i, 3) == "<<<") {
            printf "<<<"; i += 2; we()
        } else if (c == "<" && !arith && substr(line, i, 2) == "<<") {
            printf "<<"; we(); i = heredoc(line, i + 2, n); continue
        } else if (c == " " || c == "\t" || c == "<" || c == ">") {
            plain(c); we()
        } else {
            printf "%s", c; wc(c)
        }
        i++
    }
    if (joined) { if (n > 1) cont = !index(" \t", substr(line, n - 1, 1)); next }
    cont = 0
    if (ctx[sp] == "U") pipe_end()
    printf "%s", (ctx[sp] == "U" && !quoted[sp] ? "\n" : "\036")
    if (qn[lv]) { blv[++bd] = lv; bqi[bd] = 1; if (code[lv, 1]) begin_code() }
}

END { printf "\n" }
