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
| `project-translations` | UI strings, translation keys, or adding a new locale |

## Getting feedback on long documents

When you want the user's feedback on a **long-form document** — an implementation plan, a design spec, an RFC, an architecture write-up, or anything substantial they need to read and comment on at their own pace — submit it to the Better Plans app via the `betterplans` MCP (`create_document`, or `revise_document` for follow-ups) and give the user the returned review URL. That is what the app is for; dogfood it.

This applies **only** to documents meant for considered review. Do **not** route ordinary conversation through it — clarifying questions, quick confirmations, short summaries, options you're discussing inline, or anything that belongs in the normal back-and-forth stays in the chat. The test: if it's a document the user would sit down and read, send it to Better Plans; if it's a turn in a discussion, keep it in the terminal.

## Notes for later

When you identify something worth remembering for a future session — a TODO, a follow-up, a known issue, a design decision to revisit — append it to `docs/NEXT_STEPS.md`. Do not leave such notes in code comments — `docs/NEXT_STEPS.md` is the only place open work is tracked. No `TODO`/`FIXME`/`XXX` in code (gamache-enforced).

When an item in `docs/NEXT_STEPS.md` is resolved, **delete it entirely**. Do not mark it with "— CLOSED", add a resolution note, or leave it under a `CLOSED` heading. The file should contain only open work. Closed content is noise.

## Skeleton Sync

Projects bootstrapped from this skeleton track the sync state in a `.skeleton.json` file at their root:

```json
{
  "path": "~/Code/symfony-skeleton",
  "last_ported_commit": "<skeleton commit hash up to which changes have been ported>"
}
```

When porting changes from this skeleton into a consumer project, update `last_ported_commit` to the skeleton's `HEAD` at the time of the port. When back-porting improvements from a consumer project back here, open a PR against this repo's `main` — do not push directly.

## Git Worktrees

Worktrees are stored in `.claude/worktrees/` (already gitignored).

- Always branch off `main`, not the current feature branch
- Clean up the worktree after merging: `git worktree remove .claude/worktrees/<name>`

## Claude Commands

Custom slash commands go in `.claude/commands/` in this repository — not in user-level (`~/.claude/commands/`) or system-level directories.

## Pre-PR gate

Before opening a pull request, you must:

1. Run `just ci` and fix every failure — including ones that pre-date your change.
2. Run `just e2e` and fix every failure — including ones that pre-date your change.

Do not open a PR until both commands pass cleanly. Pre-existing failures are not exempt; fix them as part of the branch.

## General Guidelines

- When you create new code, ask yourself what module it should live in. If you're not sure, ask your human.
- If some code is generic and re-used across modules, it can live in the project's root namespace.
- When staging changes for a commit, always add files by name rather than `git add -A`. Other feature branches may have uncommitted working-tree changes that `git add -A` will silently include.
- **Always verify current code state when addressing PR feedback — do not trust prior agent replies.** Agent replies saying "Done" in PR threads may not reflect what was actually committed. For each unresolved thread, read the relevant file and confirm the change is present before marking it addressed or moving on.
- Always fix failing and flaky tests in any test run you observe — including ones that pass on retry, ones that pre-date your change, and ones unrelated to the current task. A "flaky" test that passes on retry is a real failure that will eventually break CI; do not declare a green run while flakes are tolerated. The only acceptable response to a flake is a fix; "it'll probably be fine" is not.
- **For PHP-specific gotchas (Serena rename workaround, FormType rename side-effects, constructor change checklist), see `project-backend`.**
- **Never write `TODO`, `FIXME`, or `XXX` comments in code.** If follow-up work is needed, capture it in a project tracking file (e.g. `docs/NEXT_STEPS.md`) or an issue. In-code TODO comments are invisible to future sessions and rot silently. Enforced by `NoTodosCheck` (runs in `just gamache`).

## Development Environment

