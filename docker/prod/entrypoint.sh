#!/bin/bash
set -euo pipefail

echo "Starting prod"

COMPOSER_ALLOW_SUPERUSER=1 composer dump-env prod

# Migrations are NOT run here — with several replicas, per-container
# migrations race against the same database. Run docker/prod/release.sh
# once per deploy instead.

exec "$@"
