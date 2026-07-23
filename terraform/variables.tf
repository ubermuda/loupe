# Root-level inputs. Everything else is configured in the module block in
# main.tf. These are the secrets — provide via gitignored terraform.tfvars OR
# TF_VAR_* env vars. Never commit real values.

variable "do_token" {
  type        = string
  default     = null
  sensitive   = true
  description = "DigitalOcean API token. Leave null to read DIGITALOCEAN_TOKEN from the environment."
}

variable "registry_credentials" {
  type        = string
  default     = ""
  sensitive   = true
  description = "Pull credential for the private image registry (e.g. \"username:PAT\" for GHCR with read:packages). Empty for DOCR."
}

variable "app_secret" {
  type        = string
  sensitive   = true
  description = "Symfony APP_SECRET. Generate once (openssl rand -hex 16); inject via TF_VAR_app_secret."
}

variable "app_encryption_key" {
  type        = string
  sensitive   = true
  description = "APP_ENCRYPTION_KEY: base64-encoded 32-byte libsodium secret-box key. Inject via TF_VAR_app_encryption_key."
}

variable "mailer_dsn" {
  type        = string
  sensitive   = true
  default     = "null://null"
  description = "Production MAILER_DSN. Defaults to a no-op transport."
}
