---
title: "Restoring the database"
description: "Putting a dump back, and the key that has to come with it."
---

**A restore is a pair: the dump and `APP_ENCRYPTION_KEY`.** The key is not in the
dump and cannot be regenerated. Restoring without the one that encrypted the data
leaves every `encrypted_string` column permanently unreadable — the rows come
back intact and simply cannot be decrypted. Find the key before you start, not
after.

**No restore has been rehearsed for this project.** These steps are written from
how the stack is built, not from a drill somebody ran. Rehearse yours on a
scratch instance while nothing depends on it.

This page covers the [single-host Docker Compose](../getting-started/docker-compose.md)
stack. On App Platform the database is a managed cluster and you restore it from
the provider's own snapshot; only the key half below applies.

## What you need

- A dump, taken with `pg_dump --format=custom`. The [backup job](backups.md)
  writes one to your bucket on a schedule; anything you made by hand works too.
- **The `APP_ENCRYPTION_KEY` the instance was running when that dump was taken.**
  If it was empty then, it must be empty now. A different value is as bad as a
  missing one.
- The rest of `docker/compose/prod.env`. Rotating `APP_SECRET` only invalidates
  sessions and signed URLs; the encryption key is the one that cannot be
  replaced.

## Restoring

Every command assumes the compose flags the stack always needs:

```bash
alias dc='docker compose -f docker/compose/prod.yaml --env-file docker/compose/prod.env'
```

**1. Fetch the dump.** The backup job's own container already has rclone and your
bucket settings, so borrow it rather than configuring a client by hand. The
single quotes matter: `$BACKUP_S3_*` must be expanded inside the container, where
those values live, not by your shell.

```bash
dc --profile backup run --rm -T --entrypoint sh backup -c \
    'rclone lsf --include "loupe-*.dump" "s3:$BACKUP_S3_BUCKET/$BACKUP_S3_PREFIX/"'

dc --profile backup run --rm -T --entrypoint sh backup -c \
    'rclone cat "s3:$BACKUP_S3_BUCKET/$BACKUP_S3_PREFIX/loupe-20260101T031500Z.dump"' \
    > loupe.dump
```

**2. Stop everything that writes.** The app must not be running against the
database while it is replaced.

```bash
dc stop web worker
```

**3. Put `APP_ENCRYPTION_KEY` back in `docker/compose/prod.env`,** matching the
value in force when the dump was taken.

**4. Replace the database.** Restore inside the `database` container so the
`pg_restore` version matches the server:

```bash
dc cp loupe.dump database:/tmp/restore.dump
dc exec database sh -c 'dropdb --force -U "$POSTGRES_USER" "$POSTGRES_DB"'
dc exec database sh -c 'createdb -U "$POSTGRES_USER" "$POSTGRES_DB"'
dc exec database sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner /tmp/restore.dump'
dc exec database rm -f /tmp/restore.dump
```

Dropping and recreating is cleaner than `pg_restore --clean` into a populated
database, which leaves behind anything the dump does not mention.

**5. Migrate, if the image is newer than the dump.** Restoring an older dump
under a newer release leaves the schema behind the code:

```bash
dc run --rm web docker/prod/release.sh
```

**6. Start the app.**

```bash
dc up -d
```

## Verifying

Not optional. A restore that nobody checked is a belief, not a backup.

1. **Log in.** A session proves the database is readable and that password
   hashes survived.
2. **Open a document whose record has an encrypted column and confirm it
   renders.** This is the check that catches a wrong `APP_ENCRYPTION_KEY`, and
   the only one that does — everything else looks perfectly healthy without it.
   No column in Loupe uses `encrypted_string` today, so on a current instance
   this step is satisfied by step 1. Keep doing it: the day a column does use it,
   this is the difference between a restore and a loss you find out about later.
3. **Check `/healthz`** and the admin area, and read `docker compose logs worker`
   for a container that is restarting.

## After a restore

**Queued messages come back with the database.** The `messenger_messages` table
is in the dump, so the worker will pick up whatever was queued when it was taken
— old verification emails and export jobs among them. If the dump is old enough
for that to be a problem, empty the queue before starting the worker:

```bash
dc exec -T database sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"' <<'SQL'
DELETE FROM messenger_messages WHERE queue_name <> 'failed';
SQL
```

**Data-export archives are not restored and do not need to be.** They are derived
data; a user can ask for a new export at any time.

**The per-user data export is not a backup.** It is a GDPR access feature that
gives one person their own records, in an archive built for a human to read. It
contains one account and cannot rebuild an instance. Do not treat a pile of them
as a backup, and do not let one stand in for this runbook.
