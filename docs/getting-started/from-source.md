---
title: "From source"
description: "A development clone. Assumes Docker, just, and a way to reach the app — see Reverse proxy."
---


**Before `just up`: the stack needs somewhere to be reached from.**
`compose.yaml` attaches to an **external** Docker network named `traefik` and
asks a step-ca resolver for certificates on `*.dev.localhost`. None of that is
created for you, and an absent network makes `just up` fail rather than degrade.
[Reverse proxy](../extending/reverse-proxy.md) has both ready-made setups:
`examples/traefik-stepca/` for the hostnames the rest of this page assumes, and
`examples/no-proxy/` if you would rather bind a port and skip TLS.

```bash
just up                # start nginx, php-fpm, postgres
just composer install  # runs inside the php-fpm container
just migrate-run       # set up the database
just exec bin/console app:dev:seed   # dev@loupe.test or admin@loupe.test / password
```

`just --list` shows every recipe. `just mercure-up` additionally starts the
Mercure hub, which only site-review push needs.


Once you are in, the seed command above is what gives you an account —
registration will not create the first one. "Ways to run it" lists the
alternatives.

Copy the environment overrides you need into `.env.local` (never commit it):

```dotenv
DATABASE_URL="postgresql://app:!ChangeMe!@db.loupe.dev.localhost:5432/app?serverVersion=16&charset=utf8"
```

That host is the `traefik-stepca` example's TCP route, and it assumes
`COMPOSE_PROJECT_NAME=loupe`. Under `examples/no-proxy/` the database is on
`127.0.0.1` instead.

Set a real `MERCURE_JWT_SECRET`, `APP_SECRET`, and (if you use encrypted columns)
`APP_ENCRYPTION_KEY` per environment. `.env` documents every variable inline;
[Environment variables](../reference/environment.md#secrets) has the commands that generate these three.
**Never commit real secrets.**
