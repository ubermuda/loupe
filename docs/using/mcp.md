---
title: "The MCP endpoint"
description: "How an AI agent creates and revises documents, and how its token is scoped."
---

`POST /mcp` is a Model Context Protocol endpoint. An agent authenticates with a
project-scoped API token and calls tools that create documents, revise them, and
read back what humans said — so a long-form plan gets considered review instead
of scrolling past in a terminal.

## Getting a token

Mint one from the project: `/projects/{id}/mcp-token`, and
`/projects/{id}/mcp-token/regenerate` to roll it. Tokens are stored hashed and
scoped — an MCP token is not a site-review token and cannot be used as one. Any
token can be revoked from `/account/api-tokens/{tokenId}/revoke`.

The first-run wizard mints one for you at `/welcome/connect`.

## The Claude Code plugin

Claude Code users can install the endpoint and the two Loupe skills together,
instead of wiring the server up by hand:

```bash
claude plugin marketplace add ubermuda/loupe
claude plugin install loupe@loupe
```

Installing asks for a project API token, from the project's Connect page. The
endpoint defaults to the hosted instance; if you self-host, set it to your own
`/mcp` URL, which the same page shows. The token is marked sensitive,
so it goes to the OS keychain rather than a settings file, and it is never read
from a repository's `.claude/settings.json`: a cloned project cannot inject one.

### Setting and changing the two values

Answer the prompts, or pass them on the command line:

```bash
claude plugin install loupe@loupe \
  --config server_url=https://loupe.example.com/mcp \
  --config api_token=<token>
```

To change either one later — pointing at a different instance, switching
projects, or rotating a token — run `/plugin configure loupe@loupe` inside
Claude Code, or re-run the same `install --config` command with the new value.
Re-running prints `Plugin "loupe@loupe" is already installed`, which reads like
nothing happened; the config is updated regardless. Confirm with `claude mcp
list`, which prints the endpoint the plugin resolved:

```
plugin:loupe:loupe: https://loupe.example.com/mcp (HTTP) - ✔ Connected
```

Omitting a value leaves the notice `1 userConfig option not yet set` after
install. For `server_url` that is cosmetic — the default applies — but the
plugin cannot work until a token is set.

Alongside the server the plugin ships `loupe:loupe-documents`, which formats a
document for the review UI, and `loupe:loupe-site-review`, which works the
comment loop. The `claude mcp add` one-liner on the Connect page remains the
right choice for any other MCP client.

**A hand-configured server of the same name wins.** If you previously ran
`claude mcp add ... loupe ...`, that entry takes precedence and the plugin's
server is ignored with no warning — `claude mcp list` shows `loupe` rather than
`plugin:loupe:loupe`. Run `claude mcp remove loupe` after installing the plugin.

## What the tools do

Roughly in the order an agent uses them:

| Tool | Purpose |
|---|---|
| `document_create` | Submit Markdown as a new document; returns a review URL |
| `document_revise` | Submit a new version, described by what changed |
| `document_get` / `document_list` | Read a document, or enumerate the project's |
| `document_get_review` | Verdict, threaded comments, and answered decision blocks |
| `document_reply_to_comment` | Reply to a reviewer's thread |
| `document_mark_comment_addressed` | Mark a thread acted on |
| `document_highlight` | Tint the passages to read first (off by default — see below) |
| `document_rename` | Change the title without minting a version |
| `document_archive` / `document_unarchive` | Take it out of the listing, or put it back |
| `document_set_tags` / `document_set_references` | Group it, or link it to sibling documents |
| `tag_list` | The project's existing tag vocabulary |
| `site_review_get` | Comments submitted through the widget |
| `site_review_mark_comment_addressed` | Mark a widget comment acted on, so the next `site_review_get` skips it |

## Two things agents get wrong

**Comment ids do not survive `document_revise`.** Re-anchoring copies a comment
onto the new version and leaves the original behind, so replying after revising
writes into rows nobody reads. Reply first, then revise — or revise, then
re-read the review for fresh ids. See [Documents and review](documents.md).

**A decision block's id is permanent.** Rewording its options is safe; changing
its id silently discards the reviewer's answer.

## Configuration

`document_highlight` is behind the `review.highlights.enabled` feature flag,
seeded **off**. An agent tinting the passages a human should read first steers
the review, which is a nudge an operator opts into rather than inherits. While
it is off the tool is absent from `tools/list` and from the Connect page, so an
agent never learns of a tool this instance would refuse — switch it on in
**Admin → Feature flags**. A client holding a tool list from before the flag
changed and calling it anyway gets a plain refusal, not a broken call.

`MCP_ALLOWED_HOSTS` is a DNS-rebinding allowlist — hostnames only, no port. It
must contain the hostname agents actually use, or every call is rejected with a
403 that names the variable and echoes the host it rejected. See
[Environment variables](../reference/environment.md).

An unauthenticated `POST /mcp` answers **401, not 404**. A 404 means the route
did not register; a 403 is the rebinding guard.
