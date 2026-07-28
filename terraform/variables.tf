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

# --- Application configuration the module does not set itself ---
# These are wired into the module's extra_env in main.tf. Secrets are marked
# sensitive, but note Terraform state stores them in plaintext regardless — use
# an encrypted remote backend (see versions.tf).

variable "admin_email" {
  type        = string
  default     = ""
  description = "ADMIN_EMAIL. The user with this address is promoted to ROLE_ADMIN. Empty disables promotion."
}

variable "install_token" {
  type        = string
  sensitive   = true
  default     = ""
  description = "INSTALL_TOKEN gating /install. REQUIRED in production: the wizard fails closed there, so an empty value makes /install 404 and you cannot create the first administrator. Append it once as ?token=<value>."
}

variable "mcp_allowed_hosts" {
  type        = string
  default     = ""
  description = "MCP_ALLOWED_HOSTS: comma-separated DNS-rebinding allowlist for /mcp. Must include the app's real hostname or every MCP call is rejected."
}

variable "export_storage" {
  type        = string
  default     = "s3"
  description = "EXPORT_STORAGE: where data-export archives are stored, `local` or `s3`. Defaults to s3 here because this deployment runs the web and the worker as separate containers with no shared volume: with `local` the worker writes an archive the web container cannot see and every download 404s. Only set it to `local` on a topology where both processes share a filesystem."
}

variable "export_storage_bucket" {
  type        = string
  default     = ""
  description = "EXPORT_STORAGE_BUCKET: bucket holding data-export archives. REQUIRED whenever export_storage is `s3`."
}

variable "export_storage_prefix" {
  type        = string
  default     = ""
  description = "EXPORT_STORAGE_PREFIX: key prefix inside the bucket. Empty stores archives at the bucket root, which is what a dedicated bucket wants."
}

variable "export_storage_region" {
  type        = string
  default     = ""
  description = "EXPORT_STORAGE_REGION. Empty falls back to AWS's default (us-east-1). DigitalOcean Spaces uses the datacenter, e.g. tor1."
}

variable "export_storage_endpoint" {
  type        = string
  default     = ""
  description = "EXPORT_STORAGE_ENDPOINT for any non-AWS S3-compatible provider, e.g. https://tor1.digitaloceanspaces.com. Empty targets AWS S3 itself."
}

variable "export_storage_key" {
  type        = string
  sensitive   = true
  default     = ""
  description = "EXPORT_STORAGE_KEY: access key id. Empty falls back to the ambient AWS credential chain, which only helps when running on AWS with an attached role."
}

variable "export_storage_secret" {
  type        = string
  sensitive   = true
  default     = ""
  description = "EXPORT_STORAGE_SECRET: secret access key. Pairs with export_storage_key."
}

variable "export_storage_use_path_style" {
  type        = string
  default     = ""
  description = "EXPORT_STORAGE_USE_PATH_STYLE: set to \"true\" for MinIO and most non-AWS providers, which address buckets as https://host/bucket/key rather than https://bucket.host/key."
}



variable "mercure_jwt_secret" {
  type        = string
  sensitive   = true
  default     = ""
  description = "Shared key signing Mercure publisher and subscriber JWTs. Setting it turns the hub on (enable_mercure in main.tf keys off this); leaving it empty leaves site-review push disabled, so submissions save but never reach the bridge CLI. The module runs the hub and derives MERCURE_URL / MERCURE_PUBLIC_URL itself."
}

variable "stripe_secret_key" {
  type        = string
  sensitive   = true
  default     = ""
  description = "STRIPE_SECRET_KEY. Empty leaves checkout and the customer portal failing."
}

variable "stripe_webhook_secret" {
  type        = string
  sensitive   = true
  default     = ""
  description = "STRIPE_WEBHOOK_SECRET used to verify inbound webhook signatures."
}

variable "oauth_google_id" {
  type        = string
  default     = ""
  description = "OAUTH_GOOGLE_ID. Empty leaves Google sign-in failing."
}

variable "oauth_google_secret" {
  type        = string
  sensitive   = true
  default     = ""
  description = "OAUTH_GOOGLE_SECRET."
}

variable "oauth_github_id" {
  type        = string
  default     = ""
  description = "OAUTH_GITHUB_ID. Empty leaves GitHub sign-in failing."
}

variable "oauth_github_secret" {
  type        = string
  sensitive   = true
  default     = ""
  description = "OAUTH_GITHUB_SECRET."
}
