# Deploying Loupe to production

This describes **the deployment we ship by default**: DigitalOcean App Platform,
from a container image built locally and pushed to GHCR, with infrastructure in
`terraform/` — a thin root over the shared module
[`terraform-digitalocean-symfony-app`](https://github.com/ubermuda/terraform-digitalocean-symfony-app),
pinned to a tag in `terraform/main.tf`.

It is a default, not a requirement. Loupe is a container plus a Postgres
database, so it runs anywhere that can host those, and nothing in the
application assumes App Platform. Everything below that is genuinely
provider-specific — the Terraform root, the `just tf-*` recipes, the App
Platform component model — is scaffolding you can replace. What you cannot skip
is in "Environment", "First deploy" and "Migrations": those describe the app's
own requirements and hold wherever you run it.

**If you would rather run Loupe on a single host you control, skip to
"Single-host Docker Compose" below.** `compose.prod.yaml` is a complete stack —
web, worker, Postgres and the Mercure hub — and needs no cloud account at all.

There is **no CI/CD pipeline** — no `.github/workflows`. Deploys are run by hand
from a workstation with `just`.

> **Read "Known gaps" before your first real deploy.** Several things the
> application needs are not configured by `terraform/main.tf` on your behalf,
> and two of them — the install token and the data-export bucket — leave a
> feature broken rather than merely off.

## What runs in production

| Component | What it is |
|---|---|
| **Web container** | `docker/prod/Dockerfile`, running supervisord as PID 1: `php-fpm` + `nginx`, and nothing else. No background process runs here. |
| **Worker container** | The *same image*, started with a different command (`enable_worker` in `terraform/main.tf`). Not part of the web container's supervisord — deliberately, so worker restarts never recycle php-fpm/nginx. It consumes `scheduler_default` first, then `async`: a deep async backlog must not delay schedule ticks. Everything recurring rides that schedule, including the hourly `app:purge-expired-exports` that deletes expired export archives — **so with `enable_worker` off, expired archives are never purged** (nor are exports ever generated). |
| **Postgres** | A per-app database and user on a managed cluster you already own, named by `db_cluster_name`. Terraform creates the database and the user; it never creates the cluster. |
| **Export bucket** | A DigitalOcean Spaces bucket plus a bucket-scoped access key, created by `terraform/spaces.tf` and wired into the app as ordinary `EXPORT_STORAGE_*` settings. |
| **Mercure hub** | Required for site-review push. A second service in the same app, run by the shared module when `mercure_jwt_secret` is set. In-memory, so delivery is best effort — see "Known gaps". |

The web container listens on port 80. Point the platform's health check at
`GET /healthz`: it is unauthenticated, returns `{"status":"ok"}` with HTTP 200
when the database answers and `{"status":"error"}` with HTTP 503 when it does
not, and it deliberately says nothing else — an anonymous caller learns whether
the instance is up, and no more.

## Prerequisites

1. `doctl`, authenticated against the DigitalOcean account.
2. `terraform`.
3. Docker with `buildx` — App Platform runs **amd64**, so the image must be
   cross-built from an Apple Silicon workstation.
4. **A container registry you can push to.** The defaults name this project's
   own package, `ghcr.io/ubermuda/loupe:prod`, which nobody else can write to.
   Point the tooling at yours in both places, or the image you push is not the
   image App Platform pulls:

   ```bash
   export LOUPE_PROD_IMAGE=ghcr.io/you/loupe:prod   # just build-prod / push-prod / deploy
   ```

   and set `registry`, `image_repository`, `image_tag` (and `registry_type`, if
   not GHCR) in `terraform.tfvars` to match.
5. A **pull token** for that registry: for GHCR, a GitHub PAT with
   `read:packages`, supplied to Terraform as `"username:PAT"`. App Platform
   needs it to pull a private image.
6. **A Postgres cluster — bring your own, or let Terraform create one.** Either
   way `region` has no default and must be set.

   **Bring your own** (what this deployment does): Terraform creates a database
   and a user on a cluster that already exists, so `db_cluster_name` has no
   default either and `terraform apply` fails until you supply it.

   ```bash
   doctl databases create loupe-db --engine pg --region tor
   doctl databases list      # the Name column is db_cluster_name
   ```

   **Or have the module create a dedicated one** — set `create_db_cluster = true`
   and leave `db_cluster_name` unset. The module creates a cluster named
   `loupe-db`, sizes it from `db_cluster_size` (default `db-s-1vcpu-1gb`) and
   `db_cluster_node_count` (default `1`), and manages its trusted sources — which
   removes the `just tf-db-bootstrap` firewall step below. The cluster carries
   `prevent_destroy`, so `terraform destroy` refuses and so does flipping the
   flag back; `terraform state rm` is the deliberate override.

   **`db_cluster_region` is a datacenter slug (`tor1`), not App Platform's metro
   slug (`tor`).** They are different namespaces. Passing the wrong one plans
   cleanly and fails at apply, so the module validates its shape and warns when
   the two are not colocated.

7. **A Spaces access key pair**, generated under "Spaces Keys" in the control
   panel. Spaces authenticates with S3-style credentials rather than the API
   token, so Terraform needs both to create the export bucket. Export them as
   `SPACES_ACCESS_KEY_ID` / `SPACES_SECRET_ACCESS_KEY`. (Not needed if you set
   `create_export_bucket = false` and bring your own S3 bucket.)

## Secrets

Generate once, then keep them somewhere durable — losing `APP_ENCRYPTION_KEY`
makes existing encrypted columns unreadable.

```bash
export TF_VAR_app_secret=$(openssl rand -hex 16)
export TF_VAR_app_encryption_key=$(php -r 'echo base64_encode(sodium_crypto_secretbox_keygen());')
export TF_VAR_registry_credentials="<github-username>:<ghcr-pat>"
export TF_VAR_mailer_dsn="<production mailer DSN>"
export DIGITALOCEAN_TOKEN="<do-token>"
export SPACES_ACCESS_KEY_ID="<spaces-key>"
export SPACES_SECRET_ACCESS_KEY="<spaces-secret>"
```

`terraform/terraform.tfvars.example` is the template; copy it to
`terraform.tfvars` for anything you would rather not keep in the environment.

> **Terraform state holds these values in plaintext.** Use an encrypted remote
> backend — there is a commented block in `terraform/versions.tf`. Do not commit
> `terraform.tfstate`.

## First deploy

The first deploy has two steps that cannot be Terraformed, because a firewall
resource would cut off the sibling apps sharing the cluster.

```bash
# 1. Build and push the amd64 image. Export LOUPE_PROD_IMAGE first unless you
#    are pushing to this project's own package.
just push-prod

# 2. Create the infrastructure, including the export bucket. Leave
#    enable_predeploy_migrations OFF for now — the migration job cannot reach
#    the database until step 3.
just tf-init
just tf-apply

# 3. One-time database bootstrap: add this app plus your IP to the cluster's
#    trusted sources, and GRANT schema privileges to the app's user.
just tf-db-bootstrap

# 4. Run migrations once, by hand.
docker run --rm --env-file <prod env file> \
    "${LOUPE_PROD_IMAGE:-ghcr.io/ubermuda/loupe:prod}" docker/prod/release.sh

# 5. Turn on automated migrations for every deploy afterwards:
#    uncomment `enable_predeploy_migrations = true` in terraform/main.tf, then
just tf-apply
```

After the first apply, note the assigned `*.ondigitalocean.app` URL and set
`default_uri` in `terraform/main.tf` to it (or set `custom_domain` and let the
module derive it). Without that, CLI- and worker-generated absolute URLs —
password reset links, data-export download links — point at the wrong host.

## Routine deploys

```bash
just deploy
```

That builds the amd64 image, pushes it, and creates an App Platform deployment,
waiting for it to go live. With `enable_predeploy_migrations = true`, migrations
run as a `PRE_DEPLOY` job before the new containers roll.

```bash
just logs-prod        # tail production logs
just shell-prod       # shell into the prod image locally, for build debugging
```

## Migrations

**Never run migrations from the container entrypoint.** `docker/prod/entrypoint.sh`
deliberately does not — with several replicas, per-container migrations race
against the same database. `docker/prod/release.sh` is the one-shot release step,
run either by the `PRE_DEPLOY` job or by hand as in step 4 above.

## Environment variables

The module sets `APP_ENV`, `APP_SECRET`, `APP_ENCRYPTION_KEY`, `DATABASE_URL`,
`MAILER_DSN` and `DEFAULT_URI` — and, when the hub is enabled, `MERCURE_URL`,
`MERCURE_PUBLIC_URL` and `MERCURE_JWT_SECRET`, which it derives itself. The rest
is wired through `extra_env` in `terraform/main.tf`, sourced from the variables
in `terraform/variables.tf` — set them in `terraform.tfvars` or as `TF_VAR_*`.
Each is omitted from the app spec entirely when left empty, so a feature is off
rather than half-configured:

| Variable | Needed for | If unset |
|---|---|---|
| `MAILER_FROM_ADDRESS`, `MAILER_FROM_NAME` | Sender of every transactional email; the address must be on a domain you control and have published SPF/DKIM/DMARC for | Falls back to `noreply@localhost`, which real mail servers reject — and since email verification is mandatory, **your users cannot complete registration** |
| `ADMIN_EMAIL` | Promotes that user to `ROLE_ADMIN` at login — only for an already-verified account, so it cannot rescue a locked-out install; `app:user:promote` can | No admin promotion |
| `INSTALL_TOKEN` | Gates `/install` | **In prod the wizard 404s outright** — it fails closed, so an unset value keeps first-run setup out of a stranger's reach rather than exposing it; recover with `app:admin:create` |
| `mercure_jwt_secret` | Runs the Mercure hub for site-review push (a Terraform variable, not an env var — the module derives the URLs) | Hub not run; review submissions save but never reach a running agent |
| `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` | Billing, checkout, webhooks | Billing paths fail |
| `OAUTH_GOOGLE_ID` / `_SECRET`, `OAUTH_GITHUB_ID` / `_SECRET` | Social login | Those buttons fail |
| `MCP_ALLOWED_HOSTS` | DNS-rebinding allowlist for `/mcp` | The MCP endpoint rejects your real hostname |
| `APP_SOURCE_URL` | The AGPL source offer: where *this instance's* source can be obtained, linked in the footer of every page | The one entry here that is not simply "off": the committed default applies, pointing at the upstream repository. That is the truth for an unmodified instance and a false statement for a modified one — **if you change the code, set this to your own repository** |
| `EXPORT_STORAGE` | Where data-export archives live: `local` or `s3`. Terraform sets it to `s3` | **Every export download 404s** on `local`: the worker writes the archive, the web container serves it, and they share no volume |
| `EXPORT_STORAGE_BUCKET`, `_ENDPOINT`, `_REGION`, `_KEY`, `_SECRET` | The bucket and its credentials. Terraform fills all five from the Spaces bucket it creates; they are yours to set only when `create_export_bucket = false` | Exports fail at upload |
| `EXPORT_STORAGE_PREFIX` | Key prefix inside the bucket | Archives sit at the bucket root |
| `EXPORT_STORAGE_USE_PATH_STYLE` | `true` for MinIO and most non-AWS providers | Virtual-hosted addressing (`https://bucket.host/key`) |
| `EXPORT_STORAGE_ACL` | `bucket-owner-full-control` on AWS S3 — see "Known gaps" | `private`, which MinIO and Spaces require and a default AWS bucket rejects |
| `SITE_REVIEW_WIDGET_TOKEN` | Only for dogfooding the widget on Loupe's own pages | Widget not loaded |

`INSTALL_TOKEN` is the one to set **before** the first deploy: the wizard is how
you create the first administrator, and in production it is unreachable without
the token. If you forget it, see "Recovering an instance" — you are not locked
out, but the fix is a shell rather than a browser.

## Recovering an instance

Three console commands are the supported way back into an instance with no
reachable administrator. Run them in the web container (`docker exec`, or the
platform's console). All three are idempotent: running one against an account
that is already in the desired state prints "already …" and exits 0.

| Command | What it does |
|---|---|
| `app:admin:create <email>` | Ensures the email is a **verified administrator**, creating the account if it does not exist. Options: `--username`, `--full-name`, `--password`. With no `--password` it prompts (or, non-interactively, generates one and prints it once). An existing account is promoted and verified in place and **keeps its password**. |
| `app:user:promote <email>` | Grants `ROLE_ADMIN` to an existing account, keeping any other roles. |
| `app:user:verify <email>` | Marks the account's email verified and burns any outstanding verification token — including on an account that was already verified, since that link logs its bearer straight in. The escape hatch when outbound mail never arrives: an unverified account is parked on the check-email page and cannot reach the admin area. |

Non-interactive first admin on a fresh instance:

```bash
docker exec <web-container> bin/console app:admin:create you@example.com
# → Created administrator you@example.com (username: you)
# → Generated password (shown once): …
```

Sign-up itself is closed until the instance is installed: `/register` (and the
OAuth sign-up branch, and `/waitlist`) refuse to create the **first** account,
so a forgotten `INSTALL_TOKEN` cannot leave the instance to whoever finds it
first. Registration is additionally gated on the `registration.enabled` feature
flag, which the wizard seeds and the admin area can toggle at any time; when the
flag row is absent — an instance upgraded from a version that never seeded it,
or one recovered entirely from the shell — registration stays open.

A shell-recovered instance has no feature-flag rows at all, and that is fine:
every install flag falls back to exactly the value the wizard would have
written (registration on, no cap, billing and social login off, a 14-day
trial), so the instance behaves like a wizard-installed one with the defaults
accepted. To change any of them, visit the admin **Feature flags** page — its
scan view lists every flag the code references but the database does not
define, and creates the rows on request.

## Post-deploy checks

1. `GET /healthz` returns 200 and `{"status":"ok"}` — the health check path.
   A 503 means the container is serving but cannot reach the database.
2. `POST /mcp` with no credentials returns **401, not 404**. A 404 means the
   route did not register; a 401 means it registered and the firewall rejected
   you.
3. `bin/console doctrine:migrations:status` reports no pending migrations.
4. Open **`/admin/status`**. It reports, for this instance, whether the mail
   transport accepts a connection, whether the sender address is still the
   undeliverable default, whether the message queue is being drained, whether
   the Mercure hub answers, and — when billing is on — whether the Stripe keys
   are set. The install wizard shows the same page before it creates your
   administrator, so a broken mailer is visible *before* it can lock you out.

   The worker check is deliberately honest about its limits: a running worker
   leaves no lasting trace, so an empty queue is reported as **unknown**, not
   as healthy. What it can prove is the failure — messages sitting available
   and unclaimed for over a minute mean nothing is consuming them.
5. Trigger something that queues async work (a data export) and confirm it
   completes — that proves the worker is actually consuming, which step 4
   cannot. **Then follow the download link in the email and check you get a
   ZIP**: completion only proves the worker ran, while the download is what
   proves the web container can reach the archive the worker wrote.

## Failed messages

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

## Known gaps

1. **Set `install_token` before the first deploy.** Since the wizard fails closed
   in production, an unset value means `/install` returns 404 and the browser
   has no route to the first administrator. Recoverable from a shell — see
   "Recovering an instance" — but the wizard is the pleasant path.

2. **The export bucket is created for you, but pick its region.** The web and
   worker containers have separate ephemeral filesystems, so the application
   default of `EXPORT_STORAGE=local` cannot work here: the worker would write an
   archive the web container cannot see, and every download would 404.
   `terraform/spaces.tf` therefore creates a private Spaces bucket and a
   bucket-scoped access key, and `main.tf` wires them into
   `EXPORT_STORAGE_BUCKET` / `_ENDPOINT` / `_REGION` / `_KEY` / `_SECRET`. All
   you supply is `export_bucket_region` — a Spaces datacenter slug like `tor1`,
   which is **not** the App Platform slug (`tor`) and cannot be derived from it.

   The bucket is `private`, is never destroyed while it holds objects, and has a
   30-day lifecycle rule as a backstop against archives that outlive their
   database row. Download links expire after 48 hours and the app deletes the
   archive then, so that rule can never reach a live one.

   **Bringing your own bucket instead**: set `create_export_bucket = false` and
   fill in the `export_storage_*` variables. AWS S3, MinIO and Cloudflare R2 all
   work — the application only ever sees generic S3 settings, and nothing about
   it is DigitalOcean-specific.

   **On AWS S3 itself, also set `export_storage_acl = "bucket-owner-full-control"`.**
   The Flysystem S3 adapter always sends a canned ACL and offers no way to send
   none, and no single value is accepted everywhere: buckets created since 2023
   default to "Bucket owner enforced", which rejects everything except
   `bucket-owner-full-control` with a 400 `AccessControlListNotSupported`, while
   MinIO and DigitalOcean Spaces accept only the app's default, `private`. Get
   this wrong and every export upload fails inside the worker.

   Nothing else in the app writes files, so this is the only place object
   storage is needed.

3. **Set `mercure_jwt_secret` if you want site-review push.** Setting it runs a
   Mercure hub as a second service in this app (module v1.6.0's `enable_mercure`)
   and routes `/.well-known/mercure` on the app's own domain to it; the module
   injects `MERCURE_URL`, `MERCURE_PUBLIC_URL` and `MERCURE_JWT_SECRET` itself.
   Leaving it empty keeps push off — review submissions still save, but never
   reach the bridge CLI, and the publish failure is only logged, so it degrades
   silently rather than erroring.

   The hub is in-memory: a restart drops undelivered updates. That is why
   submissions are recorded in the `site_review_events` outbox and the bridge
   resumes from `Last-Event-ID` — delivery is best effort, replay is not.

4. **Nothing here has been applied against a live account.** `terraform validate`
   passes and `plan` evaluates the full configuration up to the first API call,
   but no deploy has run. Specifically unobserved: the Mercure component, which
   is reasoned from the `dunglas/mercure` image's documented interface and the
   dev compose service; the Spaces bucket and key, whose `readwrite` grant is
   taken from DigitalOcean's documentation rather than from a completed
   upload-download-delete cycle; and the single-host Compose stack below, which
   has been validated as configuration but never started.

## Single-host Docker Compose

`compose.prod.yaml` runs the whole application on one host with no cloud account
of any kind. It is the same production image, run as four services: `web`
(nginx + php-fpm + the hourly export purge), `worker` (the messenger consumer),
`database` (Postgres) and `mercure` (the hub).

```bash
cp compose.prod.env.example compose.prod.env      # then fill it in
docker compose -f compose.prod.yaml --env-file compose.prod.env up -d

# Once per deploy, never from a container's entrypoint:
docker compose -f compose.prod.yaml --env-file compose.prod.env \
    run --rm web docker/prod/release.sh
```

`--env-file` is not optional. Without it Compose reads the repository's `.env`,
which is the development configuration. Every setting with no safe default is
guarded, so a forgotten flag aborts the command instead of starting a
misconfigured instance.

What you still have to provide:

- **A reverse proxy.** Both published ports bind to loopback. Terminate TLS in
  front, forward `X-Forwarded-Proto` and `X-Forwarded-For`, and set
  `TRUSTED_PROXIES` if that proxy reaches the app from a public address — until
  you do, Symfony ignores those headers, generated URLs get the wrong scheme, and
  every visitor is rate-limited as a single IP.
- **An SMTP server** for `MAILER_DSN`. Email verification is mandatory, so
  registration does not work without one.
- **A hostname for the hub.** `MERCURE_PUBLIC_URL` is a separate host that the
  bridge CLI subscribes to directly; route it to the `mercure` service.
- **Backups** of the `database_data` and `exports` volumes.

Unlike App Platform, this topology *can* share a filesystem, so
`EXPORT_STORAGE` stays at `local` and both containers mount the same `exports`
volume. That is also why the worker runs its consumer as `www-data` rather than
root: archives are written `0600`, and a root-written archive would be
unreadable to the web container's php-fpm workers.

## Rolling back

App Platform keeps previous deployments. Roll back through the DigitalOcean
console, or re-push a known-good image tag and deploy again. Note that the
default `image_tag` is a fixed `prod` — there is no per-release tag, so "the
previous image" is only recoverable through App Platform's own deployment
history. Building with `LOUPE_PROD_IMAGE=<registry>/loupe:$(git rev-parse --short HEAD)`
and setting `image_tag` to match would make rollback a one-command operation.
