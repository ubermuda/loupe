# Deploying Loupe

This is the deployment guide: what Loupe needs to run, every environment
variable it reads, the two topologies that ship with the project, and how to
get back into an instance you are locked out of. The README points here and
keeps no copy of any of it.

Loupe is a container plus a Postgres database, so it runs anywhere that can host
those. Nothing in the application assumes a particular provider.

- **"What runs in production"**, **"Environment"**, **"Secrets"** and
  **"Migrations"** describe the application's own requirements. They hold
  wherever you run it.
- **"Single-host Docker Compose"** is the whole application on one machine, with
  no cloud account of any kind.
- **"DigitalOcean App Platform"** is the deployment this project itself ships
  by default, with infrastructure in `terraform/`. Everything genuinely
  provider-specific — the Terraform root, the `just tf-*` recipes, the App
  Platform component model — lives in that section and is scaffolding you can
  replace.
- **"First run"**, **"Recovering an instance"** and **"Operating an instance"**
  apply to both.

There is **no CI/CD pipeline** — no `.github/workflows`. Deploys are run by hand
from a workstation with `just`.

> **Read "Known gaps" before your first real deploy.** Several things the
> application needs are not configured on your behalf, and two of them — the
> install token and the export bucket's canned ACL — leave a feature broken
> rather than merely off. The bucket itself is created for you on the shipped
> App Platform path, and Terraform refuses to apply until you pick its region,
> so that part fails loudly; `EXPORT_STORAGE_ACL` does not.

## What runs in production

Loupe runs as **two processes from the same image**, plus Postgres and,
optionally, a Mercure hub.

| Process | What it is |
|---|---|
| **Web** | `docker/prod/Dockerfile`, running supervisord as PID 1: `php-fpm` + `nginx`, and nothing else. No background process runs here. Listens on port 80. |
| **Worker** | The *same image*, started with a different command. Deliberately **not** a supervisord program inside the web container, so worker restarts never recycle php-fpm and nginx. It consumes `scheduler_default` first, then `async` — a deep async backlog must not delay schedule ticks. It is also the only thing that runs scheduled work: **everything recurring rides its schedule**, including the hourly `app:purge-expired-exports`, so without a worker expired archives are never purged and nothing periodic happens at all. |
| **Postgres** | Any Postgres the app can reach. It also carries the message queue: `MESSENGER_TRANSPORT_DSN` defaults to `doctrine://default?auto_setup=0`, so there is no broker to run. |
| **Object storage** | Only needed when `EXPORT_STORAGE=s3`. Any S3-compatible bucket; required whenever the web and worker processes do not share a filesystem, or data-export downloads 404. |
| **Mercure hub** | Only needed for site-review push. Optional, and off until `MERCURE_JWT_SECRET` is set. In-memory, so delivery is best effort — see "Known gaps". |

### The worker is not optional

Nothing consumes the queues unless you run it, and nothing warns you: queued
mail is never delivered, data exports never build, expired export archives are
never purged, the trial-end sweep never runs, and the site-review outbox never
drains. Every one of those fails silently — the request that queued the work
still returns 200.

```
php bin/console messenger:consume scheduler_default async --time-limit=3600 --memory-limit=128M
```

`scheduler_default` is listed **before** `async` deliberately: a deep async
backlog must not delay schedule ticks. `--time-limit` recycles the process
hourly and `--memory-limit` guards against a leak in a long-lived consumer.

Both shipped topologies run exactly that command — `worker_command` in
`terraform/main.tf`, the `worker` service in `compose.prod.yaml`. If you deploy
some other way, this is the piece it is easiest to forget.

## Environment

Everything Loupe reads is documented inline in `.env`, which is also where the
committed defaults live. Below is what a production instance must decide for
itself. **Anything you leave unset falls back to the committed default in
`.env`** — which is usually a development value.

Where a variable is set differs by topology: in `compose.prod.env` for the
single-host stack, in Terraform variables for App Platform. The last column
answers one question — **must you add this yourself, because no template has a
slot for it?** "No" means both templates cover it.

### Always

