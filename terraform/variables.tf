# Root-level inputs. Everything else is configured in the module block in
# main.tf. These are the secrets — provide via gitignored terraform.tfvars OR
# TF_VAR_* env vars. Never commit real values.

variable "do_token" {
  type        = string
  default     = null
  sensitive   = true
  description = "DigitalOcean API token. Leave null to read DIGITALOCEAN_TOKEN from the environment."
}

variable "spaces_access_id" {
  type        = string
  default     = null
  sensitive   = true
  description = "Spaces access key id. Spaces authenticates with an S3-style key pair rather than the API token, so creating the export bucket needs this as well as do_token. Leave null to read SPACES_ACCESS_KEY_ID from the environment. Unused when create_export_bucket is false."
}

variable "spaces_secret_key" {
  type        = string
  default     = null
  sensitive   = true
  description = "Spaces secret key, pairing with spaces_access_id. Leave null to read SPACES_SECRET_ACCESS_KEY from the environment."
}

# --- Placement: your account's, not anyone else's ---
# Neither of these has a default. The shared module ships defaults that name a
# specific cluster in a specific region, and inheriting them points a third
# party's deployment at infrastructure they cannot reach — so this root demands
# both explicitly.

variable "region" {
  type        = string
  description = "App Platform region slug (e.g. tor, nyc, fra). MUST match the region of the Postgres cluster named by db_cluster_name, so app-to-database traffic stays on the private network. Note this is the App Platform slug, not the Spaces slug: Spaces uses tor1/nyc3/fra1 — see export_bucket_region."
}

variable "db_cluster_name" {
  type        = string
  description = "Name of an EXISTING managed Postgres cluster in your account; the module creates a per-app database and user on it but never creates the cluster itself. App-Platform-provisioned clusters are named app-<uuid> and that string IS the name. Create one first if you have none: `doctl databases create loupe-db --engine pg --region <region>`, then `doctl databases list`."
}

variable "db_server_version" {
  type        = string
  default     = "18"
  description = "PostgreSQL major version advertised to Doctrine through DATABASE_URL's serverVersion. It must match the cluster db_cluster_name points at: understating it is safe, overstating it can break queries. Check with `doctl databases list`."
}

# --- Container image ---
# The image is built and pushed by `just build-prod` / `just push-prod` and
# pulled by App Platform, so these must agree with the justfile's prod_image.
# Override prod_image from the environment (LOUPE_PROD_IMAGE) and set the
# matching values here.

variable "registry_type" {
  type        = string
  default     = "GHCR"
  description = "Image registry type: GHCR, DOCR (DigitalOcean Container Registry) or DOCKER_HUB."
}

variable "registry" {
  type        = string
  default     = "ubermuda"
  description = "Registry namespace — your GitHub org or user for GHCR, your Docker Hub user for DOCKER_HUB, empty for DOCR. The default is this project's own namespace, which nobody else can push to; set it to yours."
}

variable "image_repository" {
  type        = string
  default     = "loupe"
  description = "Image repository name within the registry."
}

variable "image_tag" {
  type        = string
  default     = "prod"
  description = "Image tag to deploy. A fixed tag means App Platform's deployment history is the only record of what ran; tag by commit SHA if you want one-command rollbacks."
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

variable "app_source_url" {
  type        = string
  default     = ""
  description = "APP_SOURCE_URL: where the source of THIS instance can be obtained, rendered as a footer link on every page. Empty leaves the key out of the app spec entirely, so the image's committed default applies — it points at the upstream repository, which is the truth only while you run upstream's code unmodified. Modify Loupe and the AGPL obliges you to offer YOUR source to the people using the instance: set this to your own repository, because a link to upstream offers them code they are not interacting with."
}

# --- Export bucket ---
# When create_export_bucket is true (the default), spaces.tf creates a Spaces
# bucket and a scoped access key, and main.tf feeds their values into
# EXPORT_STORAGE_BUCKET / _REGION / _ENDPOINT / _KEY / _SECRET. The
# export_storage_* variables further down are then unused — they exist for the
# opposite case, an operator bringing their own AWS S3, MinIO or R2 bucket.

variable "create_export_bucket" {
  type        = bool
  default     = true
  description = "Create a DigitalOcean Spaces bucket and access key for data-export archives, and wire them into the app. Set to false to bring your own S3-compatible bucket instead and configure it through the export_storage_* variables. Creating one requires Spaces credentials on the provider (spaces_access_id / spaces_secret_key)."
}

variable "export_bucket_name" {
  type        = string
  default     = ""
  description = "Name of the Spaces bucket to create. Empty derives it from the app name (\"<app_name>-exports\"). Spaces bucket names are unique across all DigitalOcean accounts in a region, so set this explicitly if creation fails as already-taken."
}

variable "export_bucket_region" {
  type        = string
  default     = ""
  description = "Spaces datacenter for the export bucket, e.g. tor1, nyc3, fra1. REQUIRED when create_export_bucket is true. These slugs are not the App Platform slugs and are not derivable from them (App Platform's nyc corresponds to Spaces' nyc3), so it is asked for separately rather than guessed. Pick the one closest to `region`."

  validation {
    condition     = !var.create_export_bucket || var.export_bucket_region != ""
    error_message = "export_bucket_region must be set when create_export_bucket is true (e.g. tor1, nyc3, fra1)."
  }
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

variable "export_storage_acl" {
  type        = string
  default     = ""
  description = "EXPORT_STORAGE_ACL: canned ACL sent with every upload. Empty keeps the app default, `private`, which MinIO and DigitalOcean Spaces require. Set it to `bucket-owner-full-control` for an AWS S3 bucket left at its default \"Bucket owner enforced\" ownership, which rejects every other ACL with a 400."
}

variable "export_storage_use_path_style" {
  type        = string
  default     = ""
  description = "EXPORT_STORAGE_USE_PATH_STYLE: set to \"true\" for MinIO and most non-AWS providers, which address buckets as https://host/bucket/key rather than https://bucket.host/key."
}



variable "mailer_from_address" {
  type        = string
  default     = ""
  description = "MAILER_FROM_ADDRESS: sender of every transactional email. Must be on a domain you control and have published SPF/DKIM/DMARC for. Empty falls back to the committed noreply@localhost, which real mail servers reject — and since email verification is mandatory, that breaks registration."
}

variable "mailer_from_name" {
  type        = string
  default     = ""
  description = "MAILER_FROM_NAME: display name shown beside mailer_from_address. Empty falls back to the committed default."
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
