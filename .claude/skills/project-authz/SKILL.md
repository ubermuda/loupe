---
name: project-authz
description: Use when adding, changing, or reviewing access control — Voters, #[IsGranted], or any security gate.
---

# Authorization

## Voters

**The generic layer is documented in the `symfony-authorization` skill — invoke it before writing or changing any Voter, `#[IsGranted]` attribute, or `is_granted()` call.** It covers the voter class shape (one per resource, module-local in `Module/<Name>/Security/`, one `public const string` per action, the load-bearing `@extends Voter<...>` PHPDoc union), the resource-grouped dotted attribute naming convention (`'project.create'` / `PROJECT_CREATE`, never property checks like `IS_ORG_OWNER`), `#[IsGranted]` placement and `subject:` resolution by parameter name, voter scoping (most-specific resource in the route), read-vs-write separation, Twig `is_granted(constant(...))`, and the rename checklist.

The rules below are project policy on top of that layer.

**Hard rule: never use `IS_AUTHENTICATED_FULLY` in `#[IsGranted]`.** Every access check must specify a Voter constant and a subject. `IS_AUTHENTICATED_FULLY` bypasses the Voter layer entirely and cannot express resource-level ownership. Replace it with the appropriate Voter attribute:

```php
// ✗ — grants access to any authenticated user regardless of ownership
#[IsGranted('IS_AUTHENTICATED_FULLY')]

// ✓ — delegates to the Voter which checks ownership
#[IsGranted(ProjectVoter::WORKSPACE_MANAGE, subject: 'workspace')]
```

**`#[IsGranted]` on the class is the required form** (gamache's `controller.isGrantedNotClassLevel` rule fails a method-level `#[IsGranted]` on a single-action controller) **— `denyAccessUnlessGranted()` inside `__invoke()` is forbidden.**

An imperative `denyAccessUnlessGranted()` call is a smell that you have not yet found the right **(subject, permission)** pair. Before reaching for it, resolve those two things:

- **Subject:** the most-specific entity the route already resolves (`__invoke(Comment $comment)` → subject `'comment'`). The subject does *not* have to be the entity the policy ultimately checks — the voter walks from `Comment` to `comment.version.document.owner`. "The document isn't a route parameter" is **not** a reason to go imperative: make the `Comment` the subject and let the voter walk up. (`CommentVoter` does exactly this for `comment.delete` / `comment.resolve` / `comment.reply`.)
- **Permission:** a Voter constant for the action (`CommentVoter::DELETE`), per the dotted naming in `symfony-authorization`.

Then declare it on the class: `#[IsGranted(CommentVoter::DELETE, subject: 'comment')]`. Do this even for fieldless POST actions guarded by `#[CsrfToken]` — CSRF and authorization are separate concerns.

**The genuine exception — subject only available as a *query* parameter** (e.g. a Mercure authorize endpoint keyed on `?workspace=UUID`): Symfony cannot resolve the subject at attribute time, so there is no argument for `subject:` to reference. Call `denyAccessUnlessGranted()` from a **private helper method**, never from `__invoke()`, with a class-level comment explaining why the attribute form is impossible. gamache's `controller.denyAccessUnlessGranted` rule only scans `__invoke()`, so the helper-method form passes cleanly — no magic comment required.

**There is no docblock bypass.** A prose phrase in the class docblock does **not** exempt a controller (the old `"access is enforced per-branch"` escape hatch has been removed). If — and only if — a case genuinely needs imperative deny *inside* `__invoke()`, the sole permitted suppression is an explicit, reviewed `// @phpstan-ignore controller.denyAccessUnlessGranted (reason)` on the call line, with a real reason a reviewer can weigh. Reach for that essentially never; the helper-method form above covers the real exception.

## Mercure SSE Authorization

The app's primary Mercure authorization path is `MercureAuthorizationController` at `/mercure/authorize`, which handles browser-initiated JWT cookie requests. It accepts either `?conversation=UUID` or `?workspace=UUID`.

Some page controllers also **pre-authorize** topics directly by calling `$this->authorization->setCookie()` on the initial page response — so the browser does not need a separate trip to `/mercure/authorize` for those subscriptions. `IssueListController` (clone-status topic) and `IssueBrainstormController` (conversation/workspace topics) currently use this pattern.

**Always write all related topics into the cookie, not just the requested one.** Two SSE streams (conversation stream and workspace stream) share the same Mercure JWT cookie. If each stream's authorize request writes only its own topic, whichever fires second clobbers the first stream's subscription — that stream goes silent on the next reconnect. The fix: look up the related entity (workspace for a conversation; conversation for a workspace) and include both topic strings unconditionally.

**Why `#[IsGranted]` is not used at the class level on `MercureAuthorizationController`:** the subject is resolved from a query parameter, not a route parameter. See the exception rule above — `denyAccessUnlessGranted()` is called per-branch in private helper methods instead.