| Variable | Purpose | Add by hand? |
|---|---|---|
| `APP_ENV` | Must be `prod`. | No |
| `APP_SECRET` | Symfony secret. Generate once — see "Secrets". | No |
| `DATABASE_URL` | Postgres DSN. `serverVersion` must match the real cluster: understating it is safe, overstating it can break queries. | No |
| `DEFAULT_URI` | **The instance's public URL, scheme included.** The single host-shaped setting the app has. It builds absolute links in non-HTTP contexts (console commands, the worker), pins the host of links in security-sensitive email so a forged `Host` header cannot redirect them, and is the base of the Mercure topics the bridge CLI subscribes to. Get it wrong and password-reset and export-download emails point somewhere nobody can act on. | No |
| `MCP_ALLOWED_HOSTS` | Comma-separated DNS-rebinding allowlist for `/mcp`, **hostnames only, no port**. It must contain the hostname agents actually use, or every MCP call is rejected with a 403 — one that names this variable and echoes the host it rejected, so the failure is self-explaining. | No |
| `TRUSTED_PROXIES` | The reverse proxy in front of the app, as IPs or CIDR ranges. **Empty falls back to `PRIVATE_SUBNETS`**, which covers Docker and any balancer on a private network. Set it when your balancer reaches the app from a public address: until you do, `X-Forwarded-Proto` and `X-Forwarded-Host` are ignored (generated URLs get the wrong scheme and host) and every visitor shares the balancer's IP, so the per-IP registration and password-reset limiters throttle all your users collectively. | **Yes on App Platform** — `compose.prod.env.example` has a slot, Terraform has none |
| `APP_SOURCE_URL` | Where *this instance's* source can be obtained, rendered as a footer link on every page. A default ships in `.env` pointing at upstream, which is correct for an unmodified instance and wrong for a modified one. **If you change the code, the AGPL requires you to point this at your repository.** | No |

### Mail

Email verification is **mandatory**, so nobody can register until mail works.

| Variable | Purpose | Add by hand? |
|---|---|---|
| `MAILER_DSN` | Outbound transport. | No |
| `MAILER_FROM_ADDRESS` | Sender of every transactional email — verification, password reset, waitlist invite, data export, account deletion. Must be on a domain you control and have published SPF/DKIM/DMARC for. **Falls back to `noreply@localhost`, which real mail servers reject**, so registration breaks. | No |
| `MAILER_FROM_NAME` | Display name beside the address. Defaults to `Loupe`. | No |

### Site-review push (Mercure)

Optional. Without it, review submissions still save but never reach a running
agent, and the publish failure is only logged — it degrades silently.

| Variable | Purpose | Add by hand? |
|---|---|---|
| `MERCURE_JWT_SECRET` | Shared HS256 key, minimum 32 characters, **identical for the app and the hub**. No default ships: if unset, Mercure fails loudly rather than signing with a publicly-known key. | No |
| `MERCURE_URL` | Where the app POSTs updates — the hub on the internal network. | No |
| `MERCURE_PUBLIC_URL` | Where clients subscribe. A genuinely separate host (the bridge CLI reaches it directly), so it cannot be derived from `DEFAULT_URI`. | No |

### Install and first administrator

| Variable | Purpose | Add by hand? |
|---|---|---|
| `INSTALL_TOKEN` | Gates `/install`. **Set this before the first deploy** — see "First run". | No |
| `ADMIN_EMAIL` | Promotes that user to `ROLE_ADMIN` at login. Only works on an already-verified account, so it cannot rescue a locked-out install; `app:user:promote` can. | No |

### Data exports

`EXPORT_STORAGE` is `local` by default, which writes archives under
`var/exports/`. **That is only correct when the process that generates an export
and the process that serves its download share a filesystem.** The single-host
stack does share one; separate web and worker containers do not, and there
`local` means the worker writes an archive the web container cannot see and
every download 404s.

| Variable | Purpose |
|---|---|
| `EXPORT_STORAGE` | `local` or `s3`. |
| `EXPORT_STORAGE_BUCKET` | Required when `s3`. |
| `EXPORT_STORAGE_PREFIX` | Key prefix. Empty stores archives at the bucket root, which is what a dedicated bucket wants. |
| `EXPORT_STORAGE_REGION` | Empty falls back to AWS's default, `us-east-1`. |
| `EXPORT_STORAGE_ENDPOINT` | Set for any non-AWS provider, e.g. `https://tor1.digitaloceanspaces.com`. Empty targets AWS S3 itself. |
| `EXPORT_STORAGE_KEY` / `_SECRET` | Empty falls back to the ambient AWS credential chain, which only helps when running on AWS with an attached role. |
| `EXPORT_STORAGE_USE_PATH_STYLE` | `true` for MinIO and most non-AWS providers, which address buckets as `https://host/bucket/key` rather than `https://bucket.host/key`. |
| `EXPORT_STORAGE_ACL` | Canned ACL sent with every upload. **No single value works everywhere** — see "Known gaps". |

