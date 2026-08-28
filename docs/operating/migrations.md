---
title: "Running migrations"
description: "The one-shot release step, and the expand-only rule that keeps a rollback survivable."
---


**Never run migrations from the container entrypoint.**
`docker/prod/entrypoint.sh` deliberately does not — with several replicas,
per-container migrations race against the same database.
`docker/prod/release.sh` is the one-shot release step, run once per deploy:

```bash
docker run --rm --env-file <your prod env file> <image> docker/prod/release.sh
```

[Single-host Docker Compose](../getting-started/docker-compose.md) and
[App Platform](../getting-started/digitalocean.md) each have their own way of
invoking it.

## A release may only expand the schema

**A release may add to the schema. It may not take anything away.** Adding a
table, adding a nullable column, adding an index, backfilling data — all of
that ships whenever it is ready. Dropping a column or a table, renaming one,
narrowing a type, or putting `NOT NULL` on a column that already exists ships
in a *later* release, once no image still deployed reads the old shape.

The reason is the rollback path, and it is specific rather than a matter of
taste. On App Platform with `enable_predeploy_migrations = true`, migrations
are a `PRE_DEPLOY` job that runs on **every** deploy — and a rollback is a
deploy. Rolling back does not go around the migration step; it goes through
it, with the old image's migration set running against a schema a newer
release has already changed. A failing `PRE_DEPLOY` job fails the whole
deployment, on every retry too, so a rollback that trips over the schema is a
rollback that cannot complete. Mid-incident is the worst possible moment to
find that out.

Expand-only makes the two things a rollback needs true at once:

1. **The previous image's code tolerates the current schema.** It never selects
   the column that was added, and nothing it does select has been removed,
   renamed or narrowed underneath it.
2. **The previous image's migration run does nothing.** `doctrine:migrations:migrate`
   targets the newest migration *the image ships*. When the database already
   holds every one of them there is nothing left to execute: the command warns
   that the database contains migrations it does not recognise, takes
   `--no-interaction` as a yes, and exits 0 without running any SQL. It does
   not migrate down.

So the schema only ever moves forward, and every image in the expand-only run
works against it unchanged. **The rollback window is exactly the run of
consecutive expand-only releases at the head of your history.**

A migration's `down()` is not the production rollback path — nothing calls it
during a deploy. Write it anyway, because it is what makes a migration
reversible while you develop, but do not plan a production rollback around it.

### What counts as which

| Expands — ship any time | Contracts — needs its own later release |
|---|---|
| `CREATE TABLE` | `DROP TABLE`, `DROP COLUMN` |
| `ADD COLUMN` nullable, or `NOT NULL` with a default | Renaming a table or a column |
| `CREATE INDEX` | A new `UNIQUE` index, `CHECK` or foreign key over existing columns |
| Widening a type (`VARCHAR(50)` → `VARCHAR(255)`) | Narrowing a type |
| `ALTER COLUMN … DROP NOT NULL` | `ALTER COLUMN … SET NOT NULL` on an existing column |
| Backfilling a column nothing older reads | Deleting or rewriting rows the previous image reads |

The constraints in the right-hand column are there for the same reason as the
drops: the previous image was written without them, so its inserts start
failing the moment they apply.

### Renaming a column

**Release 1 — add the new name, backfill it, write both.**

```php
$this->addSql('ALTER TABLE documents ADD display_name VARCHAR(255) DEFAULT NULL');
$this->addSql('UPDATE documents SET display_name = title');
```

The entity maps both columns and every write path sets both. Reads prefer
`display_name` and fall back to `title`. The old column stays, and stays
populated, so the previous image is unaffected.

**Release 2 — once nothing deployed reads `title`, drop it.**

```php
$this->addSql('ALTER TABLE documents DROP title');
```

The entity stops mapping `title` and nothing writes it. This is the
contracting release: you can roll back *to* it, and you cannot roll back
*across* it.

### Dropping a column

**Release 1 — code only.** Stop reading and stop writing the column. If the
entity required it, the migration widens rather than removes:

```php
$this->addSql('ALTER TABLE documents ALTER COLUMN legacy_slug DROP NOT NULL');
```

The previous image still supplies a value, which is still allowed. The new one
does not, which is now also allowed.

**Release 2 — drop it.**

```php
$this->addSql('ALTER TABLE documents DROP legacy_slug');
```

### Making a column required

**Release 1 — add it nullable, backfill, and write it on every path.**

```php
$this->addSql('ALTER TABLE reviews ADD sequence INT DEFAULT NULL');
$this->addSql('UPDATE reviews SET sequence = …');
```

**Release 2 — once every row has a value and every deployed image writes one,
enforce it.**

```php
$this->addSql('ALTER TABLE reviews ALTER COLUMN sequence SET NOT NULL');
$this->addSql('CREATE UNIQUE INDEX uniq_reviews_version_sequence ON reviews (version_id, sequence)');
```

Both statements contract. `SET NOT NULL` breaks the previous image directly —
it does not set `sequence` at all. The unique index breaks it whenever it can
still write a duplicate pair. Neither can ship until every deployed image
writes a value, and writes a unique one. A brand-new column declared
`NOT NULL DEFAULT …` in one statement is different: the default covers both
the existing rows and the writers that omit it, so that one expands.

### When you have to go back past a contracting release

There is no clean move, only a choice between two. Write a forward migration
that puts back what the contracting one removed, and deploy *that* with the old
code — an expand, so it is a normal release. Or restore the database from a
backup taken before the contracting release, accepting the loss of everything
written since. [Backing up](backups.md) is what decides whether the second
option exists at all.

## The rule is machine-checked

`MigrationExpandContractRule`, a PHPStan rule in
[`ubermuda/gamache`](https://github.com/ubermuda/gamache), reads the SQL a
migration hands to `addSql()` and reports the destructive statements:
`DROP TABLE`, `DROP COLUMN`, `DROP CONSTRAINT`, `RENAME TO` and
`RENAME COLUMN`, `ALTER … TYPE`, and `SET NOT NULL` with no default. It reads
`up()` only — `down()` never runs during a deploy, so nothing written there can
break a rollback.

Three cases are exempt, because the same migration is what makes them safe:
anything done to a table that migration creates, a constraint dropped and
re-added under the same name (which is how Doctrine rebuilds a foreign key),
and `SET NOT NULL` on a column that migration gives a non-null `DEFAULT`.

The second release of a pair contracts deliberately, and says so with a
**leading** comment on the statement — or one in the `up()` docblock, to cover
the whole method:

```php
// @contract-phase: display_name replaced title a release ago, nothing reads it
$this->addSql('ALTER TABLE documents DROP title');
```

The reason is mandatory; a bare marker is reported on its own. A trailing
comment on the same line does **not** work, and is rejected on purpose: PHP
attaches it to the next statement, so it would exempt the wrong one.

Two limits are worth knowing before leaning on it. The rule only sees SQL
written as a string literal, so a query built by concatenation or held in a
variable is invisible to it. And with no prior schema in view it flags every
`ALTER … TYPE`, not only the narrowing ones. It is a guard rail, not a
boundary: the policy above is what people follow, and the rule catches the
obvious slips.

It is not switched on in this repository yet — run against the migrations
already here it reports issues in several of them, and what to do about that
back-catalogue is an open question. The policy binds from here on regardless.
