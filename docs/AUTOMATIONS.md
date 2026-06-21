# Automation opportunities

Convention rules that could be enforced automatically instead of relying on
review. Each entry names the rule, the proposed mechanism, and the caveats.
These are candidates — not yet built. Build via the relevant mechanism (a new
`ubermuda/gamache` check + PR, an ESLint rule, a git hook, or a Playwright
assertion).

## 2026-06-20 — API controller conventions

### API controllers must bind input via `#[MapRequestPayload]`, not forms
- **Rule:** a JSON API controller (under a `*\Controller\Api\*` namespace) takes
  input through `#[MapRequestPayload]` + a validated DTO — never a Symfony form
  or hand-rolled `json_decode` (see `project-backend`, "API controllers").
- **Mechanism:** a new `ubermuda/gamache` PHPStan rule keyed on the class
  namespace (`*\Controller\Api\*`, or a controller whose `#[Route]` path starts
  `/api/`): flag `$this->createForm(...)`, `FormInterface` usage, `$form->submit(...)`,
  and `json_decode($request->...->getContent())` inside `__invoke()`.
- **Caveat:** keys off the namespace/path convention, so a *misplaced* API
  controller is only caught when paired with the route-consistency rule below.
  Do **not** require a `#[MapRequestPayload]` parameter outright — a read
  endpoint (GET with only route params) legitimately has no payload, so that
  would false-positive. Flag the forbidden constructs instead. Partially overlaps
  the existing "raw request parsing" entry, but adds the form ban specifically.

### API route path, name, and namespace must agree
- **Rule:** a `#[Route]` whose **path** starts `/api/` must have a **name**
  starting `api_` and a class in a `*\Controller\Api\*` namespace — and the
  converse (a `Controller\Api` class routes under `/api/` with an `api_` name).
- **Mechanism:** a new `ubermuda/gamache` PHPStan rule reading the `#[Route]`
  attribute (path + name) together with the class namespace.
- **Caveat:** low false-positive risk. Pick the path as the canonical signal and
  derive the expected name/namespace from it; emit one error per mismatch. Handle
  classes carrying more than one `#[Route]`.

### (Weak) API request DTOs live alongside the controller
- **Rule:** a DTO consumed by `#[MapRequestPayload]` lives in the same namespace
  as the controller that references it (the `Controller/Api/` directory), with a
  `*Request` suffix — not a separate `Dto/` or `Form/` directory.
- **Mechanism:** gamache PHPStan rule — a class referenced by a
  `#[MapRequestPayload]` parameter must share the referencing controller's
  namespace.
- **Caveat:** low value; the `dto.requestSuffix` rule already covers naming and
  only the location is new. The trigger is indirect (trace the payload type back
  to its class). Probably not worth building.

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

## 2026-06-19 — SaaS visual redesign retrospective

### Ban the brand name inside page-title translation values
- **Rule:** page `{% block title %}` must compose two translated strings —
  `{{ 'x.page.title'|trans }} — {{ 'app.name'|trans }}` — and the page-title
  trans-unit value must not itself contain the brand name (see
  `project-templates`, "Page titles").
- **Mechanism:** a new `ubermuda/gamache` check scanning `translations/*.xlf`
  for `trans-unit` ids ending in `.page.title` whose `<target>` contains the
  `app.name` brand value (or a ` — ` separator).
- **Caveat:** the brand string is itself a translation value; the check must
  read `app.name`'s target and match against it rather than hard-coding
  "Better Plans".
