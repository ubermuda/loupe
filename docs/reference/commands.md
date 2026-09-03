---
title: "Console commands"
description: "Every console command this instance adds, what it does, and which are safe for cron."
---

Run these in the web container — `docker exec`, `just exec` in development, or
the platform's console.

## Recovery

All three are idempotent: run one against an account already in the desired
state and it prints "already …" and exits 0.

| Command | What it does |
|---|---|
| `app:admin:create <email>` | Ensures the address is a **verified administrator**, creating the account if needed. `--full-name`, `--password`; with no password it prompts, or non-interactively generates one and prints it once. An existing account is promoted and verified in place and **keeps its password**. |
| `app:user:promote <email>` | Grants `ROLE_ADMIN`, keeping any other roles. |
| `app:user:verify <email>` | Marks the email verified and burns any outstanding verification token — including on an already-verified account, since that link logs its bearer straight in. The escape hatch when outbound mail never arrives. |

See [Recovering an instance](../operating/recovering.md).

## Scheduled work

These ride the worker's schedule. Listing them here is not an invitation to cron
them separately — a running worker already does — but they are safe to run by
hand.

| Command | What it does |
|---|---|
| `app:purge-expired-exports` | Deletes expired data-export archives and rows. Hourly. |
| `app:sweep-ended-trials` | Disables ended trials and cancellations, sends survey emails. |
| `app:drain-site-review-outbox` | Publishes site-review events whose Mercure update never landed. Every five minutes; `--limit=<n>` to bound a manual pass. Safe to run alongside the worker — the claim is atomic. |
| `audit:purge` | Deletes audit records past the retention window. Hourly, at minute 45. The `audit.retention_days` feature flag sets the window, and both this command and the hourly task read it. It defaults to the 180 days in `retention_days` in `config/packages/ubermuda_audit.yaml`, and `purge_schedule` in the same file sets the hour. |

## Maintenance

| Command | What it does |
|---|---|
| `app:review:rerender-versions` | Re-renders stored HTML for every document version from its Markdown source. For after a renderer change. |
| `app:dev:seed` | Seeds an empty **development** database with a verified user, a project and a widget token. Not for production. |

## Symfony commands worth knowing

```sh
bin/console doctrine:migrations:status   # pending migrations?
bin/console messenger:failed:show        # parked messages
bin/console debug:dotenv                 # which .env file won
bin/console debug:router                 # every route
```
