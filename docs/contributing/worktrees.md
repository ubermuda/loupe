---
title: Git worktrees
description: Each worktree is a full application — its own URL, database and CSS.
---

Worktrees live in `.claude/worktrees/` and are provisioned by name from the main
checkout:

```sh
just worktree-up NAME     # URL, migrated + seeded database, test database, CSS
just worktrees            # list them with URL, database and sidecar status
just worktree-down NAME   # remove it along with its sidecar, route and databases
just worktree-prune       # drop sidecars and databases whose worktree is gone
```

Each gets `https://<slug>.<project>.dev.localhost` and its own dev database, so
`bin/console` in a worktree never touches the main development database. Log in
with the seeded `dev@loupe.test` / `password`.

## Rules that prevent real damage

**Never remove one with a bare `git worktree remove`.** That leaves the sidecar
container, the Traefik route and both databases behind, and the route then
serves 502s. Use `just worktree-down`.

**Never match worktrees by directory name.** A worktree called `Feature_X` owns
the slug `feature-x`; looking for a directory of that name would declare a live
worktree orphaned and drop its databases. The tooling matches on the derived
slug for exactly this reason.

**Run `just` recipes from the worktree in a subshell**, so the working directory
dies with the command:

```sh
( cd .claude/worktrees/<name> && just worktree-up )
```

## Running tests in parallel

PHPUnit's database is `app_test<TEST_TOKEN>`, so exporting a unique `TEST_TOKEN`
per worktree gives each its own schema. `just worktree-up` writes one for you.

The e2e suite is the exception and cannot be parallelised — Mailpit is shared.

## Warm the cache before an e2e run

```sh
( cd .claude/worktrees/<name> && bin/worktrees/compose-exec.sh bin/console cache:warmup )
```

A run started against a cold `var/cache/dev`, or one whose cache is rebuilt
mid-flight by a concurrent `just cs`, produces **false failures that look like
unrelated flaky specs**: a request that never completes, a submit button left
disabled, and no validation errors — because the container-load fatal happens
before the logger exists, so nothing is logged at all.

If a spec fails and then passes in isolation, count the container hashes in
`var/cache/dev/`. More than one means a rebuild happened during the run. That is
a diagnosis, not a licence to re-run until green.
