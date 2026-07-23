#!/bin/bash
set -euo pipefail

# One-shot release step. Run ONCE per deploy, before (or while) rolling out
# new containers — e.g.:
#
#   docker run --rm --env-file <prod env> <image> docker/prod/release.sh
#
# Never run this from the container entrypoint: concurrent replicas would
# race migrations against the same database.

echo "Running release step"

COMPOSER_ALLOW_SUPERUSER=1 composer dump-env prod

php bin/console doctrine:migrations:migrate --no-interaction