AWS S3, MinIO, Cloudflare R2 and DigitalOcean Spaces all work; the application
only ever sees generic S3 settings. Nothing else in the app writes files, so
this is the only place object storage is needed.

#### Keeping archives private on a bring-your-own bucket

Export archives are personal data, and the application never exposes the bucket
to a browser. It writes every object with a private ACL and private Flysystem
visibility, and it generates **no public and no presigned URL** — the download
route streams the bytes itself, behind a link that requires the authenticated
owner plus a SHA-256 token, expires 48 hours after the export completes, and
answers 404 on any mismatch.

On **an AWS S3 bucket created since 2023, that private object ACL is a no-op**.
Those buckets default to "Bucket owner enforced" ownership, under which S3
ignores object ACLs entirely and access is governed solely by Block Public
Access and the bucket policy. It is the same setting that forces
`EXPORT_STORAGE_ACL=bucket-owner-full-control`, so it applies to exactly the
buckets that need the override. **Enable Block Public Access and grant no
anonymous read in the bucket policy** — on those buckets the application cannot
do it for you.

This is already handled when `create_export_bucket = true`: `terraform/spaces.tf`
creates the bucket `private` with a key scoped to it alone, and Spaces honours
that bucket ACL. Nothing to do on the shipped DigitalOcean path.

### Optional features

| Variable | Purpose | Add by hand? |
|---|---|---|
| `APP_ENCRYPTION_KEY` | Only once an `encrypted_string` column is in use. **Losing it makes existing encrypted columns unreadable.** | No |
| `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` | Billing. Nothing instantiates the Stripe client until the `billing.enabled` feature flag is on. | No |
| `OAUTH_GOOGLE_ID` / `_SECRET`, `OAUTH_GITHUB_ID` / `_SECRET` | Social login. A provider becomes reachable only when its credentials **and** its feature flag (`auth.google.enabled` / `auth.github.enabled`) are both set. | No |
| `SITE_REVIEW_WIDGET_TOKEN` | Only for dogfooding the review widget on Loupe's own pages. It appears in page source, so use a dedicated SiteReview-scoped token, never an MCP or production credential. | **Yes, both topologies** |

### What the Terraform root does not set

One variable above has no Terraform variable and no `extra_env` entry in
`terraform/main.tf`: **`SITE_REVIEW_WIDGET_TOKEN`**. The widget is a dogfooding
aid rather than part of the deploy surface, so add it by hand to `extra_env` if
you want it — there is no variable to fill in.

`TRUSTED_PROXIES` likewise has no Terraform variable; on App Platform the
`PRIVATE_SUBNETS` fallback applies unless you add it yourself.

`APP_SOURCE_URL` **is** wired on both topologies — `app_source_url` in
`terraform/variables.tf` feeds an `extra_env` entry, and
`compose.prod.env.example` carries a commented slot. Both deliberately omit the
key entirely when it is empty, rather than passing an empty string: an absent
key leaves the image's committed default in place, while an emitted empty one
would remove the footer link altogether.

Everything else is wired. The shared module injects `APP_ENV`, `APP_SECRET`,
`APP_ENCRYPTION_KEY`, `DATABASE_URL`, `MAILER_DSN` and `DEFAULT_URI` — and, when
the hub is enabled, `MERCURE_URL`, `MERCURE_PUBLIC_URL` and `MERCURE_JWT_SECRET`,
which it derives itself. The rest goes through `extra_env` in
`terraform/main.tf`, sourced from the variables in `terraform/variables.tf`; set
them in `terraform.tfvars` or as `TF_VAR_*`. Each is omitted from the app spec
entirely when left empty, so a feature is off rather than half-configured.

