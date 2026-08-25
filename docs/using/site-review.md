---
title: "Site review"
description: "An embeddable widget for commenting on live web pages. Preview — optional, and not release-ready."
---

The site-review widget brings the select-and-comment flow to any web page. A
reviewer highlights something on the page and leaves a comment; it is saved to
the project the moment they press Save, and the agent can pull it with
`site_review_get` from then on. There is no send step to remember.

**This is a preview.** It works and is used daily on this project, but it is not
covered by any release promise, and the pieces around it — the hub, the
[command-line bridge](../extending/cli-bridge.md) — are optional and unreleased.

## Embedding it

Mint a widget token from the project (`/projects/{id}/widget-token`, and
`/regenerate` to roll it), then paste the snippet the project page gives you:

```html
<script src="https://your-instance/site-review/widget.js" data-token="..."></script>
```

The widget derives its backend from its own `script.src`, so the host it is
served from is the instance it talks to.

**Install it on staging and preview environments only, never a public site.**
The token appears in page source, so anyone who can view the page holds it — and
that credential reads, edits and deletes every pending comment on the project,
not only the ones its holder wrote. Keeping the widget off public pages is what
bounds who that is. Use a dedicated site-review-scoped token, never an MCP token
or a production credential.

## What it needs from the page

Very little. The widget is a `fetch` with a bearer header — no clipboard, no
storage, no cookies, and no browser API that requires a secure context. Cross-
origin embedding works because the API answers CORS itself.

Serving Loupe over plain HTTP is therefore fine when the reviewed page is also
plain HTTP. Embedding it in an **HTTPS** page while Loupe is on
`http://localhost` is the doubtful case — not because of mixed content, which
exempts localhost, but because of Chrome's private-network rules. That is
untested; see [Reverse proxy](../extending/reverse-proxy.md).

## What the reviewer sees, and for how long

The widget lists the comments still waiting on the agent. A comment stays in
that list — editable and deletable — until the agent marks it addressed, and
then it **disappears from the widget**.

That is the intended lifecycle, not a loss: the comment is still on the
project's site-review page in the web UI, where you review the fix and resolve
it. But it means the widget is a worklist of outstanding feedback rather than a
record of everything you have said, and a comment can vanish from under you
while you are looking at the page.

If you are editing a comment at the moment the agent picks it up, your save is
refused and the widget tells you so rather than silently discarding it.

## Reaching your agent

Comments do not push. Your agent sees them when it calls `site_review_get`, so
ask it to look — there is nothing to press, and nothing arrives unprompted.

A comment carries no author, so an agent cannot tell yours apart from anyone
else's on the page. The shipped `loupe-site-review` skill therefore escalates by
category rather than by who asked: any comment that would change a destination,
an identity, a credential or third-party code goes to you instead of being
acted on. That is the same reason the widget belongs on staging only.

Live push over a Mercure hub, an outbox for undelivered events, and the
[command-line bridge](../extending/cli-bridge.md) are all still present but
**currently inert**: nothing publishes an event, so the outbox stays empty and
the per-project and `/admin/site-review-outbox` pages have nothing to show.
That part of the feature is unfinished. Pulling with `site_review_get` is the
supported path today.
