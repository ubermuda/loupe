# Production image coordinates — must match the terraform image_* variables
# (registry / image_repository / image_tag).
prod_image := "ghcr.io/ubermuda/loupe:prod"

default:
    @just --list

build:
    docker compose build

up:
    docker compose up -d

down:
    docker compose down

exec *args:
    bin/worktrees/compose-exec.sh {{args}}

shell: (exec "bash")

# Foreground messenger worker for the current checkout (Ctrl-C to stop).
worker:
    bin/worktrees/compose-exec.sh bin/console messenger:consume async -vv

# Never run composer on the host: the container's PHP version and extension set
# are what the lockfile is resolved against, and vendor/ is bind-mounted
# straight back into it.
# Run composer inside the php-fpm container.
composer *args:
    bin/worktrees/compose-exec.sh composer {{args}}

# Provisions its own URL, dev DB (migrated + seeded), test DB, vendor and CSS.
# No-op from the main checkout. Safe to re-run; also repairs a lost sidecar.
# Prepare the current worktree and print its URL.
# Provision (or repair) a worktree. Usage: just worktree-up NAME — or with no
# NAME from inside the worktree itself. Prefer the NAME form: it works from the
# main checkout, so no session has to cd into a worktree to run it.
worktree-up name="":
    bin/worktrees/worktree-bootstrap.sh {{name}}

# Remove a worktree along with its sidecar, route and both DBs. Usage: just worktree-down NAME
worktree-down name:
    bin/worktrees/worktree-teardown.sh {{name}}

# List every worktree with its URL, database and sidecar status.
worktrees:
    #!/usr/bin/env bash
    set -euo pipefail
    main=$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')
    . "$(pwd)/bin/worktrees/slug.sh"   # this checkout's copy; just runs from the justfile dir
    project=$(grep -E '^COMPOSE_PROJECT_NAME=' "$main/.env" | head -1 | cut -d= -f2-)
    project=${project:-loupe}
    printf '%-28s %-42s %-22s %s\n' NAME URL "DEV DB" SIDECAR
    # Driven by git, so nested worktrees are listed and the slug shown is the
    # one actually provisioned.
    worktree_slug_index "$main" | while read -r slug name; do
        container="${project}-wt-${slug}-nginx-1"
        status=$(docker ps --filter "name=^${container}$" --format '{{{{.Status}}' 2>/dev/null || true)
        printf '%-28s %-42s %-22s %s\n' \
            "$name" "https://$slug.$project.dev.localhost" \
            "app_wt_$(worktree_db_token "$slug")" "${status:-stopped}"
    done

