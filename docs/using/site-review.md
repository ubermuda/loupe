---
title: "Site review"
description: "An embeddable widget for commenting on live web pages. Preview — optional, and not release-ready."
---

The site-review widget brings the select-and-comment flow to any web page. A
reviewer highlights something on the page, leaves a comment, and it arrives in
the project as a Draft comment; with a Mercure hub running, it also streams to a
connected agent in real time.

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

**The token appears in page source.** Use a dedicated site-review-scoped token,
never an MCP token or a production credential, and expect anyone who can view
the page to be able to read it.

## What it needs from the page

Very little. The widget is a `fetch` with a bearer header — no clipboard, no
storage, no cookies, and no browser API that requires a secure context. Cross-
origin embedding works because the API answers CORS itself.

Serving Loupe over plain HTTP is therefore fine when the reviewed page is also
plain HTTP. Embedding it in an **HTTPS** page while Loupe is on
`http://localhost` is the doubtful case — not because of mixed content, which
exempts localhost, but because of Chrome's private-network rules. That is
untested; see [Reverse proxy](../extending/reverse-proxy.md).

## Where submissions go

Every submission is recorded in an outbox before its Mercure update is
published, so an unreachable hub loses nothing permanently. Undelivered events
are visible per-project and, for administrators, instance-wide at
`/admin/site-review-outbox`. The worker retries them on a schedule. See
[Failed messages and the outbox](../operating/failed-messages.md).

Without a hub, submissions still save — they simply never reach a running
agent, and the publish failure is only logged. See [Mercure](../extending/mercure.md).
