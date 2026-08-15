# Groups this deployment's resources under a DigitalOcean project.
#
# Purely organisational — projects carry no permissions or billing of their own.
# It is here because the alternative is not "no project" but "the *default*
# project": DigitalOcean files every new resource under whichever project is
# marked default, which on a shared account is some unrelated app's. The app,
# the database cluster and the export bucket would otherwise land there
# individually, and moving them afterwards is manual console work.
#
# The shared module has no concept of projects, so the assignment is made here
# from the identifiers it exports.
#
# Left empty the whole thing is skipped, because an operator deploying this
# elsewhere has no reason to own a project of ours.
resource "digitalocean_project_resources" "app" {
  count = var.project_id != "" ? 1 : 0

  project = var.project_id

  # URNs, not IDs, and the prefix differs per resource type: App Platform apps
  # are `do:app:`, managed databases `do:dbaas:`. compact() drops the entries
  # that do not apply.
  #
  # The cluster is listed only when this root created it. A resource belongs to
  # exactly one DigitalOcean project, so assigning is really moving: in attach
  # mode the cluster is one the operator already runs and may share with other
  # apps, and claiming it would drag it out of its own project — and, when this
  # resource is later destroyed, into the account's default one.
  resources = compact([
    "do:app:${module.app.app_id}",
    var.create_db_cluster ? "do:dbaas:${module.app.db_cluster_id}" : "",
    var.create_export_bucket ? digitalocean_spaces_bucket.exports[0].urn : "",
  ])
}
