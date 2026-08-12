# Loupe

Loupe is a document- and site-review tool for humans working with AI agents.

An agent (or a person) submits a Markdown **document**; humans review it inline —
selecting text, leaving comments, and either approving it or requesting changes.
A **Model Context Protocol (MCP)** endpoint lets AI coding agents create documents
and hand back a review URL, so a long-form plan or spec gets considered human
feedback instead of scrolling past in a terminal. A companion **site-review**
widget — a preview, not yet release-ready — brings the same select-and-comment
flow to live web pages.

Built with Symfony, Tailwind CSS and Symfony UX (Stimulus + Turbo). There is no
component library: the visual system is hand-rolled from design tokens and
semantic component classes in `assets/styles/app.css`.

## Features

- **Document review** — submit Markdown, review it rendered, comment on selected
  passages, approve or request changes, and revise across versions.
- **MCP endpoint** — agents authenticate with a scoped API token and call
  `document_create` / `document_revise`, receiving a shareable review URL.
- **Site review** *(preview, not release-ready)* — an embeddable widget for
  leaving review comments on any web page, streamed back to the reviewer in real
  time over Mercure. Optional: it stays off until you run a Mercure hub.
- **Command-line bridge** *(preview, unreleased)* — a Go binary
  ([`cli/`](cli/README.md)) that streams each submitted site review straight
  into a Claude Code session running in tmux, so your feedback becomes the
  agent's next instruction. Build it from a clone; no binary is published.
- **Scoped API tokens** — separate MCP and site-review scopes, stored hashed.

Document review and the MCP endpoint are what this repo is for. The two marked
*preview* work, are used daily here, and are not covered by any release
promise — treat them as things to try, not to depend on.

## Ways to run it

Five paths, and what separates them is who your first account is. Loupe never
leaves a fresh instance open to whoever finds it: registration refuses to create
the **first** account, so every path below has to say where that one comes from.

