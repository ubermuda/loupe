---
title: "Failed messages and the outbox"
description: "Where queued work goes when it fails, and how to replay it."
---

A message that exhausts its three retries is moved to the `failed` transport
(`doctrine://default?queue_name=failed`) rather than dropped. Nothing surfaces
that automatically, so check it when mail or exports go missing:

```bash
bin/console messenger:failed:show          # list parked messages
bin/console messenger:failed:show <id> -vv # full detail, including the error
bin/console messenger:failed:retry         # re-queue them, interactively
```

`/admin/status` shows the current count, so you know whether it is worth
looking.

## The site-review outbox

Every site-review submission is recorded in an outbox before its Mercure update
is published, so a hub restart or an unreachable hub loses nothing permanently.
Events whose publish never landed are visible in two places:

- **`/admin/site-review-outbox`** — every undelivered event on the instance,
  with attempt counts and next-retry times. `ROLE_ADMIN`.
- **`/projects/<id>/site-review/outbox`** — the same, scoped to one project, for
  whoever can view that project.

The worker retries them every five minutes on the scheduler. To force a pass —
typically after an instance whose worker was down — run:

```bash
bin/console app:drain-site-review-outbox            # or --limit=<n>
```

It is safe to run alongside the worker; the claim is atomic.