`DEFAULT_URI` is the exception worth watching: the module injects the key, but
*you* supply the value, through `default_uri` or `custom_domain` in
`terraform/main.tf`. Both ship commented out.

## Secrets

Generate once, then keep them somewhere durable.

```bash
# APP_SECRET
openssl rand -hex 16

# APP_ENCRYPTION_KEY — base64-encoded 32-byte libsodium secret-box key
php -r 'echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;'

# MERCURE_JWT_SECRET and INSTALL_TOKEN — any long random string
openssl rand -base64 32
```

**Losing `APP_ENCRYPTION_KEY` makes existing encrypted columns unreadable.**
There is no recovery.

For App Platform, inject them as `TF_VAR_*`:

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

## Migrations

**Never run migrations from the container entrypoint.**
`docker/prod/entrypoint.sh` deliberately does not — with several replicas,
per-container migrations race against the same database.
`docker/prod/release.sh` is the one-shot release step, run once per deploy:

```bash
docker run --rm --env-file <your prod env file> <image> docker/prod/release.sh
```

The two topologies below each have their own way of invoking it.

## Single-host Docker Compose

`compose.prod.yaml` runs the whole application on one host with no cloud account
of any kind. It is the same production image, run as four services: `web`
(nginx + php-fpm), `worker` (the messenger consumer, which also runs everything
on the schedule), `database` (Postgres) and `mercure` (the hub).

```bash
cp compose.prod.env.example compose.prod.env      # then fill it in
docker compose -f compose.prod.yaml --env-file compose.prod.env up -d

# Once per deploy, never from a container's entrypoint:
docker compose -f compose.prod.yaml --env-file compose.prod.env \
    run --rm web docker/prod/release.sh
```

`--env-file` is **not optional**. Without it Compose reads the repository's
`.env`, which is the development configuration. Every setting with no safe
default is guarded, so a forgotten flag aborts the command instead of starting a
misconfigured instance.

What you still have to provide:

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

## DigitalOcean App Platform

