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
SYMFONY_IDE
SYMFONY_TRUSTED_PROXIES
SYMFONY_TRUST_X_SENDFILE_TYPE_HEADER
TEST_TOKEN
VAR_DUMPER_SERVER
WORKTREE_DB_SUFFIX
"

# The committed .env value is already the production answer, so no deployment
# path has to repeat it.
IMAGE_DEFAULT="
MESSENGER_TRANSPORT_DSN
"

# Set by terraform-digitalocean-symfony-app's own base_env, so they need no
# variable here. Transcribed from the ref main.tf pins, not inferred: only some
# module arguments become env vars, so deriving this from the argument list
# would excuse a name the module never sets. Re-read it when changing the pin.
MODULE_PROVIDED="
APP_ENCRYPTION_KEY
APP_ENV
APP_SECRET
APP_SHARE_DIR
DATABASE_URL
DEFAULT_URI
MAILER_DSN
MERCURE_JWT_SECRET
MERCURE_PUBLIC_URL
MERCURE_URL
MESSENGER_TRANSPORT_DSN
"

sorted() { grep -oE '[A-Z_0-9]+' | sort -u; }

# What the app reads: .env is not complete on its own — MERCURE_JWT_SECRET is
# resolved from config/ and never appears there.
app_vars() {
    {
        grep -oE '^[A-Z_][A-Z_0-9]*=' "$DOTENV" | tr -d '='
        # The name is the last colon-separated segment, after any processors and
        # a parameter fallback: %env(default:app.trusted_proxies_default:TRUSTED_PROXIES)%.
        grep -rhoE '%env\([^)]*\)%' "$CONFIG_DIR" \
            | sed -E 's/^%env\((.*)\)%$/\1/; s/.*://' \
            | grep -E '^[A-Z_][A-Z_0-9]*$'
    } | sort -u
}

# Env keys terraform hands the app: the extra_env map, plus what the module sets.
terraform_env() {
    {
        grep -oE '\b[A-Z_][A-Z_0-9]* *= *\{' "$MAIN_TF" | sed 's/ *= *{//'
        printf '%s\n' $MODULE_PROVIDED
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
deployable=$(comm -23 <(printf '%s\n' "$app") <(printf '%s\n%s\n' "$DEV_ONLY" "$IMAGE_DEFAULT" | sorted))

report "Read by the app, never set by terraform/ — add to extra_env in $MAIN_TF:" \
    "$(comm -23 <(printf '%s\n' "$deployable") <(terraform_env))"

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
report "Ignored by this script but no longer read by the app — drop from DEV_ONLY/IMAGE_DEFAULT:" \
    "$(comm -23 <(printf '%s\n%s\n' "$DEV_ONLY" "$IMAGE_DEFAULT" | sorted) <(printf '%s\n' "$app"))"

if [ "$failed" -eq 0 ]; then
    echo "deploy env parity: ok"
fi

exit "$failed"
