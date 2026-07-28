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
- **Command-line bridge** — a Go binary ([`cli/`](cli/README.md)) that streams
  each submitted site review straight into a Claude Code session running in
  tmux, so your feedback becomes the agent's next instruction.
- **Scoped API tokens** — separate MCP and site-review scopes, stored hashed.

## Requirements

- Docker + Docker Compose
- [`just`](https://github.com/casey/just) command runner
- PHP 8.5+ and Composer (for running tooling outside the container)

## Quickstart (local development)

```bash
just up                # start nginx, php-fpm, postgres, mercure
just composer install  # runs inside the php-fpm container
just migrate-run       # set up the database
```

`just --list` shows every recipe.

The app runs at `https://loupe.dev.localhost` — but only once a reverse proxy is
in place; see below.

### Reverse proxy (Traefik)

The containers publish no host ports. The stack joins an **external** Docker
network named `traefik` and expects a Traefik instance with a `websecure`
entrypoint and a `stepca` certificate resolver, which serves
`https://<COMPOSE_PROJECT_NAME>.dev.localhost` (plus `mercure.…` and
`mailpit.…`, which the e2e suite uses).

If you don't already run one:

```bash
docker network create traefik
```

```yaml
# traefik/compose.yaml — run once, separately from the app
services:
  traefik:
    image: traefik:v3
    restart: unless-stopped
    command:
      - --providers.docker=true
      - --providers.docker.exposedbydefault=false
      - --entrypoints.websecure.address=:443
      # Local TLS. Point this at your own ACME CA (e.g. a step-ca instance).
      - --certificatesresolvers.stepca.acme.caserver=https://ca.internal/acme/acme/directory
      - --certificatesresolvers.stepca.acme.email=you@example.com
      - --certificatesresolvers.stepca.acme.storage=/acme/acme.json
      - --certificatesresolvers.stepca.acme.tlschallenge=true
    ports:
      - '443:443'
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - ./acme:/acme
    networks: [traefik]

networks:
  traefik:
    external: true
```

**Would rather not run Traefik?** Publish nginx directly and browse
`http://localhost:8080` instead:

```yaml
# compose.override.yaml
services:
  nginx:
    ports:
      - '8080:80'
```

With that override you also need to point `DEFAULT_URI` and `MERCURE_PUBLIC_URL`
at the plain-HTTP host in `.env.local`.

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
just composer  # run composer in the container, e.g. `just composer require foo/bar`
just secrets-scan # scan the full git history for committed secrets
just cli-test  # vet + test the Go CLI in cli/
just cli-build # cross-compile the Go CLI into cli/dist/
```

Run a single unit test: `just phpunit --filter TestClassName`

## Production

`docker/prod/` builds a self-contained image (nginx + php-fpm under supervisord,
OPcache tuned, optimized autoloader, assets compiled and cache warmed):

```bash
just build-prod    # build the linux/amd64 image
just deploy        # build, push, and roll out a new deployment
just logs-prod     # tail production logs
just shell-prod    # shell into the prod image locally, for debugging the build
```

Migrations are deliberately **not** run from the entrypoint — with several
replicas they would race. Run the release step once per deploy:

```bash
docker run --rm --env-file <your prod env> <image> docker/prod/release.sh
```

Two topologies ship with the project. `compose.prod.yaml` runs everything on one
host — web, worker, Postgres and the Mercure hub — and needs no cloud account:

```bash
cp compose.prod.env.example compose.prod.env      # then fill it in
docker compose -f compose.prod.yaml --env-file compose.prod.env up -d
```

The other targets DigitalOcean App Platform, with the infrastructure in
[`terraform/`](terraform/README.md) — `just deploy` and `just logs-prod` assume
it. Both are documented in [`DEPLOY.md`](DEPLOY.md). Self-hosting on anything
else needs nothing more than the image, the release step, and the environment
variables below.

Supply these as real environment variables (never commit them):

| Variable | Purpose |
|---|---|
| `APP_SECRET` | Symfony secret |
| `DATABASE_URL` | Postgres DSN |
| `MERCURE_URL` / `MERCURE_PUBLIC_URL` | Mercure hub endpoints |
| `MERCURE_JWT_SECRET` | Must match the hub's publisher/subscriber keys. **No default ships** — if unset, Mercure fails loudly rather than signing with a public key. |
| `MAILER_DSN` | Outbound mail |
| `APP_ENCRYPTION_KEY` | Only if you use `encrypted_string` columns |

## Command-line bridge

`cli/` holds a small Go binary that closes the loop: it subscribes to your
site-review stream and types each submitted review straight into a Claude Code
session running in tmux. Build it with `just cli-build` — see
[`cli/README.md`](cli/README.md) for the commands and flags.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Please report security issues privately —
see [SECURITY.md](SECURITY.md).

## License

Licensed under the [GNU Affero General Public License v3.0 or later](LICENSE)
(AGPL-3.0-or-later). If you run a modified version of Loupe as a network service,
the AGPL requires you to make your source available to its users.
