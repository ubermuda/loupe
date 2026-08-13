---
title: "DigitalOcean App Platform"
description: "The deployment this project ships by default. DigitalOcean-specific throughout."
---


The deployment this project ships by default: a container image built locally
and pushed to GHCR, with infrastructure in `terraform/` — a thin root over the
shared module
[`terraform-digitalocean-symfony-app`](https://github.com/ubermuda/terraform-digitalocean-symfony-app),
pinned to a tag in `terraform/main.tf`.

Point the platform's health check at `GET /healthz` (see "Operating an
instance").

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
   doctl databases create loupe-db --engine pg --region tor1
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
just deploy           # build amd64, push, create a deployment, wait for it to go live
just logs-prod        # tail production logs
just shell-prod       # shell into the prod image locally, for build debugging
```

With `enable_predeploy_migrations = true`, migrations run as a `PRE_DEPLOY` job
before the new containers roll.

## Infrastructure it creates

| Resource | What it is |
|---|---|
| **Web + worker services** | Two components from the same image; the worker is `enable_worker` / `worker_command` in `terraform/main.tf`. |
| **Postgres** | A per-app database and user on a managed cluster you already own, named by `db_cluster_name`. Terraform creates the database and the user; it never creates the cluster. |
| **Export bucket** | A DigitalOcean Spaces bucket plus a bucket-scoped access key, created by `terraform/spaces.tf` and wired in as ordinary `EXPORT_STORAGE_*` settings. |
| **Mercure hub** | A second service in the same app, run by the shared module when `mercure_jwt_secret` is set. |

## Rolling back

App Platform keeps previous deployments. Roll back through the DigitalOcean
console, or re-push a known-good image tag and deploy again. Note that the
default `image_tag` is a fixed `prod` — there is no per-release tag, so "the
previous image" is only recoverable through App Platform's own deployment
history. Building with
`LOUPE_PROD_IMAGE=<registry>/loupe:$(git rev-parse --short HEAD)` and setting
`image_tag` to match would make rollback a one-command operation.

