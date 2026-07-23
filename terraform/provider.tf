provider "digitalocean" {
  # When null, the provider falls back to the DIGITALOCEAN_TOKEN /
  # DIGITALOCEAN_ACCESS_TOKEN environment variable. Prefer the env var so the
  # token never lands in a tfvars file.
  token = var.do_token
}
