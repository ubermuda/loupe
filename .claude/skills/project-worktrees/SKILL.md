---
name: project-worktrees
description: Use when creating, entering, debugging or removing a git worktree under `.claude/worktrees/`, when a worktree URL returns 404 or 502, or when writing tooling that resolves a worktree's hostname, database or container name.
---

# Git Worktrees

## Overview

Every worktree is a **full application of its own** — its own URL, database and
compiled CSS — but only nginx and Mailpit are duplicated. php-fpm and Postgres
are shared with the main stack, as is the Mercure hub when it is running — it sits
behind a compose profile and is off unless someone ran `just mercure-up`, so a
worktree working on site-review push has to start it and shares the one hub
with every other worktree.

That works because the whole main checkout is mounted at `/var/www/html` and
worktrees live *inside* it, so a worktree's files sit at the **same path** in
its own nginx sidecar and in the shared php-fpm. Only the document root
differs. A worktree is served through its own `public/index.php`, so Symfony
boots with the worktree as its project directory and reads that worktree's
`.env` chain — over HTTP and on the CLI alike.

## Quick reference

| Command | Does |
|---|---|
| `just worktree-up NAME` | Provision (or repair) a worktree, from anywhere. Idempotent. |
| `just worktrees` | Every worktree with its URL, database and sidecar status |
| `just worktree-down <name>` | Remove worktree + sidecar + both databases |
| `just worktree-prune` | Clean up sidecars/databases orphaned by `git worktree remove` |
| `just worktree-tailwind` | Tailwind watch mode for the current worktree |

Provisioned per worktree: `https://<slug>.loupe.dev.localhost`, dev DB
`app_wt_<slug>`, test DB `app_test_<slug>`, compose project `loupe-wt-<slug>`,
and a Mailpit sidecar at `https://mailpit-<slug>.loupe.dev.localhost` (SMTP
alias `mailpit-<slug>` on the app network). A worktree name that would normalise
to a slug beginning `mailpit-` is refused, because it would claim a sibling's
mail host.
Log in with `dev@loupe.test` / `password`, or `admin@loupe.test` / `password`
for the admin area.

`just up` must have run first — bootstrap fails fast rather than leaving a
worktree whose `.env.local` points at a database that was never created.

## The lifecycle runs itself

`.claude/settings.json` registers two hooks, so a worktree arrives provisioned
and leaves nothing behind:

| Hook | Script | Receives | Does |
|---|---|---|---|
| `WorktreeCreate` | `.claude/hooks/worktree-create.sh` | `name` | Creates the worktree, runs `worktree-bootstrap.sh`, prints the path |
| `WorktreeRemove` | `.claude/hooks/worktree-remove.sh` | `worktree_path` | Runs `worktree-teardown.sh` with `WORKTREE_TEARDOWN_KEEP_TREE=1` |

**The two events have different contracts, and the create side is the
surprising one.** `WorktreeCreate` does not react to a worktree the harness
made — it is handed a *name* and must produce the worktree itself, printing its
absolute path as the **last line of stdout**. A non-zero exit or a missing path
fails the creation, so everything else the script says goes to stderr. It puts
the tree at `.claude/worktrees/<name>` on a branch of the same name, cut from
`main` rather than the current branch. If bootstrap then fails it removes what
it just created before exiting non-zero: a worktree that cannot run `just ci` is
the problem the hook exists to remove, and an orphaned directory plus branch
would be a second one.

`WorktreeRemove` **cannot block** — its exit code is only logged in debug mode —
so that script is best-effort and idempotent, and leaves the `git worktree
remove` to the harness so the two cannot race. Run `just worktree-down NAME` by
hand and the tree goes too. Both hooks ignore anything outside
`.claude/worktrees/`.

`timeout` is in seconds and the documented default is 600, which is what these
use; bootstrap has to fit inside it.

Teardown kills anything still running against the worktree in the shared
php-fpm before it drops the databases, matching on the process's working
directory (every worktree's console process has an identical command line in
one container) and dropping with `--force`. A surviving consumer used to make
`dropdb` fail silently and orphan both databases.

