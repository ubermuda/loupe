# CLAUDE.md

This file guides Claude Code (claude.ai/code) when it works in this repository.

## Topic-specific guidance

These skills hold the detailed conventions for one area each. Invoke the relevant skill before you write or edit anything in that area. There are no exceptions. "I already know the pattern" and "this is a small change" are not exceptions, because the skills carry project-specific conventions that differ from the defaults and from each other.

| Skill | Use when working on… |
|---|---|
| `project-backend` | PHP code under `src/`: forms, DTOs, entities, controllers, flash messages |
| `project-command-handler` | Command and handler pairs, any controller action with business logic, `DomainErrors` |
| `project-authz` | Voters, `#[IsGranted]`, Mercure topic authorization, any access control |
| `project-testing` | PHPUnit tests: unit, integration, WebTestCase, mock setup |
| `project-e2e` | Writing or fixing Playwright tests under `e2e/` |
| `project-frontend` | CSS in `assets/`, Stimulus controllers, Turbo patterns, icons, any frontend visual behaviour |
| `project-templates` | `.html.twig` files or Twig component PHP classes |
| `project-worktrees` | Git worktrees: provisioning, URLs, per-worktree databases, worktree tooling |
| `project-deploy` | Deploying to production, `terraform apply`, verifying the live version |
| `project-next-steps` | Adding, editing, or closing entries in `docs/NEXT_STEPS.md` |
| `project-translations` | UI strings, translation keys, or adding a new locale |
| `project-site-review` | The site-review widget (`public/site-review/widget.js`), `src/Module/SiteReview/`, its API routes, dev harness or e2e specs |
| `loupe-documents` | Writing or revising any document submitted to the Loupe app through the `loupe` MCP |
| `project-tech-design` | A technical design that settles an architecture, an entity model, a module boundary, or a subsystem |
| `loupe-site-review` | Acting on site-review feedback through the `loupe` MCP: `site_review_get`, fixing comments, marking them addressed |
| `symfony-authorization` | Generic Symfony authorization mechanics: Voter classes, attribute naming, `#[IsGranted]` placement, `subject:` resolution, `is_granted()` in Twig |
| `symfony-entity-route-mapping` | Routes that resolve entities from URL parameters: `{param:variable}` notation, `#[MapEntity]`, multi-entity routes |
| `project-comments` | Writing or reviewing code comments and docblocks anywhere in `src/`, `assets/` or `tests/` |
| `working-with-prs` | Opening, gating, reviewing or merging a pull request |

A subagent does not inherit the skills you have loaded. When you delegate PHP, entity, migration or command work, state the relevant skill conventions in the prompt, or tell the subagent to invoke the skill first. A convention that lives only in a skill is silently missed otherwise. The brand-new-table migration rule in `project-backend` is the example that bit.

## Getting feedback on long documents

Submit a long-form document to the Loupe app through the `loupe` MCP, with `document_create` or `document_revise`, and give the user the review URL. This covers an implementation plan, a design spec, an RFC, an architecture write-up, or anything substantial the user reads at their own pace. That is what the app is for, so dogfood it. Invoke the `loupe-documents` skill before you write the document, because it carries the formatting rules for the review UI. Invoke `project-tech-design` as well when the document settles an architecture rather than a task list.

This applies only to a document meant for considered review. Keep ordinary conversation in the terminal: a clarifying question, a quick confirmation, a short summary, or options you are discussing inline. The test is simple. If the user would sit down and read it, send it to Loupe. If it is a turn in a discussion, keep it in the chat.

## Tracking open work

`docs/NEXT_STEPS.md` holds open work that has an addressee. Add an entry when someone must do something later: a follow-up, a known issue, or a design decision to revisit. An observation asks nothing of anyone. Put an observation in the relevant skill or in `docs/` instead.

Invoke the `project-next-steps` skill before you append, because the entry format needs an author, type and priority line. Never leave such a note in a code comment.

`docs/NEXT_STEPS.md` is committed, because a tracker only one checkout can see is a tracker the next session cannot read. That also makes it branch content. Two parallel branches that both append will conflict, and the resolution is to keep both entries. That holds for two branches that append. It is wrong for a branch that *resolves* entries, because the file's own rule is to delete a resolved entry, so keeping both sides restores work already done and nothing goes red. Hand that merge to the branch's author: an outside merger cannot tell a deliberate deletion from a lost one.

