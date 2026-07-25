#!/usr/bin/env bash
#
# Prepare a git worktree under .claude/worktrees/ so it is a fully browsable
# application in its own right — its own URL, its own database — and so its
# gates (just ci) run against its own code, isolated from other worktrees.
#
# Only nginx is duplicated per worktree. php-fpm, Postgres, Mailpit and Mercure
# are shared with the main stack.
#
# What it does (idempotent, safe to re-run on every entry):
#   1. Real vendor/ via rsync (NOT a symlink — a symlinked vendor makes
#      Composer's autoloader resolve App\Kernel from main, so the worktree
#      would silently run main's code).
#   2. node_modules symlinks (JS tooling resolves through the link fine), and a
#      real var/tailwind so worktree-only CSS classes actually get compiled.
#   3. A per-worktree TEST database name in .env.test.local so parallel
#      `just ci` runs don't drop/recreate each other's schema.
#   4. .env.local copied from the main checkout (when absent) — carries any
#      local-only env (e.g. a real APP_ENCRYPTION_KEY) the dev kernel needs —
#      plus the three per-worktree values written below.
#   5. A per-worktree DEV database, migrated and seeded.
#   6. An nginx sidecar routed by Traefik at <slug>.<project>.dev.localhost.
#   7. Dev cache warmup — phpstan reads var/cache/dev/App_KernelDevDebugContainer.xml,
#      which only exists after a kernel boot, so a fresh worktree fails `just ci`
#      without it.
#
set -euo pipefail

root=$(git rev-parse --show-toplevel)
main=$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')

if [ "$root" = "$main" ]; then
    echo "This is the main checkout — nothing to bootstrap." >&2
    exit 0
fi
case "$root" in
    "$main"/.claude/worktrees/*) ;;
    *) echo "Worktree is not under .claude/worktrees/ — refusing to bootstrap." >&2; exit 1 ;;
esac

# Sourced relative to THIS script, so the helper always comes from the same
# checkout as the script running it (bootstrap runs from a worktree, teardown
# usually from main).
# shellcheck source=bin/worktrees/slug.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/slug.sh"

name=$(worktree_relative_name "$root" "$main")
slug=$(worktree_slug "$name")
worktree_assert_slug "$slug"
token=$(worktree_db_token "$slug")
dev_db="app_wt_$token"
test_db="app_test_$token"

project=$(grep -E '^COMPOSE_PROJECT_NAME=' "$main/.env" | head -1 | cut -d= -f2-)
project=${project:-loupe}
base_host="$project.dev.localhost"
host="$slug.$base_host"

# Every remaining step needs the stack: the database must exist to be created
# and migrated, and php-fpm runs the migrations, the seed and the CSS build.
# Fail here with a clear message rather than halfway through with a Docker one,
# which would leave .env.local pointing at a database that was never created.
if ! docker compose ps --status running --services 2>/dev/null | grep -qx php-fpm; then
    echo "worktree-bootstrap: the stack is not running. Start it first: just up" >&2
    exit 1
fi

in_worktree() {
    docker compose exec -T --workdir "/var/www/html/${root#"$main"/}" php-fpm "$@"
}

# Upsert KEY=VALUE in a dotenv file without disturbing the other lines.
set_env() {
    local file=$1 key=$2 value=$3
    [ -f "$file" ] || touch "$file"
    # The value can contain slashes and ampersands, so drive the rewrite
    # through awk rather than sed's substitution syntax.
    awk -v k="$key" -v v="$value" '
        index($0, k "=") == 1 { print k "=" v; found = 1; next }
        { print }
        END { if (!found) print k "=" v }
    ' "$file" > "$file.tmp" && mv "$file.tmp" "$file"
}

# 1. vendor/ — rsync when composer.json AND composer.lock match main (cheap,
#    content-aware). If the worktree diverged its deps, run composer install in
#    the container so vendor matches the worktree's own manifest.
if cmp -s "$main/composer.json" "$root/composer.json" \
    && cmp -s "$main/composer.lock" "$root/composer.lock"; then
    rsync -a --delete "$main/vendor/" "$root/vendor/"
else
    in_worktree composer install --no-interaction --prefer-dist
fi

# assets/vendor (importmap packages, git-ignored) must be a REAL copy, not a
# symlink: AssetMapper realpaths every asset and asserts it lives under a
# configured asset path, so a symlink resolving to main/assets/ fails the
# containment check ("cannot be found in any asset map paths").
if [ -d "$main/assets/vendor" ]; then
    mkdir -p "$root/assets/vendor"
    rsync -a --delete "$main/assets/vendor/" "$root/assets/vendor/"
fi

# 2. node_modules (host-side JS tooling: eslint, prettier). These are RELATIVE
#    symlinks: a worktree's path structure is mirrored on the host and inside
#    the container, so a relative link resolves in either context (an absolute
#    host path would dangle inside the container).
mkdir -p "$root/var"
for d in node_modules e2e/node_modules; do
    [ -e "$root/$d" ] && continue
    [ -e "$main/$d" ] || continue
    mkdir -p "$(dirname "$root/$d")"
    # A worktree lives at <main>/.claude/worktrees/<name> — 3 levels up to the
    # main root, plus one more ../ per extra path segment in $d.
    depth=$(( 3 + $(printf '%s' "$d" | tr -cd '/' | wc -c) ))
    prefix=$(printf '../%.0s' $(seq 1 "$depth"))
    ln -s "${prefix}${d}" "$root/$d"
done

# var/tailwind must be a REAL directory, not a symlink to main's. Templates
# render from the `tailwind` Twig namespace, and a shared build only ever
# contains the classes main's watcher saw — so a class introduced in this
# worktree would silently render unstyled. Only the (very large) compiler
# binaries are shared, via a per-version symlink.
if [ -L "$root/var/tailwind" ]; then
    rm "$root/var/tailwind"
fi
mkdir -p "$root/var/tailwind"
if [ -d "$main/var/tailwind" ]; then
    for bindir in "$main"/var/tailwind/*/; do
        [ -d "$bindir" ] || continue
        version=$(basename "$bindir")
        [ -e "$root/var/tailwind/$version" ] && continue
        # From <main>/.claude/worktrees/<name>/var/tailwind it is 5 levels up
        # to the main checkout. Relative so the link also resolves inside the
        # container, where the repo lives at a different absolute path.
        ln -s "../../../../../var/tailwind/$version" "$root/var/tailwind/$version"
    done
