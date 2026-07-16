# Automation opportunities

Convention rules that could be enforced automatically instead of relying on
review. Each entry names the rule, the proposed mechanism, and the caveats.
These are candidates — not yet built. Build via the relevant mechanism (a new
`ubermuda/gamache` check + PR, an ESLint rule, a git hook, or a Playwright
assertion).

## 2026-06-20 — API controller conventions

### (Weak) API request DTOs live alongside the controller
- **Rule:** a DTO consumed by `#[MapRequestPayload]` lives in the same namespace
  as the controller that references it (the `Controller/Api/` directory), with a
  `*Request` suffix — not a separate `Dto/` or `Form/` directory.
- **Mechanism:** gamache PHPStan rule — a class referenced by a
  `#[MapRequestPayload]` parameter must share the referencing controller's
  namespace.
- **Caveat:** low value, and the trigger is indirect (trace the payload type
  back to its class). Note the existing `dto.requestSuffix` rule does **not**
  apply here — it only fires for DTOs in a `Form/` namespace, so colocated API
  payloads are unchecked on naming. Probably not worth building either way.

## 2026-06-19 — review-comment forms + Turbo retrospective

### Flag raw request parsing in controllers
- **Rule:** user input is bound through a Symfony form, never hand-parsed via
  `$request->request->get()` (see `project-backend`, "Forms and DTOs").
- **Mechanism:** a new `ubermuda/gamache` PHPStan rule — flag
  `Request::request->get()` / `->query->get()` (and `->getString()` etc.)
  inside `*Controller` classes.
- **Caveat:** noisy. Needs an allowlist for dev/test-only controllers and for
  legitimate non-form technical reads (CSRF tokens on hand-rolled fieldless
  forms). Without a good allowlist this will produce false positives.