| I want to… | Run | First account |
|---|---|---|
| Look at it, without cloning | `docker run … loupe:demo` — [Demo](#demo-one-container) | `admin@example.com` / `loupe-admin`, baked into the image |
| Develop on it | `just up`, then `just exec bin/console app:dev:seed` — [Quickstart](#quickstart-local-development) | `dev@loupe.test` / `password`, plus sample data |
| Develop, but see the real first run | the same, then visit `/install` instead of seeding | whoever the wizard creates. Open in dev while `INSTALL_TOKEN` is empty |
| Run it for real | `deploy/compose.prod.yaml`, or DigitalOcean — [`DEPLOY.md`](docs/DEPLOY.md) | the wizard at `/install`, which **404s in production until you set `INSTALL_TOKEN`** |
| Get back in when locked out | `bin/console app:admin:create <email>` | the address you name; creates or promotes it |

`app:admin:create` is the escape hatch for every row, not just the last: it works
on any instance you have a shell on, and needs no mail, no token and no wizard.
[`DEPLOY.md`](docs/DEPLOY.md#recovering-an-instance) covers it and its two siblings.

## Requirements

The demo needs only Docker. Everything else on this page assumes a clone:

- Docker + Docker Compose
- [`just`](https://github.com/casey/just) command runner
- PHP 8.5+ and Composer (for running tooling outside the container)

## Demo (one container)

```bash
docker run --rm -p 127.0.0.1:8080:80 ghcr.io/ubermuda/loupe:demo
```

Then open <http://localhost:8080> and log in with `admin@example.com` /
`loupe-admin`. That is the whole setup — app, background worker and Postgres in
a single container, with an administrator already created. No clone, no `just`,
no database to provision, no reverse proxy. Built for `linux/amd64` and
`linux/arm64`.

Bind to `127.0.0.1` as above. The admin password is published on this page, so a
demo bound to every interface hands an admin account to everyone on the network.

The image is for evaluating Loupe, **not for running it**. Its `APP_SECRET` is a
committed constant, nothing terminates TLS in front of it, and mail is
discarded — so registration and password reset do not work, and the seeded
administrator is the only way in. The database goes with the container unless
you keep it:

```bash
docker run --rm -p 127.0.0.1:8080:80 \
  -v loupe-demo:/var/lib/postgresql/data ghcr.io/ubermuda/loupe:demo
```

Serving it on another port needs `DEFAULT_URI` to agree, or the links it
generates point at the wrong one:

```bash
docker run --rm -p 127.0.0.1:9000:80 \
  -e DEFAULT_URI=http://localhost:9000 ghcr.io/ubermuda/loupe:demo
```

From a clone, `just demo` builds the image and runs it with all of that already
set. To run Loupe for real, see [`DEPLOY.md`](docs/DEPLOY.md) — `deploy/compose.prod.yaml`
is a complete single-host stack.

## Quickstart (local development)

```bash
just up                # start nginx, php-fpm, postgres — see the note below
just composer install  # runs inside the php-fpm container
just migrate-run       # set up the database
just exec bin/console app:dev:seed   # log in as dev@loupe.test / password
```

`just --list` shows every recipe. `just mercure-up` additionally starts the
Mercure hub, which only site-review push needs.

### Serving it

**Decide this before running that first line.** `just up` publishes no host
port: it joins an **external** Docker network named `traefik` and expects a
proxy with a `websecure` entrypoint, a `postgres` TCP entrypoint (the database
is routed too, so `psql` works from the host) and a certificate resolver named
`stepca`. Absent the network it *fails* rather than degrades. Served, it answers
at `https://<COMPOSE_PROJECT_NAME>.dev.localhost`, plus `mercure.…`, `mailpit.…`
and `db.…`.

[`examples/`](examples/) holds both ways to satisfy that:

- **[`traefik-stepca/`](examples/traefik-stepca/)** — the proxy this repo is
  developed against: Traefik, a [step-ca](https://smallstep.com/docs/step-ca/)
  certificate authority, and the dnsmasq that lets step-ca resolve those
  hostnames to validate them. `just up` then `just trust` in that directory, and
  one instance serves every `*.dev.localhost` project on the machine. Trusting
  the generated root is the one manual step, and cannot be automated away.
- **[`no-proxy/`](examples/no-proxy/)** — a compose override that publishes
  nginx on `http://localhost:8080` instead. No proxy, no certificate, nothing to
  trust; set `DEFAULT_URI` to match. Fine for the app, awkward for the
  site-review widget — that README explains why.

Then `just up` from this repo.

Once you are in, the seed command above is what gives you an account —
registration will not create the first one. "Ways to run it" lists the
alternatives.

Copy the environment overrides you need into `.env.local` (never commit it):

```dotenv
DATABASE_URL="postgresql://app:!ChangeMe!@db.loupe.dev.localhost:5432/app?serverVersion=16&charset=utf8"
```

Set a real `MERCURE_JWT_SECRET`, `APP_SECRET`, and (if you use encrypted columns)
`APP_ENCRYPTION_KEY` per environment. `.env` documents every variable inline;
[`DEPLOY.md`](docs/DEPLOY.md#secrets) has the commands that generate these three.
**Never commit real secrets.**

## Common commands

```bash
just cs        # apply formatter + Rector fixes
just ci        # check-only: phpstan, arkitect, gamache, eslint, phpunit
just e2e       # Playwright end-to-end tests
just shell     # bash inside the php-fpm container
just composer  # run composer in the container, e.g. `just composer require foo/bar`
just secrets-scan # scan the full git history for committed secrets
just cli-test  # vet + test the Go CLI in cli/
just cli-build # cross-compile the Go CLI into cli/dist/
```

Run a single unit test: `just phpunit --filter TestClassName`

## Production

Loupe in production is a container plus a Postgres database, and it runs
anywhere that can host both. `docker/prod/` builds the image: nginx and php-fpm
under supervisord, OPcache tuned, optimized autoloader, assets compiled and
cache warmed. A **second container from that same image runs the messenger
worker** — without it, queued mail is never delivered, data exports never build,
the trial-end sweep never runs and the site-review outbox never drains.

Two topologies ship with the project: `deploy/compose.prod.yaml`, a complete single-host
stack needing no cloud account, and DigitalOcean App Platform with the
infrastructure in [`terraform/`](terraform/README.md).

**[`DEPLOY.md`](docs/DEPLOY.md) is the deployment guide** — both topologies, every
environment variable, the release step, first-run setup, and how to recover an
instance you are locked out of. It is the single home for that; this file
deliberately keeps no copy to drift out of date.

## Command-line bridge (preview)

`cli/` holds a small Go binary that closes the loop: it subscribes to your
site-review stream and types each submitted review straight into a Claude Code
session running in tmux. Build it with `just cli-build` — see
[`cli/README.md`](cli/README.md) for the commands and flags.

Unreleased, like the site-review widget it listens to: there is no published
binary, and it needs a Mercure hub to have anything to subscribe to.

## Contributing

See [CONTRIBUTING.md](docs/CONTRIBUTING.md). Please report security issues privately —
see [SECURITY.md](docs/SECURITY.md).

## License

Copyright (C) 2026 Geoffrey Bachelet.

Licensed under the [GNU Affero General Public License v3.0 or later](LICENSE)
(AGPL-3.0-or-later). If you run a modified version of Loupe as a network service,
the AGPL requires you to make your source available to its users.

Every page carries a "Source code" link in the footer to satisfy that. It points
wherever `APP_SOURCE_URL` says, defaulting to this repository — correct for an
unmodified instance, and wrong for a modified one. If you change the code, set
`APP_SOURCE_URL` to the repository that holds *your* version.
