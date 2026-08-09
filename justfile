# Production image coordinates; override with LOUPE_PROD_IMAGE=ghcr.io/you/loupe:prod.
# Set the same value in three places or they drift: here (build and push),
# terraform's registry/image_repository/image_tag, and LOUPE_PROD_IMAGE in
# compose.prod.env.
prod_image := env("LOUPE_PROD_IMAGE", "ghcr.io/ubermuda/loupe:prod")

# Recipes forwarding `*args` use "$@" rather than {{args}}, which needs this.
# {{args}} interpolates one space-joined string that the shell then re-splits,
# so `just phpunit --filter A|B` runs a pipeline into a command named B. Quoting
# {{args}} is not the fix: it collapses every argument into one, which breaks
# `just exec bin/console cache:clear`. Only "$@" keeps the boundaries intact.
set positional-arguments

default:
    @just --list

build:
    docker compose build

up:
    docker compose up -d

down:
    docker compose down

exec *args:
    bin/worktrees/compose-exec.sh "$@"

shell: (exec "bash")

# Foreground messenger worker for the current checkout (Ctrl-C to stop).
worker:
    bin/worktrees/compose-exec.sh bin/console messenger:consume async -vv

# Never run composer on the host: the container's PHP version and extension set
# are what the lockfile is resolved against, and vendor/ is bind-mounted
# straight back into it.
# Run composer inside the php-fpm container.
composer *args:
    bin/worktrees/compose-exec.sh composer "$@"

# Provisions its own URL, dev DB (migrated + seeded), test DB, vendor and CSS.
# Safe to re-run; also repairs a lost sidecar. Prefer the NAME form, which works
# from the main checkout, so no session has to cd into a worktree.
# Provision (or repair) a worktree. Usage: just worktree-up NAME
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

# phpstan-symfony resolves getContainer()->get() through the dumped dev
# container, so a stale var/cache/dev aborts or degrades types to ?object. The
# limit below is the host's and covers the analyse run only — the warmup is a
# separate process in the container, which gets its headroom from
# docker/dev/php-fpm/memory.ini.
phpstan:
    bin/worktrees/compose-exec.sh bin/console cache:warmup
    vendor/bin/phpstan analyse -a worktree-bootstrap.php --memory-limit=1G

arkitect:
    vendor/bin/phparkitect check

phpunit *args:
    bin/worktrees/compose-exec.sh vendor/bin/phpunit "$@"

cs: prettier lint rector cs-fix twig-cs-fix

# Non-mutating counterpart of `cs` — reports instead of rewriting, for the gate.
cs-check:
    vendor/bin/rector --dry-run
    vendor/bin/php-cs-fixer fix --dry-run --diff
    vendor/bin/twig-cs-fixer lint

gamache:
    vendor/bin/gamache

# Security advisories against the locked dependency set. In `ci` because two
# published advisories have gone unnoticed here, each found only by someone
# running this by hand days later. Reaches packagist's advisory API and works
# unauthenticated, unlike `composer update`, which needs a GitHub token for the
# VCS repositories and is what the anonymous rate limit actually bites.
audit:
    bin/worktrees/compose-exec.sh composer audit

# Not part of `ci`, unlike `audit` above: needs host tooling as well as outbound
# network, and scans all of history rather than the current tree. gitleaks matches
# patterns and honours .gitleaksignore; trufflehog verifies candidates against
# provider APIs, so it really does call out. Run before publishing.
# Scan the whole git history for committed secrets (gitleaks + trufflehog).
secrets-scan:
    @command -v gitleaks >/dev/null 2>&1 || { echo "gitleaks not installed — run: brew install gitleaks"; exit 1; }
    @command -v trufflehog >/dev/null 2>&1 || { echo "trufflehog not installed — run: brew install trufflehog"; exit 1; }
    gitleaks detect --source . --log-opts="--all" --no-banner
    # Lob is excluded because its key format is a `test_`-prefixed string, which
    # every PHP test method name in this project matches — 43 "verified" hits,
    # all of them method names. Nothing here talks to Lob.
    trufflehog git file://. --results=verified --fail --exclude-detectors=lob

# lint already covers parallel-lint, prettier --check and eslint (incl. e2e).
# Check-only gate: lint, style dry-run, phpstan, arkitect, gamache, advisories, PHPUnit.
ci: lint cs-check phpstan arkitect gamache audit phpunit

migrate-diff: (exec "bin/console doctrine:migrations:diff")

migrate-run: (exec "bin/console doctrine:migrations:migrate")

# Needed only for site-review push; the e2e suite passes without it. Leaving it
# stopped loses nothing: submissions reach the outbox first and the scheduled
# drain replays them once a hub is back.
# Start the opt-in Mercure hub.
mercure-up:
    docker compose --profile mercure up -d mercure