The deployment this project ships by default: a container image built locally
and pushed to GHCR, with infrastructure in `terraform/` — a thin root over the
shared module
[`terraform-digitalocean-symfony-app`](https://github.com/ubermuda/terraform-digitalocean-symfony-app),
pinned to a tag in `terraform/main.tf`.

Point the platform's health check at `GET /healthz` (see "Operating an
instance").

### Prerequisites

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

### First deploy

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

### Routine deploys

```bash
just deploy           # build amd64, push, create a deployment, wait for it to go live
just logs-prod        # tail production logs
just shell-prod       # shell into the prod image locally, for build debugging
```

With `enable_predeploy_migrations = true`, migrations run as a `PRE_DEPLOY` job
before the new containers roll.

### Infrastructure it creates

| Resource | What it is |
|---|---|
| **Web + worker services** | Two components from the same image; the worker is `enable_worker` / `worker_command` in `terraform/main.tf`. |
| **Postgres** | A per-app database and user on a managed cluster you already own, named by `db_cluster_name`. Terraform creates the database and the user; it never creates the cluster. |
| **Export bucket** | A DigitalOcean Spaces bucket plus a bucket-scoped access key, created by `terraform/spaces.tf` and wired in as ordinary `EXPORT_STORAGE_*` settings. |
| **Mercure hub** | A second service in the same app, run by the shared module when `mercure_jwt_secret` is set. |

### Rolling back

App Platform keeps previous deployments. Roll back through the DigitalOcean
console, or re-push a known-good image tag and deploy again. Note that the
default `image_tag` is a fixed `prod` — there is no per-release tag, so "the
previous image" is only recoverable through App Platform's own deployment
history. Building with
`LOUPE_PROD_IMAGE=<registry>/loupe:$(git rev-parse --short HEAD)` and setting
`image_tag` to match would make rollback a one-command operation.

## First run

Set `INSTALL_TOKEN` **before** the first deploy. The install wizard at
`/install` is how you create the first administrator, and in production it
**fails closed**: with `APP_ENV=prod` and no token configured it returns 404
outright. A forgotten variable locks the wizard rather than exposing it. Append
the token once as `?token=<value>`; the app remembers it for the rest of that
session.

If you forget it you are not locked out, but the fix is a shell rather than a
browser — see "Recovering an instance".

Sign-up is closed until the instance is installed: `/register`, the OAuth
sign-up branch and `/waitlist` all refuse to create the **first** account, so a
missing `INSTALL_TOKEN` cannot leave a fresh instance to whoever finds it first.
Registration is additionally gated on the `registration.enabled` feature flag,
which the wizard seeds and the admin area can toggle at any time. When the flag
row is absent — an instance upgraded from a version that never seeded it, or one
recovered entirely from the shell — registration stays open, so an existing
instance keeps behaving as it did.

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

A shell-recovered instance has no feature-flag rows at all, and that is fine:
every install flag falls back to exactly the value the wizard would have written
(registration on, no cap, billing and social login off, a 14-day trial), so the
instance behaves like a wizard-installed one with the defaults accepted. To
change any of them, visit the admin **Feature flags** page — its scan view lists
every flag the code references but the database does not define, and creates the
rows on request.

## Operating an instance

### Post-deploy checks

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

### Failed messages

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

### The site-review outbox

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

## Known gaps

1. **Set `INSTALL_TOKEN` before the first deploy.** Since the wizard fails
   closed in production, an unset value means `/install` returns 404 and the
   browser has no route to the first administrator. Recoverable from a shell —
   see "Recovering an instance" — but the wizard is the pleasant path.

2. **On App Platform, `EXPORT_STORAGE` cannot be `local`.** The web and worker
   containers have separate ephemeral filesystems, so the application default
   would have the worker write an archive the web container cannot see, and
   every download would 404. `terraform/spaces.tf` therefore creates a private
   Spaces bucket and a bucket-scoped access key, and `main.tf` wires them into
   `EXPORT_STORAGE_BUCKET` / `_ENDPOINT` / `_REGION` / `_KEY` / `_SECRET`.

   All you supply is `export_bucket_region` — a Spaces datacenter slug like
   `tor1`, which is **not** the App Platform slug (`tor`) and cannot be derived
   from it. The bucket is `private`, is never destroyed while it holds objects,
   and has a 30-day lifecycle rule as a backstop against archives that outlive
   their database row. Download links expire after 48 hours and the app deletes
   the archive then, so that rule can never reach a live one.

   **Bringing your own bucket instead**: set `create_export_bucket = false` and
   fill in the `export_storage_*` variables.

3. **No canned ACL works on every provider, so `EXPORT_STORAGE_ACL` exists.**
   The Flysystem S3 adapter always sends a canned ACL and offers no way to send
   none. Buckets created since 2023 on AWS default to "Bucket owner enforced",
   which rejects everything except `bucket-owner-full-control` with a 400
   `AccessControlListNotSupported`, while MinIO and DigitalOcean Spaces accept
   only the app's default, `private`. Get this wrong and **every export upload
   fails inside the worker**, where nobody is watching.

4. **Set `MERCURE_JWT_SECRET` if you want site-review push.** On App Platform,
   setting it runs a Mercure hub as a second service (module v1.6.0's
   `enable_mercure`) and routes `/.well-known/mercure` on the app's own domain
   to it; the module injects `MERCURE_URL`, `MERCURE_PUBLIC_URL` and
   `MERCURE_JWT_SECRET` itself. Leaving it empty keeps push off — review
   submissions still save, but never reach the bridge CLI, and the publish
   failure is only logged, so it degrades silently rather than erroring.

   The hub is in-memory: a restart drops undelivered updates. That is why
   submissions are recorded in the `site_review_events` outbox and the bridge
   resumes from `Last-Event-ID` — delivery is best effort, replay is not.

5. **Nothing here has been applied against a live account.** `terraform
   validate` passes and `plan` evaluates the full configuration up to the first
   API call, but no deploy has run. Specifically unobserved:

   - the Mercure component, reasoned from the `dunglas/mercure` image's
     documented interface and the dev compose service;
   - the Spaces bucket and key, whose `readwrite` grant is taken from
     DigitalOcean's documentation rather than from a completed
     upload-download-delete cycle;
   - **the S3 export path as a whole — it has never touched a real bucket**;
   - the single-host Compose stack, validated as configuration but never
     started;
   - the production image itself, whose base is pinned by digest in
     `docker/prod/Dockerfile`; no image built from that pin has been deployed.