The tracker is public, and stays public. Moving it to GitHub issues was once the plan for the visibility flip. It is not any more, because a tracker an agent reads in one `cat` beats one behind an API call. Write every entry as public text, with no secrets, no customer names, and no venting about people. It is already in git history, so deleting an entry does not unpublish it.

Tracker entries go through a branch and a pull request like anything else. `main` is protected, so the old commit-straight-to-main shortcut is gone.

Delete a resolved entry entirely. Do not mark it "CLOSED", do not add a resolution note, and do not move it under a `CLOSED` heading. The file holds open work only.

## Git worktrees

Worktrees live in `.claude/worktrees/`, which is gitignored. Every worktree is a full application of its own. Run `just worktree-up` and it gets its own URL at `https://<name>.loupe.dev.localhost`, its own migrated and seeded database, and its own compiled CSS. Log in with `dev@loupe.test` and `password`, or `admin@loupe.test` and `password` for the admin area.

**The main session never moves into a worktree.** If you are the session running in the main checkout, do not call `EnterWorktree` and do not `cd` into `.claude/worktrees/`. Only a session that exists to work in one enters it: a background job, or an agent launched with `isolation: "worktree"`. Three things bite, all of them silently:

- Write access is single-valued per agent, and a plain subagent inherits the parent's current worktree. A main session that moves in binds every subagent it later dispatches to that same worktree. Work aimed at any other tree is then rejected, or lands in the wrong one.
- `main` is checked out in the main checkout and nowhere else. Merging, running `just cs` on `main` after a merge, and tearing a worktree down all need a session that still stands there. Git also refuses to check `main` out in a second worktree.
- Tearing down the worktree a session is bound to strands that session. Its writes keep pointing at a deleted path until it re-enters somewhere else.

Stay in the main checkout, and delegate the work that needs isolation.

**Running a command inside a worktree is not the same as moving into one.** The difference is whether the shell's working directory survives the call. `just worktree-up`, `bin/worktrees/compose-exec.sh` and the worktree e2e gate genuinely need the worktree as their cwd. Run them in a subshell, so the cwd dies with the command:

    ( cd .claude/worktrees/<name> && just worktree-up )

A bare `cd .claude/worktrees/<name> && just worktree-up` looks identical and is not. The Bash tool keeps its working directory between calls, so every later command in the session also runs from the worktree. This rule was arrived at by accident.

- Provision by name from the main checkout with `just worktree-up NAME`. The no-argument form still works from inside a worktree, and needing it is what made sessions `cd` there in the first place.
- Always branch off `main`, never off the current feature branch.
- Tear down with `just worktree-down <name>`, never with a bare `git worktree remove`.
- Serena's edit tools do not work from a worktree. The Serena MCP server is bound to the main checkout, so `replace_symbol_body`, `insert_*_symbol` and `replace_content` write to the main checkout instead of your worktree. Your branch stays unchanged and the main tree goes dirty. Use the built-in Edit and Write tools from a worktree. Serena read tools (`get_symbols_overview`, `find_symbol`, `find_referencing_symbols`) are safe from anywhere.
- Invoke the `project-deploy` skill before you deploy, run `terraform apply`, or report what version is live. It carries the trap that a `terraform apply` deployment does not re-pull the fixed `prod` tag, so the spec change ships and the code does not, and it names `/healthz` with `X-Probe-Token` as the only reliable way to read the running version.
- Invoke the `project-worktrees` skill before you provision, debug or write tooling for a worktree. It carries the commands, the symptoms and causes table (404 against 502, unstyled CSS, a rejected widget token), and the two rules that prevent real damage: never run bare `docker compose` from a worktree, and never match worktrees by directory name instead of slug.

## Claude commands

Custom slash commands go in `.claude/commands/` in this repository. Do not put them in a user-level (`~/.claude/commands/`) or system-level directory.

## Planning and shipping a feature

Decide two things at planning time, and say in the plan which of them the change needs.

**Documentation.** Ask whether the change alters what a user or an operator does, sees or configures. If it does, name the page that covers it under `docs/`: `docs/using/` for product behaviour, `docs/reference/` for commands and environment variables, `docs/operating/` for deployment and running, `docs/getting-started/` for setup, and `docs/extending/` for the plugin and MCP surface. A feature that ships with no doc change claims that nothing observable changed, so make that claim deliberately.

