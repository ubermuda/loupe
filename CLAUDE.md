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
| `project-next-steps` | Adding, editing, or closing entries in `docs/NEXT_STEPS.md` — entry format, attribution, lifecycle |
| `project-translations` | UI strings, translation keys, or adding a new locale |
| `project-site-review` | The site-review widget (`public/site-review/widget.js`), `src/Module/SiteReview/`, its API routes, dev harness or e2e specs |
| `loupe-documents` | Writing or revising any document submitted to the Loupe app via the `loupe` MCP |
| `loupe-site-review` | Acting on site-review feedback through the `loupe` MCP — `site_review_get`, fixing comments, marking them addressed |
| `symfony-authorization` | Generic Symfony authorization mechanics — Voter classes, attribute naming, `#[IsGranted]` placement, `subject:` resolution, `is_granted()` in Twig |
| `symfony-entity-route-mapping` | Routes that resolve entities from URL parameters — `{param:variable}` notation, `#[MapEntity]`, multi-entity routes |
| `project-comments` | Writing or reviewing code comments and docblocks anywhere in `src/`, `assets/` or `tests/` |
| `working-with-prs` | Opening, gating, reviewing or merging a pull request — the gate, the body, making a branch testable, Codex findings, merge protocol, running several branches at once |

**Delegating to subagents:** a subagent does not inherit the skills you have loaded. When delegating PHP/entity/migration/command work to a subagent, state the relevant skill conventions in its prompt (or instruct it to invoke the skill first). Conventions that live only in a skill — e.g. the brand-new-table migration rule in `project-backend` — are silently missed when the subagent has no skill context.

## Getting feedback on long documents

When you want the user's feedback on a **long-form document** — an implementation plan, a design spec, an RFC, an architecture write-up, or anything substantial they need to read and comment on at their own pace — submit it to the Loupe app via the `loupe` MCP (`document_create`, or `document_revise` for follow-ups) and give the user the returned review URL. That is what the app is for; dogfood it. **Invoke the `loupe-documents` skill before writing the document** — it covers the formatting rules for the review UI (title handling, numbered lists, list-entry lead sentences, explicit decision points).

This applies **only** to documents meant for considered review. Do **not** route ordinary conversation through it — clarifying questions, quick confirmations, short summaries, options you're discussing inline, or anything that belongs in the normal back-and-forth stays in the chat. The test: if it's a document the user would sit down and read, send it to Loupe; if it's a turn in a discussion, keep it in the terminal.

## Notes for later

When you identify something worth remembering for a future session — a TODO, a follow-up, a known issue, a design decision to revisit — append it to `docs/NEXT_STEPS.md` **following the entry format in the `project-next-steps` skill** (author / type / priority metadata line — invoke the skill before appending). Do not leave such notes in code comments — `docs/NEXT_STEPS.md` is the tracker for open work. No `TODO`/`FIXME`/`XXX` in code (gamache-enforced).

`docs/NEXT_STEPS.md` is **committed**, because a tracker only one checkout can see is a tracker the next session cannot read. Being tracked also means it is branch content: parallel branches that both append will conflict, and the resolution is always to keep both entries (see `project-next-steps`).

**The tracker is public**, and stays. Moving it to GitHub issues was once the plan for the visibility flip; it is not, because a tracker an agent can read in one `cat` beats one behind an API call. Write every entry as public text — no secrets, no customer names, no venting about people — and remember it is already in git history, so an entry cannot be unpublished by deleting it.

Entries go through a branch and a pull request like anything else. `main` is protected, so the old commit-straight-to-main shortcut for tracker notes no longer exists.

When an item in `docs/NEXT_STEPS.md` is resolved, **delete it entirely**. Do not mark it with "— CLOSED", add a resolution note, or leave it under a `CLOSED` heading. The file should contain only open work. Closed content is noise.

## Git Worktrees

