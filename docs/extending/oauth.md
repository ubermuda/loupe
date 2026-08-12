---
title: Social login
description: Optional. Google and GitHub sign-in, each gated by both credentials and a flag.
---

A provider becomes reachable only when its credentials **and** its feature flag
are both set. Credentials alone do nothing, which is deliberate: an operator can
stage the configuration and turn the provider on separately.

| Variable | Flag |
|---|---|
| `OAUTH_GOOGLE_ID`, `OAUTH_GOOGLE_SECRET` | `auth.google.enabled` |
| `OAUTH_GITHUB_ID`, `OAUTH_GITHUB_SECRET` | `auth.github.enabled` |

Flags are toggled from the admin area — see [The admin area](../using/admin.md).

The routes are `/oauth/{provider}` to start, `/oauth/{provider}/check` for the
callback, and `/oauth/link` to attach a provider to an existing account. Set
each provider's redirect URI to the check route on your instance's public host;
`DEFAULT_URI` is what the app builds absolute URLs from outside a request.

The OAuth sign-up branch respects the same rule as `/register` and `/waitlist`:
it refuses to create the **first** account on an uninstalled instance.

## Icons

Provider icons are named at runtime, so the icon importer cannot see them. They
are imported explicitly and committed; if you add a provider, import its icon by
hand or it renders as nothing in production, silently.
