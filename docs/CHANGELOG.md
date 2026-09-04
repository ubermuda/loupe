---
title: "Changelog"
description: "Notable changes, newest first."
---

All notable changes to this project are documented in this file.

The format is loosely based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Loupe carries no release tags yet, so all entries live under **Unreleased**.

Entries are listed **newest first** and each is anchored to the commit SHA(s)
that introduced it, so the list mirrors `git log` order. To see what changed
between two commits, find the two SHAs in the list and read every entry between
them (the newer one at the top, down to — but excluding — the older one). The
SHAs are the source of truth: for an exhaustive diff, run
`git log --oneline <older>..<newer>` and cross-check, rather than trusting this
file alone. Each entry is tagged `Added` / `Changed` / `Removed` / `Fixed`.

**Granularity: one entry per merged pull request, one line each.** A branch that
shipped six features gets six entries, not one entry covering the branch — a
reader looking for when tags arrived should find a line about tags, not a
paragraph about the wave that contained them. Each entry is a single sentence
stating what changed from the reader's side; the reasoning behind a change
belongs in the PR body and the commit message, which the SHA and the PR number
both point at. Anchor to the first-parent commit that landed the work on `main`
(what `git log --first-parent` shows), so this list and the log walk the same
history, and name the PR after it. Work that never surfaces in the product or
the development workflow — tracker churn in `docs/NEXT_STEPS.md` — gets no
entry.

## [Unreleased]