Worktrees are stored in `.claude/worktrees/` (already gitignored). **Every
worktree is a full application of its own** — run `just worktree-up` and it gets
its own URL (`https://<name>.loupe.dev.localhost`), its own migrated and seeded
database, and its own compiled CSS. Log in with `dev@loupe.test` / `password`,
or `admin@loupe.test` / `password` for the admin area.

- **The main session never moves into a worktree.** If you are the session
  running in the main checkout, do not call `EnterWorktree` and do not `cd` into
  `.claude/worktrees/`. Worktrees are entered only by sessions that exist to work
  in one — a background job, or an agent launched with `isolation: "worktree"`.
  Three reasons, all of which bite silently:
  - **Write access is single-valued per agent, and plain subagents inherit the
    parent's current worktree.** A main session that moves in binds every
    subagent it later dispatches to that same worktree, so work aimed at any
    other tree is rejected — or worse, lands in the wrong one.
  - **`main` is checked out in the main checkout and nowhere else.** Merging,
    running `just cs` on `main` after a merge, and tearing a worktree down all
    need a session that is still standing there. Git will not let a second
    worktree check out `main` either.
  - **Tearing down the worktree a session is bound to strands that session** —
    its writes keep pointing at a deleted path until it re-enters somewhere else.

  Stay in the main checkout, and delegate the work that needs isolation.

  **Running a command inside a worktree is not the same as moving into one**,
  and the difference is the shell's working directory surviving the call. The
  `project-worktrees` skill tells you to run `just worktree-up`,
  `bin/worktrees/compose-exec.sh` and the worktree e2e gate *from the worktree* —
  those genuinely need it as their cwd. Run them in a **subshell** so the cwd
  dies with the command:

      ( cd .claude/worktrees/<name> && just worktree-up )

  Bare `cd .claude/worktrees/<name> && just worktree-up` looks identical and is
  not: the Bash tool persists its working directory between calls, so every
  later command in the session silently runs from the worktree too. That is the
  move this rule forbids, arrived at by accident.
- **Provision by name from the main checkout: `just worktree-up NAME`.** The
  no-argument form still works from inside a worktree, but needing it is what
  made sessions `cd` there in the first place.
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

## Pull requests

**Invoke the `working-with-prs` skill before opening, gating or merging a pull request.** It carries the full gate, the merge protocol, the ruleset facts and the wave rules. The irreducible summary, so a session that skips it still does the right thing:

- The gate is `just cs`, then `just ci`, then `just e2e`, then a Codex review (`mcp__codex-cli__review`, `model: "gpt-5.6-sol"`, against `origin/main`). Fix every failure, including pre-existing ones. If the Codex MCP is missing, **stop and tell the owner** rather than routing around it.
- A branch whose every changed file ends in `.md` runs steps 1 and 2 only, and says so in the body. Verify with `git diff --name-only origin/main...HEAD | grep -v '\.md$'` — any output means the full gate applies.
- `main` is protected and takes `--squash` only, so **the PR body becomes the commit body**. It requires eight CI checks and one approving review; an approval in chat is not a GitHub approval.
- **Never approve your own work**, and do not merge on the owner's behalf unless asked.
- A green gate is not evidence the change is correct. Read the diff.

## Recommendations and the quality bar

### The owner sets the bar, not the agent

Deciding what is good enough and what has to be exactly right is the owner's call. The failure to avoid is making that call silently and then presenting the outcome as a technical necessity, which removes the decision instead of informing it. Ranking findings "must-fix" and blocking on them, declaring one branch's failing run to outrank a merge queue, imposing mutation checks as a standard, requiring a stale form submission to be refused rather than resolved — each of those is a judgement about how much rigour something deserves, and several of them may well be right. That is not the point; the point is who made them.

So: name the severity and the cost of fixing, recommend, and let the owner set the bar — especially where the fix is expensive, where the defect is unreachable in practice, or where "leave it and note it" is a legitimate answer. Reserve blocking language for what is genuinely unsafe to ship, and say plainly when something is a judgement call rather than a requirement.

