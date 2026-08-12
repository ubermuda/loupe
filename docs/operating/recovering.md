---
title: Recovering an instance
description: Getting back in when no administrator is reachable. Needs a shell on the instance.
---


Three console commands are the supported way back into an instance with no
reachable administrator. Run them in the web container (`docker exec`, or the
platform's console). All three are idempotent: running one against an account
that is already in the desired state prints "already …" and exits 0.

| Command | What it does |
|---|---|
| `app:admin:create <email>` | Ensures the email is a **verified administrator**, creating the account if it does not exist. Options: `--full-name`, `--password`. With no `--password` it prompts (or, non-interactively, generates one and prints it once). An existing account is promoted and verified in place and **keeps its password**. |
| `app:user:promote <email>` | Grants `ROLE_ADMIN` to an existing account, keeping any other roles. |
| `app:user:verify <email>` | Marks the account's email verified and burns any outstanding verification token — including on an account that was already verified, since that link logs its bearer straight in. The escape hatch when outbound mail never arrives: an unverified account is parked on the check-email page and cannot reach the admin area. |

Non-interactive first admin on a fresh instance:

```bash
docker exec <web-container> bin/console app:admin:create you@example.com
# → Created administrator you@example.com.
# → Generated password (shown once): …
```

A shell-recovered instance has no feature-flag rows at all, and that is fine:
every install flag falls back to exactly the value the wizard would have written
(registration on, no cap, billing and social login off, a 14-day trial), so the
instance behaves like a wizard-installed one with the defaults accepted. To
change any of them, visit the admin **Feature flags** page — its scan view lists
every flag the code references but the database does not define, and creates the
rows on request.

