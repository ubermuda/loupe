# Loupe

Loupe is a document- and site-review tool for humans working with AI agents.

An agent (or a person) submits a Markdown **document**; humans review it inline —
selecting text, leaving comments, and either approving it or requesting changes.
A **Model Context Protocol (MCP)** endpoint lets AI coding agents create documents
and hand back a review URL, so a long-form plan or spec gets considered human
feedback instead of scrolling past in a terminal. A companion **site-review**
widget — a preview, not yet release-ready — brings the same select-and-comment
flow to live web pages.

Built with Symfony, Tailwind CSS and Symfony UX (Stimulus + Turbo).

## Features

- **Document review** — submit Markdown, review it rendered, comment on selected
  passages, approve or request changes, and revise across versions.
- **MCP endpoint** — agents authenticate with a scoped API token and call
  `document_create` / `document_revise`, receiving a shareable review URL.
- **Site review** *(preview, not release-ready)* — an embeddable widget for
  leaving review comments on any web page, streamed back to the reviewer over
  Mercure.
- **Command-line bridge** *(preview, unreleased)* — a Go binary
  ([`cli/`](cli/README.md)) that streams each submitted site review straight
  into a Claude Code session running in tmux.
- **Scoped API tokens** — separate MCP and site-review scopes, stored hashed.

## Try it

One container holding the app, its worker and Postgres:

```bash
docker run --rm -p 127.0.0.1:8080:80 ghcr.io/ubermuda/loupe:demo
```

Open <http://localhost:8080> and log in with `admin@example.com` /
`loupe-admin`. Bind to `127.0.0.1` as above — that password is published on this
page.

The image is for **evaluating** Loupe, not running it: its `APP_SECRET` is a
committed constant, nothing terminates TLS, and mail is discarded. See
[Demo](docs/getting-started/demo.md) for the volume and port options.

## Develop on it

```bash
just up                # start nginx, php-fpm, postgres
just composer install  # runs inside the php-fpm container
just migrate-run       # set up the database
just exec bin/console app:dev:seed   # log in as dev@loupe.test / password
```

`just up` publishes no host port — it expects a reverse proxy on an external
Docker network named `traefik`, and fails rather than degrades without one.
[`examples/`](examples/) holds a working proxy and a plain-HTTP alternative;
[Reverse proxy](docs/extending/reverse-proxy.md) explains the choice.

## Documentation

Everything else lives in [`docs/`](docs/index.md):

- [Getting started](docs/getting-started/index.md) — five ways to run it, and
  which one you want
- [Using Loupe](docs/using/documents.md) — documents, MCP, site review, admin
- [Extending Loupe](docs/extending/reverse-proxy.md) — proxy, Mercure, object
  storage, social login, billing
- [Operating](docs/operating/first-run.md) — first run, recovery, backups
- [Environment variables](docs/reference/environment.md) and
  [console commands](docs/reference/commands.md)
- [Troubleshooting](docs/troubleshooting.md) and
  [Known gaps](docs/known-gaps.md)

## Contributing

See [CONTRIBUTING](docs/contributing/index.md) and
[Development](docs/contributing/development.md). Please report security issues
privately — see [SECURITY](docs/SECURITY.md).

## License

Copyright (C) 2026 Geoffrey Bachelet.

Licensed under the [GNU Affero General Public License v3.0 or later](LICENSE)
(AGPL-3.0-or-later). If you run a modified version of Loupe as a network service,
the AGPL requires you to make your source available to its users.

Every page carries a "Source code" link in the footer to satisfy that. It points
wherever `APP_SOURCE_URL` says, defaulting to this repository — correct for an
unmodified instance, and wrong for a modified one. If you change the code, set
`APP_SOURCE_URL` to the repository that holds *your* version.
