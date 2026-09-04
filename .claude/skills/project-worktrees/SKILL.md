---
name: project-worktrees
description: Use when creating, entering, debugging or removing a git worktree under `.claude/worktrees/`, when a worktree URL returns 404 or 502, or when writing tooling that resolves a worktree's hostname, database or container name.
---

# Git Worktrees

## Overview

Every worktree is a full application of its own, with its own URL, database and
compiled CSS. Only nginx and Mailpit are duplicated. php-fpm, Postgres and the
Mercure hub are shared with the main stack. Mercure sits behind a compose profile
and stays off until someone runs `just mercure-up`, so a worktree working on
site-review push must start it, and then shares that one hub with every other
worktree.

This works because the main checkout is mounted at `/var/www/html` and worktrees
live inside it, so a worktree's files sit at the same path in its own nginx
sidecar and in the shared php-fpm. Only the document root differs. A worktree is
served through its own `public/index.php`, so Symfony boots with the worktree as
its project directory and reads that worktree's `.env` chain, over HTTP and on
the CLI alike.

## Quick reference

| Command | Does |
|---|---|
| `just worktree-up NAME` | Provision (or repair) a worktree, from anywhere. Idempotent. |
| `just worktrees` | Every worktree with its URL, database and sidecar status |
| `just worktree-down <name>` | Remove worktree + sidecar + both databases |
| `just worktree-prune` | Clean up sidecars/databases orphaned by `git worktree remove` |
| `just worktree-tailwind` | Tailwind watch mode for the current worktree |

Each worktree gets `https://<slug>.loupe.dev.localhost`, dev DB `app_wt_<slug>`,
test DB `app_test_<slug>`, compose project `loupe-wt-<slug>`, and a Mailpit
sidecar at `https://mailpit-<slug>.loupe.dev.localhost` (SMTP alias
`mailpit-<slug>` on the app network). A name that normalises to a slug beginning
`mailpit-` is refused, because it would claim a sibling's mail host. Log in with
`dev@loupe.test` / `password`, or `admin@loupe.test` / `password` for the admin
area. To skip the login form, run `bin/console app:dev:preview-login-link
--path=/projects` inside the worktree. It prints a signed link that signs you in
and lands you on that page. The signature covers the host, so the link works
against that worktree only. It does not expire.

Run `just up` first. Bootstrap fails fast rather than leave a worktree whose
`.env.local` points at a database that was never created.

## The lifecycle runs itself

`.claude/settings.json` registers two hooks, so a worktree arrives provisioned
and leaves nothing behind.

| Hook | Script | Receives | Does |
|---|---|---|---|
| `WorktreeCreate` | `.claude/hooks/worktree-create.sh` | `name` | Creates the worktree, runs `worktree-bootstrap.sh`, prints the path |
| `WorktreeRemove` | `.claude/hooks/worktree-remove.sh` | `worktree_path` | Runs `worktree-teardown.sh` with `WORKTREE_TEARDOWN_KEEP_TREE=1` |

The two events have different contracts. `WorktreeCreate` does not react to a
worktree the harness made: it receives a *name*, produces the worktree itself,
and prints its absolute path as the **last line of stdout**. A non-zero exit or a
missing path fails the creation, so all other output goes to stderr. It puts the
tree at `.claude/worktrees/<name>` on a branch of the same name, cut from `main`
rather than the current branch. If bootstrap fails it removes what it created
before exiting non-zero, because a worktree that cannot run `just ci` is what the
hook exists to remove, and an orphaned directory plus branch is a second problem.

"What it created" is exact, and getting it wrong loses commits. Provisioning an
*existing* branch creates only the worktree, so the rollback tracks the two facts
separately and deletes the branch only when it made the branch *and* the ref
still points where it put it. One flag for both once deleted an existing unmerged
branch and its commits. Apply the same test to anything you add: does it undo
something that predated the run?

