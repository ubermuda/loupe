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

Take `<workflow>` and `<artifact>` from the table below. Both are parameters, and
two workflows produce three artifacts.

```
gh run list --workflow <workflow> --limit 5 --json databaseId,conclusion,createdAt
gh run download <run-id> --name <artifact> --dir /tmp/report
```

| Report | `<workflow>` | Job | `<artifact>` | Holds |
|---|---|---|---|---|
| mutation | `Mutation testing` | `infection` | `infection-report` | `summary.log`, `infection.log` |
| PHPUnit coverage | `Coverage report` | `phpunit-coverage` | `phpunit-coverage` | `summary.txt`, `clover.xml`, `html/` |
| e2e coverage | `Coverage report` | `e2e-coverage` | `e2e-coverage` | `summary.txt`, `clover.xml`, `html/` |

The first two rows were checked by downloading the artifact. The third names what
`just e2e-coverage` writes locally and what the workflow uploads. No hosted e2e
coverage artifact has been fetched, because no such run has gone green yet.

Download outside the repository, so nothing commits a report. `--name` is
required, because one run holds more than one artifact. A `--name` from the wrong
workflow fails with an error that does not say the name was the problem.

Worked example, for the PHPUnit coverage number:

```
gh run list --workflow "Coverage report" --limit 5 --json databaseId,conclusion,createdAt
gh run download 34000093901 --name phpunit-coverage --dir /tmp/report
head -12 /tmp/report/summary.txt
```

That prints `Classes: 71.64%`, `Methods: 83.40%` and `Lines: 90.89% (8585/9446)`.
The Summary block is at the top of the file, so read the head of it. The file
carries terminal colour codes, which is why a plain `grep` shows escape
characters around the numbers.

Read `summary.log` or `summary.txt` first. A mutation `summary.log` is a few
hundred bytes. A coverage `summary.txt` is about 80 KB, because every class
follows the summary. The HTML tree downloads as about 7.7 MB and expands to
about 87 MB, so fetch it only when you need a per-file breakdown.

A run with no artifact means the job failed before it wrote one. It does not mean
zero coverage, and it does not mean the upload step is missing. `just
e2e-coverage` writes its report in a `phpcov merge` at the very end, so a suite
that fails takes the whole artifact with it, and `if-no-files-found: warn`
uploads nothing without failing the step. Read the job log instead.

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

Playwright carries the same kind of bound in its own config. Its `expect`
timeout is an absolute 5 seconds. Per-request collection takes one page render
from about 0.5s to about 4.7s, so a coverage run sits on that bound and fails on
timeouts rather than on anything a spec asserts. `playwright.config.ts` raises
the timeouts when `COVERAGE` is set, and the per-pull-request gate keeps 5s.

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

## These tools fail with a signal that looks valid

Every failure here so far has produced an answer a reader would accept. Distrust
a number from a run you have not confirmed finished.

| What you see | What it means |
|---|---|
| A fast, complete-looking mutation run | It may have crashed in cleanup after printing the metrics. Check the exit status. |
| `ini_get("xdebug.mode")` says `develop` | Nothing. xdebug honours the `XDEBUG_MODE` environment variable, and `ini_get` reports the ini file. Read `getenv`. |
| No artifact on a run | The job failed before writing one. It is not zero coverage. |
| `Command terminated by signal 7` | The container died on host memory, past PHP's own limit. No PHP error and no stack trace. |

## Raising PHPUnit's memory limit

`php -d memory_limit=...` does not reach PHPUnit. `phpunit.dist.xml` sets the
limit with an `<ini>` directive, and PHPUnit applies it with `ini_set()` after
startup, so the command line loses. A run started with `-d memory_limit=4G`
still died at exactly 536870912 bytes. Change the value in `phpunit.dist.xml`,
and note that doing so raises the ceiling for `just phpunit` and the gate too,
which gives a memory regression more room to hide.

This came up while measuring `--path-coverage`, which this project does not use.
Path coverage held 492 MB for 51 tests and killed the container at about 250 of
2,262 tests, so it does not fit this suite.

## Infection needs 2 GB

Both recipes run Infection under `php -d memory_limit=2G`. It deletes about 8,500
mutant directories when it finishes, and `Filesystem::remove()` materialises that
tree before deleting it. At 512M the process dies in the cleanup, after the
reports are written, so the failure looks like something else.
