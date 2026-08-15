# Deploys this app to DigitalOcean App Platform via the shared module.
# Set app_name and provide the secrets via TF_VAR_* (see variables.tf and
# terraform.tfvars.example).
# The DB name/user default off app_name.
locals {
  app_name = "loupe"

  # Where the app reads data-export archives from. When Terraform creates the
  # Spaces bucket these come from the resources in spaces.tf; otherwise they fall
  # through to the export_storage_* variables, which is how a bring-your-own AWS
  # S3, MinIO or R2 bucket is configured. Either way the app only ever sees
  # generic S3 settings.
  #
  # The bucket resource exports `endpoint` as a bare hostname
  # (tor1.digitaloceanspaces.com); EXPORT_STORAGE_ENDPOINT is a URL.
  export_storage_bucket   = var.create_export_bucket ? digitalocean_spaces_bucket.exports[0].name : var.export_storage_bucket
  export_storage_region   = var.create_export_bucket ? digitalocean_spaces_bucket.exports[0].region : var.export_storage_region
  export_storage_endpoint = var.create_export_bucket ? "https://${digitalocean_spaces_bucket.exports[0].endpoint}" : var.export_storage_endpoint
  export_storage_key      = var.create_export_bucket ? digitalocean_spaces_key.exports[0].access_key : var.export_storage_key
  export_storage_secret   = var.create_export_bucket ? digitalocean_spaces_key.exports[0].secret_key : var.export_storage_secret
}

module "app" {
  source = "git::https://github.com/ubermuda/terraform-digitalocean-symfony-app.git//?ref=v1.7.0"

  app_name = local.app_name

  # Placement and the database cluster. Passed explicitly rather than left to the
  # module's defaults, which name one specific pre-existing cluster in one
  # specific region — inheriting them makes `terraform apply` fail for anyone
  # else. The module creates a database and user ON this cluster; it does not
  # create the cluster.
  region                  = var.region
  db_cluster_name         = var.db_cluster_name
  database_server_version = var.db_server_version

  # Either attach to a cluster the operator already runs, or have the module
  # create a dedicated one. The sizing arguments are inert in attach mode.
  create_db_cluster     = var.create_db_cluster
  db_cluster_region     = var.db_cluster_region
  db_cluster_size       = var.db_cluster_size
  db_cluster_node_count = var.db_cluster_node_count

  # Dedicated mode only, and authoritative: the module declares the cluster's
  # whole trusted-source list, so a rule appended with `doctl` is dropped on the
  # next apply. The one-time schema GRANT runs from a workstation, which must
  # therefore be listed here for that step and removed again afterwards.
  db_cluster_trusted_ips = var.db_cluster_trusted_ips

  # Image coordinates. These must agree with `prod_image` in the justfile, which
  # builds and pushes what App Platform pulls here.
  registry_type    = var.registry_type
  registry         = var.registry
  image_repository = var.image_repository
  image_tag        = var.image_tag

  # Secrets (injected via TF_VAR_*, never committed).
  registry_credentials = var.registry_credentials
  app_secret           = var.app_secret
  app_encryption_key   = var.app_encryption_key
  mailer_dsn           = var.mailer_dsn

  # Overrides (defaults shown):
  # db_name          = "loupe"   # defaults to app_name (- -> _)
  # db_user          = "loupe"   # defaults to db_name

  # Optional custom domain, supplied per-account like the placement above rather
  # than hardcoded: domain_zone must name a DNS zone the deploying account's own
  # DigitalOcean DNS serves, so a literal here would fail at apply for everyone
  # but us.
  #
  # default_uri is what absolute URLs generated outside a request are built from
  # — password-reset mails, export download links — so leaving it wrong points
  # them at the wrong host with nothing to signal it. It derives from
  # custom_domain when that is set; set it explicitly only when serving on the
  # assigned *.ondigitalocean.app hostname, which is not known until after the
  # first apply.
  custom_domain = var.custom_domain
  domain_zone   = var.domain_zone
  default_uri   = var.default_uri

  # First deploy: keep off, run `just tf-db-bootstrap`, then flip on for
  # automated migrations.
  # enable_predeploy_migrations = true

  # Background worker, from the same image as the web service. Without it
  # nothing consumes the async or scheduler transports: queued mail is never
  # delivered, data exports never build, the trial-end sweep never runs, and the
  # site-review outbox never drains.
  #
  # scheduler_default is listed first deliberately — a deep async backlog must
  # not delay schedule ticks. --time-limit recycles the process hourly and
  # --memory-limit guards against a leak in long-lived consumers.
  enable_worker  = true
  worker_command = "php bin/console messenger:consume scheduler_default async --time-limit=3600 --memory-limit=128M"

