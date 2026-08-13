---
title: "Demo"
description: "One container holding the app, worker and Postgres. For evaluating Loupe, not for running it."
---


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
set. To run Loupe for real, see [Single-host Docker Compose](docker-compose.md) — `docker/compose/prod.yaml`
is a complete single-host stack.
