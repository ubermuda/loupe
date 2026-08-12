---
title: Data exports
description: How a user requests their data, and what the download link guarantees.
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

## Where archives are stored

`EXPORT_STORAGE` is `local` by default, which is correct only when the process
that generates an export and the process that serves its download share a
filesystem. Separate web and worker containers do not, and there `local` means
every download 404s. See
[Object storage](../extending/object-storage.md).

Archives need no backup: they are derived data, and a user can ask for a new one
whenever they want. See [Backing up](../operating/backups.md).
