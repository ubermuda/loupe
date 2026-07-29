# Object storage for data-export archives.
#
# This deployment runs the web service and the messenger worker as separate
# containers with separate ephemeral filesystems, so an export the worker
# generates is only downloadable if both address the same bucket. DigitalOcean
# Spaces is what gets created here because everything else in this root already
# lives in a DigitalOcean account.
#
# The application itself knows nothing about DigitalOcean. It talks generic S3
# through EXPORT_STORAGE_ENDPOINT / _REGION / _KEY / _SECRET, and the resources
# below merely supply one provider's answers to those. Set
# create_export_bucket = false and fill in the export_storage_* variables to
# point the same application at AWS S3, MinIO or Cloudflare R2 instead.
#
# Creating a bucket needs Spaces credentials on the provider on top of the API
# token — see provider.tf.

locals {
  export_bucket_name = var.export_bucket_name != "" ? var.export_bucket_name : "${local.app_name}-exports"
}

resource "digitalocean_spaces_bucket" "exports" {
  count = var.create_export_bucket ? 1 : 0

  name   = local.export_bucket_name
  region = var.export_bucket_region

  # Export archives are personal data. Nothing but the app reads them — it
  # streams them itself behind an authenticated, expiring link — so the bucket is
  # never publicly listable or readable. This matches the app's own default
  # canned ACL (EXPORT_STORAGE_ACL=private), which is also the only value Spaces
  # accepts on an upload.
  acl = "private"

  # `terraform destroy` must not be able to discard archives a user is still
  # entitled to download; emptying the bucket is a deliberate manual step.
  force_destroy = false

  # Backstop, not the primary cleanup: the app deletes each archive when its
  # download link expires 48 hours after the export completes. This catches
  # objects that outlive their database row — an upload interrupted before the
  # row was written, or a database restored from an older snapshot — which
  # nothing else would ever remove. The window is deliberately far longer than
  # the link's own lifetime so it can never delete a live archive.
  lifecycle_rule {
    id      = "expire-orphaned-archives"
    enabled = true

    abort_incomplete_multipart_upload_days = 1

    expiration {
      days = 30
    }
  }
}

# A key scoped to this one bucket rather than the account-wide pair used to
# create it: the credentials that end up in the app's environment should not be
# able to reach anything else in the account. `readwrite` covers the upload,
# download and delete the export lifecycle performs.
#
# The secret is readable only at creation and is stored in Terraform state in
# plaintext, which is one more reason for the encrypted remote backend that
# versions.tf describes.
resource "digitalocean_spaces_key" "exports" {
  count = var.create_export_bucket ? 1 : 0

  name = "${local.app_name}-exports"

  grant {
    bucket     = digitalocean_spaces_bucket.exports[0].name
    permission = "readwrite"
  }
}
