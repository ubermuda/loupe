---
name: project-frontend
description: Use when working on `assets/styles/app.css`, Stimulus controllers, Turbo patterns, modal components, or any frontend visual behaviour.
---

# Frontend — CSS, Stimulus, Turbo, and Animations

## Semantic CSS class layer

If the project defines a semantic class layer in `app.css`, all component styles should be defined there using `@apply` with Tailwind utilities internally. Templates should use these semantic classes — not raw Tailwind utilities or inline styles scattered across templates.

When a new visual pattern is needed that isn't covered by an existing class, search `app.css` for an existing class first before adding one. New component classes should go inside `@layer components` with a doc comment describing what the class is and when to use it. Use `@apply` for standard Tailwind utilities; use raw CSS only for things with no `@apply` equivalent (multi-layer backgrounds with CSS variables, pseudo-elements, `-webkit-appearance`, SVG data URIs).

CSS design tokens (colors, gradients, backgrounds) should live as CSS custom properties in `:root` and be referenced inside class definitions, not hardcoded in templates.

## Tailwind v4 — known quirks and constraints

**CSS cascade order for modifier classes:** Modifier classes (e.g. a size or icon variant of a button) must be defined **after** the base class they modify in `app.css`. Within `@layer components`, later definitions win on conflicting properties at equal specificity. A modifier defined before its base class will be silently overridden.

**Viewport-relative values:** Tailwind's `@apply` does not support `vh`/`vw`/`dvh` units. Use `@apply` for all standard utilities and add a raw CSS line for the viewport-relative value alongside it.

**Dynamic Tailwind classes — keep a "hint" div:** Tailwind v4 only emits CSS for classes it can statically see in templates. When a Twig template interpolates a class name (e.g. `class="alert-{{ severity }}"`, `class="rounded-{{ token }}"`), the generated permutations may never appear in any single template literal, so Tailwind silently drops them. The escape hatch is a `<div class="hidden ...">` near the bottom of `templates/base.html.twig` — list every dynamically-interpolated class there. Don't scatter "safelist" hacks across templates; keep this div the single source of truth.

**`--radius-full` is not emitted by Tailwind v4:** Every other `rounded-{token}` produces a `--radius-{token}` custom property in the built CSS — except `rounded-full`, which compiles directly to `border-radius: calc(infinity * 1px)`. So `var(--radius-full)` resolves to nothing in any inline style or custom property that depends on it. If you need `var(--radius-full)` to work, declare it explicitly in `@theme` in `app.css`:

```css
--radius-full: calc(infinity * 1px);
```

Adding `rounded-full` to the hint div does **not** fix this — only the `@theme` declaration does.

**`@layer` cascade order:** Unlayered CSS in `app.css` always wins over layered rules (e.g. `@layer components`, or layers from any CSS library) regardless of specificity — use unlayered declarations to override library component styles without fighting specificity.

## General CSS patterns

**Flex alignment overrides via auto-margins:** Do not try to override a `justify-*` set inside a component class with another `justify-*` utility on the same element — results are unreliable. Instead use an auto-margin on a child: `mr-auto` on the first child pushes subsequent siblings right; `ml-auto` on the last child pushes it right. This sidesteps `justify-content` entirely.

**Layout max-width consistency:** Keep all layout container max-widths in sync using the Tailwind scale — never hardcode pixel values. Mismatched max-widths cause horizontal misalignment between anchored dropdowns and page-body elements.

**Form action rows:** Wrap submit buttons in a flex row (`flex items-center justify-end gap-3 pt-2`) as the last child of the form. When the row also contains a secondary link, place the link first with `mr-auto` — it pins to the left while the button stays right.

When a form action row needs to stack vertically on mobile and be side-by-side on desktop, use responsive flexbox utilities. If the DOM order must differ between mobile and desktop (e.g. button first on mobile, link first on desktop), use `sm:order-1` / `sm:order-2` on the children — do not duplicate elements.

