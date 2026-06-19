# Automation opportunities

Convention rules that could be enforced automatically instead of relying on
review. Each entry names the rule, the proposed mechanism, and the caveats.
These are candidates — not yet built. Build via the relevant mechanism (a new
`ubermuda/gamache` check + PR, an ESLint rule, a git hook, or a Playwright
assertion).

## 2026-06-19 — review-comment forms + Turbo retrospective

### Ban `fetch()` submissions in Stimulus controllers
- **Rule:** mutations must go through a `<form>` + Turbo, never a hand-rolled
  `fetch()` POST (see `project-frontend`, "Turbo patterns").
- **Mechanism:** ESLint rule (this repo's `eslint.config.js`) — flag
  `fetch(` calls inside `assets/controllers/**`. A `no-restricted-syntax` /
  custom rule, or `no-restricted-globals`-style ban with an inline-disable
  escape hatch for the rare legitimate non-form interaction.
- **Caveat:** read-only `fetch()` (GET, polling) may be legitimate; scope the
  ban to POST/PUT/PATCH/DELETE or require an explicit disable comment.

### Flag raw request parsing in controllers
- **Rule:** user input is bound through a Symfony form, never hand-parsed via
  `$request->request->get()` (see `project-backend`, "Forms and DTOs").
- **Mechanism:** a new `ubermuda/gamache` PHPStan rule — flag
  `Request::request->get()` / `->query->get()` (and `->getString()` etc.)
  inside `*Controller` classes.
- **Caveat:** noisy. Needs an allowlist for dev/test-only controllers and for
  legitimate non-form technical reads (CSRF tokens on hand-rolled fieldless
  forms). Without a good allowlist this will produce false positives.
