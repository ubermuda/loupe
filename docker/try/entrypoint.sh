#!/bin/bash
set -euo pipefail

# Boots the whole instance: Postgres, schema, an administrator to log in as,
# then Supervisor. Supervisor has no dependency ordering, so the database is
# started here, used, and stopped again before it hands over the long-lived one.
#
# Running migrations at start-up is what docker/prod/entrypoint.sh refuses to
# do, because concurrent replicas race each other against one database. A try
# image is one container that is its own database, so there is nothing to race.

SOCKET_DIR=/run/postgresql
install -d -o postgres -g postgres -m 0775 "$SOCKET_DIR"

# In the entrypoint rather than the Dockerfile: a fresh named volume mounted
# over PGDATA arrives root-owned, and initdb refuses to run on that.
install -d -o postgres -g postgres -m 0700 "$PGDATA"

if [ ! -s "$PGDATA/PG_VERSION" ]; then
	echo "Initialising the database…"
	su-exec postgres initdb --username=loupe --auth=trust --encoding=UTF8 -D "$PGDATA" >/dev/null
	# Loopback only. Nothing outside the container can reach it, which is why
	# trust authentication above is not a hole and the password in DATABASE_URL
	# is never checked.
	echo "listen_addresses = '127.0.0.1'" >>"$PGDATA/postgresql.conf"
	echo "host all all 127.0.0.1/32 trust" >>"$PGDATA/pg_hba.conf"
fi

su-exec postgres pg_ctl -D "$PGDATA" -w -o "-c listen_addresses=127.0.0.1" start >/dev/null
su-exec postgres psql -h 127.0.0.1 -U loupe -d postgres -tc \
	"SELECT 1 FROM pg_database WHERE datname = 'loupe'" | grep -q 1 ||
	su-exec postgres createdb -h 127.0.0.1 -U loupe loupe

# Before every console call: it snapshots the environment, so a value passed
# with `docker run -e` has to reach .env.local.php first.
COMPOSER_ALLOW_SUPERUSER=1 composer dump-env prod >/dev/null

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# The sanctioned first account — verified without sending mail, which matters
# because MAILER_DSN is null here. Creating it also closes the install wizard,
# so an unattended instance is never left minting admins for whoever finds it.
# Idempotent, so a restart over a persisted volume changes nothing.
php bin/console app:admin:create "$TRY_ADMIN_EMAIL" \
	--password="$TRY_ADMIN_PASSWORD" --full-name="Loupe Admin" --no-interaction

su-exec postgres pg_ctl -D "$PGDATA" -w -m fast stop >/dev/null

cat <<BANNER

  Loupe is starting at ${DEFAULT_URI}
  Log in with ${TRY_ADMIN_EMAIL} / ${TRY_ADMIN_PASSWORD}

  Evaluation image: mail is discarded, so registration and password reset do
  not work, and the database is lost with the container unless you mounted a
  volume at ${PGDATA}. See DEPLOY.md before running this for real.

BANNER

exec "$@"
