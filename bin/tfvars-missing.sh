#!/usr/bin/env bash
#
# What terraform.tfvars.example offers that terraform.tfvars does not set.
# Names only: terraform.tfvars holds live secrets and is gitignored.

set -euo pipefail

cd "$(dirname "$0")/.."

EXAMPLE=terraform/terraform.tfvars.example
TFVARS=${1:-terraform/terraform.tfvars}
VARIABLES=terraform/variables.tf

for f in "$EXAMPLE" "$TFVARS"; do
    [ -f "$f" ] || { echo "missing file: $f" >&2; exit 1; }
done

# Commented assignments count here: the template documents optional variables
# by commenting them out, and an option you have not set is the whole subject.
offered() { grep -oE '^[ \t]*#?[ \t]*[a-z_][a-z_0-9]*[ \t]*=' "$EXAMPLE" | tr -d ' \t#=' | sort -u; }

# Live assignments only: a commented line in your own tfvars sets nothing.
assigned() { grep -oE '^[ \t]*[a-z_][a-z_0-9]*[ \t]*=' "$TFVARS" | tr -d ' \t=' | sort -u; }

# A variable declared with no `default` must be supplied or `terraform apply` fails.
required() {
    awk '
        /^[ \t]*variable[ \t]+"/ { name = $0; sub(/^[^"]*"/, "", name); sub(/".*$/, "", name); has_default = 0; next }
        /^[ \t]*default[ \t]*=/ { has_default = 1 }
        /^}[ \t]*$/ { if (name != "" && !has_default) print name; name = "" }
    ' "$VARIABLES" | sort -u
}

# name<TAB>description, so the listing can say what each variable is for.
descriptions() {
    awk '
        /^[ \t]*variable[ \t]+"/ { name = $0; sub(/^[^"]*"/, "", name); sub(/".*$/, "", name); next }
        /^[ \t]*description[ \t]*=[ \t]*"/ {
            if (name == "") next
            d = $0; sub(/^[^"]*"/, "", d); sub(/".*$/, "", d)
            printf "%s\t%s\n", name, d
            name = ""
        }
    ' "$VARIABLES"
}

missing=$(comm -23 <(offered) <(assigned))
unknown=$(comm -13 <(offered) <(assigned))

if [ -n "$missing" ]; then
    req=$(required)
    desc=$(descriptions)

    # Descriptions run to 590 characters; the first sentence is the summary.
    # Budget: 2 indent + 2 marker + 32 name + 1 gap.
    width=$(tput cols 2>/dev/null || echo 100)
    room=$((width - 37))
    [ "$room" -lt 24 ] && room=24

    total=$(grep -c . <<<"$missing")
    n_req=$(comm -12 <(sort <<<"$missing") <(sort <<<"$req") | grep -c . || true)
    printf 'Not set in %s — %d optional, %d required:\n' \
        "$TFVARS" "$((total - n_req))" "$n_req"

    while read -r name; do
        [ -z "$name" ] && continue
        summary=$(awk -F'\t' -v n="$name" '$1 == n { print $2; exit }' <<<"$desc")
        # "(e.g. loupe.ac)" must not read as the end of the sentence.
        guarded=${summary//e.g. /e.g.$'\001'}
        guarded=${guarded//i.e. /i.e.$'\001'}
        summary=${guarded%%. *}
        summary=${summary//$'\001'/ }
        [ -n "$summary" ] && summary="${summary%.}."
        [ "${#summary}" -gt "$room" ] && summary="${summary:0:room-1}…"

        if grep -qx "$name" <<<"$req"; then
            printf '  * %-32s %s\n' "$name" "$summary"
        else
            printf '    %-32s %s\n' "$name" "$summary"
        fi
    done <<<"$missing"

    [ "$n_req" -gt 0 ] && printf '\n  * must be set — declared in %s with no default.\n' "$VARIABLES"
else
    echo "Every variable $EXAMPLE offers is set."
fi

if [ -n "$unknown" ]; then
    printf '\nSet but not offered by %s — check for a typo:\n' "$EXAMPLE"
    printf '  %s\n' $unknown
fi
