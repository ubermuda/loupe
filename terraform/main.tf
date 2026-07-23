# Deploys this app to DigitalOcean App Platform via the shared module.
# Set app_name and provide the secrets via TF_VAR_* (see variables.tf and
# terraform.tfvars.example).
# The image repo and DB name/user default off app_name.
module "app" {
  source = "git::https://github.com/ubermuda/terraform-digitalocean-symfony-app.git//?ref=v1.4.0"

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

  # Project-specific env vars:
  # extra_env = { ADMIN_EMAIL = { value = "you@example.com" } }
}