# `stop` + `rm` rather than `down`, for the reason on garage-down below.
# Stop and remove the Mercure hub.
mercure-down:
    docker compose --profile mercure stop mercure
    docker compose --profile mercure rm -f mercure

# Off by default; development runs EXPORT_STORAGE=local. Set the
# EXPORT_STORAGE_* vars in .env.local and restart BOTH php-fpm and worker — the
# worker builds the archive. REGION must be `garage` and path-style is required.
# Start the opt-in Garage node, and create its bucket and access key.
garage-up bucket="loupe-exports":
    #!/usr/bin/env bash
    set -euo pipefail
    docker compose --profile garage up -d garage

    g() { docker compose exec -T garage /garage "$@"; }

    # `up -d` returns when the container starts, not when the node answers RPC.
    for _ in $(seq 30); do
        if g status >/dev/null 2>&1; then break; fi
        sleep 1
    done

    # A fresh node holds no layout and refuses every S3 call until one is
    # applied — the failure is a 500 with no hint that layout is what is
    # missing. Assigning is idempotent in effect: re-applying an unchanged
    # layout is a no-op, so this stays safe to re-run.
    if ! g layout show 2>/dev/null | grep -q "zone"; then
        node=$(g node id -q | cut -d@ -f1)
        g layout assign -z dev -c 1G "$node" >/dev/null
        g layout apply --version 1 >/dev/null
    fi

    # Imported rather than created, so the credentials are the fixed ones above
    # instead of a fresh pair to copy out of the logs on every reset.
    if ! g key info loupe-exports >/dev/null 2>&1; then
        g key import \
            GK31c2f218a2e44f485b94239e \
            b8c2f4d1e6a390f75c8b1d24e73a5f9016cbe482d5a7139fc0e6b84a2d517f3b \
            -n loupe-exports --yes >/dev/null
    fi

    g bucket create "{{bucket}}" >/dev/null 2>&1 || true
    g bucket allow --read --write --owner "{{bucket}}" --key loupe-exports >/dev/null

    echo "garage: bucket '{{bucket}}' ready at http://garage:3900 (key GK31c2f218a2e44f485b94239e)"

# `stop` + `rm`, never `down`. `docker compose down <service>` was observed
# attempting to remove the shared `loupe_default` network — it only failed
# because another container still held it. Same family as the bare-compose rule
# in the project-worktrees skill: a `down` does not stay in its lane.
# Stop and remove the Garage container, keeping its stored objects.
garage-down:
    docker compose --profile garage stop garage
    docker compose --profile garage rm -f garage

# Stop Garage and discard its objects and metadata with it.
garage-reset:
    #!/usr/bin/env bash
    set -euo pipefail
    # Found by Compose's own labels, not by deriving `<project>_garage_*` from
    # .env — that misses a COMPOSE_PROJECT_NAME overridden in the environment
    # and would delete another project's volumes. Labels rather than the
    # container's mounts, because the container is gone once `garage-down` has
    # run and the volumes outlive it.
    project=$(docker compose config --format json | python3 -c 'import sys, json; print(json.load(sys.stdin)["name"])')
    volumes=$(docker volume ls -q --filter "label=com.docker.compose.project=$project" | grep -E '_garage_(meta|data)$' || true)
    just garage-down
    if [ -n "$volumes" ]; then
        echo "$volumes" | xargs docker volume rm -f
        echo "garage: removed $(echo "$volumes" | wc -l | tr -d ' ') volume(s)"
    else
        echo "garage: no volumes to remove" >&2
    fi

# Provision the dedicated e2e target: a disposable database plus an nginx sidecar
# serving THIS checkout. Idempotent. The suite truncates every table, so it must
# never point at the dev database.
e2e-up:
    #!/usr/bin/env bash
    set -euo pipefail
    main=$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')
    project=$(grep -E '^COMPOSE_PROJECT_NAME=' "$main/.env" | head -1 | cut -d= -f2-)
    host="e2e.${project}.dev.localhost"
    db="app_e2e"

    # fastcgi_param lands in $_SERVER; Symfony's Dotenv skips names already
    # there, so these beat .env.local. WORKTREE_DB_SUFFIX is the same knob a
    # worktree uses (doctrine.yaml: dbname_suffix), so app -> app_e2e.
    {
        printf '%s\n' "# Generated by 'just e2e-up' - do not edit, do not commit."
        printf '%s\n' 'fastcgi_param WORKTREE_DB_SUFFIX "_e2e";'
        printf 'fastcgi_param DEFAULT_URI "https://%s";\n' "$host"
    } > "$main/var/nginx-e2e-env.conf"

    if ! docker compose exec -T database psql -U app -tAc \
        "SELECT 1 FROM pg_database WHERE datname='${db}'" | grep -q 1; then
        docker compose exec -T database createdb -U app "${db}"
        echo "e2e: created database ${db}"
    fi

    docker compose exec -T -e WORKTREE_DB_SUFFIX=_e2e php-fpm \
        bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration >/dev/null
    docker compose exec -T -e WORKTREE_DB_SUFFIX=_e2e php-fpm \
        bin/console app:dev:seed >/dev/null

    E2E_MAIN="$main" E2E_PROJECT="$project" E2E_HOST="$host" \
    E2E_APP_NETWORK="${project}_default" \
        docker compose -f "$main/compose.e2e.yaml" -p "${project}-e2e" up -d >/dev/null

    echo "e2e target ready at https://${host} (database ${db})"
    echo "Run 'just e2e-worker' in another shell before specs that need mail or exports."

