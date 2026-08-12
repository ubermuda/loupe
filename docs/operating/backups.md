---
title: Backing up
description: What makes up an instance durable state, and why a database dump alone is not enough.
---

**Backups are the operator's responsibility.** Loupe schedules nothing, ships no
backup command, and will not warn you that none exist.

Two things make up an instance's durable state, and a database dump covers only
the first:

1. **Postgres.** Everything the application owns — accounts, projects,
   documents and their versions, comments, tags, the messenger queues and the
   site-review outbox.

   ```bash
   pg_dump "$DATABASE_URL" --format=custom --file=loupe-$(date +%F).dump
   ```

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

**Your restore is yours to prove.** Whatever this project tests, it tests its
own code; it cannot tell you that *your* dump, in *your* storage, restores into
*your* instance. Run one deliberately on a scratch instance before you need it,
and confirm afterwards that you can log in and that a document with an
encrypted column still renders — that pair exercises the database and the key
together, which is the combination a backup most often gets half right.