This project uses Docker Compose for local development. Commands are managed via `justfile` (requires [just](https://github.com/casey/just)).

```bash
docker compose up -d          # Start containers (nginx, php-fpm, postgres)
composer install              # Install PHP dependencies
bin/console doctrine:migrations:migrate  # Run pending migrations
just tailwind                 # Build Tailwind CSS (watch mode, auto-rebuilds on change — already running in dev)
bin/console tailwind:build    # One-shot Tailwind build (use this in CI scripts and plan verify steps only)
```

Tailwind CSS is rebuilt automatically in the dev container — **never run `bin/console tailwind:build` manually after editing templates or `app.css`**. The watcher picks changes up within a second or two; if a class doesn't appear in the compiled CSS, wait briefly and re-check rather than reaching for a manual build. Only run `bin/console tailwind:build` explicitly in CI scripts or plan verify steps. The same applies to `cache:clear` — not needed in dev.

The app runs at `https://symfony-skeleton.dev.localhost`. PHP-FPM is on port 9000.

**Database connectivity:** the Postgres container is exposed via Traefik TCP routing at `db.symfony-skeleton.dev.localhost:5432`. The `.env` file ships with `127.0.0.1:5432` as a placeholder; override it in `.env.local` on your host machine:
```
DATABASE_URL="postgresql://app:!ChangeMe!@db.symfony-skeleton.dev.localhost:5432/app?serverVersion=16&charset=utf8"
```
From inside the php-fpm container (`just shell`), use the `database` Docker service hostname directly. The `compose.yaml` sets `DATABASE_URL` to `database:5432` for the container so this is automatic.

**Database test isolation:** Use `dama/doctrine-test-bundle`. Each test runs inside a database transaction that is automatically rolled back; no custom schema-reset code is needed. Schema creation runs once in `tests/bootstrap.php` (drop → create → migrate). Never write `resetSchema()` methods that drop and recreate the schema per test.

## Common Commands

```bash
just shell                    # Open bash in php-fpm container
just lint                     # Run parallel PHP linter
just cs-fix                   # Run PHP CS Fixer
just rector                   # Run Rector (PHP modernization)
just phpstan                  # Run static analysis (level 8)
just arkitect                 # Check module boundary rules (phparkitect)
just cs                       # Run rector + cs-fix (pre-commit subset)
just ci                       # Full static-analysis + unit-test gate: cs, phpstan, arkitect, gamache, ESLint, PHPUnit (e2e is separate)
just gamache                  # Run Gamache convention checker (replaces the seven custom check scripts)
just migrate-diff             # Generate migrations from entities
just migrate-run              # Run migrations
just e2e                      # Run Playwright e2e tests
just e2e-coverage             # Run e2e tests with PHP coverage collection
just open-coverage            # Open HTML coverage report
just browser-sync             # Live-reload proxy for template changes

php vendor/bin/phpunit        # Run tests
bin/console debug:router      # List all routes
bin/console cache:clear       # Clear cache
```

To run a single test: `php vendor/bin/phpunit --filter TestClassName`

To run a single e2e spec: `just e2e tests/<area>/<spec>.spec.ts`

## CSRF

`assets/controllers/csrf_protection_controller.js` is loaded **eagerly** (`/* stimulusFetch: 'eager' */`). This is intentional: login and other forms use `input[name="_csrf_token"]` directly without a `data-controller` attribute, so a lazily-loaded Stimulus controller would never activate and the document-level `submit` listener would never register. Do not change this to `'lazy'`.

For server-side CSRF validation on hand-rolled forms (plain HTML `<form>` elements not bound to a FormType), see "Stateless CSRF tokens for hand-rolled forms" in `project-backend`.

## Email

Email is sent synchronously everywhere (`message_bus: false` in `mailer.yaml`) — no queue worker needed in development or production.

The sender address and name are defined as `config/services.yaml` parameters: `app.mailer.from_address` and `app.mailer.from_name`. Inject them with `#[Autowire(param: 'app.mailer.from_address')]`. Never hardcode `new Address('noreply@...', '...')` inside a service or controller.

**Email sender services:** Each transactional email type gets its own sender service (e.g. `VerificationEmailSender`, `PasswordResetEmailSender`) in `src/Module/*/Service/`. The service owns URL generation, template path, subject key, and mailer parameters. Controllers call `$this->fooEmailSender->send($user)` and must never contain email-building or sending logic.

`src/Messenger/Middleware/PlaywrightSyncEmailMiddleware.php` exists but is not wired up. When async email is needed, re-enable it by:
1. Remove `message_bus: false` from `mailer.yaml`
2. Uncomment `sync: 'sync://'` in `messenger.yaml`
3. Add the middleware back to `messenger.bus.default`

## Architecture

The app is a Symfony application. Source code is organized into domain modules under `src/Module/`. Each module follows a consistent layout: `Controller/`, `Entity/`, `Form/`, `Repository/`, with templates mirrored under `templates/Module/`.

All features live under `src/Module/` (e.g. `src/Module/Foo/`). Admin-facing controllers for a given feature live under `Controller/Admin/` within that feature's module directory. The `Admin` module (if present) is the shell for the admin area (layout, dashboard, auth promotion) — it does not own feature logic.

**Doctrine entity mapping:** `config/packages/doctrine.yaml` maps `dir: '%kernel.project_dir%/src'` with `prefix: 'App'`. This covers all entities under `src/Module/*/Entity/`. Do not narrow this to `src/Entity` — that directory is empty and exists only as a skeleton placeholder. If the mapping is wrong, PHPUnit will fail with "Could not find the entity manager for class" errors on every entity.

**Stack:** Tailwind CSS + DaisyUI 5 for UI, Symfony UX Icons and Stimulus.js for interactivity.

**`AppController`:** All controllers must extend a project-level `AppController` (not Symfony's `AbstractController` directly). Add shared helpers there as they are needed. Existing helpers:
- `renderFormResponse(string $view, FormInterface $form, array $extra = []): Response` — renders and automatically sets HTTP 422 when the form was submitted (invalid), 200 otherwise. Use this in every controller that renders a form instead of chaining `->setStatusCode(...)` manually. When a `DomainErrors` exception is caught (see below), add the field errors to the form and re-render with this method.

**Controllers — current user:** Use `$this->getUser()` (inherited from `AbstractController` via `AppController`) to retrieve the authenticated user. Do not inject `Symfony\Bundle\SecurityBundle\Security` into a controller solely to call `getUser()`.

**Repositories must always be injected.** Never call `$em->getRepository(SomeClass::class)` anywhere — not in controllers, not in services. Inject the concrete repository class directly in the constructor. `EntityManagerInterface` may still be injected for `flush()` calls, but all reads must go through injected repositories.

**Property access — all PHP classes:** Properties should be public by default. Do not add a `getFoo()` getter just to expose a property — make it `public` instead. Use `public private(set)` only when external mutation would cause a real problem (e.g. an immutable identity like `$id`). Do not use a `{ get => $this->prop; }` property hook as a visibility workaround — that is a public property with extra steps. Property hooks are for computed or validated values only. Exceptions are interface-required methods (`getPassword()`, `getRoles()`, `getUserIdentifier()`), which must remain as methods.

**PHPStan `non-empty-string` at call sites:** When a property is annotated `/** @phpstan-var non-empty-string */`, PHPStan requires callers to pass `non-empty-string`, not plain `string`. To narrow a `?string` DTO value (e.g. from a form), use `$dto->field ?: throw new \LogicException('field required after validation')` — PHPStan understands the `?:` truthy check as `non-empty-string` narrowing. Do not use `(string)` casts or `@phpstan-ignore` to silence this.

**`DomainErrors` — command-to-controller validation bridge:** `App\Exception\DomainErrors` (`src/Exception/DomainErrors.php`) is the standard way to propagate field-level domain validation failures from command handlers back to controllers. Command handlers collect failures into `$errors` (an `array<string, string>` of `field name => translation key`), then `throw new DomainErrors($errors)` if non-empty. Controllers catch it and map each entry to a form field error via `$form->get($field)->addError(new FormError($this->translator->trans($key)))`. Do not add validation logic directly to controllers — push it into the handler and use this exception to surface it.

**Lightweight command pattern — required for all non-trivial controller actions:** Any controller action that does more than render a template or redirect must be backed by a command + handler pair. No Symfony Messenger involved.

- **Command** (`Command/FooCommand.php`): `final readonly class` with public promoted constructor properties and no logic. Pure data carrier.
- **Handler** (`Command/FooHandler.php`): `final readonly class` with an `__invoke(FooCommand): ReturnType` method. Owns all business logic, repository reads, persistence, and side effects. Throws `DomainErrors` for domain validation failures.
- **Controller**: injects the handler directly and calls it as a callable — `($this->fooHandler)(new FooCommand(...))`. The controller's only jobs are: parse the request, call the handler, handle `DomainErrors`, and return a response.

Do not put business logic directly in a controller. Do not route commands through Symfony Messenger unless async dispatch is explicitly required.

## Translation Enforcement

`just gamache` includes a translation check that scans `src/` PHP files and `templates/` Twig files for string literals that look like untranslated user-facing text (scored by word count, mixed case, punctuation, etc.).

**It does not fail CI** — exit code is always 0. Findings are advisory only.

**Suppressing a false positive:**
- PHP: add `// @translation-check-ignore` on the same line as the string
- Twig: add `{# translation-check-ignore #}` on the same line

**Configuration** (`gamache.php` in the project root):

| Key | Type | Default | Purpose |
|---|---|---|---|
| `ignoredCallSites` | `list<string\|Closure>` | `[]` | Skip strings passed to specific constructors or methods |
| `ignoreExceptionClasses` | `bool` | `true` | Skip strings passed to any class whose name ends in `Exception` |

To adjust the threshold or ignored call sites, edit the `TranslationCheck` entry in `gamache.php`.


## Gamache Checks

`just gamache` runs convention checks from the `ubermuda/gamache` package (`vendor/ubermuda/gamache/src/Check/`). Checks are configured in `gamache.php` at the project root.

**Adding or modifying a check:** gamache is an external package. To add a new check or change an existing one, open a PR on https://github.com/ubermuda/gamache. Do not add check classes directly to this project — `src/Utils/Gamache/` no longer exists.

**Configuring checks:** each check class accepts constructor parameters (see `gamache.php`). To pass custom options (e.g. `ignoredCallSites` on `TranslationCheck`), edit the constructor call in `gamache.php`.

## Icons

All icons must use the Symfony UX Icons bundle with Lucide. Never embed inline SVG. Prefer the Twig component form; `ux_icon()` is acceptable as an alternative.

```bash
php bin/console ux:icons:import lucide:ICON_NAME
```

```twig
<twig:UX:Icon name="lucide:x" class="w-3.5 h-3.5 shrink-0 mt-px" />
{{# or #}}
{{ ux_icon('lucide:x', {'class': 'w-3.5 h-3.5 shrink-0 mt-px'}) }}
```

`assets/icons/` is gitignored. UX Icons runs with `iconify.on_demand: true` (dev **and** test), so any Lucide icon resolves at render time from the iconify API — newly used icons render and pass tests/CI **without** being committed or pre-imported. `ux:icons:import` only populates a local cache (handy offline); it is not a prerequisite for a new icon to work, so you never need to commit the SVGs. If icon SVGs are accidentally committed, remove them with `git rm --cached` — **not** `git rm`.

**Stroke colour:** Imported Lucide SVGs use `stroke="currentColor"`. Control the stroke colour via a text colour class on the icon or its parent — never hardcode `stroke="white"` as an attribute.