## Rules that prevent real damage

**Prefer the `NAME` form of every worktree command, and run it from the main
checkout.** `just worktree-up NAME`, `just worktree-down NAME`. `worktree-up`
still accepts no argument and falls back to the tree you are standing in, but
that fallback is the reason sessions used to `cd` into a worktree at all — and
a `cd` persists across later tool calls, quietly moving the whole session
(CLAUDE.md, "The main session never moves into a worktree"). Naming the target
removes the reason to move. It is also safer: the bare `docker compose` calls
inside the bootstrap script resolve their compose file from the current
directory, so running it from main is the correct-by-construction path.

**Before trusting any write, confirm the path is in `git worktree list` — not
merely that it exists on disk.** When a worktree is removed while an agent is
still bound to it, a write to the old path **reports success**: it recreates a
bare directory that is not a git worktree, so nothing lands on the branch and
nothing raises. `git status` from inside that directory falls through to the
main checkout and reports `main`, which makes the situation look normal on
inspection. Existence on disk is exactly what misleads here, so check
membership:

```bash
git worktree list --porcelain | grep -qx "worktree $(pwd)" || echo "NOT a worktree — stop"
```

An agent that finds itself in this state must **stop**, not improvise. Every
plausible-looking recovery is worse than the problem: checking the branch out
into the main checkout collides with `main` already being checked out there,
and `cp`/`rsync`/`git checkout` from another worktree's path bypass the write
binding rather than repair it. Only the orchestrating session can fix it, by
provisioning a worktree with that branch and rebinding. Same class of silent
misdirection as "Serena's edit tools do not work from a worktree" — the write
succeeds against the wrong target.

**Never run bare `docker compose up/down/restart` from a worktree.** `.env`
sets `COMPOSE_PROJECT_NAME=loupe`, so compose targets the **shared** stack but
resolves `.` to the worktree — recreating the main app's containers with the
worktree bind-mounted as their document root. Use the `just` recipes, or
`bin/worktrees/compose-exec.sh` to run something in the shared php-fpm against
the current worktree's files.

**A `docker compose -f <file>` call whose variables are unset does not fail
safely.** `docker/compose/e2e.yaml` and `docker/compose/worktree.yaml` both declare their
inputs with `${VAR:?}`. Running `down` on one of them without supplying those
variables was observed to remove the **main stack's** containers and attempt to
delete the `loupe_default` network — even though `-p` named a different
project. So the `:?` markers protect `up`, not `down`, and a teardown recipe
must pass the same variables its bring-up did. This is the same failure family
as the bare-`docker compose` rule above: an invocation whose file cannot be
resolved does not stay in its lane.

**Tear down with `just worktree-down`, never a bare `git worktree remove`.**
The latter leaves the sidecar and both databases behind, and the route then
serves 502s. `just worktree-prune` cleans up after the fact.

**The slug is not the directory name.** `Feature_X` lives in a directory of
that name but owns the slug `feature-x`. Any tooling that matches worktrees —
pruning, listing, collision checks — must resolve them through
`bin/worktrees/slug.sh` (`worktree_slug`, `worktree_db_token`,
`worktree_slug_index`), never by string-matching a directory name. Matching on
the wrong one has already been shown to declare a live worktree orphaned and
drop its databases.

**Serena's edit tools do not work from a worktree.** The MCP server is bound to
the main checkout, so `replace_symbol_body` / `insert_*_symbol` /
`replace_content` silently write to the **main checkout**, leaving your branch
unchanged and the main tree dirty. Use Edit/Write. Serena **read** tools are
safe from anywhere.

## Subagents and the write binding

The Edit/Write binding is **single-valued per agent**. Which worktree an agent
may write to depends on how it was launched.

**Plain subagents inherit the parent session's *current* worktree** — not the
one they were launched against, not the one they `cd` into. An agent editing a
file in any other worktree is rejected with "This session is now isolated in
`<path>`". A subagent calling `EnterWorktree` itself moves its `pwd` but does
**not** rebind its write access. So dispatching N plain subagents at N different
worktrees silently fails for all but the bound one. Serialize instead:
`EnterWorktree(path)` in the parent → dispatch → wait → rebind → next.

