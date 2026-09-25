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
| `DEFAULT_URI` | **The instance's public URL, scheme included.** The single host-shaped setting the app has. It builds absolute links in non-HTTP contexts (console commands, the worker), pins the host of links in security-sensitive email so a forged `Host` header cannot redirect them, and is the base of the Mercure topics the bridge CLI subscribes to. It is also the OAuth issuer, and `DEFAULT_URI` plus `/mcp` is the resource that every MCP access token is bound to, so an MCP client must connect with that exact URL. Get it wrong and password-reset and export-download emails point somewhere nobody can act on. | No |
| `OAUTH_TRUSTED_CLIENT_IDS` | Comma-separated client ids the consent page presents as vouched for. Each entry is a **full `client_id` URL**, or an origin you know serves one tenant. A bare host never matches, because a document on a shared host would then borrow the trust of every other document there. A trusted client shows its host alone, with its icon. Every other client shows its whole `client_id`, with the host emphasised and no icon. The default vouches for Claude Code's document, so Loupe ships trusting that one client. An entry stays trusted if its domain later changes hands, because nothing re-checks it. | No |
| `OAUTH_REFRESH_GRACE_SECONDS` | Seconds a rotated refresh token stays redeemable, **once**. The server revokes the old refresh token and issues the new pair before the client has persisted anything, so a response lost on the way back leaves the client holding a token the server has already refused, and no endpoint hands the new one back. This window lets that client recover instead of signing in again. It accepts the old token once and then closes, so a captured token cannot be redeemed repeatedly. The cost is that a stolen refresh token works for this long after its rightful use. Defaults to 60. Set `0` to switch it off. | No |
| `MCP_ALLOWED_HOSTS` | Comma-separated DNS-rebinding allowlist for `/mcp`, **hostnames only, no port**. It must contain the hostname agents actually use, or every MCP call is rejected with a 403 — one that names this variable and echoes the host it rejected, so the failure is self-explaining. | No |
| `TRUSTED_PROXIES` | The reverse proxy in front of the app, as IPs or CIDR ranges. **Empty falls back to `PRIVATE_SUBNETS`**, which covers Docker and any balancer on a private network. Set it when your balancer reaches the app from a public address: until you do, `X-Forwarded-Proto` and `X-Forwarded-Host` are ignored (generated URLs get the wrong scheme and host) and every visitor shares the balancer's IP, so the per-IP registration and password-reset limiters throttle all your users collectively. The API limiter is the worst of them: `/api` and `/mcp` are throttled per client address, so one bucket then covers the whole instance and unrelated clients collect each other's 429s. The *Trusted proxies* row on `/admin/status` reports this; see [Post-deploy checks](../operating/post-deploy-checks.md). | No — `trusted_proxies` in `terraform.tfvars`, or a slot in `docker/compose/prod.env.example` |
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

An open board also subscribes to the hub, so it reloads when someone changes a
column. The `live_updates.enabled` flag controls this, so a switched-off flag
stops the live refresh. Agent push has its own flag, `agent.push.enabled`.
The board response sets a cookie that authorizes the browser for that one
board. Two rules follow for a hub on its own host:

- Put the hub under the parent domain of `DEFAULT_URI`, such as
  `hub.example.com` beside `loupe.example.com`. A hub on another domain cannot
  read the cookie, so boards work but do not refresh live.
- Name the app's origin in the hub's `cors_origins` directive.
  `docker/compose/prod.yaml` sets it from `DEFAULT_URI`. Give `DEFAULT_URI`
  no trailing slash, because the hub compares the origin exactly.

The App Platform module serves the hub under the app's own host, so neither
rule applies there. The Content-Security-Policy allows `MERCURE_PUBLIC_URL` in
`connect-src`.

## Install and first administrator

| Variable | Purpose | Add by hand? |
|---|---|---|
| `INSTALL_TOKEN` | Gates `/install`. **Set this before the first deploy** — see [First run](../operating/first-run.md). | No |
| `ADMIN_EMAIL` | Promotes that user to `ROLE_ADMIN` at login. Only works on an already-verified account, so it cannot rescue a locked-out install; `app:user:promote` can. | No |

## OAuth authorization server