# A worker is a CLI process, so it never sees the fastcgi_params pointing web
# requests at the e2e database — without both variables it consumes the DEV
# queue. --memory-limit must sit BELOW PHP's own (128M here) or it never fires.
# Messenger worker for the e2e target. Runs until interrupted.
e2e-worker *args:
    #!/usr/bin/env bash
    set -euo pipefail
    main=$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')
    project=$(grep -E '^COMPOSE_PROJECT_NAME=' .env | head -1 | cut -d= -f2-)
    host="e2e.${project}.dev.localhost"
    stop_marker="$main/var/e2e-worker.stop"
    rm -f "$stop_marker"
    trap 'echo "e2e-worker: stopped." >&2; exit 0' INT TERM
    while true; do
        if ! docker compose exec -T \
            -e WORKTREE_DB_SUFFIX=_e2e \
            -e DEFAULT_URI="https://${host}" \
            php-fpm bin/console messenger:consume scheduler_default async \
            --time-limit=3600 --memory-limit=100M "$@"; then
            echo "e2e-worker: consumer exited non-zero — stopping rather than looping on a failure." >&2
            exit 1
        fi
        # A clean exit is either a limit recycle (relaunch) or `e2e-down`
        # stopping us before it drops the database (do not), and the exit code
        # cannot separate them. The marker can: e2e-down writes it BEFORE
        # signalling, so it cannot race. Not cleared here — several workers may
        # be looping, and the first to clear it would strand the rest.
        if [ -f "$stop_marker" ]; then
            echo "e2e-worker: teardown requested — stopping." >&2
            exit 0
        fi
        echo "e2e-worker: consumer recycled on a limit, relaunching." >&2
    done

