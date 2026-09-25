---
title: "Mercure hub"
description: "Optional. Needed for site-review push, the live board and the live inbox count; without it, submissions still save."
---

Site-review push, the live board, the worker run lists and the live inbox count are the things that need a Mercure hub. Leaving it off is a
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
| `agent.push.enabled` | The outbox drain, the bridge CLI's subscriber credentials at `GET /api/events`, and its worker-run reports at `PUT /api/projects/{handle}/worker-runs/{runId}`, `PUT /api/bridges/{bridgeId}/runs` and `POST /api/projects/{handle}/worker-runs`. |
| `live_updates.enabled` | Live updates in the browser: the subscriber cookie, the page element, `POST /mercure/authorize`, every publish of `LiveUpdatePublisher`, the live board, the worker run lists, and the inbox count in the sidebar. |

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
   example, and it applies the project view permission. `InboxTopicAuthorizer`
   allows a user only their own `/users/{id}/inbox` topic, while the inbox is on.
2. A call to `{% do mercure_subscribe(topic) %}` in the page template.
3. A handler in a Stimulus controller, with `on(types, handler, options)` from
   `assets/lib/live.js`. A handler receives each message whose JSON `type` it
   names. See [The browser layer](#the-browser-layer).

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

## Publishing a live update

Send every browser update through `App\Mercure\LiveUpdatePublisher`. Call
`queue(topic, payload)` after the change commits, so a rollback sends nothing.
The payload is a flat array of scalars, and it must hold a `type`.

The publisher keeps the updates until the work is done. It publishes them after
the response is sent, after a messenger message is handled, or at the end of a
console command. A hub that hangs therefore never slows a change. The same
update queued twice in one request goes out once.

The publisher adds an `origin` to each payload that has none. The value is the
`X-Loupe-Origin` header of the request, which the browser layer sets on each
Turbo request. A page compares it with its own id to tell its own changes.

The publisher reads `live_updates.enabled` when it publishes, and sends nothing
while the flag is off. A failed publish is only logged, as
`live_updates.publish_failed`, and the change stays saved.

## The browser layer

`assets/lib/live.js` sits on top of the Mercure client. A controller imports
it and never talks to the hub itself.

| Function | What it does |
|---|---|
| `on(types, handler, options)` | Calls `handler` for each change of those types, from the hub or from `emit`. Each change carries `local` and `own`. `own` is true when the `origin` is this page. It returns a function that removes the handler. |
| `emit(type, detail)` | Gives a change that this page made to its own handlers at once, with `local` and `own` true. It works with no hub. |
| `status(listener)` | Calls the listener at once and on each change with `live`, `paused` or `off`. The state is `paused` after the connection stays down for 5 seconds. |
| `originId()` | The id of this page, which the `X-Loupe-Origin` header carries. |

The hub keeps no history. A message sent while a page is not connected never
reaches that page. Pass `onReconnect` in the options of `on`, and fetch the
current state there. The board reloads its frame, and the worker run lists
fetch again.

Treat `own` as a hint only. A member can send the origin of another tab, so
`own` may hide a highlight, but it must never skip a fetch.

## Worked example: the board

A board page listens on the topic of its project. Every card write path
dispatches `App\Module\Board\Event\CardChanged` after its commit.
`PublishCardChangedOnCardChanged` turns the event into a `board.card_changed`
update with `cardId`, `change` and `contentChanged`, and no card content.

The `board_live` controller receives the update. It fetches
`/projects/{projectId}/board/cards/{cardId}/placement` with the session of the
viewer, and renders the Turbo Stream it gets back. The server builds that
stream for the viewer, so each viewer sees only the cards they may see. A burst
of updates for one card costs one fetch, and a card in a drag waits until the
drag ends.

A new path that writes a card must dispatch `CardChanged`, or open boards miss
the change until they reload. A change to the columns dispatches
`BoardColumnsChanged` instead, and the board reloads its frame.

The card drawer calls `emit('board.card_changed', …)` after a save. The board
therefore places the card at once, also with no hub.

## In production

On the single-host stack the hub sits behind a compose profile and stays off
unless you ask for it. On App Platform, setting `mercure_jwt_secret` runs a hub
as a second service and routes `/.well-known/mercure` on the app's own domain to
it, deriving all three variables itself. See
[Single-host Docker Compose](../getting-started/docker-compose.md) and
[App Platform](../getting-started/digitalocean.md).
