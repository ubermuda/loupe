---
title: "Single-host Docker Compose"
description: "The whole application on one machine, with no cloud account of any kind."
---


`docker/compose/prod.yaml` runs the whole application on one host with no cloud account
of any kind. It is the same production image, run as three services: `web`
(nginx + php-fpm), `worker` (the messenger consumer, which also runs everything
on the schedule) and `database` (Postgres).

A fourth, `mercure` (the hub), sits behind a compose profile and stays off
unless you ask for it — site-review push is the only thing that needs it. To
enable it, set `MERCURE_JWT_SECRET` and `MERCURE_PUBLIC_URL` in
`docker/compose/prod.env`, give the hub's hostname a route in your reverse proxy, and
add `--profile mercure` to every `docker compose` command for this stack.

```bash
cp docker/compose/prod.env.example docker/compose/prod.env      # then fill it in

# Only if you cannot pull the published image — see below.
docker compose -f docker/compose/prod.yaml --env-file docker/compose/prod.env build

docker compose -f docker/compose/prod.yaml --env-file docker/compose/prod.env up -d

# Once per deploy, never from a container's entrypoint:
docker compose -f docker/compose/prod.yaml --env-file docker/compose/prod.env \
    run --rm web docker/prod/release.sh
```

`--env-file` is **not optional**. Without it Compose reads the repository's
`.env`, which is the development configuration. Every setting with no safe
default is guarded, so a forgotten flag aborts the command instead of starting a
misconfigured instance.

**On the `build` step.** `LOUPE_PROD_IMAGE` defaults to this project's own
package, and a published GHCR package is not necessarily one you can pull:
package visibility is configured separately from repository visibility, so a
public repository does not imply a pullable image. Both `web` and `worker` carry
a build definition for exactly that case — run `build` once and the stack is
self-sufficient, with no registry access needed at all. If you are pushing your
own image instead, set `LOUPE_PROD_IMAGE` and skip the step.

What you still have to provide:

- **`INSTALL_TOKEN`, before the first deploy.** It gates `/install`, which is
  where the first administrator comes from — registration will not create one.
  The wizard fails closed in production, so an unset value means `/install`
  returns 404 and there is no browser route to an account at all. The
  `${INSTALL_TOKEN:?}` guard in `prod.yaml` stops the stack rather than let
  that happen. [First run](../operating/first-run.md) walks the wizard through.
- **A reverse proxy.** Both published ports bind to loopback. Terminate TLS in
  front, forward `X-Forwarded-Proto` and `X-Forwarded-For`, and set
  `TRUSTED_PROXIES` if that proxy reaches the app from a public address.
- **An SMTP server** for `MAILER_DSN`. Email verification is mandatory, so
  registration does not work without one.
- **A hostname for the hub.** `MERCURE_PUBLIC_URL` is a separate host that the
  bridge CLI subscribes to directly; route it to the `mercure` service.
- **Backups** of the `database_data` and `exports` volumes.

Unlike App Platform, this topology *can* share a filesystem, so `EXPORT_STORAGE`
stays at `local` and both containers mount the same `exports` volume. That is
also why the worker runs its consumer as `www-data` rather than root: archives
are written `0600`, and a root-written archive would be unreadable to the web
container's php-fpm workers.

