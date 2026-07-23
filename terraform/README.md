# DigitalOcean App Platform deployment

This is a **thin root** that deploys the app via the shared module
[`terraform-digitalocean-symfony-app`](https://github.com/ubermuda/terraform-digitalocean-symfony-app)
(pinned to a tag in `main.tf`). All the deployment logic — the App Platform spec,
the per-app database on the shared cluster, the optional domain and migration
job — lives in the module. Here you set only this project's values and secrets.

Adjusting the deployment? Edit the `module "app"` block in `main.tf`
(`app_name`, `image_repository`, `db_name`/`db_user`, optional `custom_domain`,
`extra_env`) and provide the secrets below.

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

## First deploy needs a one-time DB bootstrap

The shared cluster needs two steps that can't be Terraformed (a firewall resource
would cut off the sibling apps): add this app + your IP to the cluster's trusted
sources, and `GRANT` schema privileges. After the first `just tf-apply`, run:

```bash
just tf-db-bootstrap
```

Then run migrations once and set `enable_predeploy_migrations = true` for
automated migrations thereafter. (The manual equivalent is in the module's
README → "Manual database bootstrap".)

## Notes

- **State is sensitive** (holds SECRET env plaintext). Use an encrypted remote
  backend — see the commented block in `versions.tf`.
- **Region** is `tor` (module default) to match the shared cluster.
- Module inputs, outputs, and limitations are documented in the module repo.
