---
name: symfony-entity-route-mapping
description: Use when writing or changing Symfony routes that resolve entities from URL parameters — `{param:variable}` notation, `#[MapEntity]`, expr-based lookups, or multi-entity routes. Covers the alias/request-attribute pitfall, raw-string expr semantics, and org-scoped lookups.
---

# Entity ↔ Route Mapping (Symfony EntityValueResolver)

How URL parameters become entities in controller signatures, and the three
pitfalls that repeatedly cause bugs: alias renaming, raw strings in `expr`,
and unscoped multi-entity lookups.

## `{param:variable}` — declare the mapping in the path

When a route parameter corresponds to an entity, declare the mapping in the
route path itself with `{param:variable}`, where `variable` is the controller
method parameter name, and type-hint the parameter as the entity class.
Symfony's `EntityValueResolver` resolves it automatically and returns 404 when
no entity matches.

When the route param name matches the entity field name, no `#[MapEntity]`
attribute is needed:

```php
// Route param "slug" matches Organization::$slug — auto-resolved
#[Route('/{slug:org}', requirements: ['slug' => '[a-z0-9][a-z0-9_\-]{2,29}'])]
public function __invoke(Organization $org): Response
```

(Snippets here show `#[Route]` inline next to `__invoke()` for brevity — in
real controllers it goes on the **class**; gamache's
`ControllerRouteAttributeRule` fails a controller whose only `#[Route]` is
method-level.)

When the names differ, add `#[MapEntity]` to declare the field mapping. (When
the param resolves directly to the entity's `id`, prefer the colon-alias form
`{id:workspace}`, which auto-resolves with no `mapping:` bridge.)

```php
#[Route('/workspace/{workspaceId}/status')]
public function __invoke(
    #[MapEntity(mapping: ['workspaceId' => 'id'])] Workspace $workspace,
): JsonResponse
```

Never inject a repository into a controller just to do a slug lookup — entity
mapping exists for that.

## Pitfall 1 — `{param:alias}` renames the request attribute

`{projectSlug:project}` stores the URL value in request attributes under
`project` (the alias). The original name `projectSlug` **disappears** as a
top-level request attribute — it is not available in `#[MapEntity(expr:)]`
expressions or to anything else reading `$request->attributes` directly (it
survives only inside the `_route_params` attribute).

Consequence: if you need the raw slug in an `expr` (or in a Twig component
reading request attributes), use a **plain** route param without an alias
(`{projectSlug}`) and rely on `#[MapEntity]` on the controller argument for
entity resolution. That is why project routes look like
`/{slug:org}/{projectSlug}/...`: `slug` is aliased (auto-resolution is enough
for the org), while `projectSlug` stays plain because the project lookup needs
it in an expression.

## Pitfall 2 — `expr` variables are raw strings, not resolved entities

`EntityValueResolver` passes the resolved entity to the controller argument but
**does not write it back to request attributes**. Every variable inside an
`#[MapEntity(expr: ...)]` expression is whatever the router stored — always a
raw string, even when another controller argument resolves that same parameter
to an entity. Repository methods used in `expr` must therefore not expect
**entity objects** — scalar parameters are fine (the ExpressionLanguage call
site is not strict-typed, so `'42'` coerces into an `int $seq` parameter):

```php
// WRONG — 'org' here is the string slug, not an Organization entity
#[MapEntity(expr: 'repository.findByOrgAndSlug(org, projectSlug)')]

// RIGHT — a string-based method taking two slugs
#[MapEntity(expr: 'repository.findByOrgSlugAndSlug(org, projectSlug)')]
```

Inside the expression, `repository` is the entity's own repository (the one for
the type-hinted argument class).

## Pitfall 3 — multi-entity lookups must be scoped to their parent

A bare `#[MapEntity(mapping: ['projectSlug' => 'slug'])]` looks the project up
**globally** — another org's project with the same slug would match, a
cross-tenant access hole. Any entity whose uniqueness is scoped to a parent
(project slug per org, issue seq per project) must be resolved through a
repository method that JOINs through the parent chain:

```php
#[Route(
    '/{slug:org}/{projectSlug}/issues/{seq}',
    requirements: ['slug' => '[a-z0-9][a-z0-9_\-]{2,29}', 'projectSlug' => '[a-z0-9][a-z0-9\-]*', 'seq' => '\d+'],
)]
public function __invoke(
    Organization $org,
    #[MapEntity(expr: 'repository.findByOrgSlugAndSlug(org, projectSlug)')] Project $project,
    #[MapEntity(expr: 'repository.findByOrgSlugAndProjectSlugAndSeq(org, projectSlug, seq)')] Issue $issue,
): Response
```

The repository side JOINs through the parent:

```php
public function findByOrgSlugAndSlug(string $orgSlug, string $projectSlug): ?Project
{
    return $this->createQueryBuilder('p')
        ->innerJoin('p.organization', 'o')
        ->where('o.slug = :orgSlug')
        ->andWhere('p.slug = :projectSlug')
        ->setParameter('orgSlug', $orgSlug)
        ->setParameter('projectSlug', $projectSlug)
        ->getQuery()
        ->getOneOrNullResult();
}
```

Resolving the parent (`$org`) as its own argument and *also* scoping the child
lookup by the parent's slug is intentional, not redundant: each argument is
resolved independently (Pitfall 2), so the child expression cannot reuse the
resolved parent.

## Project conventions

- **Use `{slug:org}` (not `{organizationSlug}`) for the org parameter** unless
  the route already has another `slug` parameter. When two entities with the
  same field name appear in one route, keep the full descriptive name for the
  second one.
- **Parameter names are camelCase** (gamache's PHPStan `route.paramNotCamelCase`
  rule enforces this) **and spelled out in full by convention** — `{projectSlug}`,
  not `{project_slug}` or `{projSlug}`. This matches the camelCase of the
  controller argument / `expr` variable that reads the param.
- Always add `requirements:` regexes for slug/seq parameters.

The controllers and repository above are illustrative examples, not files that
ship in this skeleton.

For the surrounding controller conventions see `project-backend`; for access
control on resolved entities (`#[IsGranted]` subjects are resolved by this
mechanism) see `symfony-authorization` and `project-authz`.
