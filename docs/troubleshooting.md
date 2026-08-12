---
title: "Troubleshooting"
description: "Symptoms, and what actually causes them. Grouped by what you observe, not by subsystem."
---

## Nothing queued ever happens

Mail is never delivered, exports never build, expired archives are never purged,
the trial sweep never runs, the site-review outbox never drains — and every one
of them fails silently, because the request that queued the work still returns
200.

**There is no worker.** Nothing consumes the queues unless you run one, and
nothing warns you.

```sh
docker exec <web-container> sh -c "ps aux | grep -c '[m]essenger:consume'"   # 0 is your bug
```

See [What runs in production](getting-started/architecture.md).

## Registration does not work

Email verification is mandatory, so nobody can register until mail works.

`MAILER_FROM_ADDRESS` falls back to `noreply@localhost`, which real mail servers
reject. `/admin/status` reports whether the sender is still that default and
whether the transport accepts a connection.

If mail is broken and you are already locked out, `app:user:verify` is the
escape hatch — see [Recovering an instance](operating/recovering.md).

## `/install` returns 404 in production

Working as designed: the wizard **fails closed**. With `APP_ENV=prod` and no
`INSTALL_TOKEN` configured it 404s outright, so a forgotten variable locks the
wizard rather than exposing it to whoever finds the instance first.

Recover from a shell with `app:admin:create`, or set the token and redeploy.
See [First run](operating/first-run.md).

## Every MCP call is rejected with 403

That is the DNS-rebinding guard. The response names `MCP_ALLOWED_HOSTS` and
echoes the host it rejected. Add the hostname agents actually use — hostnames
only, no port.

A **401** instead means the route registered and the firewall rejected your
credentials. A **404** means the route did not register at all.

## Data-export downloads 404

The worker wrote an archive the web container cannot see. `EXPORT_STORAGE=local`
is only correct when both processes share a filesystem; separate containers do
not. See [Object storage](extending/object-storage.md).

If uploads to S3 fail instead, it is likely the canned ACL — no single value
works on every provider. See [Known gaps](known-gaps.md).

## Site-review comments never reach the agent

Push needs a Mercure hub, and without one the publish failure is only logged.
Submissions are not lost: they sit in the outbox, visible at
`/admin/site-review-outbox`, and a scheduled drain replays them once a hub
exists. See [Mercure](extending/mercure.md).

## Generated links point at the wrong host

`DEFAULT_URI` is the single host-shaped setting the application has. It builds
absolute links outside an HTTP request — console commands, the worker — and pins
the host of security-sensitive email so a forged `Host` header cannot redirect
it. Password-reset and export-download links are what break first.

Behind a proxy that reaches the app from a public address, also set
`TRUSTED_PROXIES`; until you do, forwarded scheme and host headers are ignored
and every visitor shares the balancer's IP, so per-IP rate limits throttle all
your users collectively.

## A development instance will not start

`just up` joins an **external** Docker network named `traefik`. Without it,
Compose errors out rather than starting something unreachable. Either run a
proxy or use the no-proxy override — see
[Reverse proxy](extending/reverse-proxy.md).
