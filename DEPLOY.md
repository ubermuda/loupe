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

There is **no CI/CD pipeline** — no `.github/workflows`. Deploys are run by hand
from a workstation with `just`.

> **Read "Known gaps" before your first real deploy.** Several things the
> application needs are not configured by `terraform/main.tf` on your behalf,
> and two of them — the install token and the data-export bucket — leave a
> feature broken rather than merely off.

## What runs in production

| Component | What it is |
|---|---|
| **Web container** | `docker/prod/Dockerfile`, running supervisord as PID 1: `php-fpm` + `nginx`, plus an `export-purge` sleep-loop that runs `app:purge-expired-exports` hourly. It purges archives the *worker* wrote, which only works because both address the same export storage — see `EXPORT_STORAGE` below. |
| **Worker container** | The *same image*, started with a different command (`enable_worker` in `terraform/main.tf`). Not part of the web container's supervisord — deliberately, so worker restarts never recycle php-fpm/nginx. It consumes `scheduler_default` first, then `async`: a deep async backlog must not delay schedule ticks. |
| **Postgres** | A per-app database and user on a **shared** App Platform cluster (`tor` region). The module creates them; the cluster already exists. |
| **Mercure hub** | Required for site-review push. A second service in the same app, run by the shared module when `mercure_jwt_secret` is set. In-memory, so delivery is best effort — see "Known gaps". |

The web container listens on port 80. App Platform health-checks `/login`,
because `/` is behind `ROLE_USER` and 302-redirects.

## Prerequisites

1. `doctl`, authenticated against the DigitalOcean account.
2. `terraform`.
3. Docker with `buildx` — App Platform runs **amd64**, so the image must be
   cross-built from an Apple Silicon workstation.
4. A **GHCR pull token**: a GitHub PAT with `read:packages`, supplied to
   Terraform as `"username:PAT"`. App Platform needs it to pull the private
   image.
5. Push access to `ghcr.io/ubermuda/loupe`.

## Secrets

Generate once, then keep them somewhere durable — losing `APP_ENCRYPTION_KEY`
makes existing encrypted columns unreadable.

```bash
export TF_VAR_app_secret=$(openssl rand -hex 16)
export TF_VAR_app_encryption_key=$(php -r 'echo base64_encode(sodium_crypto_secretbox_keygen());')
export TF_VAR_registry_credentials="<github-username>:<ghcr-pat>"
export TF_VAR_mailer_dsn="<production mailer DSN>"
export DIGITALOCEAN_TOKEN="<do-token>"
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
# 1. Build and push the amd64 image.
just build-prod
docker push ghcr.io/ubermuda/loupe:prod

# 2. Create the infrastructure. Leave enable_predeploy_migrations OFF for now —
#    the migration job cannot reach the database until step 3.
just tf-init
just tf-apply

# 3. One-time database bootstrap: add this app plus your IP to the cluster's
#    trusted sources, and GRANT schema privileges to the app's user.
just tf-db-bootstrap

# 4. Run migrations once, by hand.
docker run --rm --env-file <prod env file> ghcr.io/ubermuda/loupe:prod docker/prod/release.sh

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
| `ADMIN_EMAIL` | Promotes that user to `ROLE_ADMIN` | No admin promotion |
| `INSTALL_TOKEN` | Gates `/install` | **In prod the wizard 404s outright** — it fails closed, so an unset value locks you out of first-run setup rather than exposing it |
| `mercure_jwt_secret` | Runs the Mercure hub for site-review push (a Terraform variable, not an env var — the module derives the URLs) | Hub not run; review submissions save but never reach a running agent |
| `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` | Billing, checkout, webhooks | Billing paths fail |
| `OAUTH_GOOGLE_ID` / `_SECRET`, `OAUTH_GITHUB_ID` / `_SECRET` | Social login | Those buttons fail |
| `MCP_ALLOWED_HOSTS` | DNS-rebinding allowlist for `/mcp` | The MCP endpoint rejects your real hostname |
| `EXPORT_STORAGE` | Where data-export archives live: `local` or `s3`. Terraform sets it to `s3` by default — see "Known gaps" | **Every export download 404s** on `local`: the worker writes the archive, the web container serves it, and they share no volume |
| `EXPORT_STORAGE_BUCKET` | The bucket, when `EXPORT_STORAGE=s3` | Exports fail at upload |
| `EXPORT_STORAGE_ENDPOINT`, `_REGION` | Any S3-compatible provider (MinIO, R2, Spaces). Empty targets AWS S3 | AWS S3 in `us-east-1` |
| `EXPORT_STORAGE_KEY`, `_SECRET` | Bucket credentials | The ambient AWS credential chain (an instance role) is used — correct on AWS, nothing anywhere else |
| `EXPORT_STORAGE_PREFIX` | Key prefix inside the bucket | Archives sit at the bucket root |
| `EXPORT_STORAGE_USE_PATH_STYLE` | `true` for MinIO and most non-AWS providers | Virtual-hosted addressing (`https://bucket.host/key`) |
| `SITE_REVIEW_WIDGET_TOKEN` | Only for dogfooding the widget on Loupe's own pages | Widget not loaded |

`INSTALL_TOKEN` is the one to set **before** the first deploy: the wizard is how
you create the first administrator, and in production it is unreachable without
the token.

## Post-deploy checks

1. `GET /login` returns 200 — the health check path.
2. `POST /mcp` with no credentials returns **401, not 404**. A 404 means the
   route did not register; a 401 means it registered and the firewall rejected
   you.
3. `bin/console doctrine:migrations:status` reports no pending migrations.
4. Trigger something that queues async work (a data export) and confirm it
   completes — that proves the worker is actually consuming. **Then follow the
   download link in the email and check you get a ZIP**: completion only proves
   the worker ran, while the download is what proves the web container can
   reach the archive the worker wrote.

## Known gaps

1. **Set `install_token` before the first deploy.** Since the wizard fails closed
   in production, an unset value means `/install` returns 404 and there is no way
   to create the first administrator.

2. **Data exports need a bucket.** The web and worker containers have separate
   ephemeral filesystems, so the application default of `EXPORT_STORAGE=local`
   cannot work here: the worker would write an archive the web container cannot
   see, every download would 404, and the hourly `export-purge` loop would
   delete rows whose archives it cannot reach. `terraform/main.tf` therefore
   always sets `EXPORT_STORAGE`, defaulting it to `s3` — but a bucket is not
   created for you. Set `export_storage_bucket` plus its credentials
   (`terraform.tfvars.example` has the block) before you rely on exports. Any
   S3-compatible provider works; DigitalOcean Spaces sits in the same account
   as the rest of this deployment.

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
   passes and `plan` evaluates the full configuration, but no deploy has run. The
   Mercure component in particular is reasoned from the `dunglas/mercure` image's
   documented interface and the dev compose service, not observed working.

## Rolling back

App Platform keeps previous deployments. Roll back through the DigitalOcean
console, or re-push a known-good image tag and deploy again. Note that
`prod_image` in the `justfile` is a fixed `:prod` tag — there is no per-release
tag, so "the previous image" is only recoverable through App Platform's own
deployment history. Tagging releases by commit SHA would make rollback a
one-command operation.
