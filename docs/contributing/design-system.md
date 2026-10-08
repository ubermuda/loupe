---
title: Design system
description: The tokens, the styleguide and the rules for the markup of the Loupe app.
---

The design system is the set of values and building blocks that give every page of the app one look. The tokens are values. The components are building blocks made of those values.

## The styleguide

Run the app in dev and open `/styleguide`. The page draws every token group and every catalog entry from the real stylesheet. A component appears there when its child card adds it. Today the catalog holds the button and the form parts.

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
| Input | A one-line text field | mono |
| Select | A native select | none |
| Textarea | A multi-line text field | none |
| Label | The text that names a field. It can be a label, legend, p, span or div | none |
| FormField | One field of a form: its label, widget, hint and errors, or a wrapper for your own body | none |
| FieldErrors | The list of errors of one field | none |
| Hint | A line of help under a field | none |

Write a button like this:

```twig
<twig:Ds:Button variant="primary" size="sm" type="submit">Save</twig:Ds:Button>
<twig:Ds:Button variant="ghost" href="{{ path('app_home') }}">Back</twig:Ds:Button>
```

The component passes every other attribute to the element and appends your `class`. An `href` renders a link. A Symfony form button draws its own tag, so give it the classes with `ds_button_class('primary')`:

```twig
{{ form_widget(form.save, {attr: {class: ds_button_class('primary')}}) }}
```

`just gamache` blocks a template that writes `lp-btn` by hand.

### The form parts

`FormField` draws a Symfony form field with its label, widget, hint and errors. Pass `kind` for a select or a textarea, and `attr` for the attributes of the widget:

```twig
<twig:Ds:FormField :fieldView="form.title" :widgetAttr="{placeholder: 'Title'}" hintText="Shown on the board" />
<twig:Ds:FormField :fieldView="form.color" widgetKind="select" />
```

Give `FormField` no `field` and it wraps your own body. `as="fieldset"` draws a fieldset.

A plain field uses `Input`, `Select` or `Textarea`. A Symfony widget in a custom layout takes the classes from functions, because it draws its own tag:

```twig
{{ form_label(form.name, null, {label_attr: {class: ds_label_class()}}) }}
{{ form_widget(form.kind, {attr: {class: ds_input_class('select')}}) }}
<twig:Ds:FieldErrors :fieldView="form.kind" data-field-errors="kind" />
```

`just gamache` blocks a template that writes `lp-input`, `lp-select`, `lp-textarea`, `lp-label`, `lp-form-field`, `lp-field-errors` or `lp-form-hint` by hand.

## The rules

1. Write no arbitrary value. Use a token or a named Tailwind scale step. `NoArbitraryValuesCheck` enforces this in `just gamache`.
2. Snap an off-scale value to the nearest token. A change to a token is a change to the system.
3. Use a building block from the catalog. Write no raw markup for a block that the catalog lists.

Read `project-frontend` for the full conventions of CSS, Stimulus and Turbo.

## Related pages

- [Development](development.md)
- [Architectural priorities](architectural-priorities.md)