# A plain `git worktree remove` leaves the sidecar and databases behind, and
# the route then serves 502s.
# Remove sidecars and databases whose worktree is gone.
worktree-prune:
    #!/usr/bin/env bash
    set -euo pipefail
    main=$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')
    . "$(pwd)/bin/worktrees/slug.sh"   # this checkout's copy; just runs from the justfile dir
    project=$(grep -E '^COMPOSE_PROJECT_NAME=' "$main/.env" | head -1 | cut -d= -f2-)
    project=${project:-loupe}
    # Match on the derived SLUG, never on a directory name: a worktree called
    # Feature_X owns the slug feature-x, so looking for a directory of that
    # name would declare a live worktree orphaned and drop its databases.
    live=$(worktree_slug_index "$main" | awk '{print $1}')
    for container in $(docker ps -a --filter "name=^${project}-wt-" --format '{{{{.Names}}'); do
        slug=${container#"${project}-wt-"}
        slug=${slug%-nginx-1}
        if printf '%s\n' "$live" | grep -qx "$slug"; then
            continue
        fi
        echo "pruning '$slug' (no worktree owns this slug)"
        bin/worktrees/worktree-teardown.sh "$slug"
    done

# Tailwind watch mode for the CURRENT worktree (its own var/tailwind).
worktree-tailwind:
    bin/worktrees/compose-exec.sh bin/console tailwind:build --watch

lint:
    vendor/bin/parallel-lint --exclude vendor --exclude var --exclude node_modules --exclude .claude .
    npx prettier --check --log-level warn assets/ e2e/
    npx eslint public/site-review/widget.js assets/controllers/
    cd e2e && npx eslint .

lint-e2e:
    cd e2e && npx eslint .

cs-fix:
    vendor/bin/php-cs-fixer fix

twig-cs-fix:
    vendor/bin/twig-cs-fixer fix

prettier:
    npx prettier --write --log-level warn assets/ e2e/

rector:
    vendor/bin/rector

# phpstan-symfony resolves getContainer()->get(X::class) through the dumped dev
# container XML, so a missing or stale var/cache/dev makes it abort outright
# ("Container ... does not exist") or silently degrade service types to ?object.
phpstan:
    bin/worktrees/compose-exec.sh bin/console cache:warmup
    vendor/bin/phpstan analyse -a worktree-bootstrap.php

arkitect:
    vendor/bin/phparkitect check

phpunit *args:
    bin/worktrees/compose-exec.sh vendor/bin/phpunit {{args}}

cs: prettier lint rector cs-fix twig-cs-fix

# Non-mutating counterpart of `cs` — reports instead of rewriting, for the gate.
cs-check:
    vendor/bin/rector --dry-run
    vendor/bin/php-cs-fixer fix --dry-run --diff
    vendor/bin/twig-cs-fixer lint

gamache:
    vendor/bin/gamache

# Scan the whole git history for committed secrets with two independent tools.
# Deliberately not part of `ci`: it needs host tooling and outbound network
# access. Run it before publishing, and after any history rewrite.
#
# gitleaks matches patterns and honours .gitleaksignore, which documents the
# dev/test values that are committed on purpose. trufflehog goes further and
# *verifies* candidates against the relevant provider APIs, failing only on
# credentials that actually authenticate — so it does make outbound calls.
# Scan the whole git history for committed secrets (gitleaks + trufflehog).
secrets-scan:
    @command -v gitleaks >/dev/null 2>&1 || { echo "gitleaks not installed — run: brew install gitleaks"; exit 1; }
    @command -v trufflehog >/dev/null 2>&1 || { echo "trufflehog not installed — run: brew install trufflehog"; exit 1; }
    gitleaks detect --source . --log-opts="--all" --no-banner
    trufflehog git file://. --results=verified --fail

# lint already covers parallel-lint, prettier --check and eslint (incl. e2e).
# Check-only gate: lint, style dry-run, phpstan, arkitect, gamache, PHPUnit.
ci: lint cs-check phpstan arkitect gamache phpunit

migrate-diff: (exec "bin/console doctrine:migrations:diff")

migrate-run: (exec "bin/console doctrine:migrations:migrate")

e2e *args:
    cd e2e && npx playwright test {{args}}

# CoverageSubscriber writes .cov files to var/coverage, which are then merged
# into an HTML report.
# Run e2e with per-request PHP coverage.
e2e-coverage *args:
    rm -rf var/coverage
    cd e2e && COVERAGE=1 npx playwright test {{args}}
    bin/worktrees/compose-exec.sh vendor/bin/phpcov merge var/coverage --html var/coverage/html

open-coverage:
    open var/coverage/html/index.html

browser-sync:
    npx browser-sync start --proxy localhost --files "templates/**/*.html.twig, assets/**/*.css, assets/**/*.js"

tailwind:
    bin/console tailwind:build --watch

# Expose the dev app on the reserved ngrok domain (OAuth callbacks, Stripe
# dashboard webhooks, phone testing). Requires an authenticated ngrok agent
# and the domain reserved in the ngrok dashboard. Override with TUNNEL_HOST.
tunnel:
    #!/usr/bin/env bash
    set -euo pipefail
    # .env only — the traefik router's host is fixed at compose-up from the
    # same variable, so a per-invocation shell override would tunnel to a 404.
    host="$(grep -E '^TUNNEL_HOST=' .env | cut -d= -f2- || true)"
    [ -n "$host" ] || { echo "TUNNEL_HOST is not set in .env"; exit 1; }
    ngrok http https://localhost --url "https://$host"

# Vet + test the Go CLI (cli/) in a throwaway Go container — no host Go needed.
cli-test:
    docker run --rm -v "{{justfile_directory()}}/cli":/cli -w /cli -e GOTOOLCHAIN=local golang:1.26-alpine sh -c 'go vet ./... && go test ./...'

# Defaults to the dev's mac; override e.g. `just cli-build linux amd64`. A full
# release matrix is goreleaser's job (see NEXT_STEPS).
# Cross-compile a static CLI binary into cli/dist/.
cli-build goos="darwin" goarch="arm64":
    docker run --rm -v "{{justfile_directory()}}/cli":/cli -w /cli -e GOTOOLCHAIN=local -e CGO_ENABLED=0 -e GOOS={{goos}} -e GOARCH={{goarch}} golang:1.26-alpine sh -c 'go build -o dist/loupe-{{goos}}-{{goarch}} .'

# --- Production deploy (DigitalOcean App Platform) ---
# Infra lives in terraform/; App Platform pulls {{prod_image}}.

# Build the linux/amd64 prod image (App Platform runs amd64).
build-prod:
    docker buildx build --platform linux/amd64 -t {{prod_image}} -f docker/prod/Dockerfile .

# Build, push, and roll out a new deployment (waits for it to go live).
deploy: build-prod
    docker push {{prod_image}}
    doctl apps create-deployment $(cd terraform && terraform output -raw app_id) --wait

# Tail production logs.
logs-prod:
    doctl apps logs -f $(cd terraform && terraform output -raw app_id)

# Open a shell in the prod image locally (debugging the build).
shell-prod:
    docker run -it --entrypoint /bin/bash {{prod_image}}

# --- Terraform (infra lives in terraform/) ---

# Initialise the working dir / fetch the module + provider.
tf-init:
    cd terraform && terraform init

# Format all terraform files in place.
tf-fmt:
    cd terraform && terraform fmt -recursive

# Validate configuration (offline; no API calls).
tf-validate:
    cd terraform && terraform validate

# Show the planned changes. Review before applying.
tf-plan *args:
    cd terraform && terraform plan {{args}}

# Apply changes (prompts for confirmation). Backs up local state first if present.
tf-apply *args:
    cd terraform && { [ -f terraform.tfstate ] && cp -f terraform.tfstate terraform.tfstate.bak || true; }
    cd terraform && terraform apply {{args}}

# Read outputs, e.g. `just tf-output` or `just tf-output -raw app_id`.
tf-output *args:
    cd terraform && terraform output {{args}}

# One-time DB bootstrap on the shared cluster for THIS app (run once, after the
# first `just tf-apply`). Adds the app + your current public IP to the cluster's
# trusted sources (additive — preserves sibling apps), then grants the app user
# schema privileges (PG15+ blocks CREATE on public otherwise). Needs doctl + docker.
# One-time DB bootstrap on the shared cluster for THIS app.
tf-db-bootstrap:
    #!/usr/bin/env bash
    set -euo pipefail
    cd terraform
    cid=$(terraform output -raw db_cluster_id)
    app_id=$(terraform output -raw app_id)
    db=$(terraform output -raw db_name)
    user=$(terraform output -raw db_user)
    myip=$(curl -fsS https://ifconfig.me)
    echo "cluster=$cid app=$app_id db=$db user=$user ip=$myip"
    doctl databases firewalls append "$cid" --rule app:"$app_id"
    doctl databases firewalls append "$cid" --rule ip_addr:"$myip"
    echo "Waiting for trusted-source change to propagate…"; sleep 5
    read -r host port aduser adpass < <(doctl databases connection "$cid" --format Host,Port,User,Password --no-header)
    docker run --rm \
      -e PGHOST="$host" -e PGPORT="$port" -e PGUSER="$aduser" -e PGPASSWORD="$adpass" \
      -e PGDATABASE="$db" -e PGSSLMODE=require postgres:16-alpine \
      psql -v ON_ERROR_STOP=1 \
        -c "GRANT ALL ON SCHEMA public TO \"$user\";" \
        -c "GRANT ALL PRIVILEGES ON DATABASE \"$db\" TO \"$user\";"
    echo "DB bootstrap complete."
