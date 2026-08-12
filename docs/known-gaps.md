---
title: Known gaps
description: Things the application needs that are not configured on your behalf. Read before a first real deploy.
---


1. **Set `INSTALL_TOKEN` before the first deploy.** Since the wizard fails
   closed in production, an unset value means `/install` returns 404 and the
   browser has no route to the first administrator. Recoverable from a shell —
   see [Recovering an instance](operating/recovering.md) — but the wizard is the pleasant path.

2. **On App Platform, `EXPORT_STORAGE` cannot be `local`.** The web and worker
   containers have separate ephemeral filesystems, so the application default
   would have the worker write an archive the web container cannot see, and
   every download would 404. `terraform/spaces.tf` therefore creates a private
   Spaces bucket and a bucket-scoped access key, and `main.tf` wires them into
   `EXPORT_STORAGE_BUCKET` / `_ENDPOINT` / `_REGION` / `_KEY` / `_SECRET`.

   All you supply is `export_bucket_region` — a Spaces datacenter slug like
   `tor1`, which is **not** the App Platform slug (`tor`) and cannot be derived
   from it. The bucket is `private`, is never destroyed while it holds objects,
   and has a 30-day lifecycle rule as a backstop against archives that outlive
   their database row. Download links expire after 48 hours and the app deletes
   the archive then, so that rule can never reach a live one.

   **Bringing your own bucket instead**: set `create_export_bucket = false` and
   fill in the `export_storage_*` variables.

3. **No canned ACL works on every provider, so `EXPORT_STORAGE_ACL` exists.**
   The Flysystem S3 adapter always sends a canned ACL and offers no way to send
   none. Buckets created since 2023 on AWS default to "Bucket owner enforced",
   which rejects everything except `bucket-owner-full-control` with a 400
   `AccessControlListNotSupported`, while MinIO and DigitalOcean Spaces accept
   only the app's default, `private`. Get this wrong and **every export upload
   fails inside the worker**, where nobody is watching.

4. **Set `MERCURE_JWT_SECRET` if you want site-review push.** On App Platform,
   setting it runs a Mercure hub as a second service (module v1.6.0's
   `enable_mercure`) and routes `/.well-known/mercure` on the app's own domain
   to it; the module injects `MERCURE_URL`, `MERCURE_PUBLIC_URL` and
   `MERCURE_JWT_SECRET` itself. Leaving it empty keeps push off — review
   submissions still save, but never reach the bridge CLI, and the publish
   failure is only logged, so it degrades silently rather than erroring.

   The hub is in-memory: a restart drops undelivered updates. That is why
   submissions are recorded in the `site_review_events` outbox and the bridge
   resumes from `Last-Event-ID` — delivery is best effort, replay is not.

5. **Nothing here has been applied against a live account.** `terraform
   validate` passes and `plan` evaluates the full configuration up to the first
   API call, but no deploy has run. Specifically unobserved:

   - the Mercure component, reasoned from the `dunglas/mercure` image's
     documented interface and the dev compose service;
   - the Spaces bucket and key, whose `readwrite` grant is taken from
     DigitalOcean's documentation rather than from a completed
     upload-download-delete cycle;
   - **the S3 export path as a whole — it has never touched a real bucket**;
   - the single-host Compose stack, validated as configuration but never
     started;
   - the production image itself, whose base is pinned by digest in
     `docker/prod/Dockerfile`; no image built from that pin has been deployed.
