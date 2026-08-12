---
title: From source
description: A development clone. Assumes Docker, just, and a way to reach the app — see Reverse proxy.
---


```bash
just up                # start nginx, php-fpm, postgres — see the note below
just composer install  # runs inside the php-fpm container
just migrate-run       # set up the database
just exec bin/console app:dev:seed   # log in as dev@loupe.test / password
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

Set a real `MERCURE_JWT_SECRET`, `APP_SECRET`, and (if you use encrypted columns)
`APP_ENCRYPTION_KEY` per environment. `.env` documents every variable inline;
[Environment variables](../reference/environment.md#secrets) has the commands that generate these three.
**Never commit real secrets.**
