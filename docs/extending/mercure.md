---
title: "Mercure hub"
description: "Optional. Needed only for site-review push; without it, submissions still save."
---

Site-review push is the only thing that needs a Mercure hub. Leaving it off is a
supported state: submissions reach the outbox first and a scheduled drain
replays them once a hub exists, so nothing is lost — but nothing reaches a
running agent either, and the publish failure is only logged. It degrades
silently rather than erroring.

The hub is in-memory. A restart drops undelivered updates, which is exactly why
submissions are recorded in the `site_review_events` outbox and the bridge
resumes from `Last-Event-ID`: delivery is best effort, replay is not.

## Configuration

| Variable | Purpose |
|---|---|
| `MERCURE_JWT_SECRET` | Shared HS256 key, minimum 32 characters, **identical for the app and the hub**. No default ships — unset means Mercure fails loudly rather than signing with a publicly-known key. |
| `MERCURE_URL` | Where the app POSTs updates — the hub on the internal network. |
| `MERCURE_PUBLIC_URL` | Where clients subscribe. A genuinely separate host, since the bridge CLI reaches it directly, so it cannot be derived from `DEFAULT_URI`. |

The `site_review.push.enabled` flag requires all three: with any of them blank,
the endpoint returns an unusable hub URL.

## In development

`just mercure-up` starts the hub behind a compose profile; `just mercure-down`
stops it. The e2e suite passes without it.

If you serve the app without a reverse proxy, note that `MERCURE_PUBLIC_URL`
belongs in **`.env.dev.local`**, not `.env.local`: `.env.dev` pins it to the
Traefik host and outranks `.env.local`, so a value set there is read and then
discarded. `bin/console debug:dotenv` prints the precedence.

## In production

On the single-host stack the hub sits behind a compose profile and stays off
unless you ask for it. On App Platform, setting `mercure_jwt_secret` runs a hub
as a second service and routes `/.well-known/mercure` on the app's own domain to
it, deriving all three variables itself. See
[Single-host Docker Compose](../getting-started/docker-compose.md) and
[App Platform](../getting-started/digitalocean.md).
