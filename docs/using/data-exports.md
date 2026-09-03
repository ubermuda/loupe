---
title: "Data exports"
description: "How a user requests their data, and what the download link guarantees."
---

A user requests an export from `/account/exports`. The worker builds an archive
asynchronously and emails a download link; the link resolves at
`/account/exports/{id}/download`.

**Nothing happens without a running worker.** The request returns 200, the row
is created, and the archive is never built. See
[What runs in production](../getting-started/architecture.md).

## What the link guarantees

Export archives are personal data, and the application never exposes its storage
bucket to a browser. It writes every object privately and generates **no public
and no presigned URL** — the download route streams the bytes itself, behind a
link that requires the authenticated owner plus a SHA-256 token, expires 48
hours after the export completes, and answers 404 on any mismatch.

An hourly scheduled command, `app:purge-expired-exports`, deletes expired
archives and their rows. Like everything else on the schedule, it runs only if a
worker is consuming.

## What the archive contains

The archive holds one file per kind of data. `audit_log.json` is one of them. It
holds the audit records the user is the actor of, and the records that name the
user as the subject. What was done to the account is the account's data too.

A subject record was written by somebody else, so the file names no actor at
all. It carries the operation, the outcome, the category, the channel, the
subject and the context of each record, and never an actor name.

An administrator's export contains the identifiers of the accounts they acted
on, because those records are the administrator's own actions. An identifier is
an opaque UUID. It carries no name and no address.

That file reaches back as far as the trail does, which is 180 days by default
and is set by the `audit.retention_days` feature flag. See
[The admin area](admin.md).

## Where archives are stored

`EXPORT_STORAGE` is `local` by default, which is correct only when the process
that generates an export and the process that serves its download share a
filesystem. Separate web and worker containers do not, and there `local` means
every download 404s. See
[Object storage](../extending/object-storage.md).

Archives need no backup: they are derived data, and a user can ask for a new one
whenever they want. See [Backing up](../operating/backups.md).
