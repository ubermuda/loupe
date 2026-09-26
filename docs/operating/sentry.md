---
title: "Sentry"
description: "What Loupe sends to Sentry, what it never sends, and how to turn it on."
---

Loupe can send errors, traces and profiles to [Sentry](https://sentry.io).
It is off until you set `SENTRY_DSN`. While that variable is empty, Loupe
samples nothing and sends nothing.

## Turn it on

Copy the DSN from the *Client Keys* page of your Sentry project, and set it in
`SENTRY_DSN`. Three variables control the integration:

| Variable | Default | Purpose |
|---|---|---|
| `SENTRY_DSN` | empty | Where events go. **Secret.** Empty turns Sentry off. |
| `SENTRY_TRACES_SAMPLE_RATE` | `1.0` | The share of web requests and worker messages that Sentry traces, from `0.0` to `1.0`. |
| `SENTRY_PROFILES_SAMPLE_RATE` | `1.0` | The share of traced requests and messages that Sentry also profiles, from `0.0` to `1.0`. |

[Environment variables](../reference/environment.md) lists them with the rest.

A malformed DSN does not stop the instance. The Sentry SDK ignores it and
writes a debug log line, so nothing goes to Sentry. The *Sentry* row on
`/admin/status`, and `bin/console health-check:status`, report a malformed DSN
as a failure. They check the DSN format only, and never call Sentry.

## What Sentry receives

- A trace for each sampled web request, with a span for each Doctrine query and
  each outbound HTTP call.
- A trace for each sampled worker message, named after the message class.
- A profile for each profiled trace, when the Excimer PHP extension is loaded.
  The production image ships Excimer. The development image does not, so a
  local instance with a DSN sends traces and no profiles. `/admin/status` warns when
  profiles are sampled and Excimer is missing.
- Each unhandled exception from a web request or a worker message, except a
  404 or 405 response.
- The errors of scheduled tasks. Scheduled tasks send no traces.

Twig and cache spans are off. A render or cache span between two queries hides
a run of repeated queries, and Sentry's N+1 query detector then misses it.

## What Sentry never receives

- The email, IP address or id of a user. An email address in an exception
  message becomes `[email]`.
- A request body.
- A URL, a query string or a `Referer` header. The route name identifies the
  page.
- Cookies or an `Authorization` header.
- A console command line.
- Trace headers. Loupe adds none to an outbound request, so other hosts learn
  nothing about the trace.

## Stay within a Sentry quota

Both rates default to `1.0`, so every request and every message is traced and
profiled. On a busy instance this can use up a Sentry quota quickly. Lower
`SENTRY_TRACES_SAMPLE_RATE` first, for example to `0.1`. Lower
`SENTRY_PROFILES_SAMPLE_RATE` too if profiles still use too much. Errors are
not sampled, so each unhandled exception still reaches Sentry.