**Landing page.** Ask whether the change adds, removes or alters a capability the landing page claims, or should now claim. The page is `templates/Module/Landing/landing.html.twig` with its partials in `templates/Module/Landing/landing/`, and the marketing footer is `templates/_marketing_footer.html.twig`. A landing page that describes a product one release behind is worse than one that says less.

The third check, the changelog entry, happens after the merge. `working-with-prs` carries it, because its entry anchors to a commit that does not exist until then.

### What a new entity or feature must also register

Each row below fails silently. Nothing errors, no test goes red, and the miss surfaces later as wrong behaviour. Walk the table when a feature adds an entity, a route, an external call or a flag.

| Add an implementation of | When | If you forget |
|---|---|---|
| `Account/Deletion/AccountDataPurgerInterface` | the feature stores rows keyed to a user | account deletion leaves the data behind |
| `Account/Export/UserDataExporterInterface` | the same | the user's data export omits it |
| `Account/Install/InstallFlagDefaultsInterface` | the feature has a flag | a fresh install has no flag row |
| a `Project/Event/ProjectDeleting` listener | the module owns project-scoped rows | orphan rows outlive the project |
| `Project/Stats/ProjectStatsProviderInterface` | the entity is worth counting | project stats undercount |
| `Mcp/FlagGatedToolInterface` | an MCP tool is flag-gated | see below, it stays callable |
| `AdminMenuItemInterface` (admin bundle) | the feature adds an `/admin/` page | the page exists with no link to it |
| `DiagnosticInterface` (health-check bundle) | the feature calls an external service | a misconfiguration shows only as a user-facing failure |

Two of those carry an extra rule. A purger must read `$user->id` as a scalar, because the entity may be detached, and its `deletionOrder()` must run after `ProjectAccountPurger`. A flag-gated MCP tool must re-check its flag inside `__invoke`: the tag only filters `tools/list`, so a tool that skips the self-check is hidden and still fully callable.

Four more that no registry covers:

1. **A new flag does not reach an instance that is already installed.** Seeding runs from the install wizard and from `app:account:seed-install-flags`, and `docker/prod/release.sh` runs migrations only. `FeatureFlagService::isEnabled()` then falls back to the coded default, so the feature ships invisible. `bin/console app:dev:seed` seeds no flags either, so dev reads the same defaults.
2. **A new messenger transport or schedule name must reach the worker command in two files**, `worker_command` in `terraform/main.tf` and the `worker` service in `docker/compose/prod.yaml`. Otherwise the transport fills and nothing drains it. A message class missing from `config/packages/messenger.yaml` is handled inside the web request instead, which works and is in the wrong process.
3. **CSP is enforced in prod and empty in dev and test.** A feature that loads a new script, font, image or XHR origin works locally, passes e2e, and is blocked in production. Declare the origin in `config/packages/nelmio_security.yaml` with the `%env(default:app.csp_origin_fallback:VAR)%` shape, so an unset variable resolves to `'self'`.
4. **Navigation is hand-written in three places**: the app sidebar in `templates/base.html.twig`, the docs sidebar in `website/astro.config.mjs`, and the tool order in `Project/Mcp/AdvertisedTools`. A page or tool that is not listed is reachable only by typing its URL.

Security surfaces that fail loudly need no checklist entry. A new `/api/` route with no rule above `allow_if: 'false'` in `config/packages/security.yaml` is denied, and a missing `ApiTokenScope` case is a PHP error. Rate limiting is the exception: `config/packages/rate_limiter.yaml` is hand-declared, so a new token-authenticated write endpoint has none by default.

CI catches the rest. `DeploymentConfigParityCheck` cross-checks a new environment variable across `.env`, Terraform and the prod compose files, though `docs/reference/environment.md` is still yours to update.

## Pull requests

Invoke the `working-with-prs` skill before you open, gate or merge a pull request. It carries the full gate, the merge protocol, the ruleset facts and the wave rules. This is the irreducible summary, so that a session which skips the skill still does the right thing:

