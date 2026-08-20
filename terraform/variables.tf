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

variable "enable_predeploy_migrations" {
  type        = bool
  default     = false
  description = "Run migrations as a PRE_DEPLOY job on every deploy. Must stay false for the very first apply: PostgreSQL 15+ denies CREATE on the public schema to a non-owner, so until the one-time GRANT has been issued as the cluster admin the job fails — and a failing PRE_DEPLOY job fails the whole deployment, on every subsequent attempt too. Turn it on once migrations have been run by hand."
}

variable "health_check_path" {
  type        = string
  default     = "/login"
  description = "Path App Platform probes to decide a container is healthy. /healthz is the real check — it answers 503 when the database is unreachable — but it cannot be used on the very first apply: the module attaches the cluster's trusted sources AFTER the app, so the app boots with no database access and would never pass. /login is public, queries nothing and returns 200. Switch to /healthz once the database is reachable."
}

variable "custom_domain" {
  type        = string
  default     = ""
  description = "Custom domain to serve the app on (e.g. loupe.ac, or app.example.com). Empty serves only on the assigned *.ondigitalocean.app hostname."
}

variable "domain_zone" {
  type        = string
  default     = ""
  description = "DigitalOcean DNS zone holding custom_domain — usually the apex, and it must be a zone THIS account serves (`doctl compute domain list`). Set it and App Platform writes the record itself; leave it empty to point DNS yourself."
}

variable "default_uri" {
  type        = string
  default     = ""
  description = "Absolute base URL for URLs generated outside a request — password-reset mails, export download links — which have no host to infer one from. Derives from custom_domain when that is set, so it is only needed when serving on the assigned *.ondigitalocean.app hostname, which is not known until after the first apply."
}

variable "project_id" {
  type        = string
  default     = ""
  description = "UUID of the DigitalOcean project to file the app, database cluster and export bucket under — `doctl projects list`. Organisational only; it grants nothing. Left empty, resources land in whatever project the account marks as default, which on a multi-app account is some unrelated deployment's."
}

variable "region" {
  type        = string
  description = "App Platform region slug (e.g. tor, nyc, fra). MUST match the region of the Postgres cluster named by db_cluster_name, so app-to-database traffic stays on the private network. Note this is the App Platform slug, not the Spaces slug: Spaces uses tor1/nyc3/fra1 — see export_bucket_region."
}

variable "create_db_cluster" {
  type        = bool
  default     = false
  description = "Create a dedicated Postgres cluster for this app instead of attaching to one you already run. Leave db_cluster_name unset when this is true. Sized by db_cluster_size / db_cluster_node_count, placed by db_cluster_region. The cluster is guarded with prevent_destroy, so `terraform destroy` refuses and so does setting this back to false; `terraform state rm` is the deliberate override."
}

variable "db_cluster_name" {
  type        = string
  default     = ""
  description = "Name of an EXISTING managed Postgres cluster in your account; the module creates a per-app database and user on it. App-Platform-provisioned clusters are named app-<uuid> and that string IS the name — `doctl databases list`. Leave this empty and set create_db_cluster = true to have the module create a dedicated cluster instead. Exactly one of the two is required: the module has no default cluster to fall back on, so leaving both unset is a plan-time error rather than a silent attachment."

  validation {
    condition     = (var.db_cluster_name != "") != var.create_db_cluster
    error_message = "Set exactly one of db_cluster_name (attach to a cluster you already run) or create_db_cluster = true (have Terraform create a dedicated one). Setting neither leaves the module with no cluster to use; setting both is ambiguous."
  }
}

variable "db_cluster_region" {
  type        = string
  default     = "tor1"
  description = "Datacenter slug for the dedicated cluster, used only when create_db_cluster = true. This is a DATACENTER slug (tor1, nyc3, fra1), a different namespace from the App Platform `region` above (tor, nyc, fra). Passing the App Platform slug here plans cleanly and fails at apply. Keep it colocated with `region` or app-to-database traffic leaves the private network."
}

variable "db_cluster_size" {
  type        = string
  default     = "db-s-1vcpu-1gb"
  description = "Node size for the dedicated cluster (create_db_cluster = true). The default is the smallest managed Postgres plan — adequate for a small instance, and a real cost decision worth revisiting before it holds anything you care about."
}

variable "db_cluster_node_count" {
  type        = number
  default     = 1
  description = "Node count for the dedicated cluster (create_db_cluster = true). 1 means no standby: a node failure is downtime and restore-from-backup, not failover."
}

variable "db_cluster_trusted_ips" {
  type        = list(string)
  default     = []
  description = "Extra IPs or CIDRs allowed to reach the dedicated cluster (create_db_cluster = true), on top of the app itself. The module declares the cluster's whole trusted-source list, so this is AUTHORITATIVE: a rule appended with `doctl databases firewalls append` is removed on the next apply. Add the workstation address here for the one-time schema GRANT, then empty it again — leaving it populated exposes the cluster to an address that is probably a home connection on a dynamic lease. Inert when attaching to a cluster you already run, where the module does not manage trusted sources at all."
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
  description = "Registry namespace — your GitHub org or user for GHCR, your Docker Hub user for DOCKER_HUB, empty for DOCR. Required rather than defaulted: this project's own namespace is not one anybody else can push to, and inheriting it turns into an image-pull failure inside App Platform at apply time rather than a refusal at plan time."
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
  description = "Production MAILER_DSN, pointing at an SMTP server you operate or pay for. Required: email verification is mandatory, and a no-op transport cannot fail, so an unset value strands every registration unverified with nothing raised anywhere."
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

variable "site_review_widget_public" {
  type        = string
  default     = ""
  description = "SITE_REVIEW_WIDGET_PUBLIC: leave empty (the default) to show the site-review widget to administrators only. Set to 1 to offer it to every visitor — its comments are instructions an agent may act on, so only do that where you trust everyone who can reach the site."
}

variable "site_review_widget_token" {
  type        = string
  sensitive   = true
  default     = ""
  description = "SITE_REVIEW_WIDGET_TOKEN: the widget token of the project site-review comments should file into. Optional; empty serves no widget at all. The widget is shown to administrators only unless SITE_REVIEW_WIDGET_PUBLIC is set, which production should leave alone."
}

variable "analytics_script_url" {
  type        = string
  default     = ""
  description = "ANALYTICS_SCRIPT_URL: full URL of the Umami script. Empty emits no tag. The analytics.enabled feature flag must also be on."
}

variable "analytics_website_id" {
  type        = string
  default     = ""
  description = "ANALYTICS_WEBSITE_ID: the Umami site identifier. Empty emits no tag."
}

variable "analytics_origin" {
  type        = string
  default     = ""
  description = "ANALYTICS_ORIGIN: the origin of analytics_script_url, allowed in the content security policy. Separate because the policy is static and cannot parse a URL. Empty leaves the policy unchanged."
}

variable "health_probe_token" {
  type        = string
  sensitive   = true
  default     = ""
  description = "HEALTH_PROBE_TOKEN letting a post-deploy check read the running build from /healthz via an X-Probe-Token header. Optional: empty means /healthz never reports a version."
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
  description = "MAILER_FROM_ADDRESS: sender of every transactional email. Must be on a domain you control and have published SPF/DKIM/DMARC for. Required, because the fallback is the committed noreply@localhost, which real mail servers reject — and since email verification is mandatory, that breaks registration."
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
