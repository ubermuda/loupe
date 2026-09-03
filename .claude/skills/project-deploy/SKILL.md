---
name: project-deploy
description: Use when deploying to production, running terraform apply against the App Platform app, verifying what version is live, or diagnosing a deploy that appears to have succeeded and changed nothing.
---

# Deploying

Production is a DigitalOcean App Platform app pulling `ghcr.io/ubermuda/loupe:prod`.
Infrastructure lives in `terraform/`, and `terraform/terraform.tfvars` is
gitignored, so it holds the real values.

## The two halves, and why they are not the same event

A deploy is two independent things, and confusing them is the failure this skill
exists to prevent.

1. **The image.** `just build-prod` builds it, `just push-prod` pushes it to the
   `prod` tag. Nothing about this changes production.
2. **The app spec.** `terraform apply` changes environment variables, instance
   sizes and routes. It creates a deployment.

**A `terraform apply` deployment does not re-pull the image.** The tag is fixed
at `prod`, so App Platform sees the same tag it already has and reuses the layers
it holds. The spec change goes live and the code does not.

That is not a bug in the platform. `variable "image_tag"` says so: "A fixed tag
means App Platform's deployment history is the only record of what ran."

## Ship code

```bash
just deploy          # build-prod, push-prod, then create-deployment --wait
```

Or, when the image is already pushed:

```bash
doctl apps create-deployment "$(cd terraform && terraform output -raw app_id)" --force-rebuild --wait
```

`--force-rebuild` is what makes the platform fetch the tag again. Without it a
deployment can complete, report `ACTIVE`, and run the previous image.

The working tree must be clean. `APP_VERSION` comes from `git describe --tags
--always --dirty`, so an uncommitted change ships an image whose version cannot
be traced to a commit. `just release` refuses outright; `just deploy` does not,
so check yourself.

## Ship an infrastructure change

```bash
cd terraform
terraform plan -out=/tmp/tf.plan     # read it, then apply the file you read
terraform apply /tmp/tf.plan
```

Apply the saved plan rather than re-planning, so what lands is what you reviewed.

**Read the plan as JSON, not as text.** The DigitalOcean provider collapses the
app resource into "(N unchanged blocks hidden)", and an added environment
variable hides in there. Grepping the human-readable output for a variable name
finds nothing and reads as "no change":

```bash
terraform show -json /tmp/tf.plan | python3 -c "
import json,sys
for rc in json.load(sys.stdin)['resource_changes']:
    if rc['change']['actions'] == ['no-op']: continue
    print(rc['address'], rc['change']['actions'])
    print('  before:', 'MY_VAR' in json.dumps(rc['change']['before'] or {}))
    print('  after :', 'MY_VAR' in json.dumps(rc['change']['after'] or {}))
"
```

To ship code and an infrastructure change together, push the image first and then
apply. One rollout carries both, because the apply's deployment pulls the tag you
just pushed.

## Verify what is actually live

**Ask the app, not the platform.** `doctl apps get` reporting `ACTIVE` says a
deployment finished. It does not say which image is running, and the previous
deployment is also `ACTIVE` until the new one replaces it.

```bash
TOKEN=$(grep -E "^health_probe_token" terraform/terraform.tfvars | sed -E 's/.*= *"([^"]*)".*/\1/')
curl -sS -H "X-Probe-Token: $TOKEN" https://loupe.ac/healthz
```

It answers `{"status":"ok","version":"<short sha>"}`. Compare that against
`git rev-parse --short HEAD`. Without the header the endpoint reports liveness
alone and no metadata, which is deliberate.

Do not scrape `/about` for this. It renders the same version behind a session and
an edge cache, so it is both harder to read and less trustworthy.

`/admin/status` runs the `DiagnosticInterface` checks and needs an admin session.
It is where `TrustedProxyCheck` reports, so read it after any change to
`TRUSTED_PROXIES` or to the edge.

## Which deployment is running, and why

```bash
doctl apps list-deployments "$(cd terraform && terraform output -raw app_id)" -o json \
  | python3 -c "
import json,sys
for d in json.load(sys.stdin)[:3]:
    print(d['id'][:8], d['phase'], d['created_at'][:19], d.get('cause',''))
"
```

`cause` is the tell. `app spec updated` came from a `terraform apply` and may be
running old code. `manual` came from `create-deployment`.

## Migrations

`enable_predeploy_migrations = true`, so a pre-deploy job runs
`doctrine:migrations:migrate` before the new containers take traffic. A failed
migration fails the deployment and the previous one keeps serving.

Check whether the release adds any before you deploy:

```bash
git diff --name-only <last deployed sha>..HEAD -- migrations/
```

A release with no migration is a much smaller risk than one with. Say which you
are shipping.

## Trusted proxies

`loupe.ac` sits behind Cloudflare, which sits in front of App Platform's private
ingress. `TRUSTED_PROXIES` must list Cloudflare's ranges, or Symfony stops the
address walk at the ingress and every visitor resolves to a Cloudflare address.

The failure is quiet and it is not what you would test for. The immediate peer is
private and therefore trusted, so `isFromTrustedProxy()` answers true and the
configuration looks right, while `getClientIp()` still returns the edge. Two rate
limiters key on that address, so they collapse to one bucket per edge node.

Terraform prepends `PRIVATE_SUBNETS` to whatever `trusted_proxies` holds, so set
the Cloudflare ranges alone. Refresh them from `cloudflare.com/ips-v4` and
`/ips-v6` when they change. `TrustedProxyCheck` on `/admin/status` is what tells
you the list has gone stale.

## After a deploy

`docs/operating/post-deploy-checks.md` is the operator-facing list. Follow it.

## Symptoms and causes

| Symptom | Cause |
|---|---|
| Deployment `ACTIVE`, `/healthz` reports the old version | A `terraform apply` deployment reused the fixed tag. Run `create-deployment --force-rebuild`. |
| `terraform plan` shows no environment change you know you made | The text output hid it. Read the JSON plan. |
| `/healthz` returns liveness with no version | The `X-Probe-Token` header is missing or wrong. |
| Rate limits fire for unrelated visitors | `TRUSTED_PROXIES` does not cover the edge, so they share one bucket. |
| The image reports a `-dirty` version | It was built from an unclean tree. Commit, rebuild, push. |