`WorktreeRemove` **cannot block**, because its exit code is only logged in debug
mode. That script is best-effort and idempotent, and leaves `git worktree remove`
to the harness so the two cannot race. `just worktree-down NAME` by hand removes
the tree too. Both hooks ignore anything outside `.claude/worktrees/`. Hook
`timeout` is in seconds and defaults to 600, which these use; bootstrap must fit
inside it.

Teardown kills anything still running against the worktree in the shared php-fpm
before it drops the databases. It matches on the process working directory,
because every worktree's console process has an identical command line in one
container, and it drops with `--force`. A surviving consumer used to make
`dropdb` fail silently and orphan both databases.

## Rules that prevent real damage

Prefer the `NAME` form of every worktree command, and run it from the main
checkout: `just worktree-up NAME`, `just worktree-down NAME`. `worktree-up` also
accepts no argument and falls back to the tree you stand in, but that fallback is
why sessions used to `cd` into a worktree, and a `cd` persists across later tool
calls and moves the whole session (CLAUDE.md, "The main session never moves into
a worktree"). Running from main is also correct by construction, because the bare
`docker compose` calls inside the bootstrap script resolve their compose file
from the current directory.

Before you trust any write, confirm the path is in `git worktree list`. Existence
on disk is exactly what misleads here. When a worktree is removed while an agent
is still bound to it, a write to the old path **reports success**: it recreates a
bare directory that is not a git worktree, so nothing lands on the branch and
nothing raises, and `git status` there falls through to the main checkout and
reports `main`. Check membership:

```bash
git worktree list --porcelain | grep -qx "worktree $(pwd)" || echo "NOT a worktree — stop"
```

An agent in this state must **stop**, not improvise. Checking the branch out into
the main checkout collides with `main`, already checked out there. `cp`, `rsync`
and `git checkout` from another worktree's path bypass the write binding rather
than repair it. Only the orchestrating session can fix it, by provisioning a
worktree with that branch and rebinding.

Never run bare `docker compose up/down/restart` from a worktree. `.env` sets
`COMPOSE_PROJECT_NAME=loupe`, so compose targets the **shared** stack but resolves
`.` to the worktree, and recreates the main app's containers with the worktree as
their document root. Use the `just` recipes, or `bin/worktrees/compose-exec.sh`
to run something in the shared php-fpm against the current worktree's files.

A `docker compose -f <file>` call with unset variables does not fail safely
either. `docker/compose/e2e.yaml` and `docker/compose/worktree.yaml` declare
their inputs with `${VAR:?}`, which protects `up` but not `down`: running `down`
on one without those variables was observed to remove the **main stack's**
containers and to attempt a delete of the `loupe_default` network, even though
`-p` named a different project. A teardown recipe must pass the same variables
its bring-up did.

Tear down with `just worktree-down`, never a bare `git worktree remove`, which
leaves both sidecars and both databases behind and makes the route serve 502s.
`just worktree-prune` cleans up after the fact.

Recover a worktree's identity from the compose project *label*, never from a
container name. A worktree has more than one sidecar, so code that strips a
service suffix (`-nginx-1`) breaks the moment a different service is the
survivor: prune once parsed a Mailpit-only orphan as the slug `<slug>-mailpit-1`,
then drove a teardown, and a database drop, from a slug belonging to nothing.
Read it with `docker ps --format '{{.Label "com.docker.compose.project"}}'`.
Adding a service must not break cleanup. Teardown also passes `--remove-orphans`,
so a `down` still removes a running service the resolved compose file does not
declare.

The slug is not the directory name: `Feature_X` owns the slug `feature-x`. Any
tooling that matches worktrees (pruning, listing, collision checks) must resolve
them through `bin/worktrees/slug.sh` (`worktree_slug`, `worktree_db_token`,
`worktree_slug_index`), never by string-matching a directory name. Matching the
wrong one has already declared a live worktree orphaned and dropped its
databases.

Serena's edit tools do not work from a worktree. The MCP server is bound to the
main checkout, so `replace_symbol_body` / `insert_*_symbol` / `replace_content`
silently write there, which leaves your branch unchanged and the main tree dirty.
Use Edit/Write. Serena **read** tools are safe from anywhere.

## Subagents and the write binding

The Edit/Write binding is **single-valued per agent**, and depends on how the
agent was launched.

Plain subagents inherit the parent session's *current* worktree, not the one they
were launched against and not the one they `cd` into. An edit anywhere else is
rejected with "This session is now isolated in `<path>`". A subagent that calls
`EnterWorktree` moves its `pwd` but does **not** rebind its write access, so N
subagents dispatched at N worktrees silently fail for all but the bound one.
Serialize instead: `EnterWorktree(path)` in the parent, dispatch, wait, rebind,
next.

Check the confirmation wording before dispatching. "The session is now working in
the worktree" propagates to subagents. "This agent's working directory and write
access now point at the worktree" applies to the parent only, and appears when
you switch straight into a freshly created worktree; re-issue `EnterWorktree`
until you get the first.

Agents launched with `isolation: "worktree"` get their own binding and can write
in parallel. Verified: the agent lands in `.claude/worktrees/agent-<id>` on its
own branch, writes there freely, and is refused when writing into another
worktree. The `WorktreeCreate` hook provisions it, so it is a full application
from the start, not a bare `git worktree add`; if that hook is not registered,
run `just worktree-up` yourself.
The name is harness-generated (`agent-<id>`, and the branch takes that name), so
work that must land on a named branch has to be renamed and pushed deliberately.
These worktrees are created **locked**, but `just worktree-down agent-<id>` still
removes one cleanly, sidecars and both databases included, with no `--force`.

Tear an agent's worktree down only after its branch is pushed. **Never tear down
the worktree the parent session is bound to**: the binding keeps pointing at the
deleted path, and every later Edit fails until you `cd` elsewhere and re-issue
`EnterWorktree`.

Verify the binding before dispatching, not after: make one trivial edit in the
target worktree and confirm it lands. Three times in one wave an agent found only
mid-task that its writes were pinned elsewhere, and each cost a full agent run.

Give every subagent that same step-0 writability check and an explicit
instruction to **stop and report** if it is rejected. Forbid the *plausible*
workarounds, not just the obvious ones: an agent told not to use `cat`/`sed`
edited the wrong worktree and `cp`'d the finished file across. It reported that
honestly, but it bypassed the guard, and the next one may not verify as
carefully. `cp`, `rsync`, `git checkout` of another worktree's path,
Serena's edit tools and any shell redirection are all bypasses. If writes land in
the wrong worktree, stop, and let the orchestrator fix the binding. Work already
on disk survives a stopped agent, so resume it with a message rather than a
restart.

Parallel writes do not yet parallelise the gate. Each worktree has its own
Mailpit sidecar and `just e2e` points the suite at it, but
`playwright.config.ts` still sets `workers: 1`, and no branch has been gated
concurrently to prove the rest of the suite is parallel-safe. Until someone does
that, treat roughly three minutes per branch as the ceiling.

## Reusing one worktree for several branches

Because only one worktree is writable at a time, a wave of branches often runs
through a single provisioned worktree. Its directory name then stops matching its
branch. That is expected; do not "fix" it.

Run these after **every** `git checkout <other-branch>` in a reused worktree, and
before any gate is meaningful:

1. `bin/worktrees/compose-exec.sh composer install`. The lockfile differs between
   branches, and a drifted `vendor/` produces errors that look like application
   bugs: it once reported `Call to undefined method` for a method the *locked*
   package version does define.
2. `bin/console cache:clear` for **both** `dev` and `--env=test`. Clearing only one
   leaves phpstan without the dumped dev container ("Container ... does not
   exist"). A `var/cache` that survived a Symfony minor upgrade 500'd every page
   rendering an icon, and 31 e2e specs failed from that one stale cache.
3. `bin/console doctrine:migrations:status`. A migration from the previous branch
   shows as *executed but unavailable*. If it does, reset the dev database (drop,
   create, migrate) then run `just worktree-up` to re-seed it, or e2e runs
   against another branch's schema.

When a gate fails right after a branch switch, suspect this list before you
suspect the branch.

## Symptoms and causes

| Symptom | Cause and fix |
|---|---|
| Worktree URL returns **404** (plain text, no app markup) | The sidecar container is gone, so Traefik has no router for that host. `just worktree-up` recreates it without touching the database. |
| Worktree URL returns **502** | The route exists but the backend does not, usually a worktree removed with bare `git worktree remove`. `just worktree-prune`. |
| nginx exits with `host not found in upstream "php-fpm"` | The container reached the shared network *after* start. Attach every network at creation; this is why the sidecar is a compose file, not `docker run` + `docker network connect`. |
| A class added in the worktree renders unstyled | `var/tailwind` must be a real directory per worktree, not a symlink to main's. `just worktree-up` fixes it; `just worktree-tailwind` watches. |
| Layout looks like an older design; a hover/position spec fails but the page logs nothing | Different cause from the row above. `just worktree-up` builds `var/tailwind/app.built.css` **once** and nothing rebuilds it, so a worktree alive across a merge of `main` serves its provision-time CSS while its PHP, Twig and JS are current. `just e2e` rebuilds it before every worktree run; for a browser session use `bin/worktrees/compose-exec.sh bin/console tailwind:build` (under a second) or `just worktree-tailwind` to watch. Diff the compiled sheet for a class the design introduced before blaming the branch. |
| The site-review widget shows its rejected-token state (a red `!` on the launcher) | `SITE_REVIEW_WIDGET_TOKEN` does not resolve **at the backend the widget actually talks to**, which is `SITE_REVIEW_WIDGET_BACKEND`, not the host serving the page. A worktree keeps the main checkout's token, which production knows, so suspect an `.env.local` that carries no token, one copied before production regenerated its token, or a widget branch pointed at its own tree with a token it never minted. See "Which backend the widget talks to". |
| The widget does not appear **at all**, no launcher and no error badge | The `<script>` failed to load, so nothing ever ran. A rejected token still renders the widget; an unreachable script renders nothing. Check the backend host resolves and serves `/site-review/widget.js`: a `SERVFAIL` on `loupe.ac` from the machine's own resolver produces exactly this, while the same request succeeds through `1.1.1.1`. |
| Mail-asserting specs never see their message, or read another run's | Each worktree has its own sidecar, so a run must be pointed at it. Suspect a run launched without `just e2e` (which exports `MAILPIT_URL`) or with `E2E_BASE_URL` set alone, which falls back to the shared instance: the worktree's app then sends where nothing is reading, every registration/login/verification spec times out, and it looks like an auth regression. Set `MAILPIT_URL=https://mailpit-<slug>.<project>.dev.localhost` alongside it. `bin/e2e-target.sh` prints the Mailpit URL it resolved as its fourth line. |
| A spec fails, then passes on a quiet re-run | Something else was loading the shared php-fpm, such as a sibling agent running `just ci` or `composer install`. Check what is in flight **before** investigating the branch; this produced a false "regression" nearly filed against a clean PR. |
| `worktree-up` fails with an "Unrecognized option" or missing-class error | The new worktree seeded its `vendor/` from a stale main checkout. Fast-forwarding the main checkout does **not** update its `vendor/`, so every worktree created afterwards inherits the old commit's dependencies. Run `composer install` in the main checkout, then re-run `just worktree-up`. |
| Every e2e spec fails on a **new** worktree host with `ERR_CERT_AUTHORITY_INVALID`, while existing hosts stay fine | The `traefik-dnsmasq-1` container (in the separate `traefik` stack) is down. It holds `address=/dev.localhost/172.20.0.2`, the wildcard that lets step-ca resolve a host to validate ACME; without it `tls-alpn-01` fails with "could not connect to validation target" and Traefik serves its default cert. Hosts already in `certs/acme.json` keep working, which makes this look worktree-specific. `restart: unless-stopped` does **not** revive it after an exit 255. Fix: `( cd ../traefik && docker compose up -d dnsmasq )`, then **restart Traefik too**, because it otherwise keeps polling the already-failed authorization instead of opening a fresh order. Verify with `curl -o /dev/null -w '%{ssl_verify_result}'` (0 = good) before blaming the branch. |
| Mail-asserting e2e specs time out against a worktree / no mail in Mailpit | Not a missing consumer: `PlaywrightSyncMiddleware` handles `X-Playwright` dispatches inline. Check first that the worktree's Mailpit sidecar is up (`just worktree-up` recreates it) and that `MAILER_DSN` in the container resolves to `mailpit-<slug>`; container environment beats dotenv, so a php-fpm still carrying an old `MAILER_DSN` sends to the shared instance until the main stack is recreated. Otherwise suspect a request that never carried the header (a context built without the project's `use` options), or stale state from an interrupted run. |
| You cannot tell whether an e2e run is already in flight | `ps ax` truncates command lines to terminal width, and a worktree's `e2e/node_modules` is a symlink to the main checkout, so **every** playwright process reports an identical command line whichever tree launched it. Use `pgrep -f 'playwright test'`. It does not truncate, but it cannot distinguish trees either, so treat any hit as "someone is running e2e" and wait. |

## Which backend the widget talks to

The widget's embed is built from two independent values, and getting them out of
step is the most common way a worktree's widget breaks.

```twig
<script src="{{ site_review_widget_backend }}/site-review/widget.js"
        data-token="{{ site_review_widget_token }}"></script>
```

`widget.js` then does `BACKEND = new URL(script.src).origin`. **The widget posts
to wherever its script came from, never to the host serving the page.** So a page
on `https://<slug>.loupe.dev.localhost` whose script comes from
`https://loupe.ac` sends its comments to production. That is deliberate: it is
how the app is dogfooded, with the local UI annotated and the comments landing in
hosted Loupe where an agent reads them over MCP. It also means the token must
belong to the **backend**, not to the local database.

### The rule for a worktree

A worktree points at production, with the production token, so annotating a
branch works the way it does everywhere else:

```
SITE_REVIEW_WIDGET_BACKEND=https://loupe.ac
SITE_REVIEW_WIDGET_TOKEN=<the production widget token, same one the main checkout uses>
```

The exception is a branch that changes the widget itself: anything under
`public/site-review/` or `src/Module/SiteReview/` that alters widget behaviour.
Point that branch at its own tree, so the code under review is the code being
exercised:

```
SITE_REVIEW_WIDGET_BACKEND=https://<slug>.loupe.dev.localhost
SITE_REVIEW_WIDGET_TOKEN=<a local token you mint by hand, see below>
```

Judge that by what the diff touches, not by the branch name. A change to
`SiteReviewExporter` is not a widget change; a change to `widget.js` is.

### Bootstrap keeps the pair in step

`worktree-bootstrap.sh` copies `.env.local` from the main checkout and leaves
both `SITE_REVIEW_WIDGET_BACKEND` and `SITE_REVIEW_WIDGET_TOKEN` as it found
them. A worktree therefore talks to production with the token production knows,
and a worktree page's site-review comments land in the real project. That is the
point: you annotate the branch you are working on, and read the comments back
over MCP like any other site.

Bootstrap mints no local token. The seed still creates one for the worktree's
own database, but nothing captures the raw value.

A widget branch needs both values changed by hand after provisioning, because
bootstrap restores neither. Point the backend at the worktree host, then mint a
local token and read the raw value from the output:

```bash
( cd .claude/worktrees/<name> \
  && bin/worktrees/compose-exec.sh bin/console app:dev:seed --reissue-widget-token )
```

To diagnose a rejected token, check the pair together and test the token against
the backend the page actually names:

```bash
tag=$(curl -sk https://<slug>.loupe.dev.localhost/login \
      | grep -oE '<script src="[^"]*site-review/widget\.js" data-token="[a-f0-9]+"')
origin=$(echo "$tag" | sed -E 's|.*src="(https?://[^/]+).*|\1|')
token=$(echo "$tag"  | sed -E 's/.*data-token="([a-f0-9]+)".*/\1/')
curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $token" \
     "$origin/api/site-review/review"
```

Testing the token against `localhost` instead proves nothing:
`/api/site-review/*` exists on both hosts, so a local token returns 200 there
while the widget, which is talking to production, still fails.

## Writing worktree tooling

- Source helpers relative to the script, not to `$main`. A script in a worktree
  that reads `$main/bin/worktrees/slug.sh` breaks whenever the helper is new on
  the branch. Use
  `. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/slug.sh"`.
- `just` claims `{{ }}`. To pass a Docker `--format` string through a recipe,
  write `'{{{{.Status}}'`. `{{{{` emits a literal `{{`, while `}}` needs no
  escaping.
- Use relative symlinks, and count the depth. A worktree sits 3 levels below main
  (`.claude/worktrees/<name>`), plus one per extra segment in a nested name.
  Links must be relative so they resolve both on the host and inside the
  container, where the repo lives at a different absolute path.

## Testing

Each worktree gets its own test database via `TEST_TOKEN`, so `just ci` runs in
parallel across worktrees safely.

For the full e2e suite, reach for `just e2e-up` first, not a worktree. It
provisions a disposable `app_e2e` database and a sidecar serving the main
checkout, so it gates whatever branch is checked out there without a tree, a cert
or a seeded database. Pointing e2e at a **worktree** is still supported and is
the right tool when you need to gate a branch without checking it out, but it is
the more expensive one.

`just e2e` still runs one worker (`playwright.config.ts`), though Mailpit is now
per-worktree. Run it **from** a worktree and it resolves that worktree as its
target and its Mailpit, prints both, rebuilds the worktree's stylesheet, and
repairs the dev data the suite destroys. Every instruction that says "from the
worktree" needs the worktree as its **cwd for that call only**, so wrap the `cd`
in a subshell:

```sh
( cd .claude/worktrees/<name> && just e2e )
```

A bare `cd` persists across later tool calls and turns "run this one command
there" into "move the session in", which CLAUDE.md forbids for the main session.

`E2E_BASE_URL` still overrides, and is what aims a run at a worktree from
somewhere else. It also suppresses the repair and the per-worktree Mailpit,
because an explicit target may be anything: repairing a tree nobody chose is
worse than leaving it, and guessing a mail host is worse than the shared one. Set
`MAILPIT_URL` alongside it to aim the mail assertions too.

## Running the full e2e suite from a worktree

The worktree e2e gate needs:

1. **`DEFAULT_URI=https://<slug>.loupe.dev.localhost` in the worktree's
   `.env.local`.** CLI-generated absolute URLs (export download emails) otherwise
   point at `localhost`. Re-check after any re-provisioning, because
   `worktree-up` regenerates `.env.local`.
2. **No worker**, and no worktree-scoped `messenger:consume`. The shared `worker`
   container consumes main's database only, but `PlaywrightSyncMiddleware`
   handles every message dispatched under `X-Playwright` inline, so there is no
   queue to drain. The middleware is registered `when@dev` only, which is what a
   worktree runs.
3. **A quiet stack**: no worktree provisioning, `composer install`, sibling
   `just ci`, or **a sibling worktree's e2e suite** during the run. A worktree
   gets its own nginx and Mailpit and nothing else, while `php-fpm`, `mercure`
   and `database` are shared, so any of these skew timings past Playwright's
   timeouts. Per-worktree databases and mail sidecars make two suites *correct*
   together, not *reliable* together: the specs that fall over are the
   live-update and debounced ones (comment counters, the sliding-confirm overlay,
   search), and they fail looking like real regressions. Re-run the suite on a
   quiet stack before you accept any such failure.
4. **A current stylesheet.** `just e2e` runs `tailwind:build` for the detected
   worktree before starting Playwright, so this is automatic, but a run started
   any other way (`npx playwright test` directly, or an explicit `E2E_BASE_URL`,
   which suppresses worktree detection) still gates against the CSS the tree was
   provisioned with.

Never run the full suite against the **main checkout's live dev DB** once it
holds real data: state-dependent specs fail and, worse, mutate live state (a
waitlist spec run against main set `registration.cap` and silently closed
registration).
