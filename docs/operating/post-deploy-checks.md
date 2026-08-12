---
title: Post-deploy checks
description: What to verify after a deploy, and what each check can and cannot prove.
---

1. `GET /healthz` returns 200 and `{"status":"ok"}`. It is unauthenticated,
   answers 503 with `{"status":"error"}` when the database does not respond, and
   sends `Cache-Control: no-store` so a probe never reads a cached verdict from
   a container that has since died. It deliberately says nothing else — an
   anonymous caller learns whether the instance is up, and no more.
2. `POST /mcp` with no credentials returns **401, not 404**. A 404 means the
   route did not register; a 401 means it registered and the firewall rejected
   you. A **403** is different again: that is the DNS-rebinding guard, and the
   body names `MCP_ALLOWED_HOSTS` and echoes the host it rejected.
3. `bin/console doctrine:migrations:status` reports no pending migrations.
4. Open **`/admin/status`**. It reports, for this instance, whether the mail
   transport accepts a connection, whether the sender address is still the
   undeliverable default, whether the message queue is being drained, how many
   messages have failed, whether the Mercure hub answers, and — when billing is
   on — whether the Stripe keys are set. The install wizard shows the same page
   before it creates your administrator, so a broken mailer is visible *before*
   it can lock you out.

   The worker check is deliberately honest about its limits: a running worker
   leaves no lasting trace, so an empty queue is reported as **unknown**, not as
   healthy. What it can prove is the failure — messages sitting available and
   unclaimed for over a minute mean nothing is consuming them.
5. Trigger something that queues async work (a data export) and confirm it
   completes — that proves the worker is actually consuming, which step 4
   cannot. **Then follow the download link in the email and check you get a
   ZIP**: completion only proves the worker ran, while the download is what
   proves the web container can reach the archive the worker wrote.

