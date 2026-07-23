# Loupe

Loupe is a document- and site-review tool for humans working with AI agents.

An agent (or a person) submits a Markdown **document**; humans review it inline —
selecting text, leaving comments, and either approving it or requesting changes.
A **Model Context Protocol (MCP)** endpoint lets AI coding agents create documents
and hand back a review URL, so a long-form plan or spec gets considered human
feedback instead of scrolling past in a terminal. A companion **site-review**
widget brings the same select-and-comment flow to live web pages.

Built with Symfony, Tailwind CSS + DaisyUI, and Symfony UX (Stimulus + Turbo).

## Features

- **Document review** — submit Markdown, review it rendered, comment on selected
  passages, approve or request changes, and revise across versions.
- **MCP endpoint** — agents authenticate with a scoped API token and call
  `create_document` / `revise_document`, receiving a shareable review URL.
- **Site review** — an embeddable widget for leaving review comments on any web
  page, streamed back to the reviewer in real time over Mercure.
- **Scoped API tokens** — separate MCP and site-review scopes, stored hashed.

## Requirements

- Docker + Docker Compose
- [`just`](https://github.com/casey/just) command runner
- PHP 8.5+ and Composer (for running tooling outside the container)

## Quickstart (local development)

```bash
docker compose up -d                       # nginx, php-fpm, postgres, mercure
composer install
bin/console doctrine:migrations:migrate    # set up the database
```

The app runs at `https://loupe.dev.localhost`.

Copy the environment overrides you need into `.env.local` (never commit it):

```dotenv
DATABASE_URL="postgresql://app:!ChangeMe!@db.loupe.dev.localhost:5432/app?serverVersion=16&charset=utf8"
```

Set a real `MERCURE_JWT_SECRET`, `APP_SECRET`, and (if you use encrypted columns)
`APP_ENCRYPTION_KEY` per environment — see `.env` for the full list. **Never
commit real secrets.**

## Common commands

```bash
just cs        # apply formatter + Rector fixes
just ci        # check-only: phpstan, arkitect, gamache, eslint, phpunit
just e2e       # Playwright end-to-end tests
just shell     # bash inside the php-fpm container
```

Run a single unit test: `just phpunit --filter TestClassName`

## Production

`docker/prod/` builds a self-contained image (nginx + php-fpm under supervisord,
OPcache tuned, optimized autoloader, assets compiled and cache warmed):

```bash
docker build -f docker/prod/Dockerfile -t loupe .
```

Migrations are deliberately **not** run from the entrypoint — with several
replicas they would race. Run the release step once per deploy:

```bash
docker run --rm --env-file <your prod env> loupe docker/prod/release.sh
```

Supply these as real environment variables (never commit them):

| Variable | Purpose |
|---|---|
| `APP_SECRET` | Symfony secret |
| `DATABASE_URL` | Postgres DSN |
| `MERCURE_URL` / `MERCURE_PUBLIC_URL` | Mercure hub endpoints |
| `MERCURE_JWT_SECRET` | Must match the hub's publisher/subscriber keys. **No default ships** — if unset, Mercure fails loudly rather than signing with a public key. |
| `MAILER_DSN` | Outbound mail |
| `APP_ENCRYPTION_KEY` | Only if you use `encrypted_string` columns |

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Please report security issues privately —
see [SECURITY.md](SECURITY.md).

## License

Licensed under the [GNU Affero General Public License v3.0 or later](LICENSE)
(AGPL-3.0-or-later). If you run a modified version of Loupe as a network service,
the AGPL requires you to make your source available to its users.
