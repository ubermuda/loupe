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

- `83a3e5e` (the `feat/review-decision-controls` branch; exhaustive list via
  `git log c00bba0..fbb670f`) — **Added:** reviewer-selectable decision controls.
  An author wraps a flat list in an HTML-comment fence
  (`<!-- decision: some-id -->` … `<!-- /decision -->`) and the reviewer gets
  clickable options instead of having to write "option 2" in a comment; the
  choice persists in `decision_selections`, survives revisions (keyed to the
  document and decision id, never to a version or the quoted text) and is
  reported through `document_get_review`. HTML comments were chosen as the fence
  so a document read outside Loupe still shows the plain list it already is.
  Three behaviours are deliberate: answering from a page revised underneath you
  is **refused** rather than resolved against the newer version, because
  resolving would record an answer the reviewer never gave; a failed save
  restores the control from the database, which costs any live comment highlight
  anchored inside that block until reload, rather than letting the browser be
  the authority on what is stored; and selecting an option does **not** resolve
  an attached comment thread, since choosing and acting are different events.
  `loupe-documents` gains rule 11 for the syntax.
- `f7e32df` (the `feat/review-front-matter-and-comments` branch; exhaustive list
  via `git log 39aa6a3..83f5103`) — **Added:** YAML front matter renders as a
  table (dates as dates, not Unix timestamps) and standalone HTML comments render
  as visible annotations rather than vanishing. **Changed:**
  `app:review:rerender-versions` now **refuses** to run when the re-render would
  leave any comment unable to resolve, reporting the count and writing nothing,
  unless `--accept-comment-orphaning` is passed — a re-render moves
  `plainText()`, which is the basis comment anchors resolve against, and the
  previous behaviour stranded comments while leaving them marked healthy. A
  comment inside a block-level raw HTML region is still dropped; the obvious fix
  is unsafe (markers would land inside attribute values) and is tracked instead.
- `39aa6a3` (the `feat/review-version-diff` branch; exhaustive list via
  `git log aa47989..9c8f2ca`) — **Added:** a word-level diff between two document
  versions, on its own route and controller, bounded by both a line cap and a
  word-work cap (either alone lets a pathological shape through) and refusing
  oversized input rather than hanging. The contents panel and all comment
  anchoring are suppressed in diff mode: the diff pane shows Markdown source, so
  heading targets do not exist and anchoring cannot apply.
- `aa47989` (the `fix/flaky-search-debounce-spec` branch; exhaustive list via
  `git log 0d8e47e..8485000`) — **Fixed:** the documents-search e2e spec waited a
  fixed 600 ms against a 300 ms debounce, leaving ~300 ms for a full dev-mode
  round trip. Measured, the gap is 476–573 ms idle and over a second under load.
  It now waits on the search visit having actually landed. The old form was worse
  than flaky: when the sleep elapsed *before* the visit, the input was never
  replaced and the assertion proved nothing.
- `0d8e47e` (the `fix/site-review-get-existence-oracle` branch; exhaustive list
  via `git log 4d6ce01..5be4b4a`) — **Fixed:** `site_review_get` answered a
  missing site and an inaccessible one with different messages, telling a caller
  which site names exist. Both now return one message, matching
  `ReviewSubjectResolver`. An unbound token still reports distinctly, on purpose
  — that is a fact about the caller's own credential, not an oracle.
- `6b3f55f` (the `feat/document-search` branch; exhaustive list via
  `git log 2d251f4..eabd5e9`) — **Added:** Postgres full-text search over
  documents with a filter bar on the list; title outranks body, and results stay
  linkable through the address bar. **Changed:** three hand-rolled Doctrine
  classes replaced by `martin-georgiev/postgresql-for-doctrine`, which composes
  where ours baked the `english` configuration into emitted SQL — accepted cost:
  the package caps at `php: <8.6`, so a PHP 8.6 upgrade waits on it.
  **Fixed:** a focus bug where the debounced search replaced the page body
  mid-typing, dropping keystrokes; and an N+1 in data export.
- `c68224e` (the `feat/document-tags` branch; exhaustive list via
  `git log f7773ef..b3426b3`) — **Added:** project-scoped tags on documents, with
  `document_set_tags` and `tag_list` MCP tools and a normalisation rule that
  collapses interior whitespace so `design  spec` and `design spec` cannot become
  two rows. **Fixed:** tag names are validated before the document is
  constructed, so a rejected name cannot leave a document scheduled in the unit
  of work for someone else's flush.
- `f7773ef` (the `fix/php-fpm-pool-limits` branch; exhaustive list via
  `git log 2128c28..41dd499`) — **Fixed:** the dev php-fpm pool served every git
  worktree plus all Playwright traffic at `pm.max_children = 20` and sat *at* that
  ceiling during e2e runs. A request that gets no worker returns nothing — no
  body, no fatal, nothing logged — which is the same signature as a cold cache
  and was misdiagnosed as one for a day. Now 32, with a higher spawn floor; the
  full suite went from ~8.5 minutes to ~3.6.
- `36e3806` (the `feat/review-agent-highlights` branch; exhaustive list via
  `git log c9ccac4..22e5e2e`) — **Added:** an agent can mark passages of a
  document, rendered as a distinct highlight rung to draw the reviewer's
  attention. Highlights belong to the version they were written for and do not
  carry forward. The wavy underline is load-bearing rather than decorative: the
  background tint is near-indistinguishable from a pending comment's under
  tritanopia.
- `c9ccac4` (the `feat/document-references` branch; exhaustive list via
  `git log c06226c..55fadd5`) — **Added:** one document can reference another,
  shown on both sides. **Fixed:** `nullable: false` on many-to-many join columns
  is a no-op that Doctrine logs on every mapping read — roughly a thousand
  serialized exceptions per run, invisible to `just ci`, and a hard error in
  Doctrine 4.
- `c06226c` (the `feature/strike-and-suggest` branch; exhaustive list via
  `git log c5adc21..70d68c2`) — **Added:** strike and suggest as first-class
  review actions, distinct from leaving a comment rather than replacements for
  it. A strike is one gesture (`s` on a selection, no composer); guards cover
  keyboard auto-repeat, double-tap and a selection that has gone stale.
- `c5adc21` (the `feat/review-sanitizer-and-toc` branch; exhaustive list via
  `git log bdbc23d..6932d2f`) — **Changed:** the Markdown sanitizer rewritten to
  an explicit per-element allowlist, with `class` on `<code>` restricted to
  `language-*` — an unrestricted `class` let document content select the app's
  own stylesheet rules and paint a full-screen phishing overlay, which no
  Content-Security-Policy would have prevented. **Added:** a table-of-contents
  panel driven by heading ids, deriving labels from image `alt` text so an
  illustrated heading is still navigable. Documents stored before this shipped
  have no heading ids and so no contents panel; that is deliberate and tracked.
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