- The gate is `just cs`, then `just ci`, then `just e2e`, then a Codex review with `mcp__codex-cli__review` and `model: "gpt-5.6-sol"`, against `origin/main`. Fix every failure, including pre-existing ones. If the Codex MCP is missing, stop and tell the owner rather than routing around it.
- A branch whose every changed file ends in `.md` runs steps 1 and 2 only, and says so in the body. Verify with `git diff --name-only origin/main...HEAD | grep -v '\.md$'`. Any output means the full gate applies.
- `main` is protected and takes `--squash` only, so the PR body becomes the commit body. It requires eight CI checks and one approving review. An approval in chat is not a GitHub approval.
- Never approve your own work, because the review stays with a human. Merging does not: a PR that is approved with all required checks green is good to merge, without asking. Never merge one that is unapproved, has a failing or pending check, or would need `--admin`.
- A green gate is not evidence the change is correct. Read the diff.

## Writing style

Write everything a human or another agent reads in ASD-STE100 Simplified Technical English, and remove the AI writing tells. This covers chat replies, commit messages, PR bodies, Loupe documents, skills, and `docs/NEXT_STEPS.md`. It does not cover code, which follows the conventions below.

Apply the STE writing rules, not the approved word list. Technical names and technical verbs stay as they are.

- Use the active voice.
- Use a maximum of 20 words in an instruction, and 25 words in a descriptive sentence.
- Write one instruction per sentence.
- Use a maximum of six sentences in a paragraph.
- Use the simple present tense, or the imperative.
- Do not use a noun cluster of more than three words. An identifier is one word, however it is spelled.
- Keep articles and subjects. Full short sentences are the target. Telegraphic fragments are not.

Remove these tells: negative parallelism ("it is not X, it is Y"); a preamble that announces the answer before it gives it; stakes inflation; a summary of a section inside that section; a count before a list; bold lead-ins on bullets; em dashes and arrows; and magic adverbs such as "quietly", "deeply" and "seamlessly".

An existing file keeps its old prose until a rewrite touches it. The `compressing-skills` skill carries the full tell list and the procedure for a rewrite pass. `~/.claude/skills/ai-writing-tells/check.py` finds many tells mechanically, and its output is advice, not a gate.

## Recommendations and the quality bar

The owner ranks the priorities `correctness > simplicity > performance > shipping speed`. `docs/contributing/architectural-priorities.md` says which one yields in each of the six collisions, and when to escalate rather than apply the ranking. Read it before you call a trade-off.

### The owner sets the bar, not the agent

Deciding what is good enough, and what has to be exactly right, is the owner's call. The failure to avoid is making that call silently and then presenting the outcome as a technical necessity, which removes the decision instead of informing it. Ranking findings as must-fix and blocking on them, declaring one branch's failing run to outrank a merge queue, imposing mutation checks as a standard, requiring a stale form submission to be refused rather than resolved: each of those is a judgement about how much rigour something deserves. Several of them may well be right. The point is who made them.

So name the severity and the cost of fixing, recommend, and let the owner set the bar. This matters most where the fix is expensive, where the defect is unreachable in practice, or where "leave it and note it" is a legitimate answer. Reserve blocking language for what is genuinely unsafe to ship. Say plainly when something is a judgement call rather than a requirement.

The tell to watch for is describing a preference as though it were a property of the code. "This must refuse" and "I think refusing is better, here is the cost of each" are different sentences, and only the second leaves the decision where it belongs.

### Weigh the trade-offs, do not defend one option

The habit to break is picking an approach and then arguing for it. The recommendation is rarely the problem. The problem is that the reasoning gets inflated to match the conclusion. A minor downside of the rejected option gets described in the register of a serious one, which makes the argument unfalsifiable and hides how close the call was. A measured one-millisecond cost does not need dressing up as a real correctness cost to justify the right answer. Overstating the case is worse than a weaker conclusion argued honestly.

What to do instead:

1. State the options and what each actually costs, in proportionate language.
2. Give a recommendation and the confidence behind it, without padding the rejected options to justify it.
3. Say plainly when a call is close, or when it rests on taste rather than evidence. "Either is fine, I lean X" is a legitimate answer.
4. Keep the alternatives genuinely available, rather than mentioning them as a courtesy before dismissing them.

This sits in tension with the instruction not to capitulate under pressure, and the tension is the point. Hold a position against disagreement, but do not manufacture support for it.

