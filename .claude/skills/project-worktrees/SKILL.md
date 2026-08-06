---
name: project-worktrees
description: Use when creating, entering, debugging or removing a git worktree under `.claude/worktrees/`, when a worktree URL returns 404 or 502, or when writing tooling that resolves a worktree's hostname, database or container name.
---

# Git Worktrees

## Overview

Every worktree is a **full application of its own** — its own URL, database and
compiled CSS — but only nginx is duplicated. php-fpm, Postgres, Mailpit and
Mercure are shared with the main stack.

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
`app_wt_<slug>`, test DB `app_test_<slug>`, compose project `loupe-wt-<slug>`.
Log in with `dev@loupe.test` / `password`.

`just up` must have run first — bootstrap fails fast rather than leaving a
worktree whose `.env.local` points at a database that was never created.

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
safely.** `compose.e2e.yaml` and `compose.worktree.yaml` both declare their
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
worktree). Two caveats: the worktree starts bare, exactly like `git worktree
add`, so run `just worktree-up` to complete it, same as any worktree; and the
branch name is harness-generated, so work that must land on a named branch has
to be renamed and pushed deliberately. These worktrees are created **locked**, but
`just worktree-down agent-<id>` removes one cleanly anyway — sidecar and both
databases included, no `--force` needed.

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

**Parallelising writes does not parallelise the gate.** `just e2e` is serialized
by shared Mailpit regardless of how many worktrees exist, at roughly three
minutes per branch — that, not the write binding, is the throughput ceiling for
a multi-branch wave.

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
| The site-review widget is in its rejected-token state | `SITE_REVIEW_WIDGET_TOKEN` refers to a row in another database. `just worktree-up` detects this (it hashes the token and looks for it locally) and reissues. |
| e2e failures that vanish on a re-run | Mailpit is shared, so concurrent e2e runs read each other's messages. e2e must stay serialized — check whether another run is in flight before blaming the branch. |
| A spec fails, then passes on a quiet re-run | Something else was loading the shared php-fpm — a sibling agent running `just ci` or `composer install`. Check what is in flight **before** investigating the branch; this produced a false "regression" that was nearly filed against a clean PR. |
| `worktree-up` fails with an "Unrecognized option" or missing-class error | The new worktree seeded its `vendor/` from the main checkout, and the main checkout is stale. Fast-forwarding the main checkout does **not** update its `vendor/`, so every worktree created afterwards inherits dependencies from the old commit. Run `composer install` in the main checkout, then re-run `just worktree-up`. |
| Every e2e spec fails on a **new** worktree host with `ERR_CERT_AUTHORITY_INVALID`, while existing hosts stay fine | The `traefik-dnsmasq-1` container (in the separate `traefik` stack) is down. It holds `address=/dev.localhost/172.20.0.2`, the wildcard that lets step-ca resolve a host to validate ACME; without it `tls-alpn-01` fails with "could not connect to validation target" and Traefik serves its default cert. Hosts already in `certs/acme.json` keep working, which is what makes this look worktree-specific. `restart: unless-stopped` does **not** revive it after an exit 255. Fix: `( cd ../traefik && docker compose up -d dnsmasq )`, then **restart Traefik too** — it otherwise keeps polling the already-failed authorization instead of opening a fresh order. Verify with `curl -o /dev/null -w '%{ssl_verify_result}'` (0 = good) before blaming the branch. |
| Mail-asserting e2e specs time out against a worktree / no mail in Mailpit | Nothing consumes the worktree's `async` transport — the shared `worker` consumes only main's database. Start a worktree-scoped consumer: `bin/worktrees/compose-exec.sh bin/console messenger:consume scheduler_default async` from the worktree; stop it when done. |

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

`just e2e` does not parallelize (shared Mailpit). To aim it at a worktree:

```sh
E2E_BASE_URL=https://<slug>.loupe.dev.localhost just e2e
```

## Running the full e2e suite from a worktree

The worktree e2e gate needs four things beyond `E2E_BASE_URL`:

1. **`DEFAULT_URI=https://<slug>.loupe.dev.localhost` in the worktree's
   `.env.local`** — CLI/worker-generated absolute URLs (export download emails)
   otherwise point at `localhost`. Re-check after any re-provisioning:
   `worktree-up` regenerates `.env.local`.
2. **A worktree-scoped worker**:
   `bin/worktrees/compose-exec.sh bin/console messenger:consume async` started
   from the worktree cwd — the main `worker` container consumes main's DB only,
   so the data-export spec hangs without this. Kill it afterwards
   (`docker exec loupe-php-fpm-1 pkill -f 'messenger:consume async'`), and
   restart it after changing any PHP it has already loaded.

Everything here that says "from the worktree" means the command needs the
worktree as its **cwd for that call only**. Wrap each one in a subshell:

    ( cd .claude/worktrees/<name> && E2E_BASE_URL=https://<slug>.loupe.dev.localhost just e2e --workers=1 )

A bare `cd` persists across later tool calls and turns "run this one command
there" into "move the session in", which CLAUDE.md forbids for the main session
— see "The main session never moves into a worktree" for why that bites.
3. **`--workers=1`.**
4. **A quiet stack**: no worktree provisioning, `composer install`, or sibling
   `just ci` during the run — they share php-fpm and skew timings past
   Playwright's timeouts.

Never run the full suite against the **main checkout's live dev DB** once it
holds real data: state-dependent specs fail and, worse, mutate live state (a
waitlist spec run against main set `registration.cap` and silently closed
registration).
