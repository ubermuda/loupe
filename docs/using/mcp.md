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
| `document_highlight` | Tint the passages to read first |
| `document_rename` | Change the title without minting a version |
| `document_archive` / `document_unarchive` | Take it out of the listing, or put it back |
| `document_set_tags` / `document_set_references` | Group it, or link it to sibling documents |
| `tag_list` | The project's existing tag vocabulary |
| `site_review_get` | Comments submitted through the widget |

## Two things agents get wrong

**Comment ids do not survive `document_revise`.** Re-anchoring copies a comment
onto the new version and leaves the original behind, so replying after revising
writes into rows nobody reads. Reply first, then revise — or revise, then
re-read the review for fresh ids. See [Documents and review](documents.md).

**A decision block's id is permanent.** Rewording its options is safe; changing
its id silently discards the reviewer's answer.

## Configuration

`MCP_ALLOWED_HOSTS` is a DNS-rebinding allowlist — hostnames only, no port. It
must contain the hostname agents actually use, or every call is rejected with a
403 that names the variable and echoes the host it rejected. See
[Environment variables](../reference/environment.md).

An unauthenticated `POST /mcp` answers **401, not 404**. A 404 means the route
did not register; a 403 is the rebinding guard.