**Absolutely-positioned panels anchored to a padded parent:** `right: 0` on an absolutely positioned child resolves against the parent's *padding edge*, not its border edge. If the parent has `padding-right: Xpx`, `right: 0` places the child's right edge `X`px inward from the parent's border. Use this deliberately for precise panel alignment. Verify with DevTools `getBoundingClientRect()`.

**`details[open]` CSS for open-state styling:** Use `details[open] > .child-class { ... }` in `app.css` to style elements differently when a `<details>` is open — no Stimulus controller or JS needed.

**Horizontally scrollable containers on mobile:** For tab bars and toolbars that overflow on small screens, add `overflow-x: auto; scrollbar-width: none;` and `::-webkit-scrollbar { display: none }` to hide the scrollbar across browsers. Add `scroll-padding-inline: 1rem` so `scrollIntoView` leaves breathing room at the edges. Use `white-space: nowrap` when items must not wrap.

**Keyboard focus on custom controls wrapping hidden inputs:** When a component hides a native input (`opacity-0 w-0 h-0`) and uses a label as the visual target, add a focus ring via `:has(input:focus-visible)` on the wrapper — not `:focus` (which fires on mouse click too). Example:

```css
.my-custom-control:has(input:focus-visible) {
  @apply outline-2 outline-offset-2 outline-slate-500;
}
```

This pattern applies to any custom control that visually replaces a native input.

**Button `:active` press effect:** When adding a button variant, always add a matching `:active` block (e.g. `translate-y-px scale-97` with a darkened background or reduced shadow). The base class must also include `transition` so the press animates — omitting it produces a jarring snap.

**Distinguishing irreversible from recoverable destructive actions:** Use a visually distinct "danger zone" treatment (e.g. a striped or heavily bordered card with a solid red button) for permanent/irreversible actions such as account deletion. Use a softer destructive style (e.g. a ghost or outline button in orange) only for recoverable destructive actions (removing an integration, deleting a recoverable record). The distinction — permanent vs. reversible — should be visible at a glance.

**Inline `<details>/<summary>` for recoverable destructive actions:** Use a `<details><summary>` disclosure pattern as the inline confirmation step for reversible destructive actions on settings pages (e.g. detaching an integration). Reserve `<dialog>` modals for irreversible actions. Use `details[open] > .child-class` for open-state styling — no Stimulus controller needed.

**Reading CSS custom properties in JavaScript:** Read CSS custom properties applied to `<body>` or any ancestor with `getComputedStyle(element).getPropertyValue('--my-var').trim()` in a Stimulus controller to feed theme-matched values into canvas drawing or WAAPI animations.

## Modals

Use a Stimulus controller to open `<dialog>` modals.

- Wrap the trigger and dialog together in a `data-controller="modal"` div.
- Add `data-modal-target="dialog"` on the `<dialog>` element.
- Add `data-action="click->modal#open"` on the trigger button or link.
- To close with the slide-out animation, use `data-action="click->modal#close"` — never call `dialog.close()` directly, never use `<form method="dialog">` (even with the Stimulus action, the browser still calls `dialog.close()` natively), and never use `<a href="...">` as a cancel trigger (Turbo fires the navigation mid-animation). Cancel buttons must always be `<button type="button" data-action="click->modal#close">`.
- Never use inline `onclick` to call `showModal()` directly.

**Modal animations:** Animate the dialog element via WAAPI in the controller — not CSS animations. Only the `::backdrop` should use CSS animations.

**`csrf_protection_controller.js` must stay eager** (`/* stimulusFetch: 'eager' */`): login and other forms use `input[name="_csrf_token"]` directly without a `data-controller` attribute, so a lazily-loaded controller would never activate and its document-level `submit` listener would never register. Do not change it to `'lazy'`.

**Lazy Stimulus controllers and CSS entry animations:** Controllers marked `/* stimulusFetch: 'lazy' */` are fetched asynchronously — there is a gap between DOM render and `connect()`. An element that relies solely on a WAAPI entry animation in `connect()` will flash at full opacity before the animation runs. Fix: add a CSS `animation` with `animation-fill-mode: both` on the element's base class — CSS handles entry, WAAPI handles exit only.

