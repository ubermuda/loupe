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

output "db_name" {
  description = "Per-app database name on the shared cluster."
  value       = module.app.db_name
}

output "db_user" {
  description = "Per-app database user on the shared cluster."
  value       = module.app.db_user
}
