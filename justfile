# Production image coordinates; override with LOUPE_PROD_IMAGE=ghcr.io/you/loupe:prod.
# Set the same value in three places or they drift: here (build and push),
# terraform's registry/image_repository/image_tag, and LOUPE_PROD_IMAGE in
# docker/compose/prod.env.
prod_image := env("LOUPE_PROD_IMAGE", "ghcr.io/ubermuda/loupe:prod")

# The single-container demo image, and the host's own architecture, which is
# what it must be built for: an emulated Postgres makes for a poor demo.
demo_image := env("LOUPE_DEMO_IMAGE", "ghcr.io/ubermuda/loupe:demo")
host_platform := "linux/" + if arch() == "x86_64" { "amd64" } else { "arm64" }

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
    # Read the compose project from the container's own label rather than
    # reconstructing it by stripping a service suffix off the name. A worktree
    # has more than one sidecar, so "strip -nginx-1" mis-parsed a Mailpit-only
    # orphan as the slug '<slug>-mailpit-1' — the exact state prune exists for.
    for compose_project in $(docker ps -a --filter "name=^${project}-wt-" \
            --format '{{{{.Label "com.docker.compose.project"}}' | sort -u); do
        case "$compose_project" in
            "${project}-wt-"?*) ;;
            *) echo "worktree-prune: ignoring '$compose_project' (not a worktree project)" >&2; continue ;;
        esac
        slug=${compose_project#"${project}-wt-"}
        if printf '%s\n' "$live" | grep -qx "$slug"; then
            continue
        fi
        # This slug is about to drive a database drop, so make it prove it is
        # one bootstrap could have minted before anything destructive runs.
        if [ "$(worktree_slug "$slug")" != "$slug" ] || ! worktree_assert_slug "$slug" 2>/dev/null; then
            echo "worktree-prune: refusing '$compose_project' — '$slug' is not a slug bootstrap could have created." >&2
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

# `--locked` audits composer.lock, not whatever is installed: a stale vendor/
# must not turn a vulnerable lockfile green, and in a worktree the two drift
# routinely. Reaches packagist's advisory API, which works unauthenticated —
# the anonymous rate limit bites `composer update`, not this.
# No --omit=dev: every npm package here is tooling, so it would scan nothing.
audit:
    bin/worktrees/compose-exec.sh composer audit --locked
    npm audit
    cd e2e && npm audit

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
# Check-only gate: lint, style dry-run, phpstan, arkitect, gamache, advisories, PHPUnit, Go CLI.
ci: lint cs-check phpstan arkitect gamache audit phpunit cli-test

# One argument per word: `set positional-arguments` forwards a quoted string to
# compose-exec.sh whole, so Docker looks for a binary with a space in its name.
migrate-diff: (exec "bin/console" "doctrine:migrations:diff")

migrate-run: (exec "bin/console" "doctrine:migrations:migrate")

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
        printf '%s\n' 'fastcgi_param SITE_REVIEW_WIDGET_BACKEND "";'
    } > "$main/var/nginx-e2e-env.conf"

    # Always from scratch. A reused database carries over whatever the last run
    # left behind — a half-registered fixture user is enough to fail every
    # worker-fixture spec on every later run, and it reads as a code regression
    # rather than stale state. --force because a worker may still hold a handle.
    if docker compose exec -T database psql -U app -tAc \
        "SELECT 1 FROM pg_database WHERE datname='${db}'" | grep -q 1; then
        docker compose exec -T database dropdb -U app --force "${db}"
    fi
    docker compose exec -T database createdb -U app "${db}"
    echo "e2e: created database ${db}"

    docker compose exec -T -e WORKTREE_DB_SUFFIX=_e2e php-fpm \
        bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration >/dev/null
    docker compose exec -T -e WORKTREE_DB_SUFFIX=_e2e php-fpm \
        bin/console app:dev:seed >/dev/null

    E2E_MAIN="$main" E2E_PROJECT="$project" E2E_HOST="$host" \
    E2E_APP_NETWORK="${project}_default" \
        docker compose -f "$main/docker/compose/e2e.yaml" -p "${project}-e2e" up -d >/dev/null

    # The env file above is bind-mounted, so a container that was already up
    # keeps serving the previous values until nginx re-reads it.
    E2E_MAIN="$main" E2E_PROJECT="$project" E2E_HOST="$host" \
    E2E_APP_NETWORK="${project}_default" \
        docker compose -f "$main/docker/compose/e2e.yaml" -p "${project}-e2e" \
        exec -T nginx nginx -s reload >/dev/null

    echo "e2e target ready at https://${host} (database ${db})"

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
        docker compose -f "$main/docker/compose/e2e.yaml" -p "${project}-e2e" down >/dev/null

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
    { read -r E2E_BASE_URL; read -r worktree; read -r main; read -r MAILPIT_URL; } < <(bin/e2e-target.sh)
    export E2E_BASE_URL MAILPIT_URL
    [ -n "$worktree" ] && echo "e2e: worktree '$worktree' detected"
    echo "e2e: target $E2E_BASE_URL"
    echo "e2e: mailpit $MAILPIT_URL"
    if ! curl -sf -o /dev/null "$E2E_BASE_URL/login"; then
        echo "e2e: $E2E_BASE_URL is not reachable — run 'just e2e-up' (or 'just worktree-up') first." >&2
        exit 1
    fi
    # A worktree's stylesheet is built once at provision time and by nothing
    # afterwards, so a tree alive across a redesign serves CSS its branch had
    # days ago while its Twig is current — which reads as a spec regression.
    if [ -n "$worktree" ]; then
        echo "e2e: rebuilding $worktree's stylesheet"
        bin/worktrees/compose-exec.sh bin/console tailwind:build >/dev/null
    fi
    status=0
    ( cd e2e && npx playwright test "$@" ) || status=$?
    # install-reset truncates every table, so a worktree loses the dev user and
    # project it was seeded with. Restored here rather than left to surface
    # later as "the login does not work". The suite's exit code is preserved:
    # a re-seed must never turn a red run green.
    if [ -n "$worktree" ]; then
        # worktree-up rather than a bare seed: install-reset drops the project
        # the widget token belongs to, and bootstrap is what notices the token
        # in .env.local no longer resolves and reissues it. Run from main, where
        # the bare compose calls inside it resolve correctly.
        echo "e2e: repairing $worktree (install-reset truncates its dev data)"
        ( cd "$main" && bin/worktrees/worktree-bootstrap.sh "$worktree" >/dev/null )
    fi
    exit $status

