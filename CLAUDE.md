# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Topic-specific guidance

These skills contain detailed conventions for specific areas. **Invoke the relevant skill before writing or editing anything in that area — no exceptions.** "I already know the pattern" and "this is a small change" are not exceptions; the skills contain project-specific conventions that differ from defaults and from each other.

| Skill | Use when working on… |
|---|---|
| `project-backend` | PHP code under `src/` — forms, DTOs, entities, controllers, flash messages |
| `project-command-handler` | Command + handler pairs — any controller action with business logic, `DomainErrors` |
| `project-authz` | Voters, `#[IsGranted]`, Mercure topic authorization, any access control |
| `project-testing` | PHPUnit tests — unit, integration, WebTestCase, mock setup |
| `project-e2e` | Writing or fixing Playwright tests under `e2e/` |
| `project-frontend` | CSS in `assets/`, Stimulus controllers, Turbo patterns, icons, any frontend visual behaviour |
| `project-templates` | `.html.twig` files or Twig component PHP classes |
| `project-worktrees` | Git worktrees — provisioning, URLs, per-worktree databases, worktree tooling |
| `project-translations` | UI strings, translation keys, or adding a new locale |
| `symfony-authorization` | Generic Symfony authorization mechanics — Voter classes, attribute naming, `#[IsGranted]` placement, `subject:` resolution, `is_granted()` in Twig |
| `symfony-entity-route-mapping` | Routes that resolve entities from URL parameters — `{param:variable}` notation, `#[MapEntity]`, multi-entity routes |

**Delegating to subagents:** a subagent does not inherit the skills you have loaded. When delegating PHP/entity/migration/command work to a subagent, state the relevant skill conventions in its prompt (or instruct it to invoke the skill first). Conventions that live only in a skill — e.g. the brand-new-table migration rule in `project-backend` — are silently missed when the subagent has no skill context.

## Getting feedback on long documents

When you want the user's feedback on a **long-form document** — an implementation plan, a design spec, an RFC, an architecture write-up, or anything substantial they need to read and comment on at their own pace — submit it to the Loupe app via the `loupe` MCP (`create_document`, or `revise_document` for follow-ups) and give the user the returned review URL. That is what the app is for; dogfood it.

This applies **only** to documents meant for considered review. Do **not** route ordinary conversation through it — clarifying questions, quick confirmations, short summaries, options you're discussing inline, or anything that belongs in the normal back-and-forth stays in the chat. The test: if it's a document the user would sit down and read, send it to Loupe; if it's a turn in a discussion, keep it in the terminal.

## Notes for later

When you identify something worth remembering for a future session — a TODO, a follow-up, a known issue, a design decision to revisit — append it to `docs/NEXT_STEPS.md`. Do not leave such notes in code comments — `docs/NEXT_STEPS.md` is the tracker for open work. No `TODO`/`FIXME`/`XXX` in code (gamache-enforced).

**If the project may go public:** `docs/` (internal notes) should not ship in a public repo, so at that point move shared open-work tracking to GitHub issues and treat `docs/NEXT_STEPS.md` as a local, gitignored scratchpad. For a private project — the default for a fresh skeleton-derived repo — keep `docs/NEXT_STEPS.md` and `.skeleton.json` committed as normal.

When an item in `docs/NEXT_STEPS.md` is resolved, **delete it entirely**. Do not mark it with "— CLOSED", add a resolution note, or leave it under a `CLOSED` heading. The file should contain only open work. Closed content is noise.

## Git Worktrees

Worktrees are stored in `.claude/worktrees/` (already gitignored). **Every
worktree is a full application of its own** — run `just worktree-up` and it gets
its own URL (`https://<name>.loupe.dev.localhost`), its own migrated and seeded
database, and its own compiled CSS. Log in with `dev@loupe.test` / `password`.

- Always branch off `main`, not the current feature branch
- Tear down with `just worktree-down <name>`, never a bare `git worktree remove`
- **Invoke the `project-worktrees` skill** before provisioning, debugging or
  writing tooling for a worktree. It covers the commands, the symptom→cause
  table (404 vs 502, unstyled CSS, rejected widget token), and the two rules
  that prevent real damage: never run bare `docker compose` from a worktree, and
  never match worktrees by directory name instead of slug.