## General guidelines

- When you create new code, ask which module it belongs in. Ask your human if you are not sure.
- Generic code reused across modules can live in the project's root namespace.
- Stage files by name for a commit, never with `git add -A`. Another feature branch may have uncommitted working-tree changes that `git add -A` silently includes.
- Read `git diff --cached` before you commit. Staging by name protects against another branch's *files*, not against another session's *hunks* in a file you are both editing, because `git add <path>` stages the whole working-tree version of that path. A commit that touched the one file you expected can still carry someone else's lines.
- Verify the current code state when you address PR feedback. Do not trust a prior agent's reply. A thread that says "Done" may not reflect what was committed, so read the file and confirm the change is present before you mark it addressed.
- Fix failing and flaky tests in any test run you observe, including ones that pass on retry, ones that pre-date your change, and ones unrelated to your task. A flaky test that passes on retry is a real failure that will break CI later. The only acceptable response to a flake is a fix.
- Outbound network calls are allowed, but only as opt-in features. A self-hosted instance must work with no egress out of the box, which is why `assets/icons/` is committed and `iconify.on_demand` is off in prod. That is a default, not a prohibition. A feature that calls out is fine when an operator has to switch it on, and when the app behaves correctly both while it is off and when the call fails. `about.update_check.enabled` is the reference shape: the flag is seeded off, the HTTP client is scoped with bounded timeouts, the answer is cached, and every failure path returns null rather than breaking the page. Do not argue against an egress feature on principle. Argue about whether it is opt-in and degrades cleanly.
- Never write a `TODO`, `FIXME` or `XXX` comment in code. Capture follow-up work in `docs/NEXT_STEPS.md` or an issue. An in-code TODO is invisible to future sessions and rots silently. `NoTodosCheck` enforces this under `just gamache`.
- Keep comments short. The default is no comment at all. A comment earns its place only by recording something a reader cannot recover from the code, the tests or `git log`. The reasoning behind a change belongs in the commit message or the PR body. `CommentBudgetCheck` fails on any run of 6 or more consecutive comment lines, in PHP, Twig, JS, CSS, YAML and the justfile alike. It is binding: `just gamache` exits non-zero, so `just ci` goes red. Shorten the block, or mark it `@comment-budget-ignore` when it has earned its length. Invoke the `project-comments` skill before you write a comment longer than two lines.
- Code comments must be self-contained. Never reference an internal or ephemeral development artifact a future reader cannot open: a numbered task (`Task 16`), a handoff or design document (`handoff screen 8`), a spec section (`§3.5`), a dev phase (`Part 1`), or a dated decision (`owner decision 2026-07-13`). State the underlying fact directly. `SelfContainedCommentsCheck` enforces this under `just gamache`.
- Give a Doctrine migration a real current-datetime timestamp in its `VersionYYYYMMDDHHMMSS` class name. Never use a round placeholder such as `…000000`. Two parallel branches that both use a round number collide on the version prefix. The class names still differ, so it is harmless, but it is confusing and it breaks `migrate-diff` ordering assumptions.
- To consume an unmerged `ubermuda/*` bundle branch, pin `"dev-<branch>#<full-40-char-sha> as dev-main"`. The `as dev-main` alias is load-bearing, because sibling ubermuda packages require `dev-main@dev`. After the bundle PR merges, repoint to plain `"dev-main#<merge-sha>"` and drop the alias in the same wave. Re-copy any assets the bundle ships, such as `feature_flag_form_controller.js`, at every pin change.
- PHP gotchas, including the Serena rename workaround, FormType rename side-effects and the constructor change checklist, live in `project-backend`.

## Development environment

