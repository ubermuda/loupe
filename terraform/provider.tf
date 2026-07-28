provider "digitalocean" {
  # When null, the provider falls back to the DIGITALOCEAN_TOKEN /
  # DIGITALOCEAN_ACCESS_TOKEN environment variable. Prefer the env var so the
  # token never lands in a tfvars file.
  token = var.do_token

  # Spaces speaks the S3 API, so it authenticates with an S3-style key pair
  # rather than with the DigitalOcean API token — creating the export bucket
  # needs both. Generate the pair under "Spaces Keys" in the DigitalOcean
  # control panel. Null falls back to SPACES_ACCESS_KEY_ID /
  # SPACES_SECRET_ACCESS_KEY in the environment, which is where they belong.
  # Only required when create_export_bucket is true.
  spaces_access_id  = var.spaces_access_id
  spaces_secret_key = var.spaces_secret_key
}
