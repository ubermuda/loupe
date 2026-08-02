# Changelog

All notable changes to this project are documented in this file.

The format is loosely based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
The skeleton is an unversioned template, so there are no release tags — all
entries live under **Unreleased**.

Entries are listed **newest first** and each is anchored to the commit SHA(s)
that introduced it, so the list mirrors `git log` order. To see what changed
between two commits, find the two SHAs in the list and read every entry between
them (the newer one at the top, down to — but excluding — the older one). The
SHAs are the source of truth: for an exhaustive diff, run
`git log --oneline <older>..<newer>` and cross-check, rather than trusting this
file alone. Each entry is tagged `Added` / `Changed` / `Removed` / `Fixed`.

## [Unreleased]

- `1b7758f` … (the `feat/mcp-scoped-authz` branch, branched from `a825b59`;
  exhaustive list via `git log a825b59..feat/mcp-scoped-authz`) — **Changed:** every
  MCP tool renamed with a feature prefix, with no aliases and no deprecation
  window (breaking MCP change): `create_document` → `document_create`,
  `get_document` → `document_get`, `list_documents` → `document_list`,
  `revise_document` → `document_revise`, `get_review` → `document_get_review`,
  `get_site_review` → `site_review_get`, `address_site_review_comments` →
  `site_review_mark_comment_addressed`. A connected agent sees its tool names
  change on the next handshake; update any prompt, skill, or script that names
  them. Also **Changed:** tool access is now scoped by `McpBoundProjectVoter`
  (the token's bound project, not the user's ownership) applied through
  `ReviewSubjectResolver`, and unrecognised failures are reported with a real
  message instead of a bare `-32603`.
- `6eb95f6` … `690bc04` (the `site-review-per-site` branch; exhaustive list via
  `git log 4e7b5b0..690bc04`) — **Changed:** site review rebuilt from ephemeral
  batches to a persistent per-site model: a `Site` entity with site-bound widget
  tokens; comments save immediately into an in-progress `SiteReview` and an
  explicit "Send the review" submits it (comment ladder
  `pending → addressed → resolved`; the agent can only address, humans
  resolve/reopen on the site page); the widget is server-backed (no more
  localStorage batch); Mercure publishes per-site topics with per-site
  stream-credential and sites-list endpoints; MCP tools `get_site_review` /
  `address_site_review_comments` replace the batch fetch (breaking MCP change);
  the bridge CLI binds to one site (`bridge run --site`, interactive picker
  when omitted). Batch-era entities, endpoints, and data are dropped.
- `7618557` — **Removed:** generic slash commands (`port-to-skeleton`,
  `pr-feedback`, `retro`) promoted out of the template to user-level
  `~/.claude/commands`, shared across all skeleton-derived projects rather than
  duplicated per-repo.
- `496d168` — **Changed:** consume the extracted `ubermuda/doctrine-extra` and
  `ubermuda/symfony-extra` packages instead of in-tree helpers.
- `90443f5` — **Changed:** whole-tree test discovery in `phpunit.dist.xml`, so new
  test directories are picked up without config edits.
- `fc342f6`, `24ee28c` — **Changed:** prettier no longer formats
  `assets/controllers.json`; the file was normalised to 2-space indentation.
- `328ff99` — **Added:** optional infrastructure presets — a worktree-aware CI
  harness, an in-memory test messenger transport, and an encrypted Doctrine
  column type (all opt-in).
- `a2810d3` — **Added:** command-handler, authorization, and entity-route-mapping
  skills under `.claude/skills/`, plus the dotted `resource.action` authorization
  naming convention.
- `8d3d2d2` — **Added:** declarative `#[CsrfToken]` attribute for hand-rolled
  forms, plus Monolog stack traces in the logging stack.
- `f24bcd9`, `8edeb0d`, `b6d3e6f` — **Changed:** custom static analysis moved to
  the `ubermuda/gamache` package (PHPStan/Rector/TwigCsFixer rules and `Check`
  classes), consumed as a `dev-main` dependency instead of living inline.
- `cbd83ad` — **Added:** Mercure SSE documentation section and the Symfony UX
  Icons import conventions.
- `7064a94`, `fdc3c2a`, `b4f5d42` — **Removed:** legacy `bin/check-*` and
  `bin/gamache` scripts, superseded by gamache's `Check` classes and the
  package-provided `vendor/bin/gamache`.
- `5fd43be` — **Added:** Dockerised dev environment wired so `just ci` and
  `just e2e` run inside the container.
- `c57e7c8` — **Added:** PHPArkitect architecture tests and the initial set of
  custom CI checks.
- `c33efd8` — **Added:** initial skeleton — base Symfony application, tooling, and
  `.claude/` conventions.
