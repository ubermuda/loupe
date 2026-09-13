---
title: "Mercure hub"
description: "Optional. Needed for site-review push and live board refresh; without it, submissions still save."
---

Site-review push and live board refresh are the things that need a Mercure hub. Leaving it off is a
supported state: submissions reach the outbox first and a scheduled drain
replays them once a hub exists, so nothing is lost — but nothing reaches a
running agent either, and the publish failure is only logged. It degrades
silently rather than erroring.

The hub is in-memory. A restart drops undelivered updates, which is exactly why
submissions are recorded in the `outbox_events` outbox and the bridge
resumes from `Last-Event-ID`: delivery is best effort, replay is not.

## Configuration

| Variable | Purpose |
|---|---|
| `MERCURE_JWT_SECRET` | Shared HS256 key, minimum 32 characters, **identical for the app and the hub**. No default ships — unset means Mercure fails loudly rather than signing with a publicly-known key. |
| `MERCURE_URL` | Where the app POSTs updates — the hub on the internal network. |
| `MERCURE_PUBLIC_URL` | Where clients subscribe. A genuinely separate host, since the bridge CLI reaches it directly, so it cannot be derived from `DEFAULT_URI`. |

Two feature flags use the hub, and each switches on its own:

| Flag | Switches |
|---|---|
| `agent.push.enabled` | The outbox drain and the bridge CLI's stream credentials at `/api/projects/{handle}/stream`. |
| `live_updates.enabled` | Live updates in the browser: the subscriber cookie, the page element, `POST /mercure/authorize`, and the board refresh. |

Both flags require all three variables. With any of them blank, the flag reads
as off whatever the admin page stores. Both ship on. An instance that upgrades
gets `live_updates.enabled` with the value its `agent.push.enabled` had, because
that flag switched live updates before.

## In development

`just mercure-up` starts the hub behind a compose profile; `just mercure-down`
stops it. The e2e suite passes without it.

If you serve the app without a reverse proxy, note that `MERCURE_PUBLIC_URL`
belongs in **`.env.dev.local`**, not `.env.local`: `.env.dev` pins it to the
Traefik host and outranks `.env.local`, so a value set there is read and then
discarded. `bin/console debug:dotenv` prints the precedence.

## Listening from a page

A page opens one connection to the hub, whichever features on it listen. The
server decides which topics the connection carries, and the browser never names
a topic it was not given.

A feature that wants live updates on a page adds three things:

1. A class that implements `App\Mercure\MercureTopicAuthorizerInterface`. It
   returns `null` for a topic its module does not own. For a topic it owns, it
   returns whether the current user may subscribe. `BoardTopicAuthorizer` is the
   example, and it applies the project view permission.
2. A call to `{% do mercure_subscribe(topic) %}` in the page template.
3. A handler in a Stimulus controller, with `subscribe(type, handler)` or
   `subscribe(topic, types, handler)` from `assets/lib/mercure.js`. A handler
   receives each message whose JSON `type` it names.

The layout asks every authorizer about the requested topics. It writes one
`mercureAuthorization` cookie that holds the allowed topics, and it renders them
into `#mercure-subscriptions`. A topic that no authorizer claims, or that its
authorizer refuses, never enters the token.

Before it reconnects, the page posts its topics to `POST /mercure/authorize`.
That endpoint runs the same authorizers, drops what they refuse, and writes the
cookie again. It takes a CSRF token and allows 30 renewals a minute per user.

Keep the message `type` in the JSON body of an update. `Update::$type` renames
the SSE event, and the page listens for `message` events only.

With the hub unconfigured or `live_updates.enabled` off, the layout renders no
element, and a page opens no connection. A renewal then answers an empty topic
list and sets no cookie. `agent.push.enabled` has no effect on a page.

## In production

On the single-host stack the hub sits behind a compose profile and stays off
unless you ask for it. On App Platform, setting `mercure_jwt_secret` runs a hub
as a second service and routes `/.well-known/mercure` on the app's own domain to
it, deriving all three variables itself. See
[Single-host Docker Compose](../getting-started/docker-compose.md) and
[App Platform](../getting-started/digitalocean.md).
