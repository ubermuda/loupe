---
title: "Backing up"
description: "What makes up an instance durable state, and why a database dump alone is not enough."
---

**Backups are the operator's responsibility.** The single-host stack ships a job
that takes them, but it is off until you switch it on, and it will not warn you
that no backups exist. The bucket, the retention window and proving a restore are
yours.

Two things make up an instance's durable state, and a database dump covers only
the first:

1. **Postgres.** Everything the application owns — accounts, projects,
   documents and their versions, comments, tags, the messenger queues and the
   site-review outbox.

   ```bash
   pg_dump "$DATABASE_URL" --format=custom --file=loupe-$(date +%F).dump
   ```

   [The backup job](#the-backup-job) below runs exactly that on a schedule and
   puts the result somewhere other than this host.

2. **`APP_ENCRYPTION_KEY`.** Not in the dump, and not regenerable. **Restoring a
   database without the key that encrypted it leaves every `encrypted_string`
   column permanently unreadable** — the rows restore fine and simply cannot be
   decrypted, so losing the key is data loss rather than a configuration
   problem.

   **Store it somewhere other than the dumps.** A key sitting beside the data it
   decrypts is not a second factor: whoever obtains that backup obtains both,
   and the encryption stops buying you anything. A secrets manager, a password
   vault, or an envelope in a different account — anywhere the dumps are not.

   The other secrets in [Environment variables](../reference/environment.md#secrets) are replaceable. Rotating `APP_SECRET`
   invalidates sessions and signed URLs, which is an inconvenience, not a loss.

**Data-export archives need no backup.** They are derived data — a user can ask
for a new export whenever they want one — so whether they survive is a question
about convenience, not about durability. With `EXPORT_STORAGE=local` they are
lost on the next deploy regardless, which is why App Platform cannot use `local`
at all (see [Known gaps](../known-gaps.md)).

## The backup job

`docker/compose/prod.yaml` ships a `backup` service that dumps the database with
`pg_dump --format=custom`, uploads each dump to S3-compatible storage, and
deletes its own dumps once they pass the retention window. It runs `pg_dump` from
the same Postgres image as the `database` service, so the client is never older
than the server.

**It is opt-in, and off by default.** A self-hosted instance makes no outbound
connection of its own out of the box, so nothing starts until every compose
command for the stack carries `--profile backup`:

```bash
docker compose -f docker/compose/prod.yaml --env-file docker/compose/prod.env \
    --profile backup up -d
```

`docker/compose/prod.env.example` documents every setting. The short version:
`BACKUP_S3_BUCKET`, `BACKUP_S3_KEY`, `BACKUP_S3_SECRET` and — unless you are on
AWS — `BACKUP_S3_ENDPOINT` are required; `BACKUP_INTERVAL` defaults to `24h` and
`BACKUP_RETENTION_DAYS` to `14`.

**Failure is loud.** Every setting is checked when the container starts, and the
job proves it can put, list and delete an object in your bucket before it takes a
first dump. Anything missing or refused is a named error in
`docker compose logs backup` and a container that exits non-zero and keeps
restarting. There is no state in which it looks healthy and quietly produces
nothing — which is the failure worth designing against, because a backup nobody
distrusts is the one nobody checks.

**What stays yours:** the bucket and the credential, the retention number, and
whether the storage is somewhere a fire on this host cannot reach. Retention
prunes — `BACKUP_RETENTION_DAYS=14` means dumps older than 14 days are deleted
from the bucket, and there is no separate archive behind them. Keep the bucket
private: a dump holds every account, document and token in the instance, and the
job does not encrypt it.

The job belongs to the single-host stack. On App Platform the database is a
managed cluster with the provider's own backups, and `docker/compose/prod.yaml`
is not what runs.

**Your restore is yours to prove.** Whatever this project tests, it tests its
own code; it cannot tell you that *your* dump, in *your* storage, restores into
*your* instance. **No restore has been rehearsed for this project** — the runbook
in [Restoring the database](restoring.md) is written from how the stack is built,
not from a drill somebody ran. Run one deliberately on a scratch instance before
you need it, and confirm afterwards that you can log in and that a document with
an encrypted column still renders — that pair exercises the database and the key
together, which is the combination a backup most often gets half right.

