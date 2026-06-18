---
name: symfony-authorization
description: Use when writing or changing Symfony access control — Voter classes, authorization attribute names, `#[IsGranted]` placement, `subject:` resolution, or `is_granted()` in Twig. Covers the resource-grouped dotted attribute convention, voter scoping, read/write separation, and the rename checklist.
---

# Symfony Authorization (Voters + `#[IsGranted]`)

How access control is declared and enforced: Voter classes encapsulate the
policy; controllers and templates express **intent** through action-semantic
attributes. Project-specific hard rules on top of this layer (the
`IS_AUTHENTICATED_FULLY` ban, the `denyAccessUnlessGranted()` exception,
Mercure cookie authorization) live in `project-authz` — read both when
touching access control in this repo.

## Attributes are actions, not properties

An authorization attribute names **what the caller is trying to do**, never a
property of the user: `'project.create'`, not `IS_ORG_OWNER`. The policy
("only the org owner may do this") lives inside the voter and can change
without touching a single call site.

**Naming convention: resource-grouped, dot-separated.** Every attribute is
`{resource}.{action}` in lower-case dotted form — the resource noun
**singular**, then the action verb: `'project.create'`, `'project.manage'`,
`'issue.view'`, `'workspace.manage'`, `'organization.configure'`. The PHP
constant mirrors it as `{RESOURCE}_{ACTION}`: `PROJECT_CREATE`, `ISSUE_VIEW`,
`WORKSPACE_MANAGE`. Never kebab-case (`'create-project'`), never verb-first
(`CREATE_PROJECT`), never plural (`'manage-workspaces'`). The action half is a
verb for what the caller is doing: `create`, `list`, `view`, `manage`,
`configure`.

**Separate READ from WRITE actions per resource.** Even when today's policy
grants both to the same role, define separate constants: `ISSUE_VIEW` for
read-only GET endpoints, `ISSUE_MANAGE` for state-changing POST/PUT/DELETE
endpoints. A write permission on a read endpoint is harmless; a read
permission on a write endpoint is a security gap. Rule of thumb: GET →
`*_VIEW` / `*_LIST`; POST/PUT/DELETE → `*_MANAGE` (`configure` is an accepted
write-side action for settings-style resources, e.g. `ORGANIZATION_CONFIGURE`).

## The shape of a Voter

One voter per resource, in its owning module's `Module/<Name>/Security/`
directory (e.g. `OrganizationVoter` lives in `Module/Organization/Security/`,
namespace `App\Module\Organization\Security`) — never in a central
`src/Security/Voter/`. One `public const string` per action:

```php
/**
 * @extends Voter<'project.create'|'project.list'|'project.manage'|'organization.configure', Organization>
 */
final class OrganizationVoter extends Voter
{
    public const string PROJECT_CREATE = 'project.create';
    // ... one constant per action, all listed in SUPPORTED_ATTRIBUTES

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, strict: true)
            && $subject instanceof Organization;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // the actual policy — e.g. "user is the org owner"
    }
}
```

- `supports()` must check **both** the attribute and the subject type. A voter
  that returns `true` for subjects it does not understand will vote on checks
  it knows nothing about — at best a spurious deny, at worst a type error when
  `voteOnAttribute()` touches an unexpected subject.
- The `@extends Voter<attribute-union, subject-union>` PHPDoc is load-bearing:
  PHPStan uses it to narrow `$attribute` and `$subject` inside
  `voteOnAttribute()`. Adding a constant means adding its string to the union
  too.
- Deny by returning `false`, and log denials with a reason so 403s are
  debuggable.

## `#[IsGranted]` — placement and subject

Controllers declare access with the attribute, never with imperative
`denyAccessUnlessGranted()` calls in the body (`project-authz` documents the
one exception). On single-`__invoke()` controllers the attribute goes on the
**class**, next to `#[Route]` — gamache's `controller.isGrantedNotClassLevel`
rule fails a method-level `#[IsGranted]`. The `subject:` still resolves from the
controller arguments either way:

```php
// On the class, next to #[Route]
#[IsGranted(ProjectVoter::WORKSPACE_MANAGE, subject: 'workspace')]
class SyncWorkspaceController extends AppController

// subject references a method parameter by name — resolved before the voter runs
#[IsGranted(OrganizationVoter::PROJECT_CREATE, subject: 'org')]
class CreateProjectController extends AppController
```

`subject: 'org'` references the controller **method parameter by name**. The
parameter is resolved to an entity first (EntityValueResolver — see
`symfony-entity-route-mapping`), then handed to the voter as `$subject`. Two
consequences of that ordering:

- A non-existent entity returns 404 before the voter ever runs; a denied vote
  returns 403 (`AccessDeniedException`).
- `subject:` by name can only reference a **resolved controller argument**.
  Entities in this project are resolved from route parameters (see
  `symfony-entity-route-mapping`), so a subject that exists only as a *query*
  parameter has no argument to reference — that is why the `project-authz`
  exception exists.

## Voter scope — which voter owns an action

The `{resource}` half of the attribute names the thing being **acted on**,
which is not always the voter's subject type. `OrganizationVoter::PROJECT_CREATE`
guards creating projects *within* an org, so its subject is the
`Organization`. Conversely, issue- and workspace-level actions live in
`ProjectVoter`, which accepts the most-specific entity available in the route
(`Project|Workspace|Repository|Issue`) and walks up to the owning project to
apply the policy.

Rule: the `#[IsGranted]` `subject:` must reference the **most-specific
resource in the route**. Never check a project-scoped action against an org
subject — that skips the project-level ownership chain.

## `is_granted()` in Twig

Templates run the same check with the same constants — use `constant()` so the
attribute string is never duplicated as a literal:

```twig
{% if this.org is not null and is_granted(constant('App\\Security\\Voter\\OrganizationVoter::ORGANIZATION_CONFIGURE'), this.org) %}
```

## Renaming an attribute — four updates plus a grep

(1) The constant name. (2) Its string value. (3) The
`@extends Voter<...|'old-value'|..., Subject>` PHPDoc union. (4) Every
`#[IsGranted(Voter::OLD_CONSTANT, ...)]` reference. PHPStan catches stale
constant-name references but does **not** catch a stale string literal in the
docblock union or an `is_granted('old-value')` call written as a literal.
After renaming, run `grep -rn "'old-string-value'" .` across **all file
types** (templates, YAML, JS — not just PHP) to confirm no literal survived.

## Cross-references

- Project hard rules (`IS_AUTHENTICATED_FULLY` ban, the documented
  `denyAccessUnlessGranted()` exception, Mercure SSE cookie authorization):
  `project-authz`.
- How the `subject:` parameter is resolved from the URL (`{param:variable}`,
  `#[MapEntity]`, parent-scoped lookups): `symfony-entity-route-mapping`.