- `2091a0c` (#346) — **Changed:** the `working-with-prs` skill now says what a
  Codex pass is worth and when to stop asking for one.
- `f03f345` (#333) — **Added:** a document is stemmed for search in its own
  language, set per document or inherited from the project, so a French document
  matches a French query rather than an English stemmer's guess.
- `e275bb2` (#336) — **Added:** a decision block can take more than one answer,
  and the status line names the option a reviewer cleared as well as one they
  chose.
- `c795eff` (#340) — **Changed:** an MCP tool reports every rejected argument at
  once, rather than the first one it reached.
- `f8a26b6` (#337) — **Added:** a reviewer can leave a comment while looking at
  a diff, anchored to the passage the diff shows.
- `8ceae0f` (#334) — **Added:** documents can say they belong to an ordered
  series, filtered and read in their own order rather than newest first, with
  `document_set_series` and `series_rename` to place and rename them.
- `60ec749` (#331) — **Changed:** the decision status line names the option and
  the version an answer was recorded against, taken from what was stored rather
  than what was submitted.
- `7000cb0` (#330) — **Changed:** the front-matter table and the rendered
  annotations carry names, so a screen reader announces what they are.
- `aaec685` (#347) — **Fixed:** the development image installs xdebug from its
  tarball rather than by package name, so a build no longer queries the PECL
  index that was intermittently answering "No releases available" and failing
  four CI jobs at once.
- `3aa3bcd` (#329) — **Changed:** the document documentation warns that an
  annotation wrapped in an HTML element is dropped without a warning.
- `46fe5e6` (#344) — **Changed:** the `working-with-prs` skill puts the preview
  links at the top of a pull request body.
- `6cef3f9` (#345) — **Fixed:** the development image pins the xdebug version it
  installs, so two builds a month apart no longer install different ones.
- `1183917` (#325) — **Changed:** the audit trail now comes from the
  `ubermuda/audit-bundle` package. The `/admin/audit-log` screen and the
  `audit:purge` command work as before; the retention window moves from
  `app.audit.retention_days` in `config/services.yaml` to `retention_days` in
  `config/packages/ubermuda_audit.yaml`, which also carries the sweep's cron
  expression.
- `0406b9c` (#209) — **Fixed:** the admin area loads the application stylesheet
  again; it extends the bundle's layout rather than `layout_base.html.twig`, so
  it lost its only source of CSS when `assets/app.js` stopped importing the
  sheet.
- `167f3c9` (#208) — **Added:** `/healthz` reports the running build to a caller
  presenting `HEALTH_PROBE_TOKEN` as an `X-Probe-Token` header, so a post-deploy
  check can prove which image is live; unset, it reports nothing.
- `77bc23c` (#207) — **Changed:** the production and demo images no longer ship
  the marketing site or the Tailwind CLI, taking them from 1.13 GB to 105 MB and
  from 1.16 GB to 505 MB.
- `47c67f5` (#206) — **Fixed:** marking a comment addressed no longer overwrites
  a human's Resolve, the documents list counts matching documents rather than
  rows on the current page, and the `project-backend` skill stops documenting a
  `getLogger()` helper that does not exist.
- `2f30fd2` (#205) — **Changed:** the landing page moves into its own module,
  and each module seeds its own feature flags.
- `fdefb96` (#204) — **Changed:** each module owns its own counts, tokens and
  gates rather than sharing one surface.
- `87fc6bb` (#203) — **Changed:** the list pages batch their queries, and an
  account export stops buffering every payload at once.
- `7d788fa` (#202) — **Changed:** the content security policy blocks rather
  than merely reporting, and responses carry HSTS; `assets/app.js` no longer
  imports the stylesheet, which becomes a plain `<link>`.
- `d077eff` (#201) — **Added:** the gate audits the npm dependency trees, and
  the site-review widget ships minified.
- `2de6a00` (#200) — **Fixed:** the spacing of the type scale, and the landing
  page's document head.
- `4b8a339` (#199) — **Fixed:** five small correctness gaps found by the
  audit.
- `b8c0563` (#198) — **Fixed:** the site-review bridge CLI no longer stalls
  silently or acts on stray events.
- `80ccd47` (#197) — **Changed:** the system status page moves into its own
  Diagnostics module.
- `d749322` (#196) — **Fixed:** an incomplete subscription no longer disables
  an account that is still trialling.
- `9377355` (#195) — **Fixed:** review writes require a write permission.
- `11612ae` (#194) — **Fixed:** a 500 on the display name "0", widget
  robustness, a CSRF gap and colour contrast.
- `4a656f8` (#193) — **Fixed:** the bridge's SSE resume point survives a
  connection dropped mid-event.
- `53797e9` (#192) — **Changed:** messages dispatched under Playwright are
  handled inline, so the e2e suite needs no consumer.
- `9021216` (#190) — **Fixed:** the authentication pages render outside the
  signed-in application shell.
- `aa76637` (#189) — **Changed:** the Terraform configuration pins the shared
  module at v2.1.0.
- `f35b3fd` (#188) — **Added:** predeploy migrations can be switched on and
  off with a Terraform variable.
- `18070c3` (#187) — **Added:** Terraform configuration for a first
  DigitalOcean deploy.
- `8f5b08e` (#185) — **Added:** two site-review skills, and a correction to
  the site-review user documentation.
- `ab352e0` (#184) — **Changed:** the site-review widget saves comments as
  they are written, so there is no send step.
- `8de0aaf` (#178) — **Fixed:** the landing page scrolls to a section instead
  of jumping to it.
- `db02e7d` (#177) — **Added:** the site-review widget loads on public pages
  as well as authenticated ones.
- `ee0bad6` (#176) — **Added:** a marketing landing page at `/`.
- `f3f6a85` (#175) — **Changed:** `document_highlight` sits behind the
  `review.highlights.enabled` flag, seeded off.
- `97a1ecd` (#174) — **Added:** a Starlight site that renders `docs/` in
  place.
- `b8981fc` (#173) — **Changed:** the documentation is restructured, and non-
  development configuration moves out of the repository root.
- `90a73ce` (#169) — **Added:** `composer audit` runs as part of the CI gate.
- `31f9280` (#172) — **Added:** a single-container demo image, published to
  GHCR.
- `57be275` (#170) — **Added:** an About page, branded error pages and a real
  build version, alongside install-wizard polish.
- `0b44e2a` (#167) — **Fixed:** `league/commonmark` moves to 2.9.0, closing
  six published advisories.
- `bd03266` (#166) — **Changed:** the comment budget fails the build instead
  of advising.
- `dae4ba1` (#165) — **Fixed:** a third party's first install works, from the
  self-hosting audit.
- `a752adc` (#161) — **Changed:** the over-budget comment blocks reported by
  the comment budget are cut.
- `6d2f003` (#164) — **Fixed:** the minor items the review loop left open.
- `4387161` (#163) — **Changed:** the signed-in application is redesigned.
- `6624b79` (#162) — **Changed:** gamache is repointed at its Flex-marker fix.
- `e384254` (#159) — **Added:** comment length is measured, and handlers are
  read through everywhere.
- `99c6f21` (#154) — **Changed:** sessions, the application cache and rate-
  limit counters move to Postgres.
- `d50f3ed` (#158) — **Changed:** the site-review Mercure push sits behind a
  flag with an environment prerequisite.
- `24c9d8d` (#160) — **Changed:** the Mercure hub is opt-in.
- `b7c9f2a` (#156) — **Added:** an opt-in MinIO container, with the export
  storage exercised against it.
- `0e45ade` (#155) — **Changed:** the inline importmap scripts carry a nonce,
  ahead of enforcing the policy.
- `5a2b8b2` (#153) — **Added:** backup guidance, the torn-down-worktree write
  hazard, and the MCP findings.
- `0b2acd6` (#152) — **Fixed:** nine easy tracker items.
- `dc62be7` (#151) — **Fixed:** `guzzlehttp/guzzle` moves to 7.15.2.
- `1fa8b42` (#150) — **Changed:** the changelog is rewritten at one entry per
  pull request.
- `455648b` (#138) — **Fixed:** six small tracker items — phpstan's memory
  limit, MCP array parameter schemas, a `DisplayLabel` rename, the tsconfig, and
  the widget's send guard.
- `c80aa85` (#141) — **Added:** `document_get` reports a document's tags and the
  documents that reference it.
- `599339e` (#145) — **Added:** browser coverage for the decision controls,
  asserting the anchors that were actually stored.
- `04bc2a6` (#149) — **Changed:** `just e2e` refuses to run without an explicit
  target rather than defaulting to one.
- `647c21d` (#144) — **Added:** the agent account is created during install and
  shown on the system status page.
- `cc0aff1` (#140) — **Fixed:** pending verification links are revoked when an
  account is linked socially, waitlist idioms are deduplicated, and the Mercure
  publish is bounded.
- `f025820` (#139) — **Fixed:** the data export fetch-joins its projects instead
  of issuing a query per row, and the regenerate-token handlers take a lock.
- `3bb26d8` (#146) — **Removed:** the site-era widget-token names, and the
  deprecations this codebase owns.
- `31c2fc9` (#148) — **Changed:** a display name is mandatory, suggested from
  the email address.
- `758cde0` (#143) — **Added:** a user can edit their display name.
- `f6ed975` (#142) — **Changed:** `just e2e` refuses to run without a consumer,
  and keeps the worker alive for the duration of the suite.
- `421c484` (#137) — **Added:** archiving a document takes a reason, required
  through MCP and optional in the app; a blank reason is rejected and the
  archive guard runs under a row lock.
- `435f128` (#136) — **Added:** `document_set_references` replaces a document's
  references without minting a version.
- `bf34403` (#134) — **Added:** a manual test plan for the review wave.
- `83a3e5e` (#133) — **Added:** reviewer-selectable decision controls — an
  author fences a list in `<!-- decision: id -->` and the reviewer clicks an
  option instead of describing it in a comment.
- `f7e32df` (#129) — **Added:** YAML front matter renders as a table and
  standalone HTML comments render as visible annotations instead of vanishing.
- `39aa6a3` (#126) — **Added:** a word-level diff between two document versions.
- `aa47989` (#132) — **Fixed:** the documents-search e2e spec waits for the
  search visit to land instead of sleeping past the debounce.
- `0d8e47e` (#131) — **Fixed:** `site_review_get` answers a missing site and an
  inaccessible one identically, so it no longer reveals which sites exist.
- `6b3f55f` (#124) — **Added:** full-text search and a filter bar on the
  documents list.
- `c68224e` (#123) — **Added:** project-scoped tags on documents, with
  `document_set_tags` and `tag_list`.
- `f7773ef` (#130) — **Fixed:** the dev php-fpm pool raised, so worktree and
  Playwright traffic stops starving requests of a worker.
- `36e3806` (#128) — **Added:** an agent can highlight passages of a document.
- `c9ccac4` (#122) — **Added:** one document can reference another, shown on
  both sides.
- `c06226c` (#127) — **Added:** strike and suggest as first-class review
  actions, distinct from leaving a comment.
- `c5adc21` (#125) — **Changed:** the Markdown sanitizer rewritten to an
  explicit per-element allowlist. **Added:** a table-of-contents panel driven by
  heading ids.
- `7ceb9ed` (#118) — **Added:** documents can be renamed and archived, and each
  version records what changed in it.
- `7ad8c57` (#120) — **Added:** an agent can reply to and address comments in a
  document review.
- `333d02f` (#119) — **Added:** a comment thread carries a status, held by its
  root comment.
- `0602470` (#117) — **Fixed:** comment anchoring uses character-unit windows,
  word-edge quotes, and untrimmed captured context.
- `dce09f9` (#116) — **Changed:** every MCP tool renamed with a feature prefix
  (`create_document` → `document_create` and so on, with no aliases — a
  breaking MCP change), tool access scoped to the token's bound project, and
  unrecognised failures reported with a real message instead of a bare `-32603`.
- `7ac6fc9` (#114) — **Changed:** dev logs buffered behind `fingers_crossed`.
- `cbcc9b0` (#112) — **Changed:** the `DeleteAccountHandler` docblock corrected
  on its coupling to Billing.
- `a3c6b2a` (#111) — **Removed:** stale DaisyUI references from `CLAUDE.md` and
  the `project-e2e` skill.
- `d399c97` (#109) — **Fixed:** Turbo no longer prefetches the system status
  page on hover.
- `d50d850` (#108) — **Changed:** documentation-only branches skip e2e and the
  Codex review in the pre-PR gate.
- `83d0985` (#107) — **Changed:** `DEPLOY.md` is the single home for deployment
  documentation.
- `659576d` (#106) — **Added:** a reference production stack, with deploy
  tooling unbound from the author's own accounts.
- `7c9c1f8` (#101) — **Added:** the AGPL source is offered from the UI, as the
  licence requires.
- `546102c` (#103) — **Added:** the site-review outbox drains on a schedule and
  surfaces what is stuck.
- `efee5eb` (#100) — **Added:** recovery console commands and registration
  gating for a self-hosted instance.
- `3174b05` (#105) — **Added:** a health endpoint and a system status page.
- `dcd660c` (#102) — **Changed:** data-export archives moved to object storage.
- `9fbdaca` (#104) — **Changed:** the configuration surface reworked to be the
  operator's rather than the author's.
- `2a14104` (#99) — **Fixed:** a batch of one-line self-hosting corrections.
- `6ca2a63` (#97) — **Added:** e2e runs against its own disposable target
  instead of borrowing a worktree.
- `eb0f7d7` (#96) — **Changed:** `just worktree-up` takes a NAME, so nothing has
  to `cd` into a worktree to provision one.
- `59c1834` (#95) — **Changed:** icon SVGs are committed and no longer fetched
  from Iconify at runtime in production.
- `ca06a80` (#94) — **Changed:** Loupe documents must use stable IDs for
  cross-references.
- `eaa0d1c` (#92) — **Changed:** the worktree cwd rule reconciled, and the
  dnsmasq certificate failure recorded.
- `1b31251` (#91) — **Added:** ⌘⏎ submits a document review comment.
- `78c284e` (#89) — **Changed:** the main session must never move into a
  worktree.
- `cc6eac6` (#88) — **Changed:** the site-review feedback on the app shell, the
  widget and the pages addressed.
- `ac016f5` (#87) — **Changed:** `/api` is deny-by-default, widget agent
  forwarding is opt-in, and review rendering is per-version.
- `0bff96c` (#86) — **Changed:** `docs/NEXT_STEPS.md` is committed instead of
  gitignored.
- `34f10c0` (#85) — **Added:** the messenger worker enabled in infrastructure,
  with the app's environment wired to it.
- `0af639e` (#84) — **Removed:** the site-review `Review` entity; comments carry
  their own draft state.
- `04d9896` (#82) — **Added:** the wave-2 conventions in `CLAUDE.md` and the
  skills.
- `e8e0cf5` (#81) — **Changed:** every value in `app.css` put on the Tailwind
  scale.
- `865f5e3` (#80) — **Changed:** controllers renamed to the action verbs the
  convention requires.
- `3214b66` (#79) — **Added:** pagination on the projects and documents lists.
- `db995b0` (#78) — **Changed:** the Review templates namespaced and the Twig
  components relocated.
- `c139963` (#76) — **Changed:** arbitrary CSS values converted, and the
  site-review class names spelled out.
- `a20f5e6` (#73) — **Changed:** current-user narrowing enforced explicitly,
  since `assert()` drops it in production.
- `4208782` (#75) — **Fixed:** emailed link hosts pinned, the install wizard
  gated, and the revision race closed.
- `cbec5e5` (#77) — **Added:** site-review events persisted in a transactional
  outbox.
- `0cab887` (#74) — **Changed:** `symfony/mcp-bundle` upgraded to 0.12 and the
  custom endpoint controller retired.
- `ec1d88f` (#72) — **Added:** the audit-remediation wave's conventions.
- `d95854e` (#71) — **Changed:** the waitlist full-loop e2e spec split into
  named behaviours.
- `17363e6` (#69) — **Changed:** domain input bound through forms, and the
  command and messenger handlers thinned out.
- `2a846fd` (#66) — **Changed:** token revocation routed through a handler, with
  the CSRF and redirect drift fixed.
- `06bc59c` (#68) — **Changed:** per-module data purgers, and Stripe
  cancellation made retryable.
- `c5879eb` (#65) — **Fixed:** hot read paths no longer scale with history size
  and row count.
- `1242f50` (#67) — **Fixed:** a cancelled subscription keeps access through the
  paid-through date, and the sweep is indexed.
- `3e152b7` (#62) — **Fixed:** resolved threads no longer resurrect their
  replies.
- `6c3a8bf` (#64) — **Fixed:** the bridge CLI no longer injects
  reviewer-controlled text into the agent prompt.
- `32bbf62` (#70) — **Fixed:** the `cs` gate reports honestly inside worktrees,
  and phpstan is warmed.
- `72762b3` (#63) — **Changed:** Symfony upgraded to 8.1, clearing the
  dependency advisories.
- `0c9c210` (#61) — **Removed:** stale Codex model names from the pre-PR gate.
- `6f9f243` (#60) — **Added:** the `loupe-documents` skill, covering the
  formatting rules for the review UI.
- `c766259` (#59) — **Added:** the post-trial lifecycle — ended trials are
  disabled, their cap spots freed, and survey emails sent.
- `f16f45e` (#49) — **Fixed:** `list_documents` wraps its result in a
  `documents` object key.
- `5e7d9aa` (#58) — **Changed:** tracker entries may not carry ephemeral
  identifiers.
- `5813dc1` (#57) — **Added:** a Status field on tracker entries.
- `7c07e60` (#56) — **Added:** a priority ordering rule for the tracker.
- `c4253bd` (#55) — **Added:** the `project-next-steps` skill, defining the
  tracker entry format.
- `dd134ba` (#54) — **Added:** opt-in ngrok tunnel ingress for the dev app.
- `1c172ef` (#53) — **Added:** a first-install wizard.
- `708e35a` (#52) — **Fixed:** the worktree tooling gaps found in the
  nine-feature-wave retro.
- `d3e9bd7` (#51) — **Added:** the nine-feature-wave retro learnings, spread
  across the skills.
- `3451da3` (#50) — **Fixed:** transactional email sends from `hello@loupe.ac`.
- `2136340` (#47) — **Added:** self-service account deletion, confirmed by
  email.
- `7641503` (#45) — **Added:** a registration cap with a waitlist behind it.
- `1528356` (#46) — **Added:** a project can be deleted.
- `50db7d2` (#44) — **Added:** the paid plan — a Stripe trial, then a paywall.
- `64dd9d8` (#43) — **Added:** a first-run wizard.
- `395df8e` (#41) — **Added:** GitHub and Google social login, behind feature
  flags.
- `d6bf02d` (#42) — **Changed:** admin-bundle re-pinned to `main`, with
  `#[\Override]` style fixes.
- `3d8bb00` (#40) — **Added:** an asynchronous "download my data" export.
- `25a03e8` (#39) — **Fixed:** dev tooling for multi-worktree development —
  opcache and lint scope.
- `18b2ea3` (#38) — **Added:** an admin area and feature flags.
- `1e87f0a` (#37) — **Fixed:** rendered documents no longer truncate at the
  sanitizer's 20 KB default.
- `c183b8b` (#36) — **Fixed:** truncated `just` descriptions, and the e2e
  `node_modules` symlink ignored.
- `de09ec4` (#35) — **Added:** the `project-worktrees` skill.
- `f8a980a` (#34) — **Added:** every worktree gets its own URL and database.
- `36afb56` (#32) — **Added:** a project's name and domain can be edited.
- `bc9fa70` (#33) — **Fixed:** login throttling relaxed in dev and test, to stop
  the e2e auth flakes.
- `7c9363d` (#31) — **Fixed:** the widget surfaces token errors instead of a
  generic "try again".
- `b7f8562` (#30) — **Added:** a `just secrets-scan` recipe.
- `7921f67` (#29) — **Added:** a `just composer` recipe, used in the README.
- `6c59e61` (#28) — **Changed:** dependencies updated, clearing eight security
  advisories.
- `38187be` (#26) — **Changed:** open-source readiness — licence, hardening and
  repository hygiene.
- `ecf3074` (#27) — **Changed:** updated from the skeleton at `9d91f89`.
- `483a571` (#25) — **Added:** the `symfony/mercure-bundle` recipe artifacts
  committed.
- `9f54ddc` (#24) — **Changed:** Better Plans renamed to Loupe in the
  `project-templates` skill.
- `ab13ded` (#23) — **Changed:** the application renamed from Better Plans to
  Loupe.
- `bad9e9e` (#22) — **Added:** the API and page-title conventions from
  `AUTOMATIONS.md` enforced.
- `9752117` (#21) — **Fixed:** the Connect page's `claude mcp add` syntax, with
  an embedded token, a regenerate action and a parameterised name.
- `01d8012` (#20) — **Added:** the Connect agent page. **Removed:** the
  API-tokens page it replaces.
- `a14495b` (#19) — **Added:** the document review and site review screens.
- `2562927` (#18) — **Added:** the app shell and the Projects and Documents
  screens.
- `24ac3bf` (#17) — **Changed:** Site renamed to Project, the MCP bound to a
  project, and the URL space moved to `/projects`.
- `9b1693e` (#16) — **Changed:** site review rebuilt from ephemeral batches to a
  persistent per-site model — a `Site` entity, site-bound widget tokens, a
  server-backed widget, per-site Mercure topics, and `get_site_review` /
  `address_site_review_comments` replacing the batch fetch (a breaking MCP
  change).
- `4e7b5b0` (#15) — **Added:** the site-review bridge, piping a submitted review
  into a local Claude Code session.
- `a5581af` (#14) — **Changed:** the site-review widget redesigned, visually and
  in its interaction.
- `968a9e0` (#13) — **Changed:** site-review widget icons, pins, shortcuts and
  feedback improved.
- `b72adc8` (#12) — **Changed:** gamache bumped to the hardened deny-access
  rule.
- `7f6c1a8` (#11) — **Fixed:** a batch of site-review feedback across review,
  site review and account.
- `85366f9` (#10) — **Changed:** the SaaS visual redesign — a collapsible
  sidebar and a coherent set of primitives.
- `f9d417a` (#9) — **Added:** review comments can be replied to and resolved
  through forms and Turbo Streams, with the anchor quote shown.
- `47e72b3` (#8) — **Fixed:** Turbo prefetch disabled on the logout link.
- `225f5b4` (#7) — **Added:** the annotation widget embedded on the app itself,
  for dogfooding.
- `c3bd63e` (#6) — **Added:** human-facing site-review batch list and detail
  pages.
- `5ad6f74` (#5) — **Added:** an embeddable annotation widget and scoped API
  tokens.
- `23bcb25` (#4) — **Fixed:** anchor context kept UTF-8-safe, which was crashing
  revisions.
- `b06b44d` (#3) — **Changed:** the skeleton sync point recorded for the gamache
  bump.
- `d1ffc2e` (#2) — **Changed:** `ubermuda/gamache` bumped for the
  third-party-arguments exemption.
- `a0d60dc` (#1) — **Added:** the agent document review MCP service — the first
  feature of the app.
- `2cc07e0` — **Added:** the application bootstrapped from `symfony-skeleton`,
  with the MCP and Markdown dependencies. **Every entry below this line belongs
  to the skeleton's own history**, not this app's, and predates the
  one-entry-per-PR rule above.

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
