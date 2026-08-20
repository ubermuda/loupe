#!/usr/bin/env bash
#
# Every environment variable the app reads must reach both deployment paths —
# App Platform (terraform/) and single-host Compose (docker/compose/) — and every
# operator-settable knob on those paths must appear in the file an operator
# copies. Nothing else enforces this: `terraform validate` passes with a variable
# missing from the tfvars template, and Compose interpolates an undocumented
# variable to empty without complaint. The option is then real but undiscoverable.

set -euo pipefail

cd "$(dirname "$0")/.."

DOTENV=.env
CONFIG_DIR=config
MAIN_TF=terraform/main.tf
VARIABLES_TF=terraform/variables.tf
TFVARS_EXAMPLE=terraform/terraform.tfvars.example
PROD_YAML=docker/compose/prod.yaml
PROD_ENV_EXAMPLE=docker/compose/prod.env.example

# Read by the app in development only; no deployment path is expected to set them.
DEV_ONLY="
COMPOSE_PROJECT_NAME
MESSENGER_TRANSPORT_DSN
SYMFONY_IDE
SYMFONY_TRUSTED_PROXIES
SYMFONY_TRUST_X_SENDFILE_TYPE_HEADER
TEST_TOKEN
VAR_DUMPER_SERVER
WORKTREE_DB_SUFFIX
"

# Set by terraform-digitalocean-symfony-app itself (pinned in main.tf), so they
# have no variable here and must not be reported as unreachable.
MODULE_INTERNAL="
APP_ENV
DATABASE_URL
MERCURE_PUBLIC_URL
MERCURE_URL
TRUSTED_PROXIES
"

sorted() { grep -oE '[A-Z_0-9]+' | sort -u; }

# What the app reads: .env is not complete on its own — MERCURE_JWT_SECRET is
# resolved from config/ and never appears there.
app_vars() {
    {
        grep -oE '^[A-Z_][A-Z_0-9]*=' "$DOTENV" | tr -d '='
        grep -rhoE '%env\([A-Za-z:]*[A-Z_][A-Z_0-9]*\)%' "$CONFIG_DIR" | grep -oE '[A-Z_][A-Z_0-9]{3,}'
    } | sort -u
}

# Env keys terraform hands the app: the extra_env map, plus module arguments,
# which the module uppercases into env names (app_secret -> APP_SECRET).
terraform_env() {
    {
        grep -oE '\b[A-Z_][A-Z_0-9]* *= *\{' "$MAIN_TF" | sed 's/ *= *{//'
        sed -n '/^module "app"/,/^}/p' "$MAIN_TF" | grep -oE '^  [a-z_0-9]+ *=' | tr -d ' =' | tr 'a-z' 'A-Z'
    } | sort -u
}

terraform_declared() { grep -oE '^variable "[a-z_0-9]+"' "$VARIABLES_TF" | sed 's/variable "//;s/"//' | sort -u; }
terraform_documented() { grep -oE '^#? *[a-z_0-9]+ *=' "$TFVARS_EXAMPLE" | tr -d '#= ' | sort -u; }

# Keys the container receives.
compose_env() { sed -n '/^x-app-environment/,/^[a-z-]*:$/p' "$PROD_YAML" | grep -oE '^  [A-Z_][A-Z_0-9]*:' | tr -d ' :' | sort -u; }

# Operator knobs: an interpolation is settable and so is a bare `KEY:`, which
# passes the host's value through; a literal is not. Scans the whole file so the
# database and image sections count too.
compose_settable() {
    {
        grep -oE '\$\{[A-Z_][A-Z_0-9]*' "$PROD_YAML" | sed 's/\${//'
        grep -oE '^  [A-Z_][A-Z_0-9]*: *$' "$PROD_YAML" | tr -d ' :'
    } | sort -u
}

compose_documented() { grep -oE '^#? *[A-Z_][A-Z_0-9]*=' "$PROD_ENV_EXAMPLE" | tr -d '#= ' | sort -u; }

failed=0

report() {
    local message=$1 missing=$2
    [ -z "$missing" ] && return 0
    failed=1
    printf '\n%s\n' "$message"
    printf '  %s\n' $missing
}

app=$(app_vars)
deployable=$(comm -23 <(printf '%s\n' "$app") <(printf '%s\n' $DEV_ONLY | sorted))

report "Read by the app, never set by terraform/ — add to extra_env in $MAIN_TF:" \
    "$(comm -23 <(printf '%s\n' "$deployable") <(cat <(terraform_env) <(printf '%s\n' $MODULE_INTERNAL | sorted) | sort -u))"

report "Read by the app, never set by $PROD_YAML:" \
    "$(comm -23 <(printf '%s\n' "$deployable") <(compose_env))"

report "Declared in $VARIABLES_TF, missing from $TFVARS_EXAMPLE:" \
    "$(comm -23 <(terraform_declared) <(terraform_documented))"

report "Listed in $TFVARS_EXAMPLE, not declared in $VARIABLES_TF:" \
    "$(comm -13 <(terraform_declared) <(terraform_documented))"

report "Settable in $PROD_YAML, missing from $PROD_ENV_EXAMPLE:" \
    "$(comm -23 <(compose_settable) <(compose_documented))"

report "Listed in $PROD_ENV_EXAMPLE, never read by $PROD_YAML:" \
    "$(comm -13 <(compose_settable) <(compose_documented))"

# An ignore list that outlives the variable it excuses is where the next missing
# variable hides.
report "Ignored by this script but no longer read by the app — drop from DEV_ONLY/MODULE_INTERNAL:" \
    "$(comm -23 <(printf '%s\n%s\n' "$DEV_ONLY" "$MODULE_INTERNAL" | sorted) <(printf '%s\n' "$app"))"

if [ "$failed" -eq 0 ]; then
    echo "deploy env parity: ok"
fi

exit "$failed"
