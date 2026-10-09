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
| `app:drain-outbox` | Publishes outbox events whose Mercure update never landed. Every five minutes; `--limit=<n>` to bound a manual pass. Safe to run alongside the worker — the claim is atomic. |
| `app:purge-worker-runs` | Deletes bridge worker run records, experiment pins and bridge host samples past the retention window. Hourly, at minute 20. The `bridge.run_retention_days` feature flag sets the window, and both this command and the hourly task read it. It defaults to the 180 days in `app.bridge.default_run_retention_days` in `config/services.yaml`, and `app.bridge.run_purge_schedule` in the same file is the cron expression that sets when the sweep runs. See [Worker run API](worker-runs.md). |
| `app:time-out-worker-runs` | Marks the open worker runs of quiet bridges as timed out. Every minute. A bridge is quiet when its last heartbeat is older than three heartbeat intervals, and a later report from the bridge replaces the timeout. One pass takes at most 500 runs. `app.bridge.run_timeout_schedule` in `config/services.yaml` is the cron expression that sets when the task runs. See [Worker run API](worker-runs.md#timed-out-and-lost). |
| `app:reopen-lapsed-work-requests` | Opens again each claimed work request whose lease ran out, so another bridge can claim it. Every minute. It clears the bridge and the claim token, and sends a `bridge.work_request` event for each request. `app.bridge.work_request_reopen_schedule` in `config/services.yaml` is the cron expression that sets when the task runs. See [Bridge heartbeat](bridge-heartbeat.md#the-lease). |
| `app:expire-subject-work-requests` | Expires each open work request about a subject other than a card that no bridge claimed in time. Every minute. A request waits 2 hours, and a reopen starts the wait again. `app.bridge.subject_work_timeout_minutes` in `config/services.yaml` sets the wait, and the task runs on the schedule of `app:reopen-lapsed-work-requests`. The workflow expires the work of a card by its own rules. See [Bridge heartbeat](bridge-heartbeat.md#the-lease). |
| `app:sweep-forge-pull-requests` | Queues a state refresh of each open pull request that Loupe has not read in the last nine minutes. It runs every ten minutes. A forge delivery refreshes a pull request at once, so the sweep catches a delivery that never arrived. The worker reads each queued pull request once, however many times it is queued. `app.forge.sweep_schedule` in `config/services.yaml` is the cron expression that sets when the task runs. |
| `app:evaluate-due-workflow-cards` | Queues a workflow evaluation of each card whose refused rule is due to retry. Every minute. One pass takes at most 500 cards. `app.workflow.evaluate_due_schedule` in `config/services.yaml` is the cron expression that sets when the task runs. |
| `app:sweep-workflow-cards` | Queues a workflow evaluation of each card in a column that is not terminal, in every project bound to a workflow. Every ten minutes. It catches a card change that no event reported. It also expires an open work request that no bridge claimed within the work timeout of the template. When no event reaches the card, that expiry can come up to ten minutes late. `app.workflow.sweep_schedule` in `config/services.yaml` is the cron expression that sets when the task runs. |
| `app:oauth:purge-expired-tokens` | Deletes expired OAuth tokens, authorization codes and device codes. Hourly, at minute 17. It keeps an expired access token while a live refresh token still points at it, so a user can still revoke that refresh token. Do not use the bundle's `league:oauth2-server:clear-expired-tokens`, which does not keep them. See [Connected apps](../using/connected-apps.md). |
| `audit:purge` | Deletes audit records past the retention window. Hourly, at minute 45. The `audit.retention_days` feature flag sets the window, and both this command and the hourly task read it. It defaults to the 180 days in `retention_days` in `config/packages/ubermuda_audit.yaml`, and `purge_schedule` in the same file is the cron expression that sets when the sweep runs. |

## Maintenance

| Command | What it does |
|---|---|
| `app:review:rerender-versions` | Re-renders stored HTML for every document version from its Markdown source. For after a renderer change. |
| `app:workflow:count-legacy-fingerprints` | Reads the facts of each card that has a workflow rule state, and counts the states by the fingerprint they hold. It prints four numbers: the cards read, the states that hold only the legacy fingerprint, the states that hold the current one, and the states that hold neither. Run it after the release that moves the facts to providers. The legacy fingerprint can go once the first count is low and every managed card was evaluated at least once. It changes nothing. |
| `app:bridge:rebuild-run-facts` | Rewrites the fact row of each worker run from the run and its usage, 500 runs at a time, and prints the number of runs. Run it once after the release that adds the run fact table, when no old container runs. An old container changes runs and does not update their fact rows. A second run changes nothing. A fact row whose run the retention sweep deleted stays. See [Worker run API](worker-runs.md). |
| `league:oauth2-server:create-client` | Registers an OAuth client. For a public client, pass `--public`, one `--redirect-uri` for each address, `--grant-type=authorization_code --grant-type=refresh_token`, and the scopes it may ask for. `league:oauth2-server:list-clients` and `league:oauth2-server:delete-client` list and remove clients. Do not delete `loupe-cli`: a migration registers it for `loupe login`, and deleting it signs out every CLI. |
| `app:dev:seed` | Seeds an empty **development** database with a verified user and a project. It prints `SITE_REVIEW_WIDGET_PROJECT=<project id>` for you to copy. Not for production. |

## Symfony commands worth knowing

```sh
bin/console doctrine:migrations:status   # pending migrations?
bin/console messenger:failed:show        # parked messages
bin/console debug:dotenv                 # which .env file won
bin/console debug:router                 # every route
```