The tell to watch for is describing a preference as though it were a property of the code. "This must refuse" and "I think refusing is better, here is the cost of each" are different sentences, and only the second leaves the decision where it belongs.

### Weigh the trade-offs; do not defend one option

The habit to break is picking an approach and then arguing for it. The problem is rarely that the recommendation is wrong — it is that the *reasoning gets inflated to match the conclusion*. A minor downside of the rejected option gets described in the register of a serious one, which makes the argument unfalsifiable and hides how close the call actually was. A measured one-millisecond cost does not need to be dressed up as a "real correctness cost" to justify the right answer; overstating the case is worse than a weaker conclusion honestly argued.

What to do instead:

1. State the options and what each actually costs, in proportionate language.
2. Give a recommendation and the confidence behind it, without padding the rejected options' downsides to justify it.
3. Say plainly when a call is close, or when it rests on taste rather than evidence — "either is fine, I lean X" is a legitimate answer.
4. Keep the alternatives genuinely available rather than mentioning them as a courtesy before dismissing them.

This sits in tension with the instruction not to capitulate under pressure, and the tension is the point: hold a position against disagreement, but do not manufacture support for it.

## General Guidelines

- When you create new code, ask yourself what module it should live in. If you're not sure, ask your human.
- If some code is generic and re-used across modules, it can live in the project's root namespace.
- When staging changes for a commit, always add files by name rather than `git add -A`. Other feature branches may have uncommitted working-tree changes that `git add -A` will silently include.
- **Always verify current code state when addressing PR feedback — do not trust prior agent replies.** Agent replies saying "Done" in PR threads may not reflect what was actually committed. For each unresolved thread, read the relevant file and confirm the change is present before marking it addressed or moving on.
- Always fix failing and flaky tests in any test run you observe — including ones that pass on retry, ones that pre-date your change, and ones unrelated to the current task. A "flaky" test that passes on retry is a real failure that will eventually break CI; do not declare a green run while flakes are tolerated. The only acceptable response to a flake is a fix; "it'll probably be fine" is not.
- **For PHP-specific gotchas (Serena rename workaround, FormType rename side-effects, constructor change checklist), see `project-backend`.**
- **Outbound network calls are allowed, but only as opt-in features.** A self-hosted instance must work with no egress *out of the box* — that is why `assets/icons/` is committed and `iconify.on_demand` is off in prod. It is a default, not a prohibition. A feature that calls out is fine when an operator has to switch it on, and the app behaves correctly both while it is off and when the call fails. `about.update_check.enabled` is the reference shape: flag seeded off, bounded timeouts on a scoped HTTP client, the answer cached, and every failure path returning null rather than breaking the page. Do not argue against an egress feature on principle; argue about whether it is opt-in and degrades cleanly.
- **Never write `TODO`, `FIXME`, or `XXX` comments in code.** If follow-up work is needed, capture it in a project tracking file (e.g. `docs/NEXT_STEPS.md`) or an issue. In-code TODO comments are invisible to future sessions and rot silently. Enforced by `NoTodosCheck` (runs in `just gamache`).
- **Keep comments short — the default is no comment at all.** A comment earns its place only by recording something a reader cannot recover from the code, the tests or `git log`; the reasoning behind it belongs in the commit message or PR body. `CommentBudgetCheck` fails on any run of 6+ consecutive comment lines, in PHP, Twig, JS, CSS, YAML, the justfile and `.env` alike. It is **binding here** — `just gamache` exits non-zero, so `just ci` goes red. Shorten the block, or mark it `@comment-budget-ignore` if it has genuinely earned its length. **Invoke the `project-comments` skill** before writing a comment longer than two lines.
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
just tailwind                 # Foreground Tailwind watcher (the `tailwind` compose service already does this)
bin/console tailwind:build    # One-shot Tailwind build (use this in CI scripts and plan verify steps only)
```

Tailwind CSS is rebuilt automatically in the dev container — **never run `bin/console tailwind:build` manually after editing templates or `app.css`**. The watcher picks changes up within a second or two; if a class doesn't appear in the compiled CSS, wait briefly and re-check rather than reaching for a manual build. Only run `bin/console tailwind:build` explicitly in CI scripts or plan verify steps. The same applies to `cache:clear` — not needed in dev.

The app runs at `https://loupe.dev.localhost`. PHP-FPM is on port 9000. A `worker` compose service consumes the async transport; `docker compose logs worker` to observe, `just worker` for a foreground consumer. A `tailwind` compose service watches and rebuilds the stylesheet the same way; `docker compose logs tailwind` to observe, `just tailwind` for a foreground watcher. Do not run both — two watchers write the same file and race.