This project runs on Docker Compose, with commands in a `justfile` (requires [just](https://github.com/casey/just)).

`COMPOSE_PROJECT_NAME` is set in `.env` and is required, because compose refuses to start without it. It names the containers, the traefik routes and the database volume. Change it before the first `docker compose up` when you bootstrap a new project from this skeleton. Two projects that share a name silently share containers and the database volume.

```bash
docker compose up -d          # Start containers (nginx, php-fpm, postgres)
composer install              # Install PHP dependencies
bin/console doctrine:migrations:migrate  # Run pending migrations
just tailwind                 # Foreground Tailwind watcher (the `tailwind` compose service already does this)
bin/console tailwind:build    # One-shot Tailwind build (use this in CI scripts and plan verify steps only)
```

The dev container rebuilds Tailwind CSS on its own, so never run `bin/console tailwind:build` by hand after you edit a template or `app.css`. The watcher picks a change up within a second or two. When a class does not appear in the compiled CSS, wait and re-check rather than reaching for a manual build. Run `bin/console tailwind:build` explicitly in CI scripts and plan verify steps only. The same applies to `cache:clear`, which dev does not need.

The app runs at `https://loupe.dev.localhost`, and PHP-FPM is on port 9000. The `worker` compose service consumes the async transport. Use `docker compose logs worker` to observe it, or `just worker` for a foreground consumer. The `tailwind` compose service watches and rebuilds the stylesheet the same way, with `docker compose logs tailwind` and `just tailwind`. Never run both, because two watchers write the same file and race.

**A process started inside a container can only be observed and stopped from inside it.** The container has its own PID namespace, so a host-side `pkill -f <script>`, and the harness's own `TaskStop`, kill only the wrapper. They report success while the real process keeps running, invisible to the host process table. Stop it with `docker compose exec php-fpm pkill -f <script>`, and confirm with `docker exec <project>-php-fpm-1 ps aux`. A host-side check shows a quiet container that is in fact fully loaded.

**Production runs per-process containers.** Each process type, web and messenger worker, is its own container from the same image. `docker/prod/supervisord.conf` is only the web container's image-default CMD, so never add a background process there as a `[program:]` block. The worker's command belongs to whatever orchestrates the containers: `worker_command` in `terraform/main.tf` for App Platform, or the `worker` service in `docker/compose/prod.yaml` for a single host. Both run `messenger:consume scheduler_default async --time-limit=3600 --memory-limit=128M`. The schedule transport comes first, because a deep async backlog must not delay ticks.

`docker/compose/prod.yaml` is the reference single-host topology: web, worker, Postgres and a Mercure hub. It is a distributed artefact rather than a scratch file, so it must keep working alongside `terraform/`. It is driven by `docker/compose/prod.env`, which is gitignored, and `docker/compose/prod.env.example` is its template. Run it with `--env-file`, because Compose otherwise interpolates the development `.env`. `compose.yaml` stays dev-only and shares nothing with it.

**Database connectivity.** Traefik TCP routing exposes the Postgres container at `db.loupe.dev.localhost:5432`. The `.env` file ships `127.0.0.1:5432` as a placeholder, so override it in `.env.local` on your host machine:

```
DATABASE_URL="postgresql://app:!ChangeMe!@db.loupe.dev.localhost:5432/app?serverVersion=16&charset=utf8"
```

From inside the php-fpm container (`just shell`), use the `database` Docker service hostname directly. `compose.yaml` sets `DATABASE_URL` to `database:5432` for the container, so this is automatic.

**Database test isolation.** `dama/doctrine-test-bundle` wraps each test in a database transaction and rolls it back, so no custom schema-reset code is needed. Schema creation runs once in `tests/bootstrap.php`, which drops, creates and migrates. Never write a `resetSchema()` method that drops and recreates the schema per test.

**php-cs-fixer works from worktrees.** `.php-cs-fixer.dist.php` uses explicit excludes rather than `ignoreVCSIgnored(true)`, and throws when the finder matches zero files. Both matter. The old VCS-ignore heuristic matched 0 files under the gitignored `.claude/worktrees/`, so `just cs` fixed nothing and `just ci`'s cs-check leg passed vacuously. A committed brace jam once sailed through a green gate that way. `.claude` stays excluded deliberately, because worktrees live inside the main checkout and the main run would otherwise scan every worktree's copy of the tree.

## End-to-end tests

CI's `e2e` check is the gate. Push, read the check, and fix every failure it reports, including pre-existing ones. Do not run the full suite locally before you open a PR, because a local run is slower, destructive and less truthful. Run it locally for a named spec you are working on, with `just e2e tests/<area>/<spec>.spec.ts`, or for a branch you cannot push yet.

`just e2e-up` creates the disposable target the suite runs against: an `app_e2e` database and an nginx sidecar at `e2e.<project>.dev.localhost` serving this checkout. `just e2e` defaults there and refuses to start when it is not up. Tear it down with `just e2e-down`. Never point the suite at the dev host. The suite is destructive by design: the `install-reset` project truncates every table, and `trial-end-lifecycle` flips global feature flags and disables every expired-trial account. One run against `loupe.dev.localhost` wipes your development database.

`playwright.config.ts` keeps `workers: 1`. Each worktree now has its own Mailpit sidecar, so the mail coupling that blocked parallelism is gone, but nothing has yet gated two branches concurrently to prove the rest of the suite is parallel-safe.

The `project-e2e` skill carries the rest, including its `references/parallelism-and-sessions.md` for fixtures, Mailpit and session invalidation. `working-with-prs` carries how to aim a local run at a sibling worktree, which needs both `E2E_BASE_URL` and `MAILPIT_URL`, and the cache warm-up that a worktree run needs first.

## Common commands

```bash
just shell                    # Open bash in php-fpm container
just lint                     # Run parallel PHP linter
just cs-fix                   # Run PHP CS Fixer
just rector                   # Run Rector (PHP modernization)
just phpstan                  # Run static analysis (level 8)
just arkitect                 # Check module boundary rules (phparkitect)
just cs                       # Write-mode fixer pipeline: prettier, lint, rector, cs-fix, twig-cs-fix
just ci                       # Check-only gate (never rewrites files): lint, cs-check (rector/cs-fixer/twig-cs-fixer dry-run), phpstan, arkitect, gamache, composer audit, PHPUnit, Vitest (e2e is separate)
just audit                    # Security advisories against composer.lock (also runs inside `just ci`)
just gamache                  # Run Gamache convention checker (replaces the seven custom check scripts)
just migrate-diff             # Generate migrations from entities
just migrate-run              # Run migrations
just js-test                  # Run Vitest over tests/js (needs Node alone)
just e2e                      # Run Playwright e2e tests
just e2e-coverage             # Run e2e with per-request PHP coverage, merged to var/coverage/html
just open-coverage            # Open the merged HTML coverage report
just browser-sync             # Live-reload proxy for template changes

php vendor/bin/phpunit        # Run tests
bin/console debug:router      # List all routes
bin/console cache:clear       # Clear cache
```

To run a single test: `php vendor/bin/phpunit --filter TestClassName`

To run a single JavaScript test: `just js-test tests/js/<name>.test.js`

To run a single e2e spec: `just e2e tests/<area>/<spec>.spec.ts`

## CSRF

`csrf_protection_controller.js` must stay eagerly loaded (see `project-frontend`). For server-side CSRF on hand-rolled forms, read "Stateless CSRF tokens for hand-rolled forms" in `project-backend`.

## Email

The messenger worker delivers email asynchronously. `MailerInterface::send()` enqueues a `SendEmailMessage` on the `async` transport, and `messenger:consume async` performs the delivery: the dev `worker` compose service, or a dedicated worker in production. A failed delivery retries 3 times, then lands in the `failed` transport. `project-backend` documents the sender parameters and the per-email-type sender services.

## Architecture

The app is a Symfony application. Source code is organised into domain modules under `src/Module/`, and each module follows the same layout: `Controller/`, `Entity/`, `Form/`, `Repository/`, with templates mirrored under `templates/Module/`.

Every feature lives under `src/Module/`. Admin-facing controllers for a feature live under `Controller/Admin/` inside that feature's module. The `Admin` module is the shell for the admin area, covering layout, dashboard and auth promotion, and it does not own feature logic.

**Doctrine entity mapping.** `config/packages/doctrine.yaml` maps `dir: '%kernel.project_dir%/src'` with `prefix: 'App'`, which covers every entity under `src/Module/*/Entity/`. Do not narrow it to `src/Entity`, which is empty and exists only as a skeleton placeholder. When the mapping is wrong, PHPUnit fails with "Could not find the entity manager for class" on every entity.

**Stack.** Tailwind CSS v4 for UI, with no component library. The visual system is hand-rolled: CSS custom properties in the `@theme` design-token block, plus semantic component classes in `@layer components`, all in `assets/styles/app.css`. Templates use those semantic classes rather than raw utility strings. DaisyUI was removed in the 2026-06-19 visual redesign, so do not reach for `btn`, `card` or `modal` classes, and do not reintroduce a component library without a decision to. Symfony UX Icons (Lucide) and Stimulus.js provide interactivity. `project-frontend` carries the conventions.

**Core PHP conventions.** `project-backend` and `project-command-handler` carry the details and the exact patterns, so invoke them before you write PHP.

- Every controller extends the project-level `AppController`, never `AbstractController` directly. Use its `renderFormResponse()` for every form response, which sets 422 on an invalid submit.
- Any controller action that does more than render or redirect is backed by a command and handler pair, `Command/FooCommand.php` and `Command/FooHandler.php`, with no Messenger. `DomainErrors` carries field-level domain failures from the handler back to the form.
- Repositories are always constructor-injected. Never call `$em->getRepository()`.
- Properties are public by default in all PHP classes. Do not write a getter that merely exposes a property.

## Translation enforcement

`just gamache` includes a translation check. It scans PHP files under `src/` and Twig files under `templates/` for string literals that look like untranslated user-facing text, scored by word count, mixed case and punctuation.

It never fails CI, because its exit code is always 0. Its findings are advisory.

To suppress a false positive, add `// @translation-check-ignore` on the same line in PHP, or `{# translation-check-ignore #}` on the same line in Twig.

To configure it, edit the `TranslationCheck` constructor call in `gamache.php` at the project root. That call documents its own options inline: ignored call sites (`ignoredCallSites`), exception classes, ignored source namespaces, safe attribute namespaces and safe Twig functions.

## Gamache checks

`ubermuda/gamache` enforces project conventions through five layers, each wired into a different tool. Check all five before you conclude that gamache has no rule for something. Most of them run under `just ci` rather than `just gamache`.

| Layer | Package dir | Wired via | Run by |
|---|---|---|---|
| Convention checks | `src/Check/` | `gamache.php` | `just gamache` (`vendor/bin/gamache`) |
| PHPStan rules | `src/PHPStan/` | `extension.neon` + `parameters.gamache:` in `phpstan.dist.neon` | `just phpstan` / `just ci` |
| Rector rules | `src/Rector/` | `GamacheSetList::CONVENTIONS` in `rector.php` | `just rector` |
| PHP-CS-Fixer rules | `src/PhpCsFixer/` | `Gamache\PhpCsFixer\Fixers` in `.php-cs-fixer.dist.php` | `just cs-fix` |
| Twig-CS-Fixer rules | `src/TwigCsFixer/` | `GamacheStandard` in `.twig-cs-fixer.php` | twig-cs-fixer |

`just gamache` runs the `src/Check/` layer only, which holds the advisory and structural convention checks. The other four belong to the normal static-analysis pipeline. So "does a gamache check exist for this?" is answered by grepping the package's `src/Check/`, `src/PHPStan/`, `src/Rector/`, `src/PhpCsFixer/` and `src/TwigCsFixer/`.

The PHPStan-rule layer sees only the files in PHPStan's `paths:` in `phpstan.dist.neon`. `migrations/` must stay in that list, or a migration-targeting rule such as `MigrationDescriptionRule` silently never runs. Add a new top-level source dir to `paths:` when a rule should police it.

gamache is an external package, so open a PR on https://github.com/ubermuda/gamache to add or change a rule in any of the five layers. Do not add a rule, check or fixer class to this project. `src/Utils/Gamache/` no longer exists.

Each `src/Check/` class takes constructor parameters, set in `gamache.php`. The PHPStan layer is configured under `parameters.gamache:` in `phpstan.dist.neon`.

## Icons

Use the Symfony UX Icons bundle with Lucide for every icon. Never embed inline SVG.

```twig
<twig:UX:Icon name="lucide:x" class="w-3.5 h-3.5 shrink-0 mt-px" />
```

`assets/icons/` is committed, because a self-hosted instance must render its UI with no egress. After you add a new icon, run `bin/console ux:icons:lock` and commit what appears under `assets/icons/`. Do not `git rm --cached` these files, because that breaks production rendering.

`ux:icons:lock` reads literal names only. Import a dynamically-named icon by hand with `bin/console ux:icons:import`, or it is missing in prod with no error.

The `project-frontend` skill carries the rest: the `ux_icon()` form, the `iconify.on_demand` behaviour per environment, and the stroke-colour rule.