# CoverageSubscriber writes .cov files to var/coverage, which are then merged
# into an HTML report.
# Run e2e with per-request PHP coverage.
e2e-coverage *args:
    #!/usr/bin/env bash
    set -euo pipefail
    # Same target resolution as `just e2e`, through the same script so the two
    # cannot drift: this recipe once fell through to Playwright's own default of
    # the dev host and truncated the development database.
    { read -r E2E_BASE_URL; read -r worktree; read -r main; read -r MAILPIT_URL; } < <(bin/e2e-target.sh)
    export E2E_BASE_URL MAILPIT_URL
    echo "e2e: target $E2E_BASE_URL"
    echo "e2e: mailpit $MAILPIT_URL"
    if ! curl -sf -o /dev/null "$E2E_BASE_URL/login"; then
        echo "e2e: $E2E_BASE_URL is not reachable — run 'just e2e-up' (or 'just worktree-up') first." >&2
        exit 1
    fi
    # Same stale-stylesheet rebuild as `just e2e`, for the same reason.
    if [ -n "$worktree" ]; then
        echo "e2e: rebuilding $worktree's stylesheet"
        bin/worktrees/compose-exec.sh bin/console tailwind:build >/dev/null
    fi
    rm -rf var/coverage
    status=0
    ( cd e2e && COVERAGE=1 npx playwright test "$@" ) || status=$?
    # Same repair as `just e2e`, for the same reason: this run is destructive to
    # a worktree's dev data, and `set -e` would otherwise skip the repair on
    # exactly the failing runs that leave the tree in the worst state.
    if [ -n "$worktree" ]; then
        # worktree-up rather than a bare seed: install-reset drops the project
        # the widget token belongs to, and bootstrap is what notices the token
        # in .env.local no longer resolves and reissues it. Run from main, where
        # the bare compose calls inside it resolve correctly.
        echo "e2e: repairing $worktree (install-reset truncates its dev data)"
        ( cd "$main" && bin/worktrees/worktree-bootstrap.sh "$worktree" >/dev/null )
    fi
    [ "$status" -eq 0 ] || exit $status
    bin/worktrees/compose-exec.sh vendor/bin/phpcov merge var/coverage --html var/coverage/html

open-coverage:
    open var/coverage/html/index.html

browser-sync:
    npx browser-sync start --proxy localhost --files "templates/**/*.html.twig, assets/**/*.css, assets/**/*.js"

# The `tailwind` compose service already watches; this is the foreground
# equivalent, for when you want to see the rebuilds.
tailwind:
    bin/worktrees/compose-exec.sh bin/console tailwind:build --watch

# --- Docs site (Starlight, reads docs/ — see website/README.md) ---

# Runs on the host, not in a container: the site is a static build with no
# dependency on the app. First run installs into website/node_modules.
docs-install:
    cd website && npm install

# Live-reloading preview of docs/ at http://localhost:4321/loupe/ (Ctrl-C to
# stop). The /loupe/ prefix is the deployed base path, applied in dev too so a
# link that would 404 on Pages 404s here first.
docs *args: docs-install
    cd website && npx astro dev "$@"