Check the confirmation wording before dispatching. "The session is now working
in the worktree" propagates to subagents; "This agent's working directory and
write access now point at the worktree" applied to the parent only. The second
form appears when switching straight into a freshly created worktree — re-issue
`EnterWorktree` until you get the first.

**Agents launched with `isolation: "worktree"` get their own binding** and can
write in parallel (verified: the agent lands in `.claude/worktrees/agent-<id>`
on its own branch, writes there freely, and is refused when writing into another
worktree). The `WorktreeCreate` hook creates and provisions it, so it is a full
application from the start rather than the bare `git worktree add` it used to
be; if that hook is not registered, run `just worktree-up` yourself. The
remaining caveat is that the name is harness-generated (`agent-<id>`, and the
hook gives the branch that same name), so work that must land on a named branch
has to be renamed and pushed deliberately. These worktrees are created
**locked**, but `just worktree-down agent-<id>` removes one cleanly anyway —
sidecars and both databases included, no `--force` needed.

Tear the agent's worktree down only after its branch is pushed, and **never
tear down the worktree the parent session is bound to**: the binding keeps
pointing at the now-deleted path, and every subsequent Edit fails until you
`cd` elsewhere and re-issue `EnterWorktree`.

**Verify the binding yourself before dispatching, not after.** Make one trivial
edit in the target worktree and confirm it lands. Three separate times in one
wave an agent was dispatched at a correctly-provisioned worktree and only
discovered mid-task that its writes were pinned elsewhere; each cost a full
agent run.

Whichever mode you use, give every subagent a step-0 writability check (a
trivial Edit in its target worktree) and an explicit instruction to **stop and
report** if rejected. Forbid the *plausible* workarounds, not just the obvious
ones: an agent told not to use `cat`/`sed` instead edited the wrong worktree and
`cp`'d the finished file across. It produced a correct result and reported it
honestly, but it bypassed the guard, and the next one may not verify as
carefully. `cp`, `rsync`, `git checkout` of another worktree's path, Serena's
edit tools and any shell redirection are all bypasses. The rule is: if writes
land in the wrong worktree, stop — the orchestrator fixes the binding. Work
already on disk survives a stopped agent, so resume it with a message rather
than restarting.

**Parallelising writes does not yet parallelise the gate.** Mailpit is no longer
shared — each worktree has its own sidecar and `just e2e` points the suite at it
— but `playwright.config.ts` still sets `workers: 1`, and no branch has been
gated concurrently to prove the rest of the suite is parallel-safe. Until
someone does that, treat roughly three minutes per branch as the ceiling.

## Reusing one worktree for several branches

Because only one worktree is writable at a time, a wave of branches is often run
through a single provisioned worktree. Its directory name then no longer matches
its branch — that is expected; do not "fix" it.

After **every** `git checkout <other-branch>` in a reused worktree, and before
any gate is meaningful:

1. `bin/worktrees/compose-exec.sh composer install` — the lockfile differs
   between branches. Skipping this produces errors that look like application
   bugs: a drifted `vendor/` once reported `Call to undefined method` for a
   method the *locked* package version does define, costing a long
   investigation into whether `main` was broken. It was not.
