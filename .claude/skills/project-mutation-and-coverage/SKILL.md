---
name: project-mutation-and-coverage
description: "Use when running mutation testing or code coverage, reading a mutation score or a coverage percentage, fetching a weekly report from GitHub Actions, choosing between just mutation, just mutation-diff, just phpunit-coverage and just e2e-coverage, or writing a test that asserts on elapsed wall-clock time."
---

# Mutation testing and coverage

Two weekly GitHub Actions produce these reports. Neither is a required check and
neither gates a merge. Both write their results to an artifact, so the run page
shows no score.

## The recipes, and where each writes

Four recipes exist and the names do not make the difference obvious.

| Recipe | Measures | Writes |
|---|---|---|
| `just mutation` | mutation, all of `src` | `var/infection/` |
| `just mutation-diff [BASE]` | mutation, the `src/` files you changed | `var/infection/` |
| `just phpunit-coverage` | coverage, the PHPUnit suites | `var/phpunit-coverage/` |
| `just e2e-coverage` | coverage, the Playwright suite | `var/coverage/` |

`just e2e-coverage` deletes `var/coverage` at the start of every run. Keep any
report you want out of that directory. `just open-coverage` opens the e2e report
and `just open-phpunit-coverage` opens the PHPUnit one.

`just mutation-diff` is the one to run while you work. It mutates the `src/`
files you added or changed against `origin/main`, including uncommitted and
untracked ones, and takes minutes.

## Never run two of these at once in one worktree

They share one test database. `TEST_SCHEMA_READY` stops each mutant process
rebuilding the schema, so a second run that does rebuild it pulls the schema out
from under the first. The symptom is `duplicate key value violates unique
constraint "pg_type_typname_nsp_index"` in `tests/bootstrap.php`.

To stop a run, read `docker exec loupe-php-fpm-1 ps aux | grep -E 'infection|phpunit'`
first, then match your own worktree path. A bare `pkill -f phpunit` kills every
worktree's tests on the machine.

## Fetching a weekly report

The reports exist only as artifacts. Retention is 90 days.

```
gh run list --workflow "Mutation testing" --limit 5 --json databaseId,conclusion,createdAt
gh run download <run-id> --name <artifact> --dir /tmp/report
```

Download outside the repository, so nothing commits a report. `--name` is
required, because a run can hold more than one artifact.

| Workflow | Job | Artifact | Holds |
|---|---|---|---|
| `Mutation testing` | `infection` | `infection-report` | `summary.log`, `infection.log` |
| `Coverage report` | `phpunit` | `phpunit-coverage` | `summary.txt`, `clover.xml`, `html/` |
| `Coverage report` | `e2e` | `e2e-coverage` | `summary.txt`, `clover.xml`, `html/` |

Read `summary.log` or `summary.txt` first. Each is a few hundred bytes and
carries the numbers. Open the HTML only when you need a per-file breakdown.

`infection.log` is about 380 KB and holds four sections: `Escaped mutants:`,
`Timed Out mutants:`, `Skipped mutants:` and `Not Covered mutants:`. An escaped
mutant names a test that asserts nothing about a line it covers. Judge each one,
because some are harmless.

## A wall-clock assertion is a landmine

xdebug drives both mutation and coverage, and it roughly doubles execution.

Never assert an absolute elapsed time in a test. `MarkdownRendererTest` asserted
that a render finished in under 5 seconds. It took about 2.5s normally and 5.4s
under coverage, so it failed the initial coverage run and aborted Infection
before a single mutant ran. The failure reads as Infection being broken rather
than as one unrelated test. It breaks a coverage run the same way.

Assert a ratio instead. Run the work at two input sizes and compare the times:

```php
[$halfElapsed] = self::timeRender($renderer, 10_000);
[$fullElapsed, $html] = self::timeRender($renderer, 20_000);

// Linear doubles the time, quadratic quadruples it.
self::assertLessThan(3.0, $fullElapsed / $halfElapsed);
```

Coverage slows both halves by the same factor, so the ratio survives it, and so
does a loaded machine.

## Never size a run from a local timing

A workload that starts one process per unit of work runs about 8 times slower on
a development Mac than on a hosted runner. Every process autoloads `vendor/`
across the bind mount, and Docker Desktop serves that mount through a virtual
filesystem. The same suite inside one process is only about 2 times slower.

This holds on an idle machine, so waiting for a quiet machine does not fix it. A
local projection of the full mutation run said 1h 22m. The runner does it in 15
to 25 minutes.

Two runs of identical work on identical runners differed by 1.8x, so read one
hosted timing as a sample. Set `timeout-minutes` from a measurement, at a few
multiples of it. A loose timeout turns a degraded run into an hour of runner time
nobody watches.

## Infection needs 2 GB

Both recipes run Infection under `php -d memory_limit=2G`. It deletes about 8,500
mutant directories when it finishes, and `Filesystem::remove()` materialises that
tree before deleting it. At 512M the process dies in the cleanup, after the
reports are written, so the failure looks like something else.
