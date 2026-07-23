terraform {
  required_version = ">= 1.6"

  required_providers {
    digitalocean = {
      source  = "digitalocean/digitalocean"
      version = "~> 2.43"
    }
  }

  # State holds the plaintext of SECRET-typed env vars (APP_SECRET,
  # APP_ENCRYPTION_KEY, MAILER_DSN). Treat it as sensitive: use an encrypted
  # remote backend rather than a local state file committed anywhere. Example:
  #
  # backend "s3" {
  #   endpoints                   = { s3 = "https://<region>.digitaloceanspaces.com" }
  #   bucket                      = "my-tfstate"
  #   key                         = "loupe/terraform.tfstate"
  #   region                      = "us-east-1" # ignored by Spaces, but required
  #   skip_credentials_validation = true
  #   skip_metadata_api_check     = true
  #   skip_region_validation      = true
  #   skip_requesting_account_id  = true
  # }
}