# Static build into website/dist, including the Pagefind search index.
docs-build: docs-install
    cd website && npx astro build

# Serve the built site, to check the real output rather than the dev server.
docs-preview: docs-build
    cd website && npx astro preview

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

# Defaults to the dev's mac; override e.g. `just cli-build linux amd64`. The
# full release matrix is goreleaser's job — see cli/.goreleaser.yaml.
# Cross-compile a static CLI binary into cli/dist/.
cli-build goos="darwin" goarch="arm64":
    docker run --rm -v "{{justfile_directory()}}/cli":/cli -w /cli -e GOTOOLCHAIN=local -e CGO_ENABLED=0 -e GOOS={{goos}} -e GOARCH={{goarch}} golang:1.26-alpine sh -c 'go build -o dist/loupe-{{goos}}-{{goarch}} .'

# --- Production deploy (DigitalOcean App Platform) ---
# Infra lives in terraform/; App Platform pulls {{prod_image}}.

# Build the prod image. Defaults to linux/amd64 because App Platform runs amd64;
# pass a platform to build for the host instead: `just build-prod linux/arm64`.
# APP_VERSION is what /about reports; an image built without it says so instead.
build-prod platform="linux/amd64":
    docker buildx build --platform {{platform}} --load --build-arg APP_VERSION="$(git describe --tags --always --dirty)" --build-arg APP_SOURCE_URL="${APP_SOURCE_URL:-https://github.com/ubermuda/loupe}" -t {{prod_image}} -f docker/prod/Dockerfile .

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

# --- Demo image (one container: app, worker, Postgres) ---

# Host architecture only, because --load cannot take a manifest list.
build-demo platform=host_platform:
    PLATFORMS={{platform}} DEMO_IMAGE={{demo_image}} APP_VERSION="$(git describe --tags --always --dirty)" docker buildx bake -f docker/bake.hcl demo --load

# Publish for both architectures — most people running it are on one or the
# other, and the wrong one fails only after the whole image has been pulled.
# The GHCR package must be public separately from the repository.
push-demo:
    DEMO_IMAGE={{demo_image}} APP_VERSION="$(git describe --tags --always --dirty)" docker buildx bake -f docker/bake.hcl demo --push

# Loopback-bound: the demo's admin password is published, so a demo on a laptop
# must not be reachable from the rest of the network.
demo port="8080": build-demo
    docker run --rm -it -p 127.0.0.1:{{port}}:80 -e DEFAULT_URI=http://localhost:{{port}} {{demo_image}}

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

# One-time DB bootstrap for THIS app, run once after the first `just tf-apply`.
# Grants the app user schema privileges (PG15+ blocks CREATE on public otherwise)
# and, when attaching to a cluster someone else owns, appends the app + your
# public IP to its trusted sources. Needs doctl + docker.
tf-db-bootstrap:
    #!/usr/bin/env bash
    set -euo pipefail
    cd terraform
    cid=$(terraform output -raw db_cluster_id)
    app_id=$(terraform output -raw app_id)
    db=$(terraform output -raw db_name)
    user=$(terraform output -raw db_user)
    dedicated=$(terraform output -raw db_cluster_is_dedicated)
    myip=$(curl -fsS https://ifconfig.me)
    echo "cluster=$cid app=$app_id db=$db user=$user ip=$myip dedicated=$dedicated"
    # In dedicated mode the module declares a digitalocean_database_firewall for
    # the cluster, and that resource replaces the WHOLE trusted-source list — so
    # anything appended here is dropped by the next apply, including the app's
    # own rule. Terraform is the only supported channel there.
    if [ "$dedicated" = "true" ]; then
      echo "Dedicated cluster: skipping the firewall append (Terraform owns the trusted-source list)."
      echo "If this host cannot reach the cluster, add $myip to db_cluster_trusted_ips in"
      echo "terraform.tfvars, run 'just tf-apply', re-run this recipe, then empty it and apply again."
    else
      doctl databases firewalls append "$cid" --rule app:"$app_id"
      doctl databases firewalls append "$cid" --rule ip_addr:"$myip"
      echo "Waiting for trusted-source change to propagate…"; sleep 5
    fi
    read -r host port aduser adpass < <(doctl databases connection "$cid" --format Host,Port,User,Password --no-header)
    docker run --rm \
      -e PGHOST="$host" -e PGPORT="$port" -e PGUSER="$aduser" -e PGPASSWORD="$adpass" \
      -e PGDATABASE="$db" -e PGSSLMODE=require postgres:16-alpine \
      psql -v ON_ERROR_STOP=1 \
        -c "GRANT ALL ON SCHEMA public TO \"$user\";" \
        -c "GRANT ALL PRIVILEGES ON DATABASE \"$db\" TO \"$user\";"
    echo "DB bootstrap complete."
