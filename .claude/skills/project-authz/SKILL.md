---
name: project-authz
description: Use when adding, changing, or reviewing access control — Voters, #[IsGranted], or any security gate.
---

# Authorization

## Voters

**The generic layer — voter class shape, dotted attribute naming, `#[IsGranted]` placement and `subject:` resolution, voter scoping, read-vs-write separation, Twig `is_granted(constant(...))`, the rename checklist — is documented in the `symfony-authorization` skill. Invoke it before writing or changing any Voter, `#[IsGranted]` attribute, or `is_granted()` call.** The rules below are project policy on top of that layer.

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

**What the rule actually flags.** gamache's `controller.denyAccessUnlessGranted` fires on a `denyAccessUnlessGranted()` call in `__invoke()` only when it carries a **subject** (2nd argument) **and** `__invoke()` receives a **route-resolved parameter** to hang that subject on — i.e. exactly the case that should be `#[IsGranted(Voter::ACTION, subject: 'param')]`. Two cases it deliberately leaves alone, because there is no route-resolved subject to declare:

- a **role-only** check with no subject argument (`denyAccessUnlessGranted('ROLE_ADMIN')`); and
- a subject **only resolvable at runtime** because `__invoke()` takes no route parameter — e.g. a Mercure authorize endpoint keyed on `?workspace=UUID`, with `__invoke(Request $request)`. Resolve the subject and deny inside the action; there is no route argument for `subject:` to reference, so the attribute form is genuinely impossible.

`createAccessDeniedException()` and `new AccessDenied*Exception()` are **always** flagged — express a 403 as a denied Voter vote, not by throwing imperatively.

**There is no docblock bypass and no sanctioned suppression.** A prose phrase in the class docblock does **not** exempt a controller (the old `"access is enforced per-branch"` escape hatch was removed). If the rule fires, the fix is to express the check as `#[IsGranted(Voter::ACTION, subject: 'param')]` — not to silence it.

## Mercure SSE Authorization

> **Downstream-only pattern.** The skeleton ships no Mercure integration — `MercureAuthorizationController`, `IssueListController` and `IssueBrainstormController` do not exist here. This section documents the pattern used by consumer projects and applies once Mercure is added.

The app's primary Mercure authorization path is `MercureAuthorizationController` at `/mercure/authorize`, which handles browser-initiated JWT cookie requests. It accepts either `?conversation=UUID` or `?workspace=UUID`.

Some page controllers also **pre-authorize** topics directly by calling `$this->authorization->setCookie()` on the initial page response — so the browser does not need a separate trip to `/mercure/authorize` for those subscriptions. `IssueListController` (clone-status topic) and `IssueBrainstormController` (conversation/workspace topics) currently use this pattern.

**Always write all related topics into the cookie, not just the requested one.** Two SSE streams (conversation stream and workspace stream) share the same Mercure JWT cookie. If each stream's authorize request writes only its own topic, whichever fires second clobbers the first stream's subscription — that stream goes silent on the next reconnect. The fix: look up the related entity (workspace for a conversation; conversation for a workspace) and include both topic strings unconditionally.

**Why `#[IsGranted]` is not used at the class level on `MercureAuthorizationController`:** the subject is resolved from a query parameter, not a route parameter, so the `controller.denyAccessUnlessGranted` rule does not fire (it flags only a deny that carries a subject *and* has a route-resolved parameter). Resolve the subject and call `denyAccessUnlessGranted()` per-branch inside the action.

## Account enumeration policy

The auth flows deliberately differ on whether they reveal account existence:

- **Registration** shows an explicit duplicate error ("email already registered") — standard UX; the enumeration leak is accepted.
- **Password-reset request** and **resend-verification** are silent: unknown account, already-active token, and mail-transport failure all produce the same redirect. Keep new code on the silent side of these flows silent — do not add flashes or errors that distinguish the branches.

This split is intentional; do not "fix" one flow to match the other without a maintainer decision.

**The line between the two sides is what the response can carry.** The anti-enumeration policy holds everywhere the response is a bare acknowledgement — there is nothing to lose by making the two outcomes identical, which is why `RequestPasswordResetHandler` documents account existence as unobservable, and why `JoinWaitlistController` and `ListSitesController` reason the same way. It is waived where a form has a field to attach the error to. `RegisterUserHandler` returns a distinct `account.registration.error.email_duplicate` field error for exactly that reason: responding identically either way costs a real inline error for anyone who mistypes an address they already registered with, and buys little when the same address can be probed at other providers anyway.

So registration's leak is a deliberate exception rather than an oversight. Do not re-file it as a finding, and do not generalise it to a flow whose response is a bare acknowledgement.
