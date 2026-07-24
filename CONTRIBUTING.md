# Contributing to Loupe

Thanks for your interest in improving Loupe! This guide covers the essentials.

## Getting set up

Follow the [Quickstart in the README](README.md#quickstart-local-development) to
get a local environment running.

## Before you open a pull request

Run these in order; the checks must pass cleanly — including any pre-existing
failures you notice:

```bash
just cs     # applies PHP CS Fixer + Rector fixes — commit anything it changes
just ci     # check-only: PHPStan (level 8), phparkitect, gamache, ESLint, PHPUnit
just e2e    # Playwright end-to-end tests
```

`just ci` never rewrites files, so run `just cs` first — otherwise style and
Rector violations will fail `ci` with nothing having been fixed.

## Conventions

Loupe follows a set of project conventions (module layout, the command + handler
pattern, authorization voters, translations, and more). The `.claude/skills/`
directory documents them in detail — please skim the relevant skill before
working in an area. In short:

- Source lives in domain modules under `src/Module/`.
- Controller actions with logic are backed by a command + handler pair.
- All user-facing strings are translated.
- Access control goes through Symfony voters, not inline checks.

## Commits and pull requests

- Work on a branch and open a pull request against `main`.
- Keep the PR focused; describe what changed and why.
- Add or update tests for behavior changes.

## Reporting bugs and security issues

- Regular bugs: open an issue using the templates.
- Security vulnerabilities: **do not** open a public issue — see
  [SECURITY.md](SECURITY.md).

## License

By contributing, you agree that your contributions are licensed under the
project's [AGPL-3.0-or-later](LICENSE) license.
