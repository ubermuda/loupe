---
name: symfony-authorization
description: "Use when writing or changing Symfony access control, Voter classes, authorization attribute names, `#[IsGranted]` placement, `subject:` resolution, or `is_granted()` in Twig. Covers the resource-grouped dotted attribute convention, voter scoping, read/write separation, and the rename checklist."
---

# Symfony Authorization (Voters + `#[IsGranted]`)

Voter classes hold the policy. Controllers and templates express intent through action-semantic attributes. Project hard rules sit on top of this layer in `project-authz`. Read both skills when you touch access control in this repo.

## Attributes are actions, not properties

An attribute names what the caller tries to do. It never names a property of the user: write `'project.create'`, not `IS_ORG_OWNER`. The policy ("only the org owner may do this") lives inside the voter, and can change without touching a call site.

Name every attribute `{resource}.{action}` in lower-case, dot-separated form. Write the resource noun singular, then the action verb: `'project.create'`, `'project.manage'`, `'issue.view'`, `'workspace.manage'`, `'organization.configure'`. Mirror it in the PHP constant as `{RESOURCE}_{ACTION}`: `PROJECT_CREATE`, `ISSUE_VIEW`, `WORKSPACE_MANAGE`. The action half is a verb for what the caller does: `create`, `list`, `view`, `manage`, `configure`.

- Never use kebab-case (`'create-project'`).
- Never put the verb first (`CREATE_PROJECT`).
- Never use a plural (`'manage-workspaces'`).

Separate READ from WRITE actions per resource. Define separate constants even when today's policy grants both to the same role. Use `ISSUE_VIEW` for read-only GET endpoints, and `ISSUE_MANAGE` for state-changing POST/PUT/DELETE endpoints.

A write permission on a read endpoint is harmless. A read permission on a write endpoint is a security gap. Rule of thumb: GET takes `*_VIEW` or `*_LIST`, POST/PUT/DELETE takes `*_MANAGE`. `configure` is an accepted write-side action for settings-style resources, such as `ORGANIZATION_CONFIGURE`.

## The shape of a Voter

Write one voter per resource. Put it in its owning module's `Module/<Name>/Security/` directory. `OrganizationVoter` lives in `Module/Organization/Security/`, namespace `App\Module\Organization\Security`. Never put a voter in a central `src/Security/Voter/`. Declare one `public const string` per action.

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
        // the actual policy, e.g. "user is the org owner"
    }
}
```

- `supports()` must check both the attribute and the subject type. A voter that returns `true` for a subject it does not understand votes on checks it knows nothing about. At best you get a spurious deny. At worst `voteOnAttribute()` hits a type error on an unexpected subject.
- The `@extends Voter<attribute-union, subject-union>` PHPDoc is load-bearing. PHPStan uses it to narrow `$attribute` and `$subject` inside `voteOnAttribute()`. When you add a constant, add its string to the union.
- Deny by returning `false`. Log denials with a reason so 403s are debuggable.

## `#[IsGranted]` placement and subject

Controllers declare access with the attribute. Never call `denyAccessUnlessGranted()` in the controller body; `project-authz` documents the one exception. On a single-`__invoke()` controller, put the attribute on the class, next to `#[Route]`. gamache's `controller.isGrantedNotClassLevel` rule fails a method-level `#[IsGranted]`. The `subject:` still resolves from the controller arguments either way.

```php
// On the class, next to #[Route]
#[IsGranted(ProjectVoter::WORKSPACE_MANAGE, subject: 'workspace')]
class SyncWorkspaceController extends AppController

// subject references a method parameter by name, resolved before the voter runs
#[IsGranted(OrganizationVoter::PROJECT_CREATE, subject: 'org')]
class CreateProjectController extends AppController
```

`subject: 'org'` references the controller method parameter by name. The EntityValueResolver resolves that parameter to an entity first, then hands it to the voter as `$subject` (see `symfony-entity-route-mapping`). That ordering has these effects:

- A non-existent entity returns 404 before the voter runs. A denied vote returns 403 (`AccessDeniedException`).
- `subject:` by name can only reference a resolved controller argument. This project resolves entities from route parameters, so a subject that exists only as a *query* parameter has no argument to reference. That is why the `project-authz` exception exists.

## Voter scope, or which voter owns an action

The `{resource}` half of the attribute names the thing acted on, which is not always the voter's subject type. `OrganizationVoter::PROJECT_CREATE` guards creating projects inside an org, so its subject is the `Organization`. Issue-level and workspace-level actions live in `ProjectVoter`. That voter accepts the most-specific entity available in the route (`Project|Workspace|Repository|Issue`), then walks up to the owning project to apply the policy.

Rule: the `#[IsGranted]` `subject:` must reference the most-specific resource in the route. Never check a project-scoped action against an org subject. That skips the project-level ownership chain.

## `is_granted()` in Twig

Templates run the same check with the same constants. Use `constant()` so the attribute string is never duplicated as a literal.

```twig
{% if this.org is not null and is_granted(constant('App\\Security\\Voter\\OrganizationVoter::ORGANIZATION_CONFIGURE'), this.org) %}
```

## Renaming an attribute

Update all four places, then grep.

1. The constant name.
2. Its string value.
3. The `@extends Voter<...|'old-value'|..., Subject>` PHPDoc union.
4. Every `#[IsGranted(Voter::OLD_CONSTANT, ...)]` reference.

PHPStan catches a stale constant-name reference. It does not catch a stale string literal in the docblock union, nor an `is_granted('old-value')` call written as a literal. After the rename, run `grep -rn "'old-string-value'" .` across all file types to confirm no literal survived. Check templates, YAML and JS, not just PHP.

## Cross-references

- `project-authz`: project hard rules, the `IS_AUTHENTICATED_FULLY` ban, the documented `denyAccessUnlessGranted()` exception, Mercure SSE cookie authorization.
- `symfony-entity-route-mapping`: how the `subject:` parameter resolves from the URL (`{param:variable}`, `#[MapEntity]`, parent-scoped lookups).
