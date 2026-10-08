---
title: Design system
description: The tokens, the styleguide and the rules for the markup of the Loupe app.
---

The design system is the set of values and building blocks that give every page of the app one look. The tokens are values. The components are building blocks made of those values.

## The styleguide

Run the app in dev and open `/styleguide`. The page draws every token group and every catalog entry from the real stylesheet. A component appears there when its child card adds it.

The route exists in dev only. In production it does not exist.

## The tokens

All tokens live in `assets/styles/tokens.css`. `assets/styles/app.css` imports it. A token is a CSS custom property, and the comment above it is its use. A `@group` comment starts a group.

| Group | What it holds |
|---|---|
| colour | The raw colour values, and the Tailwind names for them |
| type | The font stacks. The Tailwind text sizes carry the scale |
| spacing | One step of the spacing scale. Every gap and size is a multiple of it |
| radius | The corner radii |
| shadow | The shadows of surfaces that float above the page |
| motion | The durations and the easing curves |

A new token appears on the styleguide with no other change.

## The stylesheets

1. `tokens.css` holds the tokens and nothing else.
2. `components/<name>.css` holds the styles of one building block. A child card adds each file with its component.
3. `app.css` imports both, and holds the rules of the features.

The widget and the email stylesheet cannot import `tokens.css`. They hold copies of the values they need, and a test keeps each copy equal to the token.
In `email.css`, each copied value names its token in a trailing comment, such as `/* --accent */`, and `EmailTokensMatchAppTest` compares the two.

## The components

A building block is a Twig component under `templates/components/Ds/`. Its CSS lives in `assets/styles/components/<name>.css`, and its entry in `src/Module/DesignSystem/Catalog.php` lists its root class, variants and states.

| Component | Use | Variants |
|---|---|---|
| Button | Any action or link that looks like a button | primary, inverse, outline, success, danger, ghost, danger-ghost, icon, compact, open, on-card. Sizes sm and lg |
| Flash | A message after an action, with a dismiss button | success, error, warning, info |
| EmptyState | A panel that says a list or page has nothing yet | none. Takes an icon, a title, a body and one link |
| Badge | The status of a document in a list | in-review, draft, approved, changes-requested |
| Tag | A short label, such as a card type or a column | neutral, lime, purple, green, amber, red, teal, sky, blue, indigo, pink, orange |
| StatusChip | A state with a coloured dot and an optional reason tooltip | pending, addressed, resolved, ok, failed, neutral |
| Dialog | A modal that the `modal` Stimulus controller opens | document, search |
| Tabs | A strip of links or tab buttons with an underline | none |
| Pagination | The previous, next and page-number control of a list | none |
| Tooltip | A text bubble under its anchor | none |

Write a button like this:

```twig
<twig:Ds:Button variant="primary" size="sm" type="submit">Save</twig:Ds:Button>
<twig:Ds:Button variant="ghost" href="{{ path('app_home') }}">Back</twig:Ds:Button>
```

The component passes every other attribute to the element and appends your `class`. An `href` renders a link. A Symfony form button draws its own tag, so give it the classes with `ds_button_class('primary')`:

```twig
{{ form_widget(form.save, {attr: {class: ds_button_class('primary')}}) }}
```

Write the feedback parts like this:

```twig
<twig:Ds:Flash severity="error" :dismissLabel="'flash.dismiss'|trans">{{ message }}</twig:Ds:Flash>
<twig:Ds:EmptyState icon="lucide:inbox" :title="'x.empty'|trans" :body="'x.empty.body'|trans" />
<twig:Ds:Badge :status="document.status.value">{{ label }}</twig:Ds:Badge>
<twig:Ds:Tag tone="amber">{{ label }}</twig:Ds:Tag>
<twig:Ds:StatusChip modifier="ok" :label="'x.state'|trans" :reason="reason" />
```

A Tag takes `as="li"` inside a list. A StatusChip takes `:dot="false"` for no dot, and `as="button"` for a chip that toggles a panel.

Write a dialog, tabs, pagination and a tooltip like this:

```twig
<twig:Ds:Dialog size="document" aria-labelledby="edit-title" data-action="cancel->modal#close">
    <h3 id="edit-title" class="lp-dialog-title">Edit</h3>
</twig:Ds:Dialog>

<twig:Ds:Tabs class="lp-analytics-tabs" aria-label="Sections">
    <a class="lp-tabs__tab" href="{{ path('app_home') }}" aria-current="page">Home</a>
</twig:Ds:Tabs>

<twig:Ds:Pagination route="app_projects" :page="page" :totalPages="totalPages" :pageList="pageList" />

<twig:Ds:Tooltip id="why-1">The reason.</twig:Ds:Tooltip>
```

The dialog sets `data-modal-target="dialog"` itself. The tabs take `tag="div"` for a tab list that is not a navigation. The pagination draws nothing when there is one page. The feature that owns the anchor decides when a tooltip shows.


## The Claude Design copy

A Claude Design project holds a copy of the design system. The app repository is the source of the copy.

Run this command to write the copy into a directory:

```bash
bin/console app:design-system:export <dir>
```

The command prints each path it wrote, relative to the directory, one per line.

| Path | What it holds |
|---|---|
| `tokens/*.css` | The tokens, split by group from `tokens.css`, and the fonts and base resets |
| `styles.css` | The Tailwind build of `assets/styles/design-system.css`, with the real `lp-` rules |
| `components/<group>/` | A React wrapper, a type file and a prompt file for each catalog entry, and one card for the group |
| `guidelines/` | One card for each token group |
| `readme.md` and `SKILL.md` | This page, and the skill that points at it |
| `assets/fonts/` | The font files and their licence |

The wrappers write the same class names as the Twig components. A wrapper adds no style of its own.

The command never writes `_ds_bundle.js`, `_ds_manifest.json`, `_adherence.oxlintrc.json`, `.thumbnail` or `ds-mount.js`. The platform makes those files.

The command does not copy icons. A catalog entry that needs icons must add them to the command.

## The rules

1. Write no arbitrary value. Use a token or a named Tailwind scale step. `NoArbitraryValuesCheck` enforces this in `just gamache`.
2. Snap an off-scale value to the nearest token. A change to a token is a change to the system.
3. Use a building block from the catalog. Write no raw markup for a block that the catalog lists.

Read `project-frontend` for the full conventions of CSS, Stimulus and Turbo.

## Related pages

- [Development](development.md)
- [Architectural priorities](architectural-priorities.md)

`just gamache` blocks a template that writes `lp-btn`, `lp-flash`, `lp-empty-state`, `lp-badge`, `lp-tag`, `lp-status-chip`, `lp-dialog`, `lp-tabs`, `lp-pagination` or `lp-tooltip` by hand.
