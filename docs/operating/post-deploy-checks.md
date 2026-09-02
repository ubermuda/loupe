---
title: "Post-deploy checks"
description: "What to verify after a deploy, and what each check can and cannot prove."
---

1. `GET /healthz` returns 200 and `{"status":"ok"}`. It is unauthenticated,
   answers 503 with `{"status":"error"}` when the database does not respond, and
   sends `Cache-Control: no-store` so a probe never reads a cached verdict from
   a container that has since died. It deliberately says nothing else — an
   anonymous caller learns whether the instance is up, and no more.
   `GET /livez` is the weaker sibling: no security listener, no template, no
   database, so it answers 200 as soon as PHP runs. It is what a platform
   health check should point at until the database is reachable — it proves
   the container is alive and nothing else.

   **To prove *which build* went live, send `HEALTH_PROBE_TOKEN` as an
   `X-Probe-Token` header.** `/healthz` then adds a `version` field:

   ```console
   $ curl -s -H "X-Probe-Token: $HEALTH_PROBE_TOKEN" https://<host>/healthz
   {"status":"ok","version":"31bf5dd"}
   ```

   Compare that to the commit you built from. This is the check that actually
   closes a deploy: a 200 only says *something* is serving, and a rollout can
   report ACTIVE while an old container still answers. Do not substitute
   grepping the HTML for a string the new build introduced — it works, but it
   is indirect, silently wrong when a page is cached, and needs a fresh guess
   at what changed on every deploy.

   Unset, the field never appears — the default a self-hosted instance
   inherits, since an instance must not advertise its build to anyone who asks.
   The token goes in the header, never a query parameter: those are recorded in
   access logs and forwarded in `Referer`.
2. `POST /mcp` with no credentials returns **401, not 404**. A 404 means the
   route did not register; a 401 means it registered and the firewall rejected
   you. A **403** is different again: that is the DNS-rebinding guard, and the
   body names `MCP_ALLOWED_HOSTS` and echoes the host it rejected.
3. `bin/console doctrine:migrations:status` reports no pending migrations.
4. Run **`bin/console health-check:status`**, or open **`/admin/status`** — same
   checks, same wording. The command exits non-zero when any check has failed,
   so a deploy script can end with it; add `--strict` to fail on warnings too.
   Either one reports, for this instance, whether the mail
   transport accepts a connection, whether the sender address is still the
   undeliverable default, whether the message queue is being drained, how many
   messages have failed, whether the Mercure hub answers, whether client
   addresses survive your proxy (step 5), and — when billing is
   on — whether the Stripe keys are set. The install wizard shows the same page
   before it creates your administrator, so a broken mailer is visible *before*
   it can lock you out.

   The worker check is deliberately honest about its limits: a running worker
   leaves no lasting trace, so an empty queue is reported as **unknown**, not as
   healthy. What it can prove is the failure — messages sitting available and
   unclaimed for over a minute mean nothing is consuming them.
5. **If a proxy sits in front of the app, open `/admin/status` in a browser and
   read the *Trusted proxies* row.** Reach the page through the public URL your
   API clients use. The check compares the client address the app resolves
   against the caller named in `X-Forwarded-For`. A warning means the app
   ignored that header, so every caller now resolves to the proxy.

   `TRUSTED_PROXIES` lists the proxies whose `X-Forwarded-*` headers the app
   accepts. Left empty, it falls back to `PRIVATE_SUBNETS`. That default already
   covers Docker and any balancer on a private network, so most single-host
   deployments need nothing.

   Set it explicitly when a proxy reaches the app from a **public** address.
   That is a managed load balancer, a CDN such as Cloudflare or Fastly, or an
   API gateway outside your private network. On App Platform, set
   `trusted_proxies` in `terraform.tfvars`. The module appends your range to the
   private ranges rather than replacing them, because the nearest hop is always
   the platform's own private ingress.

   Two hops are the case that is easy to miss. A trusted private ingress in
   front of an untrusted public CDN still resolves every caller to the CDN's
   egress address. The check reads the whole chain, so it reports that case too.

   **What breaks.** The app throttles `/api` and `/mcp` per client address, at
   300 requests a minute, before the firewall runs. One address for every caller
   turns that into a single bucket for the whole instance. Unrelated clients
   then collect each other's 429s, and one busy integration is enough to lock
   the rest out. Absolute URLs rendered in web pages also take their scheme and
   host from these headers, so they come out wrong as well. Email is safe:
   security-sensitive links take their host from `DEFAULT_URI` instead.

   **Two limits of the check.** It needs an HTTP request, so
   `bin/console health-check:status` omits the row altogether. It also reads
   *your* request, so it proves the path your own browser took. A gateway that
   only API clients pass through stays invisible to it, and you must confirm
   that path from its own configuration.
6. Trigger something that queues async work (a data export) and confirm it
   completes — that proves the worker is actually consuming, which step 4
   cannot. **Then follow the download link in the email and check you get a
   ZIP**: completion only proves the worker ran, while the download is what
   proves the web container can reach the archive the worker wrote.