## General JS conventions

Always use full descriptive variable names in JavaScript and TypeScript — never abbreviate. `this._resizeObserver` not `this._ro`, `context` not `ctx`, `devicePixelRatio` not `dpr`, `remaining` not `rem`. The code is minified for production; source readability takes priority.

## Stimulus patterns

**`isMobile()` breakpoint check:** When a controller needs different behaviour on mobile vs desktop (e.g. different WAAPI keyframes), use `const isMobile = () => window.innerWidth < 640` — matching Tailwind's `sm:` breakpoint. Evaluate it at interaction time (inside the event handler), not at `connect()`, so it responds correctly after orientation changes.

**`<details>` animations:** CSS `max-height` transitions work for simple one-shot disclosure. For repeated open/close, use WAAPI in a Stimulus controller — CSS transitions silently stop working after the first cycle. To clip the animation cleanly, wrap the collapsible content in a `rounded-xl overflow-hidden` container.

## Turbo patterns

**Never submit through `fetch()`/JS — always use a form.** Mutations go through a plain HTML `<form method="POST">` or (preferred) a Symfony form, and Turbo handles the async submit and in-place update. Do not hand-roll `fetch()` POSTs in a Stimulus controller: it bypasses the eager document-level `submit` listener in `csrf_protection_controller.js` (so you have to re-implement the stateless double-submit CSRF dance by hand — a recurring source of silent 403s) and it duplicates error handling Turbo gives for free. The only acceptable reason to reach for `fetch()` is a genuine non-form interaction with no good Turbo equivalent — and that bar is high. A fieldless action (e.g. "resolve") is still a `<form>` (a submit button + CSRF token), just without a Symfony FormType. For in-place updates without a full-page visit, return a Turbo Stream (or scope the form to a `<turbo-frame>`).

**Returning a Turbo Stream from a controller — inline, no bespoke responder.** When a form-backed action updates the page in place, return the stream directly from the controller; do not extract a "responder" / "stream builder" service. The established pattern:

```php
if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
    return $this->redirectToRoute('app_…', ['id' => (string) $entity->id]); // no-JS / non-Turbo fallback
}
$html = $this->renderView('area/_thing.stream.html.twig', [...]);
return new Response($html, $status, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
```

- Always provide the redirect fallback (Post/Redirect/Get) for non-Turbo requests.
- Name stream templates `_<name>.stream.html.twig`. The leading `_` marks them partials so gamache's `ControllerTemplateNameRule` skips the controller; `.stream.` documents intent.
- A stream template wraps content in `<turbo-stream action="replace|update|append" target="dom-id">…</turbo-stream>`; the targeted element must render its own matching `id` so the stream can find it.
- Minor per-controller duplication of the format check + redirect is acceptable — it is the conventional shape and reads more clearly than a shared abstraction.

**Turbo Drive form submissions require a 4xx/5xx response to re-render:** When Turbo intercepts a form POST, a 200 response is treated as a successful navigation and silently discarded — the browser stays on the current page without re-rendering anything. Only 4xx/5xx responses cause Turbo to render the response body in place. When a controller handles a form submission and needs to re-display the form with errors, always return HTTP 422. The Symfony pattern:

```php
return $this->forward(SomeController::class, $params)
    ->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
```

**Turbo Stream animations (opt-in):** Hook `turbo:before-stream-render` globally to fade elements in/out. Add a `data-` attribute to any target element to opt it in.

**Disable prefetch on side-effecting GET links:** Turbo 8 prefetches links on hover by default (it issues a real GET to the `href`). For a link whose GET has side effects — `logout` being the canonical case, but also any "magic" GET that mutates state — that prefetch fires just from hovering, e.g. silently logging the user out. Add `data-turbo-prefetch="false"` to such links:

```twig
<a href="{{ path('app_logout') }}" data-turbo-prefetch="false">Log out</a>
```

Prefer making state changes POST endpoints; when a GET link with side effects is unavoidable, it must opt out of prefetch.

## Stimulus `connect()` timing and layout

