# Next steps

Open work and observations worth revisiting. Delete items entirely once resolved.

## Account-level API tokens page is unreachable from the UI

Loop redesign PR 2, Task 2 rebuilt the app shell and removed the sidebar's
"API tokens" nav link. The `app_api_tokens` route (`/account/api-tokens`) still
exists and works, but nothing in the navigation links to it anymore — it is
reachable only by typing the URL. This is expected on the Loop path: token setup
moves to a per-project "Connect agent" page (PR 4). Until PR 4 ships, decide
whether to keep a temporary link or leave it URL-only, then remove this note.

## `Project` module now depends on the `SiteReview` module (dependency cycle)

Task 2 added `ProjectNavExtension` (Project module) which injects
`SiteReviewCommentRepository` (SiteReview module) for the nav open-count pill.
`SiteReview` already depends on `Project`, so this introduces a `Project ↔ SiteReview`
cycle. `phparkitect.php` is currently an empty stub so CI does not catch it. If
module-boundary rules are ever enabled, this needs redesign (e.g. move the count
into a SiteReview-owned Twig function, or dispatch/read via an event/shared
abstraction) — see the "cross-module needs" patterns in the `project-backend` skill.

## Dashboard document search + status/tag filtering

Deferred from the SaaS visual redesign (visual-only phase). The dashboard mockup
envisioned a search box + status filter + tag filter, but `autosearch_controller`
submits to the server, so making search real needs backend work: a query param on
the documents controller and a repository filter (title contains, status equals,
tag in). Tag filtering further depends on the not-yet-built tag entity. When tags
land, wire search + status + tag filters into the existing `.bp-doc-list` header.

## Port the "no fetch — forms + Turbo" convention to the skeleton

Added a convention to the `project-frontend` skill (Turbo patterns section):
never submit via `fetch()`/JS — always use a plain HTML form or (preferred) a
Symfony form, and rely on Turbo (Streams or a Frame) for the async submit and
in-place update. This same rule must be ported into `~/Code/symfony-skeleton`
(its equivalent frontend skill / docs) so new projects inherit it. See
`.skeleton.json` for the sync baseline.

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

Site-review uses a small `SiteReviewCorsSubscriber` scoped to `^/api/site-review`
(reflects `Origin`, answers preflight before the firewall), mirroring how the MCP
endpoint handles CORS locally. This is intentionally per-endpoint. If we add more
cross-origin API surface, replace these ad-hoc subscribers with a single shared
mechanism — either `nelmio/cors-bundle` or one app-wide CORS subscriber driven by
a path/origin allowlist — so CORS policy lives in one place.

## Port Turbo prefetch convention to the skeleton

Turbo 8 prefetches links on hover, which silently fires the GET behind any
side-effecting link — we hit this with the `logout` link logging users out on
hover. Fixed here by adding `data-turbo-prefetch="false"` to the logout link and
documenting the convention in `.claude/skills/project-frontend/SKILL.md`
("Disable prefetch on side-effecting GET links").

The skeleton has **no logout link**, so there's nothing to fix literally — port
the *convention* instead: copy the SKILL.md note into the skeleton's
project-frontend skill so future consumers know to opt side-effecting GET links
out of prefetch. Open a PR against the skeleton (`main`), then update
`.skeleton.json`.

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

## Site-review: harden Mercure publish against a hung hub

`SubmitReviewHandler::publish()` makes a synchronous HTTP call to the Mercure hub
after `flush()`. Failures are caught and swallowed (best-effort, by design), but
`symfony/mercure-bundle` exposes no per-hub `http_client` config option, so the
call uses the default HttpClient timeout — a hung hub could add latency to every
review submit before the catch fires. Wire the default hub to a scoped HttpClient
with a low `timeout`/`max_duration` (custom hub service or a decorated
HttpClient) so a slow hub can never noticeably delay a review submit.

## Site-review: double-mint race can orphan a token

`MintSiteTokenHandler` rejects minting when `site->token` is already set, but two
concurrent mints can both pass that check before either flushes — one of the two
`SiteReview`-scoped tokens ends up attached to no site (it authenticates but
cannot submit). No DB-level guard exists. Low stakes (single-owner action); if it
ever bites, add a unique constraint or lock the site row during mint.

## Site-review widget: send during an in-flight delete