- **Serena's edit tools do not work from a worktree.** The Serena MCP server is bound to the main checkout, so `replace_symbol_body` / `insert_*_symbol` / `replace_content` silently write to the **main checkout**, not your worktree — leaving your branch unchanged and the main tree dirty. When working in a worktree (e.g. a subagent implementing a PR), use the built-in **Edit/Write** tools for all edits. Serena **read** tools (`get_symbols_overview`, `find_symbol`, `find_referencing_symbols`) are safe from anywhere.

## Claude Commands

Custom slash commands go in `.claude/commands/` in this repository — not in user-level (`~/.claude/commands/`) or system-level directories.

## Pre-PR gate

Before opening a pull request, you must:

1. Run `just cs` to apply formatter and rector fixes, and commit anything it changed. (From a worktree, step 1 is blind — see "php-cs-fixer is blind inside worktrees" under Development Environment for the real gate.)
2. Run `just ci` and fix every failure — including ones that pre-date your change. `ci` is check-only: it reports style/rector violations but never rewrites files — `just cs` is the step that applies them.
3. Run `just e2e` and fix every failure — including ones that pre-date your change.
4. Run a Codex review of the branch: `mcp__codex-cli__review` with `model: "gpt-5.6-sol"` — the tool's default models (`gpt-5.3-codex`, `gpt-5.1-codex`) are rejected by this Codex account ("not supported when using Codex with a ChatGPT account"). CLI fallback if the MCP server is unavailable: `codex review -c model="gpt-5.6-sol" --base main` (the `review` subcommand has no `-m` flag — the model is set with `-c model=`). Address the findings before opening the PR.

Do not open a PR until both commands pass cleanly. Pre-existing failures are not exempt; fix them as part of the branch.

## General Guidelines

- When you create new code, ask yourself what module it should live in. If you're not sure, ask your human.
- If some code is generic and re-used across modules, it can live in the project's root namespace.
- When staging changes for a commit, always add files by name rather than `git add -A`. Other feature branches may have uncommitted working-tree changes that `git add -A` will silently include.
- **Always verify current code state when addressing PR feedback — do not trust prior agent replies.** Agent replies saying "Done" in PR threads may not reflect what was actually committed. For each unresolved thread, read the relevant file and confirm the change is present before marking it addressed or moving on.
- Always fix failing and flaky tests in any test run you observe — including ones that pass on retry, ones that pre-date your change, and ones unrelated to the current task. A "flaky" test that passes on retry is a real failure that will eventually break CI; do not declare a green run while flakes are tolerated. The only acceptable response to a flake is a fix; "it'll probably be fine" is not.
- **For PHP-specific gotchas (Serena rename workaround, FormType rename side-effects, constructor change checklist), see `project-backend`.**
- **Never write `TODO`, `FIXME`, or `XXX` comments in code.** If follow-up work is needed, capture it in a project tracking file (e.g. `docs/NEXT_STEPS.md`) or an issue. In-code TODO comments are invisible to future sessions and rot silently. Enforced by `NoTodosCheck` (runs in `just gamache`).
- **Code comments must be self-contained.** Never reference internal or ephemeral development artifacts a future reader can't access — numbered tasks (`Task 16`), a handoff/design document (`handoff screen 8`), spec sections (`§3.5`, `spec §3.9`), dev phases (`Part 1`, `Phase 1`), or dated decisions (`owner decision 2026-07-13`). State the underlying fact directly. A comment that only makes sense with a document open in another tab is worse than no comment. Enforced by `SelfContainedCommentsCheck` (runs in `just gamache`).
- **Doctrine migrations: use a real current-datetime timestamp** in the `VersionYYYYMMDDHHMMSS` class name — never a round placeholder like `…000000`. Parallel branches that both use a round number collide on the version prefix (harmless because the class names still differ, but confusing, and it breaks `migrate-diff` ordering assumptions).
- **Pinning ubermuda/\* VCS bundles:** to consume an unmerged bundle branch, pin `"dev-<branch>#<full-40-char-sha> as dev-main"` — the `as dev-main` alias is load-bearing (sibling ubermuda packages require `dev-main@dev`). After the bundle PR merges, repoint to plain `"dev-main#<merge-sha>"` and drop the alias in the same wave. If the bundle ships copied assets (e.g. `feature_flag_form_controller.js`), re-copy them at every pin change.

## Development Environment

