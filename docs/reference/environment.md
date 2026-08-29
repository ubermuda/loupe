---
title: "Environment variables"
description: "Every variable a production instance decides for itself, and how to generate the secrets."
---


Everything Loupe reads is documented inline in `.env`, which is also where the
committed defaults live. Below is what a production instance must decide for
itself. **Anything you leave unset falls back to the committed default in
`.env`** — which is usually a development value.

Where a variable is set differs by topology: in `docker/compose/prod.env` for the
single-host stack, in Terraform variables for App Platform. The last column
answers one question — **must you add this yourself, because no template has a
slot for it?** "No" means both templates cover it.

## Always

| Variable | Purpose | Add by hand? |
|---|---|---|
| `APP_ENV` | Must be `prod`. | No |
| `APP_SECRET` | Symfony secret. Generate once — see [Secrets](#secrets). | No |
| `DATABASE_URL` | Postgres DSN. `serverVersion` must match the real cluster: understating it is safe, overstating it can break queries. | No |
| `DEFAULT_URI` | **The instance's public URL, scheme included.** The single host-shaped setting the app has. It builds absolute links in non-HTTP contexts (console commands, the worker), pins the host of links in security-sensitive email so a forged `Host` header cannot redirect them, and is the base of the Mercure topics the bridge CLI subscribes to. Get it wrong and password-reset and export-download emails point somewhere nobody can act on. | No |
| `MCP_ALLOWED_HOSTS` | Comma-separated DNS-rebinding allowlist for `/mcp`, **hostnames only, no port**. It must contain the hostname agents actually use, or every MCP call is rejected with a 403 — one that names this variable and echoes the host it rejected, so the failure is self-explaining. | No |
| `TRUSTED_PROXIES` | The reverse proxy in front of the app, as IPs or CIDR ranges. **Empty falls back to `PRIVATE_SUBNETS`**, which covers Docker and any balancer on a private network. Set it when your balancer reaches the app from a public address: until you do, `X-Forwarded-Proto` and `X-Forwarded-Host` are ignored (generated URLs get the wrong scheme and host) and every visitor shares the balancer's IP, so the per-IP registration and password-reset limiters throttle all your users collectively. | No — `trusted_proxies` in `terraform.tfvars`, or a slot in `docker/compose/prod.env.example` |
| `APP_SOURCE_URL` | Where *this instance's* source can be obtained, rendered as a footer link on every page. A default ships in `.env` pointing at upstream, which is correct for an unmodified instance and wrong for a modified one. **If you change the code, the AGPL requires you to point this at your repository.** | No |

## Mail

Email verification is **mandatory**, so nobody can register until mail works.

| Variable | Purpose | Add by hand? |
|---|---|---|
| `MAILER_DSN` | Outbound transport. | No |
| `MAILER_FROM_ADDRESS` | Sender of every transactional email — verification, password reset, waitlist invite, data export, account deletion. Must be on a domain you control and have published SPF/DKIM/DMARC for. **Falls back to `noreply@localhost`, which real mail servers reject**, so registration breaks. | No |
| `MAILER_FROM_NAME` | Display name beside the address. Defaults to `Loupe`. | No |

## Site-review push (Mercure)

Optional. Without it, review submissions still save but never reach a running
agent, and the publish failure is only logged — it degrades silently.

| Variable | Purpose | Add by hand? |
|---|---|---|
| `MERCURE_JWT_SECRET` | Shared HS256 key, minimum 32 characters, **identical for the app and the hub**. No default ships: if unset, Mercure fails loudly rather than signing with a publicly-known key. | No |
| `MERCURE_URL` | Where the app POSTs updates — the hub on the internal network. | No |
| `MERCURE_PUBLIC_URL` | Where clients subscribe. A genuinely separate host (the bridge CLI reaches it directly), so it cannot be derived from `DEFAULT_URI`. | No |

## Install and first administrator

| Variable | Purpose | Add by hand? |
|---|---|---|
| `INSTALL_TOKEN` | Gates `/install`. **Set this before the first deploy** — see [First run](../operating/first-run.md). | No |
| `ADMIN_EMAIL` | Promotes that user to `ROLE_ADMIN` at login. Only works on an already-verified account, so it cannot rescue a locked-out install; `app:user:promote` can. | No |

## Optional features

| Variable | Purpose | Add by hand? |
|---|---|---|
| `APP_ENCRYPTION_KEY` | Only once an `encrypted_string` column is in use. **Losing it makes existing encrypted columns unreadable.** | No |
| `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` | Billing. Nothing instantiates the Stripe client until the `billing.enabled` feature flag is on. | No |
| `OAUTH_GOOGLE_ID` / `_SECRET`, `OAUTH_GITHUB_ID` / `_SECRET` | Social login. A provider becomes reachable only when its credentials **and** its feature flag (`auth.google.enabled` / `auth.github.enabled`) are both set. | No |
| `HEALTH_PROBE_TOKEN` | Adds the build version to `GET /healthz`, for a caller presenting it as an `X-Probe-Token` header — so a post-deploy check can prove which build went live without a session. Unset, the field never appears: an instance must not advertise its build to anyone who asks. | No |
| `SITE_REVIEW_WIDGET_PUBLIC` | Serves the site-review widget to every visitor instead of administrators only. Its comments are instructions an agent may act on, so set it only where you trust everyone who can reach the site — **production should leave it empty**. | No |
| `SITE_REVIEW_WIDGET_BACKEND` | Overrides the instance the widget talks to. Empty means the host that served the script, which is what you want unless the widget is embedded from somewhere else. | No |
| `ANALYTICS_SCRIPT_URL`, `ANALYTICS_WEBSITE_ID`, `ANALYTICS_ORIGIN`, `ANALYTICS_COLLECT_ORIGIN` | A self-hosted analytics tag. Nothing is emitted unless both of the first two are set **and** the `analytics.enabled` flag is on, so the page calls nowhere by default. The last two go in the content security policy: the script's origin in `script-src`, the origin events post to in `connect-src`. Umami Cloud splits those (`cloud.umami.is` and `gateway.umami.is`); a self-hosted Umami uses one value for both. | No |
| `SITE_REVIEW_WIDGET_TOKEN` | Only for dogfooding the review widget on Loupe's own pages, public ones included. It appears in the page source anyone can view without an account, so use a dedicated SiteReview-scoped token, never an MCP or production credential. | No — `site_review_widget_token` in `terraform.tfvars`, or a slot in `docker/compose/prod.env.example` |

## How the Terraform root sets things

Every variable on this page is wired on both topologies — nothing needs adding
to `extra_env` by hand, including `SITE_REVIEW_WIDGET_TOKEN` (`site_review_widget_token`,
emitted as a `SECRET`) and `TRUSTED_PROXIES` (`trusted_proxies`, appended to the
private ranges rather than replacing them). Adding either by hand would produce
a duplicate key and fail the apply.

`APP_SOURCE_URL` is wired the same way — `app_source_url` in
`terraform/variables.tf` feeds an `extra_env` entry, and
`docker/compose/prod.env.example` carries a commented slot. Both deliberately omit the
key entirely when it is empty, rather than passing an empty string: an absent
key leaves the image's committed default in place, while an emitted empty one
would remove the footer link altogether.

Everything else is wired. The shared module injects `APP_ENV`, `APP_SECRET`,
`APP_ENCRYPTION_KEY`, `DATABASE_URL`, `MAILER_DSN` and `DEFAULT_URI` — and, when
the hub is enabled, `MERCURE_URL`, `MERCURE_PUBLIC_URL` and `MERCURE_JWT_SECRET`,
which it derives itself. The rest goes through `extra_env` in
`terraform/main.tf`, sourced from the variables in `terraform/variables.tf`; set
them in `terraform.tfvars` or as `TF_VAR_*`. Each is omitted from the app spec
entirely when left empty, so a feature is off rather than half-configured.

`DEFAULT_URI` is the exception worth watching: the module injects the key, but
*you* supply the value, through the `default_uri` or `custom_domain` variables.
Set them in `terraform.tfvars` — both ship commented out in
`terraform/terraform.tfvars.example`. Do not edit `terraform/main.tf`, where
they are already wired to those variables: replacing a reference there breaks
`TF_VAR_default_uri` silently.

`EXPORT_STORAGE` and its `EXPORT_STORAGE_*` companions are not listed here;
[Object storage](../extending/object-storage.md) covers them, including the
`EXPORT_STORAGE_ACL` value each provider needs.

## Secrets

Generate once, then keep them somewhere durable.

```bash
# APP_SECRET
openssl rand -hex 16

# APP_ENCRYPTION_KEY — base64-encoded 32-byte libsodium secret-box key
php -r 'echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;'

# MERCURE_JWT_SECRET and INSTALL_TOKEN — any long random string
openssl rand -base64 32
```

**Losing `APP_ENCRYPTION_KEY` makes existing encrypted columns unreadable.**
There is no recovery.

For App Platform, inject them as `TF_VAR_*`:

```bash
export TF_VAR_app_secret=$(openssl rand -hex 16)
export TF_VAR_app_encryption_key=$(php -r 'echo base64_encode(sodium_crypto_secretbox_keygen());')
export TF_VAR_registry_credentials="<github-username>:<ghcr-pat>"
export TF_VAR_mailer_dsn="<production mailer DSN>"
export DIGITALOCEAN_TOKEN="<do-token>"
export SPACES_ACCESS_KEY_ID="<spaces-key>"
export SPACES_SECRET_ACCESS_KEY="<spaces-secret>"
```

`terraform/terraform.tfvars.example` is the template; copy it to
`terraform.tfvars` for anything you would rather not keep in the environment.

> **Terraform state holds these values in plaintext.** Use an encrypted remote
> backend — there is a commented block in `terraform/versions.tf`. Do not commit
> `terraform.tfstate`.

