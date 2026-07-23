---
name: project-templates
description: Use when working on `.html.twig` files, Twig components, or Turbo stream templates.
---

# Templates — Twig, Twig Components, Form Theme

## Twig Templates

- All `{% include %}` calls must pass variables explicitly using `with { ... } only`. No implicit context cascading.
- When extracting a shared partial, identify every variable the partial reads and pass them all explicitly at every include site.
- Turbo stream templates must also pass all variables explicitly — they run in a different controller context and do not inherit page variables automatically.
- Turbo stream templates reference target element IDs directly (e.g., `<turbo-stream target="event-hero-title">`). When you modify a template and change or remove an element's `id`, search for stream templates that target that ID and update them too — they live in a different file and won't cause a compile error if they're wrong.

**Turbo page cache and DOM inspection:** When inspecting the live DOM via DevTools or the Chrome MCP, always hard-reload (`ignoreCache: true`) first. Turbo's page cache can serve a snapshot with stale class names that don't reflect recent template edits.

## Module template namespaces

Module templates are referenced through a per-module Twig namespace, **not** the plain `Module/<Module>/...` path. Each module's `templates/Module/<Module>/` directory is registered as `@<Module>` in `config/packages/twig.yaml` under `twig.paths`:

```yaml
twig:
    paths:
        '%kernel.project_dir%/templates/Module/Account': 'Account'
```

Reference them as `@Account/security/login.html.twig` everywhere — PHP (`render()`, `->htmlTemplate()`, `->textTemplate()`) and Twig (`extends`, `include`, `embed`, `from`, `import`). The namespace name is the module name verbatim.

- **Plain `templates/`-root templates stay un-namespaced** (`base.html.twig`, `form/...`, `email/...`).
- **`twig_component.yaml` directory mappings are unrelated** — those map component PHP namespaces to scan directories (`Module/<Module>/components/`) and stay as plain paths; components are referenced by `<twig:Name>`, never by `@`-path.
- **Adding a new module?** Register its `@<Module>` namespace in `twig.yaml` in the same change, or every template reference 404s at render time.
- **Dynamic template paths** must build the namespace form too — any builder that assembles a template path from a class's module segment must emit `@<Module>/...`, not `Module/<Module>/...`.
- Verify registration with `bin/console debug:twig` (lists all loader namespaces).

**Verifying a template-path refactor:** `lint:twig` only validates *syntax*, not whether `extends`/`include` targets resolve. The real safety net is `bin/console debug:twig` (confirms namespaces are registered) plus the e2e suite (catches render-time resolution failures) — a green `lint:twig` alone does not prove the paths resolve.

## Twig Components

- Component templates are resolved by namespace prefix via `config/packages/twig_component.yaml`. The `defaults` entries must end in `\` — per-component overrides are not supported in the config.
- When a component's template needs to live outside its namespace-mapped directory, set the path explicitly on the attribute: `#[AsTwigComponent(template: 'path/to/Component.html.twig')]`.

**PHP class location:** Component PHP classes live in their owning module, under `Module/<Name>/Twig/Components/` (namespace `App\Module\<Name>\Twig\Components\`) — never in a central `src/Twig/Components/`. Each module registers its own namespace→directory mapping in `config/packages/twig_component.yaml`:

```yaml
twig_component:
    defaults:
        App\Module\Event\Twig\Components\: 'Module/Event/components/'
        App\Module\Event\Twig\Components\Admin\: 'Module/Event/admin/components/'
        App\Module\Poll\Twig\Components\: 'Module/Poll/components/'
```

Usage: `<twig:ComponentName prop="{{ value }}" />`. To pass a PHP object (not a string), use the colon-prefix syntax — `:prop="someObject"` evaluates the Twig expression; without the colon the value is a literal string.

**Self-contained components that need an entity must inject a repository and resolve from the raw route attribute** rather than receiving the entity as a prop. `EntityValueResolver` does not write the resolved entity back to request attributes, so `$request->attributes->get('param')` always returns the raw string from the URL — the component must do its own repository lookup. See `symfony-entity-route-mapping`.

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

**Component template header comments must stay in sync with `mount()`.** When a component template starts with a header comment documenting its props, those documented props must match the component's `mount()` signature. When you change `mount()` (add, remove, or rename a parameter), update that comment in the same commit. Stale comments are worse than no comments.

## Turbo Frames and active state

Never use server-rendered active state on links inside Turbo frames — only the frame content re-renders on navigation, so the sidebar stays stale. Derive active state client-side (e.g., match `window.location.pathname` against each link's `href` on `turbo:load`). Use a dedicated Stimulus controller for this pattern.

## Template naming

**Template file names must match the controller's verb prefix.** A controller named `CreateFooController` renders `create_foo.html.twig`; `new_foo.html.twig` is wrong. The verb (create, list, view, edit, delete) must be identical in both names. When you create or rename a controller, rename the template to match in the same commit. Enforced by gamache's `ControllerTemplateNameRule`, which mirrors the controller → template path.

## Page titles

A page `{% block title %}` must compose **two translated strings** — the page-specific part and the brand — never one key with the brand baked in:

```twig
{% block title %}{{ 'account.login.page.title'|trans }} — {{ 'app.name'|trans }}{% endblock %}
```

The page-title trans-unit holds only the page part (`account.login.page.title` → `Sign in`). `app.name` is the single source of truth for the brand name in titles — do not write `Sign in — Loupe` into the title key. This keeps the brand changeable in one place and is what review expects.
