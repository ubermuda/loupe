# Deploys this app to DigitalOcean App Platform via the shared module.
# Set app_name and provide the secrets via TF_VAR_* (see variables.tf and
# terraform.tfvars.example).
# The image repo and DB name/user default off app_name.
module "app" {
  source = "git::https://github.com/ubermuda/terraform-digitalocean-symfony-app.git//?ref=v1.6.0"

  app_name = "loupe"

  # Secrets (injected via TF_VAR_*, never committed).
  registry_credentials = var.registry_credentials
  app_secret           = var.app_secret
  app_encryption_key   = var.app_encryption_key
  mailer_dsn           = var.mailer_dsn

  # Overrides (defaults shown):
  # image_repository = "loupe"   # defaults to app_name
  # db_name          = "loupe"   # defaults to app_name (- -> _)
  # db_user          = "loupe"   # defaults to db_name

  # Optional custom domain (this repo has none):
  # custom_domain = "app.example.com"
  # domain_zone   = "example.com"
  # default_uri   = "https://app.example.com"

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
  enable_mercure     = var.mercure_jwt_secret != ""
  mercure_jwt_secret = var.mercure_jwt_secret

  # Everything the module does not set itself. Values come from variables.tf so
  # secrets stay out of this file; see terraform.tfvars.example.
  extra_env = merge(
    var.admin_email == "" ? {} : { ADMIN_EMAIL = { value = var.admin_email } },
    var.mcp_allowed_hosts == "" ? {} : { MCP_ALLOWED_HOSTS = { value = var.mcp_allowed_hosts } },
    var.install_token == "" ? {} : { INSTALL_TOKEN = { value = var.install_token, type = "SECRET" } },

    # Data-export archives. Unlike every other entry here, EXPORT_STORAGE is
    # always set: the app's own default is `local`, which cannot work on this
    # topology (separate web and worker containers, no shared volume), so
    # omitting it would silently 404 every export download.
    { EXPORT_STORAGE = { value = var.export_storage } },
    var.export_storage_bucket == "" ? {} : { EXPORT_STORAGE_BUCKET = { value = var.export_storage_bucket } },
    var.export_storage_prefix == "" ? {} : { EXPORT_STORAGE_PREFIX = { value = var.export_storage_prefix } },
    var.export_storage_region == "" ? {} : { EXPORT_STORAGE_REGION = { value = var.export_storage_region } },
    var.export_storage_endpoint == "" ? {} : { EXPORT_STORAGE_ENDPOINT = { value = var.export_storage_endpoint } },
    var.export_storage_key == "" ? {} : { EXPORT_STORAGE_KEY = { value = var.export_storage_key, type = "SECRET" } },
    var.export_storage_secret == "" ? {} : { EXPORT_STORAGE_SECRET = { value = var.export_storage_secret, type = "SECRET" } },
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
