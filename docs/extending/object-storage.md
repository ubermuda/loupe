---
title: Object storage for data exports
description: EXPORT_STORAGE and its settings, for any S3-compatible bucket. Applies wherever web and worker do not share a filesystem.
---


`EXPORT_STORAGE` is `local` by default, which writes archives under
`var/exports/`. **That is only correct when the process that generates an export
and the process that serves its download share a filesystem.** The single-host
stack does share one; separate web and worker containers do not, and there
`local` means the worker writes an archive the web container cannot see and
every download 404s.

| Variable | Purpose |
|---|---|
| `EXPORT_STORAGE` | `local` or `s3`. |
| `EXPORT_STORAGE_BUCKET` | Required when `s3`. |
| `EXPORT_STORAGE_PREFIX` | Key prefix. Empty stores archives at the bucket root, which is what a dedicated bucket wants. |
| `EXPORT_STORAGE_REGION` | Empty falls back to AWS's default, `us-east-1`. |
| `EXPORT_STORAGE_ENDPOINT` | Set for any non-AWS provider, e.g. `https://tor1.digitaloceanspaces.com`. Empty targets AWS S3 itself. |
| `EXPORT_STORAGE_KEY` / `_SECRET` | Empty falls back to the ambient AWS credential chain, which only helps when running on AWS with an attached role. |
| `EXPORT_STORAGE_USE_PATH_STYLE` | `true` for MinIO and most non-AWS providers, which address buckets as `https://host/bucket/key` rather than `https://bucket.host/key`. |
| `EXPORT_STORAGE_ACL` | Canned ACL sent with every upload. **No single value works everywhere** — see [Known gaps](../known-gaps.md). |

AWS S3, MinIO, Cloudflare R2 and DigitalOcean Spaces all work; the application
only ever sees generic S3 settings. Nothing else in the app writes files, so
this is the only place object storage is needed.

## Keeping archives private on a bring-your-own bucket

Export archives are personal data, and the application never exposes the bucket
to a browser. It writes every object with a private ACL and private Flysystem
visibility, and it generates **no public and no presigned URL** — the download
route streams the bytes itself, behind a link that requires the authenticated
owner plus a SHA-256 token, expires 48 hours after the export completes, and
answers 404 on any mismatch.

On **an AWS S3 bucket created since 2023, that private object ACL is a no-op**.
Those buckets default to "Bucket owner enforced" ownership, under which S3
ignores object ACLs entirely and access is governed solely by Block Public
Access and the bucket policy. It is the same setting that forces
`EXPORT_STORAGE_ACL=bucket-owner-full-control`, so it applies to exactly the
buckets that need the override. **Enable Block Public Access and grant no
anonymous read in the bucket policy** — on those buckets the application cannot
do it for you.

This is already handled when `create_export_bucket = true`: `terraform/spaces.tf`
creates the bucket `private` with a key scoped to it alone, and Spaces honours
that bucket ACL. Nothing to do on the shipped DigitalOcean path.

