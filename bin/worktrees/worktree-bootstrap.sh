#!/usr/bin/env bash
#
# Prepare a git worktree under .claude/worktrees/ so its gates (just ci) run
# against the worktree's own code, isolated from other worktrees — without
# spinning up a second compose stack.
#
# What it does (idempotent, safe to re-run on every entry):
#   1. Real vendor/ via rsync (NOT a symlink — a symlinked vendor makes
#      Composer's autoloader resolve App\Kernel from main, so the worktree
#      would silently run main's code).
#   2. node_modules symlinks (JS tooling resolves through the link fine).
#   3. A per-worktree TEST database name in .env.test.local so parallel
#      `just ci` runs don't drop/recreate each other's schema. Only the test
#      DB needs isolating — e2e (the dev app) runs serially via the main
#      checkout, so the dev DB and the rest of the stack stay shared.
#   4. .env.local copied from the main checkout (when absent) — carries any
#      local-only env (e.g. a real APP_ENCRYPTION_KEY) the dev kernel needs.
#   5. Dev cache warmup — phpstan reads var/cache/dev/App_KernelDevDebugContainer.xml,
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

name=$(basename "$root")
db="app_test_$(printf '%s' "$name" | tr -c 'a-zA-Z0-9' '_')"

# 1. vendor/ — rsync when composer.json AND composer.lock match main (cheap,
#    content-aware). If the worktree diverged its deps, run composer install in
#    the container so vendor matches the worktree's own manifest.
if cmp -s "$main/composer.json" "$root/composer.json" \
    && cmp -s "$main/composer.lock" "$root/composer.lock"; then
    rsync -a --delete "$main/vendor/" "$root/vendor/"
else
    if ! docker compose ps --status running --services 2>/dev/null | grep -qx php-fpm; then
        echo "worktree-bootstrap: composer.json/lock differ from main and php-fpm is not running." >&2
        echo "Start the stack first: just up" >&2
        exit 1
    fi
    docker compose exec --workdir "/var/www/html/${root#"$main"/}" php-fpm \
        composer install --no-interaction --prefer-dist
fi

# assets/vendor (importmap packages, git-ignored) must be a REAL copy, not a
# symlink: AssetMapper realpaths every asset and asserts it lives under a
# configured asset path, so a symlink resolving to main/assets/ fails the
# containment check ("cannot be found in any asset map paths").
if [ -d "$main/assets/vendor" ]; then
    mkdir -p "$root/assets/vendor"
    rsync -a --delete "$main/assets/vendor/" "$root/assets/vendor/"
fi

# 2. node_modules (host-side JS tooling: eslint, prettier) and var/tailwind (the
#    Tailwind build output, git-ignored and produced by the dev watcher rather
#    than the kernel). Tests render templates from the `tailwind` Twig namespace
#    (var/tailwind), so a worktree without it 500s on template-rendering tests.
#    These are RELATIVE symlinks: var/tailwind is read inside the php-fpm
#    container (repo at /var/www/html) while node_modules is read on the host, and
#    a worktree's path structure is mirrored in both, so a relative link resolves
#    in either context (an absolute host path would dangle inside the container).
mkdir -p "$root/var"
for d in node_modules e2e/node_modules var/tailwind; do
    [ -e "$root/$d" ] && continue
    [ -e "$main/$d" ] || continue
    mkdir -p "$(dirname "$root/$d")"
    # A worktree lives at <main>/.claude/worktrees/<name> — 3 levels up to the
    # main root, plus one more ../ per extra path segment in $d.
    depth=$(( 3 + $(printf '%s' "$d" | tr -cd '/' | wc -c) ))
    prefix=$(printf '../%.0s' $(seq 1 "$depth"))
    ln -s "${prefix}${d}" "$root/$d"
done

# 3. Per-worktree test DB via Doctrine's TEST_TOKEN knob. In the test env,
#    config/packages/doctrine.yaml sets dbname_suffix '_test%env(default::TEST_TOKEN)%',
#    so setting TEST_TOKEN gives this worktree its own test DB (app_test_<name>) and
#    parallel `just ci` runs can't drop each other's schema. .env.test.local is
#    git-ignored and loaded by Symfony in the test environment. The schema is
#    created lazily by tests/bootstrap.php on the first phpunit run.
token=$(printf '%s' "$name" | tr -c 'a-zA-Z0-9' '_')
printf 'TEST_TOKEN=_%s\n' "$token" > "$root/.env.test.local"

# 4. .env.local (git-ignored). The dev kernel — and therefore cache:warmup and
#    phpstan's container XML — reads it for any local-only env. Copy from main
#    only when absent so a worktree may diverge.
if [ -f "$main/.env.local" ] && [ ! -f "$root/.env.local" ]; then
    cp "$main/.env.local" "$root/.env.local"
fi

# 5. Warm the dev cache so phpstan finds var/cache/dev/App_KernelDevDebugContainer.xml.
#    Mirrors the composer-install branch above: route through php-fpm with the
#    worktree as workdir so the cache is built from the worktree's own code.
if docker compose ps --status running --services 2>/dev/null | grep -qx php-fpm; then
    docker compose exec --workdir "/var/www/html/${root#"$main"/}" php-fpm \
        bin/console cache:warmup --no-interaction
else
    echo "worktree-bootstrap: php-fpm not running — skipped cache:warmup; run 'just exec bin/console cache:warmup' after 'just up'." >&2
fi

echo "Bootstrapped worktree '$name': vendor ready, node_modules linked, env + dev cache ready, test DB = $db"