  # Mercure hub for site-review push, run as a second service in this app.
  # Gated on the secret rather than hardcoded true, so the feature is cleanly
  # off until it is configured — the same shape as every extra_env entry below.
  # Without it, review submissions still save but never reach the bridge CLI,
  # and the publish failure is only logged.
  #
  # MERCURE_URL, MERCURE_PUBLIC_URL and MERCURE_JWT_SECRET are injected by the
  # module; setting them here as well would duplicate the env keys.
  #
  # The hub image is pinned to a minor version — the module would otherwise
  # track `latest`, so two applies weeks apart would silently run different hub
  # builds. It is the same version docker/compose/prod.yaml runs.
  enable_mercure     = var.mercure_jwt_secret != ""
  mercure_jwt_secret = var.mercure_jwt_secret
  mercure_image_tag  = "v0.24"

  # Everything the module does not set itself. Values come from variables.tf so
  # secrets stay out of this file; see terraform.tfvars.example.
  extra_env = merge(
    var.admin_email == "" ? {} : { ADMIN_EMAIL = { value = var.admin_email } },
    var.mcp_allowed_hosts == "" ? {} : { MCP_ALLOWED_HOSTS = { value = var.mcp_allowed_hosts } },
    var.mailer_from_address == "" ? {} : { MAILER_FROM_ADDRESS = { value = var.mailer_from_address } },
    var.mailer_from_name == "" ? {} : { MAILER_FROM_NAME = { value = var.mailer_from_name } },
    var.install_token == "" ? {} : { INSTALL_TOKEN = { value = var.install_token, type = "SECRET" } },

    # The AGPL source offer. Conditional like the rest, but for a different
    # reason: everywhere else an empty value and an absent key mean the same
    # thing, a feature left off. Here an absent key leaves the image's own
    # default in place — a link to the upstream repository — while an emitted
    # empty one removes the footer link altogether. So empty must mean "this
    # instance has nothing to add", never "make no offer at all".
    var.app_source_url == "" ? {} : { APP_SOURCE_URL = { value = var.app_source_url } },

    # Data-export archives. Unlike every other entry here, EXPORT_STORAGE is
    # always set: the app's own default is `local`, which cannot work on this
    # topology (separate web and worker containers, no shared volume), so
    # omitting it would silently 404 every export download.
    #
    # The values below come from the Spaces bucket in spaces.tf, or from the
    # export_storage_* variables when create_export_bucket is false. They are
    # ordinary S3 settings either way — nothing here is DigitalOcean-specific
    # from the application's point of view.
    { EXPORT_STORAGE = { value = var.export_storage } },
    local.export_storage_bucket == "" ? {} : { EXPORT_STORAGE_BUCKET = { value = local.export_storage_bucket } },
    var.export_storage_prefix == "" ? {} : { EXPORT_STORAGE_PREFIX = { value = var.export_storage_prefix } },
    local.export_storage_region == "" ? {} : { EXPORT_STORAGE_REGION = { value = local.export_storage_region } },
    local.export_storage_endpoint == "" ? {} : { EXPORT_STORAGE_ENDPOINT = { value = local.export_storage_endpoint } },
    local.export_storage_key == "" ? {} : { EXPORT_STORAGE_KEY = { value = local.export_storage_key, type = "SECRET" } },
    local.export_storage_secret == "" ? {} : { EXPORT_STORAGE_SECRET = { value = local.export_storage_secret, type = "SECRET" } },
    var.export_storage_use_path_style == "" ? {} : { EXPORT_STORAGE_USE_PATH_STYLE = { value = var.export_storage_use_path_style } },
    var.export_storage_acl == "" ? {} : { EXPORT_STORAGE_ACL = { value = var.export_storage_acl } },

    var.stripe_secret_key == "" ? {} : { STRIPE_SECRET_KEY = { value = var.stripe_secret_key, type = "SECRET" } },
    var.stripe_webhook_secret == "" ? {} : { STRIPE_WEBHOOK_SECRET = { value = var.stripe_webhook_secret, type = "SECRET" } },

    var.oauth_google_id == "" ? {} : { OAUTH_GOOGLE_ID = { value = var.oauth_google_id } },
    var.oauth_google_secret == "" ? {} : { OAUTH_GOOGLE_SECRET = { value = var.oauth_google_secret, type = "SECRET" } },
    var.oauth_github_id == "" ? {} : { OAUTH_GITHUB_ID = { value = var.oauth_github_id } },
    var.oauth_github_secret == "" ? {} : { OAUTH_GITHUB_SECRET = { value = var.oauth_github_secret, type = "SECRET" } },
  )
}
