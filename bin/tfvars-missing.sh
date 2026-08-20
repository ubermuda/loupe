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

missing=$(comm -23 <(offered) <(assigned))
unknown=$(comm -13 <(offered) <(assigned))

if [ -n "$missing" ]; then
    req=$(required)
    echo "Not set in $TFVARS:"
    while read -r name; do
        [ -z "$name" ] && continue
        if grep -qx "$name" <<<"$req"; then
            printf '  %-32s required\n' "$name"
        else
            printf '  %s\n' "$name"
        fi
    done <<<"$missing"
else
    echo "Every variable $EXAMPLE offers is set."
fi

if [ -n "$unknown" ]; then
    printf '\nSet but not offered by %s — check for a typo:\n' "$EXAMPLE"
    printf '  %s\n' $unknown
fi