fi

# 3. Per-worktree test DB via Doctrine's TEST_TOKEN knob, so parallel `just ci`
#    runs can't drop each other's schema. The schema is created lazily by
#    tests/bootstrap.php on the first phpunit run.
printf 'TEST_TOKEN=_%s\n' "$token" > "$root/.env.test.local"

# 4. .env.local (git-ignored). Copy from main only when absent so a worktree
#    may diverge, then force the values that must differ per worktree.
if [ -f "$main/.env.local" ] && [ ! -f "$root/.env.local" ]; then
    cp "$main/.env.local" "$root/.env.local"
fi

set_env "$root/.env.local" WORKTREE_DB_SUFFIX "_wt_$token"

# The MCP endpoint's DNS-rebinding guard is an exact-hostname allowlist, so
# every worktree request would be rejected without its own host added.
mcp_hosts=$(grep -E '^MCP_ALLOWED_HOSTS=' "$main/.env" | head -1 | cut -d= -f2- | tr -d '"')
case ",$mcp_hosts," in
    *",$host,"*) ;;
    *) mcp_hosts="$mcp_hosts,$host" ;;
esac
set_env "$root/.env.local" MCP_ALLOWED_HOSTS "$mcp_hosts"

# 5. Per-worktree dev database, created, migrated and seeded. WORKTREE_DB_SUFFIX
#    is already in .env.local above, so every command below — and every browser
#    request — resolves to this database.
if ! docker compose exec -T database psql -U app -tAc \
        "SELECT 1 FROM pg_database WHERE datname = '$dev_db'" | grep -q 1; then
    docker compose exec -T database createdb -U app "$dev_db"
    echo "worktree-bootstrap: created database $dev_db"
fi

in_worktree bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration >/dev/null

# The seed prints the raw widget token exactly once, on the run that mints it.
# The token copied from main's .env.local matches no row in this fresh
# database, so without this the annotation widget loads into its
# rejected-token fatal state on every page.
seed_output=$(in_worktree bin/console app:dev:seed)
widget_token=$(printf '%s' "$seed_output" | grep -E '^SITE_REVIEW_WIDGET_TOKEN=' | head -1 | cut -d= -f2- || true)
if [ -n "${widget_token:-}" ]; then
    set_env "$root/.env.local" SITE_REVIEW_WIDGET_TOKEN "$widget_token"
fi

# Build this worktree's own CSS now that var/tailwind is local to it.
in_worktree bin/console tailwind:build >/dev/null

# 6. The nginx sidecar. Its config is the main stack's, with only the document
#    root swapped — see docker/common/nginx/default.conf.
cat > "$root/var/nginx-docroot.conf" <<EOF
# Generated by worktree-bootstrap.sh for the '$name' worktree.
root /var/www/html/${root#"$main"/}/public;
EOF

WT_MAIN="$main" \
WT_ROOT="$root" \
WT_SLUG="$slug" \
WT_BASE_HOST="$base_host" \
WT_APP_NETWORK="${project}_default" \
    docker compose -f "$root/compose.worktree.yaml" -p "${project}-wt-$slug" up -d >/dev/null

# 7. Warm the dev cache so phpstan finds var/cache/dev/App_KernelDevDebugContainer.xml.
in_worktree bin/console cache:warmup --no-interaction >/dev/null

cat <<EOF

Bootstrapped worktree '$name'
  URL       https://$host
  login     dev@loupe.test / password
  dev DB    $dev_db
  test DB   $test_db
EOF
