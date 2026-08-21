---
title: "Development"
description: "The commands you will actually use, and the gate a change has to pass."
---

Everything runs in Docker; `just --list` shows every recipe.

```sh
just up                # start nginx, php-fpm, postgres
just composer install  # composer inside the container — never on the host
just migrate-run       # set up the database
just exec bin/console app:dev:seed   # dev@loupe.test or admin@loupe.test / password
```

`just up` publishes no host port and needs a reverse proxy — see
[Reverse proxy](../extending/reverse-proxy.md) before the first command.

## Day to day

```sh
just shell     # bash inside the php-fpm container
just composer  # e.g. just composer require foo/bar
just worker    # foreground messenger consumer
just tailwind  # CSS watch mode (already running in dev)
```

Tailwind rebuilds automatically in the dev container. Do not run
`tailwind:build` by hand after editing templates — wait a second and re-check.
The same goes for `cache:clear`.

Run one unit test with `just phpunit --filter TestClassName`, one e2e spec with
`just e2e tests/<area>/<spec>.spec.ts`.

## The gate

```sh
just cs        # apply formatter + Rector fixes
just ci        # check-only: lint, style, phpstan, arkitect, gamache, phpunit
just e2e       # Playwright end-to-end
```

`cs` writes, `ci` only reports — running `ci` alone will tell you about style
violations it will not fix. Fix every failure before proposing a change,
including ones that pre-date it.

**e2e runs against a dedicated target, never the dev host.** `just e2e-up`
creates a disposable database and a sidecar serving this checkout; `just e2e`
refuses to start without it. The suite is destructive by design — one project
truncates every table — so pointing it at your development database wipes it.

No messenger consumer is needed. Messages dispatched during a request carrying
`X-Playwright: 1` are handled inline, so specs that assert on mail or a download
link work with nothing draining the queue.

The suite cannot be parallelised: Mailpit is shared, so mail-asserting specs
across concurrent runs read each other's messages.

## Secrets

```sh
just secrets-scan   # gitleaks + trufflehog over the whole history
```

Not part of `ci` — it needs host tooling and outbound network. Run it before
publishing anything.
