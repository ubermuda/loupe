# Deploys this app to DigitalOcean App Platform via the shared module.
# Set app_name and provide the secrets via TF_VAR_* (see variables.tf and
# terraform.tfvars.example).
# The image repo and DB name/user default off app_name.
module "app" {
  source = "git::https://github.com/ubermuda/terraform-digitalocean-symfony-app.git//?ref=v1.5.0"

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

  # Everything the module does not set itself. Values come from variables.tf so
  # secrets stay out of this file; see terraform.tfvars.example.
  extra_env = merge(
    var.admin_email == "" ? {} : { ADMIN_EMAIL = { value = var.admin_email } },
    var.mcp_allowed_hosts == "" ? {} : { MCP_ALLOWED_HOSTS = { value = var.mcp_allowed_hosts } },
    var.install_token == "" ? {} : { INSTALL_TOKEN = { value = var.install_token, type = "SECRET" } },

    # Site-review push. The hub is NOT provisioned by this module — point these
    # at one you host separately, or leave them empty and accept that review
    # submissions never reach the bridge CLI.
    var.mercure_url == "" ? {} : { MERCURE_URL = { value = var.mercure_url } },
    var.mercure_public_url == "" ? {} : { MERCURE_PUBLIC_URL = { value = var.mercure_public_url } },
    var.mercure_jwt_secret == "" ? {} : { MERCURE_JWT_SECRET = { value = var.mercure_jwt_secret, type = "SECRET" } },

    var.stripe_secret_key == "" ? {} : { STRIPE_SECRET_KEY = { value = var.stripe_secret_key, type = "SECRET" } },
    var.stripe_webhook_secret == "" ? {} : { STRIPE_WEBHOOK_SECRET = { value = var.stripe_webhook_secret, type = "SECRET" } },

    var.oauth_google_id == "" ? {} : { OAUTH_GOOGLE_ID = { value = var.oauth_google_id } },
    var.oauth_google_secret == "" ? {} : { OAUTH_GOOGLE_SECRET = { value = var.oauth_google_secret, type = "SECRET" } },
    var.oauth_github_id == "" ? {} : { OAUTH_GITHUB_ID = { value = var.oauth_github_id } },
    var.oauth_github_secret == "" ? {} : { OAUTH_GITHUB_SECRET = { value = var.oauth_github_secret, type = "SECRET" } },
  )
}
