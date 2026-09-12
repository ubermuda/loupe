---
title: "Contributing"
description: "How to propose a change, and what the gate expects before you do."
---


Thanks for your interest in improving Loupe! This guide covers the essentials.

## Getting set up

Follow [From source](../getting-started/from-source.md) to
get a local environment running.

## Before you open a pull request

Run these in order; the checks must pass cleanly — including any pre-existing
failures you notice:

```bash
just cs     # applies PHP CS Fixer + Rector fixes — commit anything it changes
just ci     # check-only: PHPStan (level 8), phparkitect, gamache, ESLint, PHPUnit, Vitest
just e2e    # Playwright end-to-end tests
```

`just ci` never rewrites files, so run `just cs` first — otherwise style and
Rector violations will fail `ci` with nothing having been fixed.

## Conventions

Loupe follows a set of project conventions (module layout, the command + handler
pattern, authorization voters, translations, and more). The `.claude/skills/`
directory documents them in detail — please skim the relevant skill before
working in an area. [Architectural priorities](architectural-priorities.md)
ranks correctness, simplicity, performance and shipping speed, and says which
one yields when two of them collide. In short:

- Source lives in domain modules under `src/Module/`.
- Controller actions with logic are backed by a command + handler pair.
- All user-facing strings are translated.
- Access control goes through Symfony voters, not inline checks.

## Commits and pull requests

- Work on a branch and open a pull request against `main`.
- Keep the PR focused; describe what changed and why.
- Add or update tests for behavior changes.

## Changelog entries

A pull request that changes something a reader can act on carries its own
changelog entry, in its own branch. Write the entry in a fragment file named
after your pull request number, such as `changelog.d/429.md`:

```markdown
- (#429) — **Added:** what changed, from the reader's side.
```

Tag each line with one of the six tags Keep a Changelog defines: `Added`,
`Changed`, `Deprecated`, `Removed`, `Fixed` or `Security`. Write one sentence
per line. Two branches never write one fragment file, so two entries never
conflict. `changelog.d/README.md` carries the rest of the format.

You do not have to fold your fragment in. The documentation site folds every
merged fragment on each deploy, so your entry is published once the branch
lands. A maintainer runs `just changelog` at a release, which folds them into
`docs/CHANGELOG.md` for good and deletes them.

`just lint` reports a malformed fragment, so the gate catches one on the branch
that wrote it.

## Reporting bugs and security issues

- Regular bugs: open an issue using the templates.
- Security vulnerabilities: **do not** open a public issue — see
  [SECURITY.md](../SECURITY.md).

## License

By contributing, you agree that your contributions are licensed under the
project's [AGPL-3.0-or-later](../../LICENSE) license.