2. `bin/console cache:clear` for **both** `dev` and `--env=test`. Clearing only
   one leaves phpstan without the dumped dev container ("Container ... does not
   exist"). A `var/cache` that survived a Symfony minor upgrade referenced an
   internal class whose shape had changed and 500'd every page rendering an
   icon — 31 e2e specs failed from that one stale cache.
3. `bin/console doctrine:migrations:status` — a migration from the previous
   branch shows as *executed but unavailable*. If so, reset the dev database
   (drop → create → migrate) then `just worktree-up` to re-seed and re-issue the
   widget token, or e2e runs against another branch's schema.

When a gate fails right after a branch switch, suspect this list before
suspecting the branch.

## Symptoms → cause

| Symptom | Cause and fix |
|---|---|
| Worktree URL returns **404** (plain text, no app markup) | The sidecar container is gone, so Traefik has no router for that host. `just worktree-up` recreates it without touching the database. |
| Worktree URL returns **502** | Route exists but the backend doesn't — usually a worktree removed with bare `git worktree remove`. `just worktree-prune`. |
| nginx exits with `host not found in upstream "php-fpm"` | The container reached the shared network *after* start. Attach every network at creation — this is why the sidecar is a compose file, not `docker run` + `docker network connect`. |
| A class added in the worktree renders unstyled | `var/tailwind` must be a real directory per worktree, not a symlink to main's. `just worktree-up` fixes it; `just worktree-tailwind` watches. |
| Layout looks like an older design; a hover/position spec fails but the page logs nothing | Different cause from the row above, same symptom family. `just worktree-up` builds `var/tailwind/app.built.css` **once** and nothing rebuilds it, so a worktree alive across a merge of `main` serves the CSS its branch had at provision time while its PHP, Twig and JS are current. `just e2e` now rebuilds it before every worktree run; for a browser session, `bin/worktrees/compose-exec.sh bin/console tailwind:build` (under a second) or `just worktree-tailwind` to watch. Diff the compiled sheet for a class the design introduced before blaming the branch. |
| The site-review widget is in its rejected-token state | `SITE_REVIEW_WIDGET_TOKEN` refers to a row in another database. `just worktree-up` detects this (it hashes the token and looks for it locally) and reissues. |
| Mail-asserting specs read another run's messages | Was the shared Mailpit; each worktree now has its own sidecar. Suspect a run launched without `just e2e` (which exports `MAILPIT_URL`) or with `E2E_BASE_URL` set, which deliberately falls back to the shared instance. `bin/e2e-target.sh` prints the Mailpit URL it resolved as its fourth line. |
| A spec fails, then passes on a quiet re-run | Something else was loading the shared php-fpm — a sibling agent running `just ci` or `composer install`. Check what is in flight **before** investigating the branch; this produced a false "regression" that was nearly filed against a clean PR. |
| `worktree-up` fails with an "Unrecognized option" or missing-class error | The new worktree seeded its `vendor/` from the main checkout, and the main checkout is stale. Fast-forwarding the main checkout does **not** update its `vendor/`, so every worktree created afterwards inherits dependencies from the old commit. Run `composer install` in the main checkout, then re-run `just worktree-up`. |
| Every e2e spec fails on a **new** worktree host with `ERR_CERT_AUTHORITY_INVALID`, while existing hosts stay fine | The `traefik-dnsmasq-1` container (in the separate `traefik` stack) is down. It holds `address=/dev.localhost/172.20.0.2`, the wildcard that lets step-ca resolve a host to validate ACME; without it `tls-alpn-01` fails with "could not connect to validation target" and Traefik serves its default cert. Hosts already in `certs/acme.json` keep working, which is what makes this look worktree-specific. `restart: unless-stopped` does **not** revive it after an exit 255. Fix: `( cd ../traefik && docker compose up -d dnsmasq )`, then **restart Traefik too** — it otherwise keeps polling the already-failed authorization instead of opening a fresh order. Verify with `curl -o /dev/null -w '%{ssl_verify_result}'` (0 = good) before blaming the branch. |
| Mail-asserting e2e specs time out against a worktree / no mail in Mailpit | Not a missing consumer — `PlaywrightSyncMiddleware` handles `X-Playwright` dispatches inline, so no worker is involved. Check first that the worktree's Mailpit sidecar is up (`just worktree-up` recreates it) and that `MAILER_DSN` in the container resolves to `mailpit-<slug>`; container environment beats dotenv, so a php-fpm still carrying an old `MAILER_DSN` sends to the shared instance until the main stack is recreated. Otherwise suspect a request that never carried the header (a context built without the project's `use` options), or stale state from an interrupted run. |
| You cannot tell whether an e2e run is already in flight | `ps ax` truncates command lines to terminal width, and a worktree's `e2e/node_modules` is a symlink to the main checkout, so **every** playwright process reports an identical command line whichever tree launched it. Use `pgrep -f 'playwright test'` — it neither truncates nor lets you distinguish trees, so treat any hit as "someone is running e2e" and wait. |