`send()` doesn't check `state.deleting` — a Send clicked while a delete is
in flight could submit a review that still contains the being-deleted comment.
Minor for a single-reviewer tool; track only.

## Site-review widget: surface per-comment save errors more granularly

All widget API failures render into the single `#bp-error` banner. Fine for a
one-reviewer tool; if bulk operations ever appear, attach errors to the affected
list row instead.

## e2e tsconfig triggers TS5107 under bare tsc

`e2e/tsconfig.json` uses `moduleResolution: node` (node10), deprecated in
TypeScript 5.x — a bare `npx tsc --noEmit` in `e2e/` fails with TS5107. Nothing
in the gates runs bare tsc today (Playwright transpiles specs itself), so this is
latent. Modernize the tsconfig (`module`/`moduleResolution` `nodenext`, or
`bundler`) when convenient.

## Site-review bridge: set a lifetime on subscriber JWTs

`StreamCredentialsController` mints subscriber JWTs via the Lcobucci factory with
no `exp` claim (the bundle's `jwt_lifetime` is unset), so stream tokens are
effectively long-lived. Once the bridge CLI handles re-fetching creds on expiry,
set a finite `jwt_lifetime` (or pass an `exp` claim) so a leaked subscriber token
is not valid forever.

## Site-review bridge CLI (`cli/`): polish before shipping

The Go bridge is functional (`betterplans login` + `betterplans bridge run
--site <name>`, with an interactive picker when the flag is omitted). Remaining
work before it's a turnkey distributable:

- **Re-fetch stream creds on auth failure.** `bridge run` fetches the subscriber
  JWT once and reuses it across reconnects. Harmless while JWTs have no `exp`, but
  once a lifetime is set (see above) the bridge must re-call
  `/api/site-review/stream?site=…` on a `401` and resubscribe.
- **No-echo token prompt.** `login` reads the token from stdin with the terminal
  still echoing. Use `golang.org/x/term` (or equivalent) to read without echo.
- **OS keychain storage.** The token is stored in `~/.config/betterplans/config.json`
  (mode 0600). Move it to the OS keychain (e.g. `go-keyring`) with the file as a fallback.
- **CI + release.** Wire `just cli-test` into the gate, and add goreleaser for a
  multi-platform release matrix (current `just cli-build` only cross-compiles one target).

## Mint handlers are check-then-set without locking

`MintProjectWidgetTokenHandler` and `MintProjectMcpTokenHandler` both guard
with `null !== $project->xxxToken` and then persist — two concurrent mints can
both pass the guard, and the loser's token ends up unbound (an account-level
token whose raw value was flashed to a user). Impact is low today: an unbound
token resolves no project, so project-scoped consumers reject it. Revisit with
a unique constraint or `SELECT … FOR UPDATE` if project-bound tokens multiply.

## Widget-token mint flow still uses site-era CSRF id and translation keys

`MintProjectWidgetTokenController` keeps CSRF token id `mint-site-token` and
the `site_review.site.token.*` translation-key family (template ↔ controller
pairs are consistent). Rename both to `project.*` when the Connect page (Loop
redesign PR 4) takes over the token UI.

## E2E specs still reference the pre-/projects URL space

The route restructure (Loop redesign PR 1, Task 4) moved the web URL space
under `/projects/...` and changed the post-login landing (HomeController now
redirects to `app_projects` or `app_project_documents`, never `/documents`).
Several Playwright specs still hardcode the old paths and will fail the PR-gate
`just e2e` until updated:

- `e2e/tests/account/{login,email-verification,forgot-password}.spec.ts` — assert
  `toHaveURL('/documents')` after auth; new landing is `/projects` (0 projects)
  or `/projects/{id}/documents` (exactly 1).
- `e2e/tests/review/review-loop.spec.ts` — navigates to `/documents` and builds
  `reviewUrl` as `/documents/${id}/review`; the review URL now needs the project
  id (`/projects/{projectId}/documents/{documentId}/review`). The `/dev/seed/document`
  endpoint may need to return the project id for the spec to build the URL.
- `e2e/tests/site-review/site-page.spec.ts` — uses `/documents` and
  `/site-review/sites`; now `/projects` and `/projects/{id}/site-review`.

Task 4's commit scope deliberately excluded `e2e/`; fold these fixes into the
task/step that runs `just e2e` before opening the PR.