**A process started inside a container can only be observed and stopped from inside it.** The container has its own PID namespace, so a host-side `pkill -f <script>` — and the harness's own `TaskStop` — kills only the wrapper and reports success while the real process keeps running, invisible to the host process table. Stop it with `docker compose exec php-fpm pkill -f <script>` and confirm with `docker exec <project>-php-fpm-1 ps aux`; a host-side check will show a quiet container that is in fact fully loaded.

**Production deployment — prod runs per-process containers.** Each process type (web, messenger worker) is its own container from the same image; `docker/prod/supervisord.conf` is only the web container's image-default CMD. Never add background processes as `[program:]` blocks there — the worker's command belongs to whatever orchestrates the containers: `worker_command` in `terraform/main.tf` for App Platform, the `worker` service in `docker/compose/prod.yaml` for a single host. Both run `messenger:consume scheduler_default async --time-limit=3600 --memory-limit=128M` (schedule transport first: a deep async backlog must not delay ticks).

`docker/compose/prod.yaml` is the reference single-host topology — web, worker, Postgres, Mercure hub — and is a distributed artefact, not a scratch file: it must keep working alongside `terraform/`. It is driven by `docker/compose/prod.env` (gitignored; `docker/compose/prod.env.example` is the template) and **must** be run with `--env-file`, because Compose otherwise interpolates the development `.env`. `compose.yaml` remains dev-only and shares nothing with it.

**Database connectivity:** the Postgres container is exposed via Traefik TCP routing at `db.loupe.dev.localhost:5432`. The `.env` file ships with `127.0.0.1:5432` as a placeholder; override it in `.env.local` on your host machine:
```
DATABASE_URL="postgresql://app:!ChangeMe!@db.loupe.dev.localhost:5432/app?serverVersion=16&charset=utf8"
```
From inside the php-fpm container (`just shell`), use the `database` Docker service hostname directly. The `compose.yaml` sets `DATABASE_URL` to `database:5432` for the container so this is automatic.

**Database test isolation:** Use `dama/doctrine-test-bundle`. Each test runs inside a database transaction that is automatically rolled back; no custom schema-reset code is needed. Schema creation runs once in `tests/bootstrap.php` (drop → create → migrate). Never write `resetSchema()` methods that drop and recreate the schema per test.

**Running tests in parallel worktrees:** `config/packages/doctrine.yaml` sets `dbname_suffix: '_test%env(default::TEST_TOKEN)%'`, so PHPUnit's database is `app_test<TEST_TOKEN>`. To run `just ci` / PHPUnit in a git worktree without colliding with the main checkout or a sibling worktree, export a unique `TEST_TOKEN` (e.g. `TEST_TOKEN=_wt1`) so each gets its own `app_test_wt1` schema (bootstrap drop→create→migrate is per-DB). `just worktree-up` writes that token for you. Since each worktree also has its own **dev** database (`app_wt_<name>`, selected by `WORKTREE_DB_SUFFIX` in its `.env.local`), `just migrate-run` and `bin/console` in a worktree never touch the main dev database. **`just e2e` still cannot be parallelized** — Mailpit is shared, so mail-asserting specs across concurrent runs would read each other's messages. `playwright.config.ts` therefore sets `workers: 1`, so the plain command is already correct — pass `--workers=N` only for a subset that touches no mail.