## Writing worktree tooling

- **Source helpers relative to the script, not to `$main`.** A script in a
  worktree that reads `$main/bin/worktrees/slug.sh` breaks whenever the helper
  is new on the branch. Use
  `. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/slug.sh"`.
- **`just` claims `{{ }}`.** To pass a Docker `--format` string through a
  recipe, write `'{{{{.Status}}'` — `{{{{` emits a literal `{{`, while `}}`
  needs no escaping.
- **Relative symlinks, and count the depth.** A worktree sits 3 levels below
  main (`.claude/worktrees/<name>`), plus one per extra segment in a nested
  name. Links must be relative so they resolve both on the host and inside the
  container, where the repo lives at a different absolute path.

## Testing

Each worktree gets its own test database via `TEST_TOKEN`, so `just ci` runs in
parallel across worktrees safely.

**For the full e2e suite, reach for `just e2e-up` first, not a worktree.** It
provisions a disposable `app_e2e` database and a sidecar serving the main
checkout, so it gates whatever branch is checked out there without provisioning
a tree, a cert or a seeded database. The rest of this section covers pointing
e2e at a **worktree**, which is still supported and is the right tool when you
need to gate a branch without checking it out — but it is no longer the only
way, and it is the more expensive one.

`just e2e` still runs one worker (`playwright.config.ts`), though Mailpit is now
per-worktree rather than shared. Run **from** a worktree it resolves that
worktree as its target and its Mailpit, prints both, rebuilds the worktree's
stylesheet, and repairs the dev data the suite destroys once it finishes:

```sh
( cd .claude/worktrees/<name> && just e2e )
```

`E2E_BASE_URL` still overrides, and is what you want to aim at a worktree from
somewhere else. Setting it also suppresses the repair and the per-worktree
Mailpit, because an explicit target may be anything: repairing a tree nobody
chose is worse than leaving it, and guessing a mail host is worse than the
shared one. Set `MAILPIT_URL` alongside it to aim the mail assertions too.

## Running the full e2e suite from a worktree

The worktree e2e gate needs three things:

1. **`DEFAULT_URI=https://<slug>.loupe.dev.localhost` in the worktree's
   `.env.local`** — CLI-generated absolute URLs (export download emails)
   otherwise point at `localhost`. Re-check after any re-provisioning:
   `worktree-up` regenerates `.env.local`.
2. **No worker.** This used to require a worktree-scoped
   `messenger:consume`, because the shared `worker` container consumes main's
   database only. It no longer does: `PlaywrightSyncMiddleware` handles every
   message dispatched under `X-Playwright` inline, so there is no queue to drain.
   The middleware is registered `when@dev` only, which is what a worktree runs.

Everything here that says "from the worktree" means the command needs the
worktree as its **cwd for that call only**. Wrap each one in a subshell:

    ( cd .claude/worktrees/<name> && just e2e )

A bare `cd` persists across later tool calls and turns "run this one command
there" into "move the session in", which CLAUDE.md forbids for the main session
— see "The main session never moves into a worktree" for why that bites.
3. **A quiet stack**: no worktree provisioning, `composer install`, or sibling
   `just ci` during the run — they share php-fpm and skew timings past
   Playwright's timeouts.
4. **A current stylesheet.** `just e2e` now runs `tailwind:build` for the
   detected worktree before starting Playwright, so this is automatic — but a
   run started any other way (`npx playwright test` directly, or an explicit
   `E2E_BASE_URL`, which suppresses worktree detection) still gates against the
   CSS the tree was provisioned with.

Never run the full suite against the **main checkout's live dev DB** once it
holds real data: state-dependent specs fail and, worse, mutate live state (a
waitlist spec run against main set `registration.cap` and silently closed
registration).