Loupe issues OAuth tokens to the apps a user connects. The server is always on. When a key is missing, only OAuth fails: the consent page and the token endpoint return errors, and the *OAuth keys* row on `/admin/status` fails. [Secrets](#secrets) has the commands that generate these values.

| Variable | Purpose | Add by hand? |
|---|---|---|
| `OAUTH_PRIVATE_KEY` | The RSA private key that signs access tokens. Give the PEM text, or the path of a PEM file in the container. | No |
| `OAUTH_PRIVATE_KEY_PASSPHRASE` | The passphrase of `OAUTH_PRIVATE_KEY`. Empty when the key is not encrypted. | No |
| `OAUTH_PUBLIC_KEY` | The public half of the key pair, as PEM text or a path. The API checks each access token against it. | No |
| `OAUTH_ENCRYPTION_KEY` | Encrypts authorization codes and refresh tokens. **A new value makes every refresh token unusable**, so each connected app must connect again. | No |

A new key pair makes every issued access token invalid. The apps then refresh, so users do not see it.

## Optional features

| Variable | Purpose | Add by hand? |
|---|---|---|
| `APP_ENCRYPTION_KEY` | Encrypts `encrypted_string` columns. The secret of a per-project GitHub webhook is one, so while the key is unset no project can create a webhook. **Losing it makes existing encrypted columns unreadable.** | No |
| `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` | Billing. Nothing instantiates the Stripe client until the `billing.enabled` feature flag is on. | No |
| `GITHUB_APP_SLUG` | The GitHub App that project owners install to connect repositories. The slug is the last segment of the App's public page, `https://github.com/apps/<slug>`. Not a secret. Set the four `GITHUB_APP_*` variables together. While one is empty, projects connect with a webhook only, and the *GitHub App* row on `/admin/status` names the empty ones. [Forge webhooks](../extending/forge-webhooks.md) lists the App settings. | No |
| `GITHUB_APP_CLIENT_ID` | The client ID on the *General* page of the App settings on GitHub. Not a secret. | No |
| `GITHUB_APP_CLIENT_SECRET` | A client secret, which you generate on the *General* page of the App settings. **Secret.** On each install, Loupe uses it to get a user token, and reads with that token which installations the user can reach. | No |
| `GITHUB_APP_WEBHOOK_SECRET` | The webhook secret you set on the *General* page of the App settings. **Secret.** It verifies a delivery to `/webhooks/forge/github`. Unset refuses every App delivery. A per-project webhook has its own secret and does not use it. | No |
| `OAUTH_GOOGLE_ID`, `OAUTH_GOOGLE_SECRET`, `OAUTH_GITHUB_ID`, `OAUTH_GITHUB_SECRET` | Social login. A provider becomes reachable only when its credentials **and** its feature flag (`auth.google.enabled` / `auth.github.enabled`) are both set. | No |
| `HEALTH_PROBE_TOKEN` | Adds the build version to `GET /healthz`, for a caller presenting it as an `X-Probe-Token` header — so a post-deploy check can prove which build went live without a session. Unset, the field never appears: an instance must not advertise its build to anyone who asks. | No |
| `SITE_REVIEW_WIDGET_PUBLIC` | Serves the site-review widget to every visitor instead of administrators only. Its comments are instructions an agent may act on, so set it only where you trust everyone who can reach the site — **production should leave it empty**. | No |
| `SITE_REVIEW_WIDGET_BACKEND` | Overrides the instance the widget talks to. Empty means the host that served the script, which is what you want unless the widget is embedded from somewhere else. | No |
| `SITE_REVIEW_WIDGET_CONTEXT` | An opaque marker saying what this deployment serves. It is stored on every comment the widget files, and reported by `feedback_list` and `card_get`. A `card:` marker locks the widget to that card, so every note on the page goes to it. Leave it empty on a real deployment, which stores nothing. A preview instance sets it so a comment carries the work it was made against. | No |
| `ANALYTICS_SCRIPT_URL`, `ANALYTICS_WEBSITE_ID`, `ANALYTICS_ORIGIN`, `ANALYTICS_COLLECT_ORIGIN` | A self-hosted analytics tag. Nothing is emitted unless both of the first two are set **and** the `analytics.enabled` flag is on, so the page calls nowhere by default. The last two go in the content security policy: the script's origin in `script-src`, the origin events post to in `connect-src`. Umami Cloud splits those (`cloud.umami.is` and `gateway.umami.is`); a self-hosted Umami uses one value for both. | No |
| `SITE_REVIEW_WIDGET_PROJECT` | Only for dogfooding the review widget on Loupe's own pages. It names the project comments file into. The reviewer signs in through the OAuth popup, so the page source carries no credential. The project must allow the page's origin under its allowed sites, and only its owner can sign in. | No — `site_review_widget_project` in `terraform.tfvars`, or a slot in `docker/compose/prod.env.example` |

## How the Terraform root sets things

Every variable on this page is wired on both topologies. Nothing needs adding
to `extra_env` by hand, including `TRUSTED_PROXIES` (`trusted_proxies`, appended
to the private ranges rather than replacing them). Adding it by hand would
produce a duplicate key and fail the apply.

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

## Adding a row to this page

Write the variable's full name. A shorthand that folds two names into one row,
such as `OAUTH_GOOGLE_ID` / `_SECRET`, reads well and hides the second name from
every search a person or a tool makes for it.

That is not hypothetical. `EXPORT_STORAGE_SECRET` sat in no page at all until
`DeploymentConfigParityCheck` gained its documentation scan. Six existing scans
passed over it, because each one compares two files a machine reads, and the
shorthand row satisfied a reader skimming the page. The check found it only once
someone spelled the family out.

Delegating a whole family to another page, as the paragraph above does for
`EXPORT_STORAGE_*`, is a different thing and stays correct. Say where the
variables live, and make sure that page names each one in full.

## Secrets

Generate once, then keep them somewhere durable.

```bash
# APP_SECRET
openssl rand -hex 16

# APP_ENCRYPTION_KEY — base64-encoded 32-byte libsodium secret-box key
php -r 'echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;'

# MERCURE_JWT_SECRET and INSTALL_TOKEN — any long random string
openssl rand -base64 32

# OAUTH_PRIVATE_KEY and OAUTH_PUBLIC_KEY — an RSA key pair, no passphrase
openssl genrsa -out oauth-private.pem 2048
openssl rsa -in oauth-private.pem -pubout -out oauth-public.pem

# OAUTH_ENCRYPTION_KEY
openssl rand -hex 32
```

In development and in a worktree, `league:oauth2-server:generate-keypair --skip-if-exists` writes a key pair into `var/oauth/`. Worktree provisioning and `just e2e-up` run it for you. The test suite uses the key pair in `tests/Support/oauth/`, which is for tests only.

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

