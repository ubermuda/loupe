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

# Run composer inside the php-fpm container. Never run it on the host: the
# container's PHP version and extension set are what the lockfile is resolved
# against, and vendor/ is bind-mounted straight back into it.
composer *args:
    bin/worktrees/compose-exec.sh composer {{args}}

# Prepare the current worktree for isolated, parallel `just ci` (rsync vendor,
# link node_modules, per-worktree test DB). No-op from the main checkout.
worktree-up:
    bin/worktrees/worktree-bootstrap.sh

# Remove a worktree and drop its per-worktree test DB. Usage: just worktree-down NAME
worktree-down name:
    bin/worktrees/worktree-teardown.sh {{name}}

lint:
    vendor/bin/parallel-lint --exclude vendor --exclude var --exclude node_modules .
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

phpstan:
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

# lint already covers parallel-lint, prettier --check and eslint (incl. e2e).
ci: lint cs-check phpstan arkitect gamache phpunit

migrate-diff: (exec "bin/console doctrine:migrations:diff")

migrate-run: (exec "bin/console doctrine:migrations:migrate")

e2e *args:
    cd e2e && npx playwright test {{args}}

# Runs e2e with per-request PHP coverage (CoverageSubscriber writes .cov files
# to var/coverage), then merges them into an HTML report.
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

# Vet + test the Go CLI (cli/) in a throwaway Go container — no host Go needed.
cli-test:
    docker run --rm -v "{{justfile_directory()}}/cli":/cli -w /cli -e GOTOOLCHAIN=local golang:1.23-alpine sh -c 'go vet ./... && go test ./...'

# Cross-compile a static CLI binary into cli/dist/. Defaults to the dev's mac;
# override e.g. `just cli-build linux amd64`. A full release matrix is goreleaser's job (see NEXT_STEPS).
cli-build goos="darwin" goarch="arm64":
    docker run --rm -v "{{justfile_directory()}}/cli":/cli -w /cli -e GOTOOLCHAIN=local -e CGO_ENABLED=0 -e GOOS={{goos}} -e GOARCH={{goarch}} golang:1.23-alpine sh -c 'go build -o dist/loupe-{{goos}}-{{goarch}} .'

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
