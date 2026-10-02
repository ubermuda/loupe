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
| `SENTRY_TRACES_SAMPLE_RATE` | `1.0` | The share of web requests, worker messages and console commands that Sentry traces, from `0.0` to `1.0`. |
| `SENTRY_PROFILES_SAMPLE_RATE` | `1.0` | The share of traces that Sentry also profiles, from `0.0` to `1.0`. |

[Environment variables](../reference/environment.md) lists them with the rest.

A malformed DSN does not stop the instance. The Sentry SDK ignores it and
writes a debug log line, so nothing goes to Sentry. The *Sentry* row on
`/admin/status`, and `bin/console health-check:status`, report a malformed DSN
as a failure. They check the DSN format only, and never call Sentry.

## Browser

Loupe can also load the Sentry browser SDK on every page, the admin area and
the error pages included. It is off until you
set `SENTRY_BROWSER_DSN`. These variables control it:

| Variable | Default | Purpose |
|---|---|---|
| `SENTRY_BROWSER_DSN` | empty | Where browser events go. It is not a secret, because each page that loads the SDK shows it. Empty loads no SDK. |
| `SENTRY_BROWSER_TRACES_SAMPLE_RATE` | `1.0` | The share of page loads that the browser SDK traces, from `0.0` to `1.0`. While `SENTRY_DSN` is set, a page load continues the server trace, and `SENTRY_TRACES_SAMPLE_RATE` decides for it. |

The browser sends page loads, Web Vitals and JavaScript errors. It posts them
directly to the ingest origin of the DSN, which Loupe adds to the
Content-Security-Policy `connect-src` list. Loupe scrubs each browser event
before it leaves the page. Every item in
[What Sentry never receives](#what-sentry-never-receives) stays true for the
browser: no URL or query string, no user content and no user identity.

The *Sentry in the browser* row on `/admin/status` reports a malformed
`SENTRY_BROWSER_DSN` as a failure, in the same way as the *Sentry* row.

## What Sentry receives

- A trace for each sampled web request, with a span for each Doctrine query and
  each outbound HTTP call. The health check and the bridge heartbeat get no
  trace, because they carry no performance signal. Their errors still reach Sentry.
- A trace for each sampled worker message, named after the message class.
- A trace for each sampled console command, named after the command.
  `messenger:consume` itself gets no trace, because each message it handles
  gets its own.
- A profile for each profiled trace, when the Excimer PHP extension is loaded.
  The production image ships Excimer. The development image does not, so a
  local instance with a DSN sends traces and no profiles. `/admin/status`
  warns when profiles are sampled and Excimer is missing.
- Each unhandled exception from a web request, a worker message or a console
  command, except a 404 or 405 response. An access denial on a web request
  with no signed-in user is also left out, because Loupe answers it with a
  redirect to the login page or a 401 response.
- The errors of scheduled tasks. Scheduled tasks send no traces.

Twig and cache spans are off. A render or cache span between two queries hides
a run of repeated queries, and Sentry's N+1 query detector then misses it.

## What Sentry never receives

- The email, IP address or id of a user. An email address in an exception
  message becomes `[email]`.
- A request body.
- A URL or a query string. The route name identifies the page.
- A request header other than `Host`, `User-Agent`, `Accept`,
  `Accept-Language`, `Content-Type` and `Content-Length`. Cookies, the
  `Authorization` header, the `Referer` header and every token header stay on
  the instance.
- A console command line.
- The arguments of a function in a stack trace.
- Trace headers. Loupe adds none to a request to another host, so other hosts
  learn nothing about the trace. The browser SDK adds them only to requests to
  the instance itself.

## Stay within a Sentry quota

Both rates default to `1.0`, so every request, message and command is traced
and profiled. On a busy instance this can use up a Sentry quota quickly. Lower
`SENTRY_TRACES_SAMPLE_RATE` first, for example to `0.1`. Lower
`SENTRY_PROFILES_SAMPLE_RATE` too if profiles still use too much. Errors are
not sampled, so each unhandled exception still reaches Sentry.
