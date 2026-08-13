---
title: "Introduction"
description: "A document- and site-review tool for humans working with AI agents."
---

Loupe is a document- and site-review tool for humans working with AI agents.

An agent (or a person) submits a Markdown **document**; humans review it inline —
selecting text, leaving comments, and either approving it or requesting changes.
A **Model Context Protocol (MCP)** endpoint lets AI coding agents create documents
and hand back a review URL, so a long-form plan or spec gets considered human
feedback instead of scrolling past in a terminal. A companion **site-review**
widget — a preview, not yet release-ready — brings the same select-and-comment
flow to live web pages.

## Start here

- **[Getting started](getting-started/index.md)** — five ways to run it, and
  which one you want.
- **[Using Loupe](using/documents.md)** — documents, the MCP endpoint, site
  review, the admin area.
- **[Extending Loupe](extending/reverse-proxy.md)** — optional infrastructure:
  reverse proxy, Mercure, object storage, social login, billing.
- **[Operating](operating/first-run.md)** — first run, recovery, backups, and
  what to check after a deploy.
- **[Reference](reference/environment.md)** — every environment variable and
  every console command.
- **[Known gaps](known-gaps.md)** — read this before a first real deploy.

## What it is built on

Symfony, Tailwind CSS and Symfony UX (Stimulus + Turbo). There is no component
library: the visual system is hand-rolled from design tokens and semantic
component classes in `assets/styles/app.css`.

Loupe runs anywhere that can host a container and a Postgres database. Nothing
in the application assumes a particular provider.

## Licence

AGPL-3.0-or-later. If you run a modified version as a network service, the
licence requires you to make your source available to its users — which is what
`APP_SOURCE_URL` and the footer link on every page exist for. See
[Environment variables](reference/environment.md).