This project uses Docker Compose for local development. Commands are managed via `justfile` (requires [just](https://github.com/casey/just)).

`COMPOSE_PROJECT_NAME` (set in `.env`, required — compose refuses to start without it) names the containers, traefik routes, and database volume. **When bootstrapping a new project from this skeleton, change it before the first `docker compose up`** — two projects sharing a name silently share containers and the database volume.

```bash
docker compose up -d          # Start containers (nginx, php-fpm, postgres)
composer install              # Install PHP dependencies
bin/console doctrine:migrations:migrate  # Run pending migrations
just tailwind                 # Build Tailwind CSS (watch mode, auto-rebuilds on change — already running in dev)
bin/console tailwind:build    # One-shot Tailwind build (use this in CI scripts and plan verify steps only)
```

Tailwind CSS is rebuilt automatically in the dev container — **never run `bin/console tailwind:build` manually after editing templates or `app.css`**. The watcher picks changes up within a second or two; if a class doesn't appear in the compiled CSS, wait briefly and re-check rather than reaching for a manual build. Only run `bin/console tailwind:build` explicitly in CI scripts or plan verify steps. The same applies to `cache:clear` — not needed in dev.

The app runs at `https://loupe.dev.localhost`. PHP-FPM is on port 9000. A `worker` compose service consumes the async transport; `docker compose logs worker` to observe, `just worker` for a foreground consumer.

**Production deployment — prod runs per-process containers.** Each process type (web, messenger worker) is its own container from the same image; `docker/prod/supervisord.conf` is only the web container's image-default CMD. Never add background processes as `[program:]` blocks there — the worker's command lives in deploy config (outside this repo), currently `messenger:consume scheduler_default async --time-limit=3600 --memory-limit=128M` (schedule transport first: a deep async backlog must not delay ticks).

**Database connectivity:** the Postgres container is exposed via Traefik TCP routing at `db.loupe.dev.localhost:5432`. The `.env` file ships with `127.0.0.1:5432` as a placeholder; override it in `.env.local` on your host machine:
```
DATABASE_URL="postgresql://app:!ChangeMe!@db.loupe.dev.localhost:5432/app?serverVersion=16&charset=utf8"
```
From inside the php-fpm container (`just shell`), use the `database` Docker service hostname directly. The `compose.yaml` sets `DATABASE_URL` to `database:5432` for the container so this is automatic.

**Database test isolation:** Use `dama/doctrine-test-bundle`. Each test runs inside a database transaction that is automatically rolled back; no custom schema-reset code is needed. Schema creation runs once in `tests/bootstrap.php` (drop → create → migrate). Never write `resetSchema()` methods that drop and recreate the schema per test.

**Running tests in parallel worktrees:** `config/packages/doctrine.yaml` sets `dbname_suffix: '_test%env(default::TEST_TOKEN)%'`, so PHPUnit's database is `app_test<TEST_TOKEN>`. To run `just ci` / PHPUnit in a git worktree without colliding with the main checkout or a sibling worktree, export a unique `TEST_TOKEN` (e.g. `TEST_TOKEN=_wt1`) so each gets its own `app_test_wt1` schema (bootstrap drop→create→migrate is per-DB). `just worktree-up` writes that token for you. Since each worktree also has its own **dev** database (`app_wt_<name>`, selected by `WORKTREE_DB_SUFFIX` in its `.env.local`), `just migrate-run` and `bin/console` in a worktree never touch the main dev database. **`just e2e` still cannot be parallelized** — Mailpit is shared, so mail-asserting specs across concurrent runs would read each other's messages. It can now be *pointed* at a worktree, which is the better gate for a branch: `E2E_BASE_URL=https://<name>.loupe.dev.localhost just e2e`. The DB-free static gate (`just cs`, `just phpstan`, `just lint`, `just arkitect`, `just gamache`) is always safe to run in parallel.

**php-cs-fixer is blind inside worktrees.** `.php-cs-fixer.dist.php` uses `ignoreVCSIgnored(true)`, and under the gitignored `.claude/worktrees/` its finder matches **0 files** — `just cs` fixes nothing and `just ci`'s cs-check leg passes vacuously. On a worktree branch, the real cs gate is an explicit-path run (CLI paths override the finder): expand the file list on the **host** (`git` is not installed in the container), pass `--config .php-cs-fixer.dist.php`, and in zsh word-split explicitly: `FILES=$(git diff --name-only main...HEAD -- '*.php'); bin/worktrees/compose-exec.sh vendor/bin/php-cs-fixer fix --config .php-cs-fixer.dist.php ${=FILES}`. State in the PR body that cs was verified this way.

## Common Commands

```bash
just shell                    # Open bash in php-fpm container
just lint                     # Run parallel PHP linter
just cs-fix                   # Run PHP CS Fixer
just rector                   # Run Rector (PHP modernization)
just phpstan                  # Run static analysis (level 8)
just arkitect                 # Check module boundary rules (phparkitect)
just cs                       # Write-mode fixer pipeline: prettier, lint, rector, cs-fix, twig-cs-fix
just ci                       # Check-only gate (never rewrites files): lint, cs-check (rector/cs-fixer/twig-cs-fixer dry-run), phpstan, arkitect, gamache, PHPUnit (e2e is separate)
just gamache                  # Run Gamache convention checker (replaces the seven custom check scripts)
just migrate-diff             # Generate migrations from entities
just migrate-run              # Run migrations
just e2e                      # Run Playwright e2e tests
just e2e-coverage             # Run e2e with per-request PHP coverage, merged to var/coverage/html
just open-coverage            # Open the merged HTML coverage report
just browser-sync             # Live-reload proxy for template changes

php vendor/bin/phpunit        # Run tests
bin/console debug:router      # List all routes
bin/console cache:clear       # Clear cache
```

To run a single test: `php vendor/bin/phpunit --filter TestClassName`

To run a single e2e spec: `just e2e tests/<area>/<spec>.spec.ts`

## CSRF

`csrf_protection_controller.js` must stay eagerly loaded (see `project-frontend`). Server-side CSRF for hand-rolled forms: "Stateless CSRF tokens for hand-rolled forms" in `project-backend`.

## Email

Email is delivered asynchronously by the messenger worker: `MailerInterface::send()` enqueues a `SendEmailMessage` on the `async` transport, and `messenger:consume async` (the dev `worker` compose service, a dedicated worker in production) performs the delivery. Failed deliveries retry 3 times, then land in the `failed` transport. Sender parameters and per-email-type sender services are documented in `project-backend` ("Email").

## Architecture

The app is a Symfony application. Source code is organized into domain modules under `src/Module/`. Each module follows a consistent layout: `Controller/`, `Entity/`, `Form/`, `Repository/`, with templates mirrored under `templates/Module/`.

All features live under `src/Module/` (e.g. `src/Module/Foo/`). Admin-facing controllers for a given feature live under `Controller/Admin/` within that feature's module directory. The `Admin` module (if present) is the shell for the admin area (layout, dashboard, auth promotion) — it does not own feature logic.

**Doctrine entity mapping:** `config/packages/doctrine.yaml` maps `dir: '%kernel.project_dir%/src'` with `prefix: 'App'`. This covers all entities under `src/Module/*/Entity/`. Do not narrow this to `src/Entity` — that directory is empty and exists only as a skeleton placeholder. If the mapping is wrong, PHPUnit will fail with "Could not find the entity manager for class" errors on every entity.

**Stack:** Tailwind CSS + DaisyUI 5 for UI, Symfony UX Icons and Stimulus.js for interactivity.

**Core PHP conventions** (details and exact patterns live in `project-backend` and `project-command-handler` — invoke them before writing PHP):

- All controllers extend the project-level `AppController` (never `AbstractController` directly); use its `renderFormResponse()` for every form response (sets 422 on invalid submit).
- Any controller action that does more than render or redirect is backed by a **command + handler pair** (`Command/FooCommand.php` + `Command/FooHandler.php`, no Messenger); `DomainErrors` carries field-level domain failures from handler back to the form.
- Repositories are always constructor-injected — never `$em->getRepository()`.
- Properties are public by default in all PHP classes; no getters that merely expose a property.

## Translation Enforcement

`just gamache` includes a translation check that scans `src/` PHP files and `templates/` Twig files for string literals that look like untranslated user-facing text (scored by word count, mixed case, punctuation, etc.).

**It does not fail CI** — exit code is always 0. Findings are advisory only.

**Suppressing a false positive:**
- PHP: add `// @translation-check-ignore` on the same line as the string
- Twig: add `{# translation-check-ignore #}` on the same line

**Configuration:** edit the `TranslationCheck` constructor call in `gamache.php` (project root). It documents its own options inline — ignored call sites, exception classes, ignored source namespaces, safe attribute namespaces, safe Twig functions.


## Gamache Checks

`ubermuda/gamache` enforces project conventions through **five** layers, each wired into a different tool. Before concluding "gamache has no rule for X," check **all five** — and note that most of them run under `just ci`, **not** `just gamache`:

| Layer | Package dir | Wired via | Run by |
|---|---|---|---|
| Convention checks | `src/Check/` | `gamache.php` | `just gamache` (`vendor/bin/gamache`) |
| PHPStan rules | `src/PHPStan/` | `extension.neon` + `parameters.gamache:` in `phpstan.dist.neon` | `just phpstan` / `just ci` |
| Rector rules | `src/Rector/` | `GamacheSetList::CONVENTIONS` in `rector.php` | `just rector` |
| PHP-CS-Fixer rules | `src/PhpCsFixer/` | `Gamache\PhpCsFixer\Fixers` in `.php-cs-fixer.dist.php` | `just cs-fix` |
| Twig-CS-Fixer rules | `src/TwigCsFixer/` | `GamacheStandard` in `.twig-cs-fixer.php` | twig-cs-fixer |

`ubermuda/gamache` enforces project conventions through **five** layers, each wired into a different tool. Before concluding "gamache has no rule for X," check **all five** — and note that most of them run under `just ci`, **not** `just gamache`:

| Layer | Package dir | Wired via | Run by |
|---|---|---|---|
| Convention checks | `src/Check/` | `gamache.php` | `just gamache` (`vendor/bin/gamache`) |
| PHPStan rules | `src/PHPStan/` | `extension.neon` + `parameters.gamache:` in `phpstan.dist.neon` | `just phpstan` / `just ci` |
| Rector rules | `src/Rector/` | `GamacheSetList::CONVENTIONS` in `rector.php` | `just rector` |
| PHP-CS-Fixer rules | `src/PhpCsFixer/` | `Gamache\PhpCsFixer\Fixers` in `.php-cs-fixer.dist.php` | `just cs-fix` |
| Twig-CS-Fixer rules | `src/TwigCsFixer/` | `GamacheStandard` in `.twig-cs-fixer.php` | twig-cs-fixer |

`just gamache` runs **only** the `src/Check/` layer (advisory/structural convention checks). The other four are part of the normal static-analysis pipeline. So "is gamache working / does a check exist?" is answered by grepping the package's `src/Check/`, `src/PHPStan/`, `src/Rector/`, `src/PhpCsFixer/`, and `src/TwigCsFixer/` — not just `src/Check/`.

**PHPStan-rule layer only sees files in PHPStan's `paths:`** (`phpstan.dist.neon`). `migrations/` **must** stay in that list or migration-targeting rules (e.g. `MigrationDescriptionRule`) silently never run. When adding a new top-level source dir a rule should police, add it to `paths:`.

**Adding or modifying any gamache rule:** gamache is an external package. To add or change a rule in **any** of the five layers, open a PR on https://github.com/ubermuda/gamache. Do not add rule/check/fixer classes directly to this project — `src/Utils/Gamache/` no longer exists.

**Configuring checks:** each `src/Check/` class accepts constructor parameters (see `gamache.php`). To pass custom options (e.g. `ignoredCallSites` on `TranslationCheck`), edit the constructor call in `gamache.php`. The PHPStan layer is configured under `parameters.gamache:` in `phpstan.dist.neon`.

**Configuring checks:** each `src/Check/` class accepts constructor parameters (see `gamache.php`). To pass custom options (e.g. `ignoredCallSites` on `TranslationCheck`), edit the constructor call in `gamache.php`. The PHPStan layer is configured under `parameters.gamache:` in `phpstan.dist.neon`.

## Icons

All icons must use the Symfony UX Icons bundle with Lucide. Never embed inline SVG.

```twig
<twig:UX:Icon name="lucide:x" class="w-3.5 h-3.5 shrink-0 mt-px" />
```

`assets/icons/` is gitignored. UX Icons runs with `iconify.on_demand: true` (dev **and** test), so any Lucide icon resolves at render time from the iconify API — newly used icons render and pass tests/CI **without** being committed or pre-imported. `ux:icons:import` only populates a local cache (handy offline); it is not a prerequisite for a new icon to work, so you never need to commit the SVGs. If icon SVGs are accidentally committed, remove them with `git rm --cached` — **not** `git rm`.

**Stroke colour:** Imported Lucide SVGs use `stroke="currentColor"`. Control the stroke colour via a text colour class on the icon or its parent — never hardcode `stroke="white"` as an attribute.
