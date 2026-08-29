---
name: project-templates
description: Use when working on `.html.twig` files, Twig components, or Turbo stream templates.
---

# Templates, Twig components and form theme

## Twig templates

- Every `{% include %}` passes its variables explicitly with `with { ... } only`. Context does not cascade.
- When you extract a shared partial, list every variable it reads. Pass them all at every include site.
- Turbo stream templates run in a different controller context and inherit no page variables. Pass every variable explicitly.
- Turbo stream templates target element IDs directly, for example `<turbo-stream target="event-hero-title">`. When you change or remove an element `id`, search the stream templates for that ID and update them. They live in another file, and a wrong ID raises no compile error.
- Hard-reload (`ignoreCache: true`) before you inspect the live DOM with DevTools or the Chrome MCP. Turbo's page cache serves snapshots with stale class names.

### The authenticated layout owns `project`

`base.html.twig` runs `{% set project = current_project() %}` before it yields `block body`. On a route with no project `{id}` param it resolves to null and silently clobbers a controller-passed variable named `project`. Never pass `project` from a param-less route to a template inside the authenticated layout. Use a distinct name (`wizardProject`), or forward the raw id string so `current_project()` resolves it.

## Module template namespaces

Each module's `templates/Module/<Module>/` directory is registered as `@<Module>` in `config/packages/twig.yaml` under `twig.paths`. Reference module templates by that namespace, never by the plain `Module/<Module>/...` path.

```yaml
twig:
    paths:
        '%kernel.project_dir%/templates/Module/Account': 'Account'
```

Write `@Account/security/login.html.twig` everywhere: in PHP (`render()`, `->htmlTemplate()`, `->textTemplate()`) and in Twig (`extends`, `include`, `embed`, `from`, `import`). The namespace name is the module name verbatim.

- Templates in the `templates/` root stay un-namespaced: `base.html.twig`, `form/...`, `email/...`.
- `twig_component.yaml` directory mappings are unrelated. They map component PHP namespaces to scan directories (`Module/<Module>/components/`) and stay plain paths. Components are referenced by `<twig:Name>`, never by an `@`-path.
- When you add a module, register its `@<Module>` namespace in `twig.yaml` in the same change. Otherwise every template reference 404s at render time.
- A builder that assembles a template path from a class's module segment must emit `@<Module>/...`, not `Module/<Module>/...`.
- Verify registration with `bin/console debug:twig`, which lists all loader namespaces.

`lint:twig` validates syntax only. It does not check that `extends` and `include` targets resolve, so a green `lint:twig` does not prove a template-path refactor is correct. Verify with `bin/console debug:twig` for namespace registration, and with the e2e suite for render-time resolution failures.

## Twig components

- `config/packages/twig_component.yaml` resolves component templates by namespace prefix. Each `defaults` entry must end in `\`. The config supports no per-component override.
- For a template outside its namespace-mapped directory, set the path on the attribute: `#[AsTwigComponent(template: 'path/to/Component.html.twig')]`.
- Component PHP classes live in their owning module, under `Module/<Name>/Twig/Components/` (namespace `App\Module\<Name>\Twig\Components\`). Never use a central `src/Twig/Components/`. Each module registers its own namespace to directory mapping:

```yaml
twig_component:
    defaults:
        App\Module\Event\Twig\Components\: 'Module/Event/components/'
        App\Module\Event\Twig\Components\Admin\: 'Module/Event/admin/components/'
        App\Module\Poll\Twig\Components\: 'Module/Poll/components/'
```

Use `<twig:ComponentName prop="{{ value }}" />`. To pass a PHP object, prefix the prop with a colon: `:prop="someObject"` evaluates the Twig expression. Without the colon the value is a literal string.

A self-contained component that needs an entity injects a repository and resolves the entity from the raw route attribute. It does not take the entity as a prop. `EntityValueResolver` does not write the resolved entity back to the request attributes, so `$request->attributes->get('param')` always returns the raw string from the URL. See `symfony-entity-route-mapping`.

```php
public function mount(): void
{
    $raw = $this->requestStack->getCurrentRequest()?->attributes->get('param');
    if (!is_string($raw) || '' === $raw) {
        return;
    }
    $this->entity = $this->repository->findOneBy(['slug' => $raw]);
    // ...
}
```

When a component template starts with a header comment that documents its props, those props must match the component's `mount()` signature. When you add, remove or rename a `mount()` parameter, update the comment in the same commit. A stale comment is worse than no comment.

## Turbo frames and active state

Never render active state on the server for links inside a Turbo frame. Only the frame content re-renders on navigation, so the sidebar stays stale. Derive the active state client-side, for example match `window.location.pathname` against each link's `href` on `turbo:load`. Use a dedicated Stimulus controller for this pattern.

## Template naming

A template file name must match the controller's verb prefix. `CreateFooController` renders `create_foo.html.twig`; `new_foo.html.twig` is wrong. The verb (create, list, view, edit, delete) is identical in both names. When you create or rename a controller, rename its template in the same commit. Gamache's `ControllerTemplateNameRule` enforces this, and mirrors the controller path to the template path.

## Page titles

A page `{% block title %}` composes two translated strings, the page-specific part and the brand. Never use one key with the brand baked in.

```twig
{% block title %}{{ 'account.login.page.title'|trans }} — {{ 'app.name'|trans }}{% endblock %}
```

The page-title trans-unit holds only the page part: `account.login.page.title` gives `Sign in`. `app.name` is the single source of truth for the brand name in titles. Do not write `Sign in — Loupe` into the title key. The brand then changes in one place, and review expects it.