**`connect()` fires before layout is computed:** During Turbo navigation, `connect()` fires via MutationObserver before the browser has computed layout — `getBoundingClientRect()` returns `(0, 0)`. Never do geometry-dependent setup synchronously in `connect()`. Use a `ResizeObserver` instead: its initial callback always fires after layout, making it the correct entry point for size-dependent canvas or element initialization.

**Canvas resize and ResizeObserver repaint:** Setting `canvas.width` clears the canvas. ResizeObserver callbacks fire *after* `requestAnimationFrame` in the browser's rendering pipeline, so resizing in a ResizeObserver callback leaves a blank canvas sitting in the paint queue until the next rAF. Fix: always call your draw function immediately after resizing the canvas inside the ResizeObserver callback — do not wait for the next frame.

## Design System

**Never use arbitrary CSS var values in markup** (e.g. no `[var(--accent)]` or other bracketed `var(...)` values in templates). Use the defined design-token / semantic Tailwind classes instead — every token should be exposed as a named utility. (gamache's `NoArbitraryValuesCheck` enforces this.)

**No arbitrary values in `app.css`.** All sizes, colours, and spacing must resolve to named Tailwind tokens. Bracket arbitrary values (`w-[45rem]`, `blur-[1.25rem]`) are not acceptable in `@apply` or as bare CSS properties — round to the nearest named token instead (`w-180`, `blur-xl`, `tracking-tight`).

When a CSS property has no Tailwind utility equivalent (e.g. `grid-template-columns`, `background-size`), derive the value from the Tailwind spacing token: `calc(var(--spacing) * n)`. This keeps the value on the Tailwind scale without a raw literal.

The only acceptable raw CSS in `@layer components` is genuinely non-expressible as utilities: multi-layer `background:` gradients referencing CSS variables, `mask-image` / `-webkit-mask-image`, and viewport-relative lengths (`vh`/`dvh`) which `@apply` cannot emit.

**Semantic color tokens in `@theme`** that map to a Tailwind palette color should use `var(--color-{palette}-{shade})` rather than a raw hex value (e.g. `--color-danger: var(--color-red-700)`, not `--color-danger: #c83a3a`). Brand palette tokens (the values in `:root`, `.theme-light`, `.theme-dark`) are the exception — they define the source of truth and may use raw hex/rgba.

**Do not add new element style rules to `@layer base`.** Tailwind's preflight handles the HTML reset for all elements including `dialog`. Component-specific styles belong in `@layer components` or as Tailwind utilities in templates. Existing rules in `@layer base` may have their raw CSS values converted to `@apply` during a normalisation pass — converting is not the same as adding a new rule.

**CSS semantic class names: no abbreviations.** Semantic and utility class names must spell out every word in full — `.sidebar-item`, not `.sb-item`; `.sidebar-footer`, not `.sb-foot`. This applies both to `app.css` definitions and to usages in templates.

### Icons

All icons must use the Symfony UX Icons bundle with Lucide. Never embed inline SVG. Prefer the Twig component form; `ux_icon()` is acceptable as an alternative.

```twig
<twig:UX:Icon name="lucide:x" class="w-3.5 h-3.5 shrink-0 mt-px" />
{# or #}
{{ ux_icon('lucide:x', {'class': 'w-3.5 h-3.5 shrink-0 mt-px'}) }}
```

`assets/icons/` is **gitignored** (`/assets/icons/lucide/` and `/assets/icons/simple-icons/` in `.gitignore`) — icon SVGs are never committed. UX Icons runs with `iconify.on_demand: true` (dev **and** test), so any Lucide icon resolves at render time from the iconify API: newly used icons render and pass tests/CI without being committed or pre-imported. `ux:icons:import` only populates a local cache (handy offline); it is not a prerequisite for a new icon to work. If icon SVGs are accidentally committed, remove them with `git rm --cached` — not `git rm`.

**Stroke colour:** Imported Lucide SVGs use `stroke="currentColor"`. Control the stroke colour via a text colour class on the icon or its parent — never hardcode `stroke="white"` as an attribute.
