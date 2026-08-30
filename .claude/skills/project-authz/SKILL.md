---
name: project-authz
description: "Use when adding, changing, or reviewing access control: Voters, #[IsGranted], security gates, Mercure topic authorization, or account enumeration policy."
---

# Authorization

## Voters

Invoke the `symfony-authorization` skill before you write or change a Voter, an `#[IsGranted]` attribute, or an `is_granted()` call. It documents the generic layer:

- voter class shape
- dotted attribute naming
- `#[IsGranted]` placement and `subject:` resolution
- voter scoping
- read-vs-write separation
- Twig `is_granted(constant(...))`
- the rename checklist

The rules below are project policy on top of that layer.

Never use `IS_AUTHENTICATED_FULLY` in `#[IsGranted]`. Every access check names a Voter constant and a subject. `IS_AUTHENTICATED_FULLY` bypasses the Voter layer and cannot express resource-level ownership.

```php
// ✗ — grants access to any authenticated user regardless of ownership
#[IsGranted('IS_AUTHENTICATED_FULLY')]

// ✓ — delegates to the Voter which checks ownership
#[IsGranted(ProjectVoter::WORKSPACE_MANAGE, subject: 'workspace')]
```

Put `#[IsGranted]` on the class. gamache's `controller.isGrantedNotClassLevel` rule fails a method-level `#[IsGranted]` on a single-action controller. `denyAccessUnlessGranted()` inside `__invoke()` is forbidden.

An imperative `denyAccessUnlessGranted()` call shows that you have not found the right (subject, permission) pair. Resolve both:

- Subject: the most specific entity the route already resolves. `__invoke(Comment $comment)` gives the subject `'comment'`. The subject need not be the entity the policy checks, because the voter walks up to `comment.version.document.owner`. "The document isn't a route parameter" is not a reason to go imperative. `CommentVoter` walks up this way for `comment.delete`, `comment.resolve` and `comment.reply`.
- Permission: a Voter constant for the action, such as `CommentVoter::DELETE`, per the dotted naming in `symfony-authorization`.

Then declare it on the class: `#[IsGranted(CommentVoter::DELETE, subject: 'comment')]`. Do this also for fieldless POST actions guarded by `#[CsrfToken]`. CSRF and authorization are separate concerns.

### What the rule flags

gamache's `controller.denyAccessUnlessGranted` fires on a `denyAccessUnlessGranted()` call in `__invoke()`. It fires only when the call carries a subject (2nd argument) and `__invoke()` receives a route-resolved parameter, which is the case that must become `#[IsGranted(Voter::ACTION, subject: 'param')]`. The rule leaves two cases alone, because neither has a route-resolved subject to declare:

- A role-only check with no subject argument, such as `denyAccessUnlessGranted('ROLE_ADMIN')`.
- A subject that is resolvable only at runtime, because `__invoke()` takes no route parameter. An example is a Mercure authorize endpoint keyed on `?workspace=UUID`, with `__invoke(Request $request)`. Resolve the subject and deny inside the action. No route argument exists for `subject:` to reference.

`createAccessDeniedException()` and `new AccessDenied*Exception()` are always flagged. Express a 403 as a denied Voter vote. Do not throw imperatively.

There is no docblock bypass and no sanctioned suppression. A prose phrase in the class docblock does not exempt a controller, and the old `"access is enforced per-branch"` escape hatch was removed. If the rule fires, write `#[IsGranted(Voter::ACTION, subject: 'param')]`. Do not silence the rule.

## Mercure SSE Authorization

> Downstream-only pattern. The skeleton ships no Mercure integration, so `MercureAuthorizationController`, `IssueListController` and `IssueBrainstormController` do not exist here. This section applies once Mercure is added.

`MercureAuthorizationController` at `/mercure/authorize` is the primary path. It handles browser-initiated JWT cookie requests, and accepts either `?conversation=UUID` or `?workspace=UUID`.

Some page controllers pre-authorize topics with `$this->authorization->setCookie()` on the initial page response, so the browser needs no separate trip to `/mercure/authorize`. `IssueListController` (clone-status topic) and `IssueBrainstormController` (conversation and workspace topics) use this pattern.

Write all related topics into the cookie, not only the requested one. The conversation stream and the workspace stream share one Mercure JWT cookie. If each authorize request writes only its own topic, the second request clobbers the first stream's subscription, and that stream goes silent on the next reconnect. Look up the related entity, workspace for a conversation and conversation for a workspace, and include both topic strings unconditionally.

`MercureAuthorizationController` has no class-level `#[IsGranted]`, because it resolves the subject from a query parameter, not a route parameter. `controller.denyAccessUnlessGranted` therefore does not fire. Resolve the subject and call `denyAccessUnlessGranted()` per branch inside the action.

## Account enumeration policy

The auth flows differ on purpose about whether they reveal account existence.

- Registration shows an explicit duplicate error, "email already registered". The enumeration leak is accepted.
- Password-reset request and resend-verification stay silent. An unknown account, an already-active token and a mail-transport failure all produce the same redirect.

Keep new code on the silent side of these flows silent. Do not add flashes or errors that distinguish the branches. Do not "fix" one flow to match the other without a maintainer decision.

The split follows what the response can carry. The anti-enumeration policy holds wherever the response is a bare acknowledgement. `RequestPasswordResetHandler` documents account existence as unobservable, and `JoinWaitlistController` and `ListSitesController` reason the same way. The policy is waived where a form has a field to attach the error to. `RegisterUserHandler` returns a distinct `account.registration.error.email_duplicate` field error for that reason. An identical response would cost a real inline error for anyone who mistypes an address they already registered with. It buys little, because the same address can be probed at other providers.

Registration's leak is a deliberate exception. Do not re-file it as a finding. Do not generalise it to a flow whose response is a bare acknowledgement.
