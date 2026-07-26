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
| `just worktree-up` | Provision (or repair) the current worktree. Idempotent. |
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

**Never run bare `docker compose up/down/restart` from a worktree.** `.env`
sets `COMPOSE_PROJECT_NAME=loupe`, so compose targets the **shared** stack but
resolves `.` to the worktree — recreating the main app's containers with the
worktree bind-mounted as their document root. Use the `just` recipes, or
`bin/worktrees/compose-exec.sh` to run something in the shared php-fpm against
the current worktree's files.

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

## Symptoms → cause

| Symptom | Cause and fix |
|---|---|
| Worktree URL returns **404** (plain text, no app markup) | The sidecar container is gone, so Traefik has no router for that host. `just worktree-up` recreates it without touching the database. |
| Worktree URL returns **502** | Route exists but the backend doesn't — usually a worktree removed with bare `git worktree remove`. `just worktree-prune`. |
| nginx exits with `host not found in upstream "php-fpm"` | The container reached the shared network *after* start. Attach every network at creation — this is why the sidecar is a compose file, not `docker run` + `docker network connect`. |
| A class added in the worktree renders unstyled | `var/tailwind` must be a real directory per worktree, not a symlink to main's. `just worktree-up` fixes it; `just worktree-tailwind` watches. |
| The site-review widget is in its rejected-token state | `SITE_REVIEW_WIDGET_TOKEN` refers to a row in another database. `just worktree-up` detects this (it hashes the token and looks for it locally) and reissues. |
| e2e failures that vanish on a re-run | Mailpit is shared, so concurrent e2e runs read each other's messages. e2e must stay serialized — check whether another run is in flight before blaming the branch. |
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
parallel across worktrees safely. `just e2e` does not parallelize (shared
Mailpit), but it can be pointed at a worktree, which is the better gate for a
branch:

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
3. **`--workers=1`.**
4. **A quiet stack**: no worktree provisioning, `composer install`, or sibling
   `just ci` during the run — they share php-fpm and skew timings past
   Playwright's timeouts.

Never run the full suite against the **main checkout's live dev DB** once it
holds real data: state-dependent specs fail and, worse, mutate live state (a
waitlist spec run against main set `registration.cap` and silently closed
registration).