**The full suite runs against the dedicated e2e target, not a worktree and never the dev host.** `just e2e-up` creates a disposable `app_e2e` database and an nginx sidecar at `e2e.<project>.dev.localhost` serving **this checkout** — so it gates whatever branch you have checked out, with no worktree involved. `just e2e` defaults there and refuses to start if it is not up. Tear down with `just e2e-down`.

Why the dev host is not an option: the suite is destructive by design. The `install-reset` project **truncates every table**, and `trial-end-lifecycle` flips global feature flags and disables every expired-trial account — so one run against `loupe.dev.localhost` wipes your development database. Pointing e2e at a worktree still works (`E2E_BASE_URL=https://<slug>.loupe.dev.localhost just e2e --workers=1`) and remains the right tool when you need to gate a branch *without* checking it out; it is no longer the only one. The DB-free static gate (`just cs`, `just phpstan`, `just lint`, `just arkitect`, `just gamache`) is always safe to run in parallel.

**Before every worktree e2e run, warm the dev cache and leave it alone for the duration:**

```bash
( cd .claude/worktrees/<name> && bin/worktrees/compose-exec.sh bin/console cache:warmup )
```

A run started against a cold `var/cache/dev`, or one whose cache is rebuilt mid-flight by a concurrent `just cs` / `just ci` in the same worktree, produces **false failures that look like unrelated flaky specs**. The container is swapped underneath in-flight requests, and a container-load fatal happens before Monolog exists — so the request logs nothing at all and php-fpm returns an empty response. It surfaced three separate times in one session, always as a request that never completed: a logout that stayed on the same page, a registration that stayed on `/register`, an admin page that never rendered. Each showed a submit button left in its disabled state with **no validation errors**, which is the signature to recognise.

So: warm first, then run e2e, and do not run a gate against a worktree while its suite is in flight. If a spec fails and then passes in isolation, check `ls var/cache/dev/ | grep -c '^Container'` — more than one container hash means a rebuild happened during the run, and the failure is environmental rather than yours. That is a diagnosis, not a licence to re-run until green: confirm the cause before dismissing any failure.

**The e2e suite needs no messenger consumer.** Every message dispatched during a request carrying `X-Playwright: 1` is handled inline by `PlaywrightSyncMiddleware`, registered under `when@dev` only — production and ordinary dev dispatch are untouched. There is no worker to start and none to forget.

A large auth-shaped block of failures — login, signup, the wizard, admin smoke and paywall going down together while the app returns 200 and `just ci` is green — therefore means **stale state, not a missing queue**. The usual cause is a fixture user left half-registered by an interrupted run: `just e2e-up` drops and recreates `app_e2e` every time it is called, so re-running it clears the poison. Diagnose before re-running; a database that survives a run is how stale state gets mistaken for a code regression.

**php-cs-fixer works from worktrees.** `.php-cs-fixer.dist.php` uses explicit excludes rather than `ignoreVCSIgnored(true)`, and throws when the finder matches zero files. Both matter: the old VCS-ignore heuristic matched **0 files** under the gitignored `.claude/worktrees/`, so `just cs` fixed nothing and `just ci`'s cs-check leg passed vacuously (a committed brace jam once sailed through a "green" gate that way). `.claude` stays excluded deliberately — worktrees live inside the main checkout, so without it the main run would scan every worktree's copy of the tree. No explicit-path workaround is needed any more; if you see one in an old PR body, it predates this.

## Common Commands

