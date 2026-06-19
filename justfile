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
    npx eslint public/site-review/widget.js
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

gamache:
    vendor/bin/gamache

ci: cs phpstan arkitect gamache lint-e2e phpunit

sort-translations:
    php bin/sort-translations

migrate-diff: (exec "bin/console doctrine:migrations:diff")

migrate-run: (exec "bin/console doctrine:migrations:migrate")

e2e *args:
    cd e2e && npx playwright test {{args}}

e2e-coverage *args:
    cd e2e && npx playwright test {{args}}

open-coverage:
    open var/coverage/html/index.html

browser-sync:
    npx browser-sync start --proxy localhost --files "templates/**/*.html.twig, assets/**/*.css, assets/**/*.js"

tailwind:
    bin/console tailwind:build --watch
