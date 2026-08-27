output "app_id" {
  description = "App Platform application ID (used by `just deploy` and the firewall bootstrap)."
  value       = module.app.app_id
}

output "live_url" {
  description = "The app's live URL."
  value       = module.app.live_url
}

output "default_ingress" {
  description = "The assigned *.ondigitalocean.app ingress URL."
  value       = module.app.default_ingress
}

output "db_cluster_id" {
  description = "UUID of the shared Postgres cluster (for the manual bootstrap steps)."
  value       = module.app.db_cluster_id
}

output "db_cluster_is_dedicated" {
  description = "True when Terraform created the cluster, and therefore owns its trusted-source list authoritatively. `just tf-db-bootstrap` reads this to decide whether appending firewall rules would survive the next apply."
  value       = var.create_db_cluster
}

output "db_name" {
  description = "Per-app database name on the shared cluster."
  value       = module.app.db_name
}

output "db_user" {
  description = "Per-app database user on the shared cluster."
  value       = module.app.db_user
}

output "export_bucket_name" {
  description = "Bucket holding data-export archives. Null when create_export_bucket is false — you supplied the bucket yourself."
  value       = var.create_export_bucket ? digitalocean_spaces_bucket.exports[0].name : null
}

output "export_bucket_endpoint" {
  description = "S3 endpoint URL of the export bucket, as handed to the app as EXPORT_STORAGE_ENDPOINT."
  value       = local.export_storage_endpoint
}
