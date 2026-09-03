---
title: "The admin area"
description: "Instance status, feature flags, the waitlist, the audit log and the site-review outbox. Requires ROLE_ADMIN."
---

`/admin` is the dashboard. Everything below it needs `ROLE_ADMIN`, which
`app:user:promote` grants and `app:admin:create` grants while also verifying the
account.

## Instance status

**`/admin/status`** reports, for this instance: whether the mail transport
accepts a connection, whether the sender address is still the undeliverable
default, whether the message queue is being drained, how many messages have
failed, whether the Mercure hub answers, and — when billing is on — whether the
Stripe keys are set.

The install wizard shows the same page before it creates your administrator, so
a broken mailer is visible *before* it can lock you out.

The worker check is deliberately honest about its limits. A running worker
leaves no lasting trace, so an empty queue is reported as **unknown**, not as
healthy. What it can prove is the failure: messages sitting available and
unclaimed for over a minute mean nothing is consuming them.

## Feature flags

The flags page lists every flag the database defines, and its scan view lists
every flag the code references but the database does not — creating those rows
on request.

A missing row is not an error. Every install flag falls back to exactly the
value the wizard would have written (registration on, no cap, billing and social
login off, a 14-day trial), so an instance recovered entirely from the shell
behaves like a wizard-installed one with the defaults accepted.

Some flags gate integrations rather than behaviour: `billing.enabled` decides
whether the Stripe client is ever instantiated, and `auth.google.enabled` /
`auth.github.enabled` decide whether a social provider is reachable at all — its
credentials alone are not enough.

## Audit log

**`/admin/audit-log`** lists every recorded audit event on the instance, newest
first. Each row carries the operation, the outcome, the actor, the channel the
actor used, the subject and a context object. Filter by actor name, by operation
prefix, by channel, and by a date range.

### Retention

The trail keeps **180 days** by default. An hourly scheduled task deletes every
record older than that window, and `audit:purge` is the manual backstop. Both
read the same window. Like everything else on the schedule, the task runs only
if a worker is consuming. See [Commands](../reference/commands.md).

The `audit.retention_days` feature flag sets the window, and you change it in
the admin area with no restart. An instance installed with the audit log already
has the flag row. Edit it at **`/admin/feature-flags`**.

An instance installed before the flag existed has no row for it. That instance
keeps 180 days, which is the value of `retention_days` in
`config/packages/ubermuda_audit.yaml`. To change the window there, create the row at
**`/admin/feature-flags/new`**. Set **Name** to `audit.retention_days`, set
**Type** to `Int`, then put the number of days in **Value**.

A window below one day is read as one day.

Back up the audit table if you must keep records for longer than the window. The
purge is a hard delete and it is not reversible.

### What account deletion removes

Deleting an account removes what that account did, and keeps what was done to
it.

- Records the account is the actor of are deleted.
- Records written with the account's API tokens are deleted.
- Records that only name the account as a subject are kept whole. Such a record
  holds a subject type and an id, never a name, and it carries the acting
  party's name instead. Deleting it would erase an admin's own record of what
  they did.
- The records the deletion itself writes carry no name for the account they
  erase.

## Waitlist

When a registration cap is closed, `/waitlist` collects addresses and
`/admin/waitlist` works through them: invite a single entry, invite a selection,
or invite the oldest. Redeeming an invite converts the entry into an account.

## Site-review outbox

`/admin/site-review-outbox` lists every undelivered site-review event on the
instance, with attempt counts and next-retry times. See
[Failed messages and the outbox](../operating/failed-messages.md).
