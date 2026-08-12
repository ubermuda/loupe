---
title: Billing
description: Optional, off by default. Stripe subscriptions behind the billing.enabled flag.
---

Nothing instantiates the Stripe client until `billing.enabled` is on, so an
instance with no Stripe account is not merely unbilled — the integration is
never constructed.

| Variable | Purpose |
|---|---|
| `STRIPE_SECRET_KEY` | API key |
| `STRIPE_WEBHOOK_SECRET` | Verifies inbound webhooks |

`/admin/status` reports whether both are set once the flag is on.

## What it adds

`/billing/subscribe` offers the plan, `/billing/checkout` starts a Stripe
Checkout session, `/billing/checkout/success` returns from it, and
`/billing/portal` sends a subscriber to Stripe's own management portal.

Trials end on a schedule rather than on a request: `app:sweep-ended-trials`
disables expired trials and cancellations past their period, and sends survey
emails. Like everything scheduled, it runs only if a worker is consuming — see
[What runs in production](../getting-started/architecture.md).

An account disabled this way meets a paywall that still lets it reach the
subscribe page, and — when a registration cap is full — the waitlist.