# Remove the e2e sidecar and drop its database.
e2e-down:
    #!/usr/bin/env bash
    set -euo pipefail
    main=$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')
    project=$(grep -E '^COMPOSE_PROJECT_NAME=' "$main/.env" | head -1 | cut -d= -f2-)
    # Not optional: with these unset, `down` does NOT fail safely — it was
    # observed removing the MAIN stack's containers and deleting loupe_default,
    # despite -p naming another project. Assert before invoking, so a future
    # edit that drops one stops here instead of taking the dev stack down.
    for required in main project; do
        if [ -z "${!required:-}" ]; then
            echo "e2e-down: refusing to run — \$$required is empty." >&2
            exit 1
        fi
    done

    E2E_MAIN="$main" E2E_PROJECT="$project" \
    E2E_HOST="e2e.${project}.dev.localhost" \
    E2E_APP_NETWORK="${project}_default" \
        docker compose -f "$main/compose.e2e.yaml" -p "${project}-e2e" down >/dev/null

    # Stop the worker first, or it survives to its --time-limit and the next
    # `e2e-up` gets two consumers on one queue. Matched by environment, not
    # command line — the main worker's command is byte-identical. The marker
    # goes down BEFORE the signal, or e2e-worker reads the clean exit as a
    # recycle and relaunches into a dropped database.
    : > "$main/var/e2e-worker.stop"
    docker compose exec -T php-fpm sh -c '
        for proc in /proc/[0-9]*; do
            pid=${proc#/proc/}
            [ -r "$proc/environ" ] || continue
            tr "\0" "\n" < "$proc/environ" 2>/dev/null | grep -qx "WORKTREE_DB_SUFFIX=_e2e" || continue
            tr "\0" " " < "$proc/cmdline" 2>/dev/null | grep -q "messenger:consume" || continue
            kill "$pid" 2>/dev/null || true
        done' 2>/dev/null || true

    # The database may legitimately not exist; the sidecar removal above may
    # not. Only these are tolerant.
    docker compose exec -T database psql -U app -d postgres -tAc \
        "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='app_e2e'" >/dev/null 2>&1 || true
    docker compose exec -T database dropdb -U app --if-exists app_e2e >/dev/null 2>&1 || true
    rm -f "$main/var/nginx-e2e-env.conf"
    echo "e2e target removed"

# Run the Playwright suite. Defaults to the dedicated e2e target, NOT the dev
# host — the suite truncates every table, so pointing it at dev wipes your
# development database. Run `just e2e-up` first. Override with E2E_BASE_URL to
# aim it elsewhere (e.g. a worktree).
e2e *args:
    #!/usr/bin/env bash
    set -euo pipefail
    project=$(grep -E '^COMPOSE_PROJECT_NAME=' .env | head -1 | cut -d= -f2-)
    dedicated_url="https://e2e.${project}.dev.localhost"
    export E2E_BASE_URL="${E2E_BASE_URL:-$dedicated_url}"
    if ! curl -sf -o /dev/null "$E2E_BASE_URL/login"; then
        echo "e2e: $E2E_BASE_URL is not reachable — run 'just e2e-up' first." >&2
        exit 1
    fi
    # With nothing consuming `async`, login, signup, the wizard and paywall all
    # fail while the app returns 200 and `just ci` is green. Matching the command
    # line is not enough — `just worker` is byte-identical against the DEV
    # database — so read the environment. A worktree's consumer is invisible
    # from here, so that case is skipped rather than failed.
    if [ "$E2E_BASE_URL" = "$dedicated_url" ]; then
        if ! docker compose exec -T php-fpm sh -c '
            for p in /proc/[0-9]*; do
                grep -qa "messenger:consume" "$p/cmdline" 2>/dev/null || continue
                tr "\0" "\n" < "$p/environ" 2>/dev/null | grep -qx "WORKTREE_DB_SUFFIX=_e2e" && exit 0
            done
            exit 1'; then
            echo "e2e: no messenger consumer for the e2e database — run 'just e2e-worker' in another shell." >&2
            echo "     Without one the authenticated fixture cannot verify its user, and roughly" >&2
            echo "     a third of the suite fails in ways that look like application bugs." >&2
            echo "     A consumer for the dev database does not count: it drains a different queue." >&2
            exit 1
        fi
    else
        echo "e2e: targeting $E2E_BASE_URL — make sure that worktree has a consumer running." >&2
    fi
    cd e2e && npx playwright test "$@"

# CoverageSubscriber writes .cov files to var/coverage, which are then merged
# into an HTML report.
# Run e2e with per-request PHP coverage.
e2e-coverage *args:
    #!/usr/bin/env bash
    set -euo pipefail
    # Same target and same guard as `just e2e`. Without this the coverage run
    # fell through to Playwright's own default of the dev host — a documented
    # full-suite command that truncates the development database.
    project=$(grep -E '^COMPOSE_PROJECT_NAME=' .env | head -1 | cut -d= -f2-)
    export E2E_BASE_URL="${E2E_BASE_URL:-https://e2e.${project}.dev.localhost}"
    if ! curl -sf -o /dev/null "$E2E_BASE_URL/login"; then
        echo "e2e: $E2E_BASE_URL is not reachable — run 'just e2e-up' first." >&2
        exit 1
    fi
    rm -rf var/coverage
    cd e2e && COVERAGE=1 npx playwright test "$@"
    cd .. && bin/worktrees/compose-exec.sh vendor/bin/phpcov merge var/coverage --html var/coverage/html

open-coverage:
    open var/coverage/html/index.html

browser-sync:
    npx browser-sync start --proxy localhost --files "templates/**/*.html.twig, assets/**/*.css, assets/**/*.js"

tailwind:
    bin/console tailwind:build --watch

# Expose the dev app on the reserved ngrok domain (OAuth callbacks, Stripe
# dashboard webhooks, phone testing). Requires an authenticated ngrok agent
# and the domain reserved in the ngrok dashboard. Set TUNNEL_HOST in .env — it
# ships commented out, because a reserved domain belongs to one ngrok account.
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

# Build the prod image. Defaults to linux/amd64 because App Platform runs amd64;
# pass a platform to build for the host instead, which is what a self-hoster on
# arm64 running compose.prod.yaml wants: `just build-prod linux/arm64`.
build-prod platform="linux/amd64":
    docker buildx build --platform {{platform}} -t {{prod_image}} -f docker/prod/Dockerfile .

# Build and push the image without deploying — the first deploy needs this,
# because the App Platform app does not exist yet to deploy to.
push-prod: build-prod
    docker push {{prod_image}}

# Build, push, and roll out a new deployment (waits for it to go live).
deploy: push-prod
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
    cd terraform && terraform plan "$@"

# Apply changes (prompts for confirmation). Backs up local state first if present.
tf-apply *args:
    cd terraform && { [ -f terraform.tfstate ] && cp -f terraform.tfstate terraform.tfstate.bak || true; }
    cd terraform && terraform apply "$@"

# Read outputs, e.g. `just tf-output` or `just tf-output -raw app_id`.
tf-output *args:
    cd terraform && terraform output "$@"

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
