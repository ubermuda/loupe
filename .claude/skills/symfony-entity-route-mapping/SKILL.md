---
name: symfony-entity-route-mapping
description: "Use when writing or changing Symfony routes that resolve entities from URL parameters, with `{param:variable}` notation, `#[MapEntity]`, expr-based lookups, or multi-entity routes. Covers the alias/request-attribute pitfall, raw-string expr semantics, and org-scoped lookups."
---

# Entity and route mapping (Symfony EntityValueResolver)

## `{param:variable}` declares the mapping in the path

Declare the mapping in the route path with `{param:variable}`. `variable` is the
controller method parameter name. Type-hint that parameter as the entity class.
`EntityValueResolver` then resolves it and returns 404 when no entity matches.

When the route parameter name matches the entity field name, you need no
`#[MapEntity]` attribute:

```php
// Route param "slug" matches Organization::$slug — auto-resolved
#[Route('/{slug:org}', requirements: ['slug' => '[a-z0-9][a-z0-9_\-]{2,29}'])]
public function __invoke(Organization $org): Response
```

The snippets here put `#[Route]` next to `__invoke()` for brevity. In a real
controller it goes on the **class**. Gamache's `ControllerRouteAttributeRule`
fails a controller whose only `#[Route]` is method-level.

When the names differ, add `#[MapEntity]` to declare the field mapping. When the
parameter resolves to the entity's `id`, prefer the colon-alias form
`{id:workspace}`, which auto-resolves with no `mapping:` bridge.

```php
#[Route('/workspace/{workspaceId}/status')]
public function __invoke(
    #[MapEntity(mapping: ['workspaceId' => 'id'])] Workspace $workspace,
): JsonResponse
```

Never inject a repository into a controller only to do a slug lookup. Entity
mapping exists for that.

## Pitfall 1: `{param:alias}` renames the request attribute

`{projectSlug:project}` stores the URL value under the alias `project`. The
original name `projectSlug` **disappears** as a top-level request attribute. It
is not available in `#[MapEntity(expr:)]` expressions, or to anything that reads
`$request->attributes` directly. It survives only inside the `_route_params`
attribute.

When you need the raw slug in an `expr`, or in a Twig component that reads
request attributes, use a **plain** route parameter with no alias. Resolve the
entity with `#[MapEntity]` on the controller argument. Project routes therefore
look like `/{slug:org}/{projectSlug}/...`. Auto-resolution is enough for the org,
so `slug` is aliased. The project lookup needs the raw value in an expression, so
`projectSlug` stays plain.

## Pitfall 2: `expr` variables are raw strings, not resolved entities

`EntityValueResolver` passes the resolved entity to the controller argument.
It **does not write it back to request attributes**. Every variable inside an
`#[MapEntity(expr: ...)]` expression is the raw string the router stored, even
when another controller argument resolves that same parameter to an entity.

Repository methods used in `expr` must not expect **entity objects**. Scalar
parameters are fine. The ExpressionLanguage call site is not strict-typed, so
`'42'` coerces into an `int $seq` parameter.

```php
// WRONG — 'org' here is the string slug, not an Organization entity
#[MapEntity(expr: 'repository.findByOrgAndSlug(org, projectSlug)')]

// RIGHT — a string-based method taking two slugs
#[MapEntity(expr: 'repository.findByOrgSlugAndSlug(org, projectSlug)')]
```

Inside the expression, `repository` is the repository of the type-hinted
argument class.

## Pitfall 3: scope a multi-entity lookup to its parent

A bare `#[MapEntity(mapping: ['projectSlug' => 'slug'])]` looks the project
up **globally**. Another org's project with the same slug then matches, which
is a cross-tenant access hole. An entity whose uniqueness is scoped to a parent must
resolve through a repository method that JOINs the parent chain. Project slug per
org and issue seq per project are both such entities.

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

The parent `$org` resolves as its own argument, and the child lookup also takes
the parent's slug. That is intentional. Each argument resolves independently
(Pitfall 2), so the child expression cannot reuse the resolved parent.

## Project conventions

- Use `{slug:org}` for the org parameter, not `{organizationSlug}`. When the
  route already carries another `slug` parameter, keep the full descriptive name
  for the second entity.
- Write parameter names in camelCase. Gamache's PHPStan
  `route.paramNotCamelCase` rule enforces this.
- Spell parameter names out in full: `{projectSlug}`, not `{project_slug}` or
  `{projSlug}`. This matches the camelCase controller argument, or `expr`
  variable, that reads the parameter.
- Always add `requirements:` regexes for slug and seq parameters.

The controllers and repository above are illustrative examples. They are not
files that ship in this skeleton.

For the surrounding controller conventions see `project-backend`. For access
control on resolved entities see `symfony-authorization` and `project-authz`.
`#[IsGranted]` subjects resolve through this mechanism.
