# DigitalOcean App Platform deployment

This is a **thin root** that deploys the app via the shared module
[`terraform-digitalocean-symfony-app`](https://github.com/ubermuda/terraform-digitalocean-symfony-app)
(pinned to a tag in `main.tf`). All the deployment logic — the App Platform spec,
the per-app database on the shared cluster, the optional domain and migration
job — lives in the module. Here you set only this project's values and secrets.

Adjusting the deployment? Edit the `module "app"` block in `main.tf`
(`app_name`, `db_name`/`db_user`, optional `custom_domain`, `extra_env`) and
provide the secrets below.

`spaces.tf` additionally creates the object storage bucket that data-export
archives live in, because the web and worker containers do not share a
filesystem. That part is DigitalOcean-specific; the application is not — it
speaks plain S3 and can be pointed at AWS, MinIO or R2 by setting
`create_export_bucket = false` and filling in the `export_storage_*` variables.

## Deploy

```bash
just build-prod && just deploy    # build linux/amd64 image, push, roll out

# terraform (from this dir):
cp terraform.tfvars.example terraform.tfvars     # secrets only
export TF_VAR_app_secret=$(openssl rand -hex 16)
export TF_VAR_app_encryption_key=$(php -r 'echo base64_encode(sodium_crypto_secretbox_keygen());')

terraform init      # fetches the pinned module
terraform apply
```

`just build-prod`/`just deploy`/`just logs-prod` (in the project `justfile`) build
and roll out the image; the `prod_image` variable there must match the module's
`registry`/`image_repository`/`image_tag`.

## You must bring a Postgres cluster

Terraform creates a database and a user *on* an existing managed cluster; it
never creates the cluster. `db_cluster_name` and `region` therefore have no
defaults — pass your own, or `terraform apply` fails asking for them:

```bash
doctl databases create loupe-db --engine pg --region tor
doctl databases list      # the Name column is db_cluster_name
```

## First deploy needs a one-time DB bootstrap

The cluster needs two steps that can't be Terraformed (a firewall resource
would cut off any sibling apps sharing it): add this app + your IP to the
cluster's trusted sources, and `GRANT` schema privileges. After the first
`just tf-apply`, run:

```bash
just tf-db-bootstrap
```

Then run migrations once and set `enable_predeploy_migrations = true` for
automated migrations thereafter. (The manual equivalent is in the module's
README → "Manual database bootstrap".)

## Notes

- **State is sensitive** (it holds the plaintext of every SECRET env var, and now
  also the Spaces key's secret). Use an encrypted remote backend — see the
  commented block in `versions.tf`.
- **Region** has no default: set `region` to your cluster's region, and
  `export_bucket_region` to a Spaces datacenter. They use different slugs —
  App Platform's `tor` is Spaces' `tor1`, `nyc` is `nyc3`.
- Module inputs, outputs, and limitations are documented in the module repo.
