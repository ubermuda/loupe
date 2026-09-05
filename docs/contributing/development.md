---
title: "Development"
description: "The commands you will actually use, and the gate a change has to pass."
---

Everything runs in Docker; `just --list` shows every recipe.

```sh
just up                # start nginx, php-fpm, postgres
just composer install  # composer inside the container — never on the host
just migrate-run       # set up the database
just exec bin/console app:dev:seed   # dev@loupe.test or admin@loupe.test / password
```

`just up` publishes no host port and needs a reverse proxy — see
[Reverse proxy](../extending/reverse-proxy.md) before the first command.

## Day to day

```sh
just shell     # bash inside the php-fpm container
just composer  # e.g. just composer require foo/bar
just worker    # foreground messenger consumer
just tailwind  # CSS watch mode (already running in dev)
```

Tailwind rebuilds automatically in the dev container. Do not run
`tailwind:build` by hand after editing templates — wait a second and re-check.
The same goes for `cache:clear`.

Run one unit test with `just phpunit --filter TestClassName`, one e2e spec with
`just e2e tests/<area>/<spec>.spec.ts`.

## The gate

```sh
just cs        # apply formatter + Rector fixes
just ci        # check-only: lint, style, phpstan, arkitect, gamache, phpunit
just e2e       # Playwright end-to-end
```

`cs` writes, `ci` only reports — running `ci` alone will tell you about style
violations it will not fix. Fix every failure before proposing a change,
including ones that pre-date it.

**e2e runs against a dedicated target, never the dev host.** `just e2e-up`
creates a disposable database and a sidecar serving this checkout; `just e2e`
refuses to start without it. The suite is destructive by design — one project
truncates every table — so pointing it at your development database wipes it.

No messenger consumer is needed. Messages dispatched during a request carrying
`X-Playwright: 1` are handled inline, so specs that assert on mail or a download
link work with nothing draining the queue.

The suite cannot be parallelised: Mailpit is shared, so mail-asserting specs
across concurrent runs read each other's messages.

## Mutation testing

```sh
just mutation-diff   # only the lines this branch changed — run this one
just mutation        # all of src — hours, and the weekly Action already does it
```

Infection changes the source code one edit at a time and reruns the tests. A
change that no test catches shows that the test asserts nothing. `infection.json5`
holds the scope, which is all of `src` apart from `Kernel.php`.

Read `var/infection/infection.log` after a run. Escaped mutants name a test that
asserts nothing. Some are harmless, so judge each one. Not-covered mutants name a
line no test reaches, which `--with-uncovered` in both recipes keeps visible.

`just mutation-diff` is the one to run while you work. It mutates the `src/` files
you added or changed against `origin/main`, which takes minutes. Pass another base
as its argument, such as `just mutation-diff main~5`.

A weekly GitHub Action named "Mutation testing" runs the full pass and attaches
its log as an artifact. It is informational, not a required check.

Neither is a `just ci` leg. Run either one alone: it starts one PHPUnit process
per mutant against this checkout's test database, and a second test run in the
same checkout crashes on the shared schema.

## Coverage

```sh
just phpunit-coverage        # HTML at var/phpunit-coverage/html, summary on stdout
just open-phpunit-coverage   # open that report
```

This covers the PHPUnit suites. `just e2e-coverage` covers the e2e suite instead,
and it owns `var/coverage`, which it deletes at the start of every run. The two
reports keep separate trees for that reason.

Both recipes take PHPUnit arguments, so `just phpunit-coverage tests/Module/Review`
reports on one directory.

## Secrets

```sh
just secrets-scan   # gitleaks + trufflehog over the whole history
```

Not part of `ci` — it needs host tooling and outbound network. Run it before
publishing anything.
