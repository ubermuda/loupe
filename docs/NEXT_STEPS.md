# Next steps

Open work and observations worth revisiting. Delete items entirely once resolved.

## Review anchoring — possible enhancement (low priority)

Observed while dogfooding the review loop on the site-review spec: revising a
document re-anchors open comments by matching their quoted text, so comments on
a region that gets rewritten come back `orphaned`. This is **expected** and
matches GitHub's "outdated" review comments — not a bug.

Possible future improvement, only if orphaning proves annoying in practice: add
a secondary **structural anchor** (e.g. nearest heading path + relative offset)
alongside the existing quote/prefix/suffix text anchor, and fall back to it when
the text match fails. Would let a comment survive a rewrite of its surrounding
prose by re-attaching to the same section. Not worth doing pre-emptively.

Minor/cosmetic: the `quote` returned by `get_review` uses arbitrary character
boundaries (mid-word, mid-sentence), which makes mapping a comment back to its
section take some inference. Snapping quote boundaries to word/line edges would
read more cleanly. Cosmetic only.

## Make `revise_document` surface real errors instead of generic `-32603`

When a tool handler throws (e.g. a DB exception), the MCP layer flattens it to
`-32603 Error while executing tool` with no detail, so clients can't tell a
validation problem from a server fault. Consider mapping known failures to
`ToolCallException` with a useful message.

## Anchor offset-unit mismatch (latent)

`AnchorService` now slices UTF-8-safely (`mb_strcut`), but offsets are still
byte-based (`strpos`/`strlen`/`offsetHint`) while the frontend computes selection
offsets in JS string units (UTF-16 code units). For documents with multibyte
characters this can mis-place an anchor by a few positions even though it no
longer crashes. If anchors drift on real content, reconcile the offset unit
end-to-end (characters throughout, or bytes throughout).

## Host PHPUnit can't reach Postgres through Traefik

Running `php vendor/bin/phpunit` on the host fails at bootstrap (drop/create
schema): the DB is only reachable via Traefik's TCP `HostSNI` router on the
`postgres` entrypoint (`compose.yaml`; no direct `ports:` mapping). PHP's
`pdo_pgsql`/OpenSSL can't complete that handshake — `sslmode=require|prefer` →
`tlsv1 alert no application protocol` (ALPN mismatch), `sslmode=disable|allow` →
timeout (no TLS → SNI can't be read). Both `127.0.0.1` and
`db.betterplans.dev.localhost` fail. Tests only run via the container
(`docker compose exec -T php-fpm php vendor/bin/phpunit`), which connects to
`database:5432` directly.

Want host phpunit to work. Likely fixes to evaluate: (a) publish the Postgres
port directly on the host (`ports: ["5432:5432"]` on the `database` service) so
host clients bypass Traefik; or (b) adjust the Traefik `postgres` entrypoint so
its TLS layer negotiates the ALPN libpq offers; or (c) document a working
`.env.test.local` DSN. Until then, plan task `verifyCommand`s use the container
form.

## Generalize CORS handling if the API surface grows

Site-review uses a small `SiteReviewCorsSubscriber` scoped to `^/site-review/api`
(reflects `Origin`, answers preflight before the firewall), mirroring how the MCP
endpoint handles CORS locally. This is intentionally per-endpoint. If we add more
cross-origin API surface, replace these ad-hoc subscribers with a single shared
mechanism — either `nelmio/cors-bundle` or one app-wide CORS subscriber driven by
a path/origin allowlist — so CORS policy lives in one place.

## Port php-cs-fixer `ignoreVCSIgnored` fix to the skeleton

`.php-cs-fixer.dist.php` used `->in(__DIR__)->exclude('var')`, so the
Finder descended into `vendor/` (and `node_modules/` once root npm deps
arrived), rewriting third-party PHP. Fixed here by switching to
`->ignoreVCSIgnored(true)` (honours `.gitignore`), plus `--exclude
node_modules` on `parallel-lint`. This is a generic tooling fix that
belongs in the skeleton too — open a PR against the symfony-skeleton repo
(`main`) porting both changes, then update `.skeleton.json`.

## New `/api` routes must carry their own scope access_control rule

The `api` firewall matches all of `^/api`, but `access_control` only guards
`^/api/site-review` (→ `ROLE_API_SITE_REVIEW`). Every API-token user also carries
`ROLE_USER` (via `User::getRoles()`), so a hypothetical future `^/api/<other>`
route would fall through to the catch-all `^/ → ROLE_USER` and accept ANY scoped
token — defeating scope enforcement. No live exposure today (no other `/api/*`
route exists). When adding a new `/api` endpoint, add a matching scope rule above
the catch-all (do not narrow the firewall pattern — stray `/api` traffic would
otherwise hit the stateful form-login firewall). Consider a deny-by-default
`^/api` rule once more than one scope exists.

## Revisit: migrate API auth to Symfony's `access_token` authenticator

We hand-roll `ApiTokenAuthenticator` (custom `AbstractAuthenticator`). Symfony's
built-in `access_token` firewall + an `AccessTokenHandler` is the more idiomatic
mechanism. Deferred during the site-review work — decided to extend the custom
authenticator for now and revisit later. Note: `access_token` has **no** native
scope→role mapping (verified against current Symfony docs), so the migration is
a modernization, not a scope win; per-token scope roles are slightly more
awkward there (you don't own `createToken()`), so weigh that when revisiting.