```bash
just shell                    # Open bash in php-fpm container
just lint                     # Run parallel PHP linter
just cs-fix                   # Run PHP CS Fixer
just rector                   # Run Rector (PHP modernization)
just phpstan                  # Run static analysis (level 8)
just arkitect                 # Check module boundary rules (phparkitect)
just cs                       # Write-mode fixer pipeline: prettier, lint, rector, cs-fix, twig-cs-fix
just ci                       # Check-only gate (never rewrites files): lint, cs-check (rector/cs-fixer/twig-cs-fixer dry-run), phpstan, arkitect, gamache, composer audit, PHPUnit (e2e is separate)
just audit                    # Security advisories against composer.lock (also runs inside `just ci`)
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

**Stack:** Tailwind CSS v4 for UI — no component library. The visual system is
hand-rolled: CSS custom properties in the `@theme` design-token block plus
semantic component classes in `@layer components`, all in
`assets/styles/app.css`. Templates use those semantic classes, not raw utility
strings. DaisyUI was removed in the 2026-06-19 visual redesign; do not reach for
`btn`, `card` or `modal` classes, and do not reintroduce a component library
without a decision to. Symfony UX Icons (Lucide) and Stimulus.js for
interactivity. Conventions live in `project-frontend`.

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

`just gamache` runs **only** the `src/Check/` layer (advisory/structural convention checks). The other four are part of the normal static-analysis pipeline. So "is gamache working / does a check exist?" is answered by grepping the package's `src/Check/`, `src/PHPStan/`, `src/Rector/`, `src/PhpCsFixer/`, and `src/TwigCsFixer/` — not just `src/Check/`.

**PHPStan-rule layer only sees files in PHPStan's `paths:`** (`phpstan.dist.neon`). `migrations/` **must** stay in that list or migration-targeting rules (e.g. `MigrationDescriptionRule`) silently never run. When adding a new top-level source dir a rule should police, add it to `paths:`.

**Adding or modifying any gamache rule:** gamache is an external package. To add or change a rule in **any** of the five layers, open a PR on https://github.com/ubermuda/gamache. Do not add rule/check/fixer classes directly to this project — `src/Utils/Gamache/` no longer exists.

**Configuring checks:** each `src/Check/` class accepts constructor parameters (see `gamache.php`). To pass custom options (e.g. `ignoredCallSites` on `TranslationCheck`), edit the constructor call in `gamache.php`. The PHPStan layer is configured under `parameters.gamache:` in `phpstan.dist.neon`.

## Icons

All icons must use the Symfony UX Icons bundle with Lucide. Never embed inline SVG.

```twig
<twig:UX:Icon name="lucide:x" class="w-3.5 h-3.5 shrink-0 mt-px" />
```

`assets/icons/` is **committed**. A self-hosted instance must render its UI with no egress, so the SVGs ship in the repo and therefore in the production image; `iconify.on_demand` is **off in prod** so a production instance never calls `api.iconify.design`. It stays on in dev and test, so a newly used icon still renders immediately while you work — but it is then only in your local cache, and the build would ship without it.

**So: after adding a new icon, run `bin/console ux:icons:lock` and commit what appears under `assets/icons/`.** The command scans the project and imports what it finds. Do **not** `git rm --cached` these files; earlier guidance said to, and it now breaks production rendering.

`ux:icons:lock` only sees icon names it can read as literals. A name built at runtime — e.g. `<twig:UX:Icon name="simple-icons:{{ provider }}" />` in `templates/Module/Account/security/_social_buttons.html.twig` — is invisible to the scan, so those icons must be imported by hand (`bin/console ux:icons:import simple-icons:google simple-icons:github`) and will otherwise be missing in prod with no error, because `ignore_not_found: true` renders nothing. If you add a dynamically-named icon, import its full set of possible values explicitly.

**Stroke colour:** Imported Lucide SVGs use `stroke="currentColor"`. Control the stroke colour via a text colour class on the icon or its parent — never hardcode `stroke="white"` as an attribute.
