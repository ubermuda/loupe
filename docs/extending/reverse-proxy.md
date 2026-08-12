---
title: "Reverse proxy"
description: "How to reach a development instance — with trusted certificates, or over plain HTTP."
---


**Decide this before running that first line.** `just up` publishes no host
port: it joins an **external** Docker network named `traefik` and expects a
proxy with a `websecure` entrypoint, a `postgres` TCP entrypoint (the database
is routed too, so `psql` works from the host) and a certificate resolver named
`stepca`. Absent the network it *fails* rather than degrades. Served, it answers
at `https://<COMPOSE_PROJECT_NAME>.dev.localhost`, plus `mercure.…`, `mailpit.…`
and `db.…`.

[`examples/`](../../examples/) holds both ways to satisfy that:

- **[`traefik-stepca/`](../../examples/traefik-stepca/)** — the proxy this repo is
  developed against: Traefik, a [step-ca](https://smallstep.com/docs/step-ca/)
  certificate authority, and the dnsmasq that lets step-ca resolve those
  hostnames to validate them. `just up` then `just trust` in that directory, and
  one instance serves every `*.dev.localhost` project on the machine. Trusting
  the generated root is the one manual step, and cannot be automated away.
- **[`no-proxy/`](../../examples/no-proxy/)** — a compose override that publishes
  nginx on `http://localhost:8080` instead. No proxy, no certificate, nothing to
  trust; set `DEFAULT_URI` to match. Fine for the app, awkward for the
  site-review widget — that README explains why.

Then `just up` from this repo.
