---
name: project-translations
description: "Use when adding or modifying UI strings, adding translation keys, removing form fields, or adding a new locale."
---

# Translations

The app supports multiple locales. Translations live in XLF string files and in locale-specific templates.

## XLF string translations

XLF string translations live in `translations/`, for example `messages.en.xlf`. Use them for UI strings through `{% trans %}` or the `trans` filter.

Add a new translation key to every locale file in the same edit. A locale that misses the key falls back to the bare key string at runtime.

## Locale-specific templates

Use a locale-specific template when a page differs in structure between locales. Legal pages and date format partials are the usual cases. Name the file `template.{locale}.html.twig`, for example `privacy.en.html.twig` or `dashboard/_card_date.en.html.twig`.

Every locale-specific template must exist for all supported locales. When you add or change one, add or update the equivalent file for every other locale.

## Cleaning up orphan keys

When you remove a field from a Symfony form, delete its translation entries from all locale files. Those entries are `*.form.<field>.label` and `*.form.<field>.placeholder`. Delete `*.form.save.label` as well if the whole form is gone. PHPStan and CI do not flag unused translation keys, and there is no scanner for them, so orphans silently rot until someone notices.

If the form has no fields left, delete the `*Type` and `*Request` classes as well. An empty `data_class` DTO is dead code.

## Translation keys inside FormType classes

Each FormType must define and use translation keys scoped to its own feature path. Do not borrow keys from another form's namespace, even when the English text is identical today. Each FormType uses its own derived prefix.

The required prefix is `{module_lowercase}.form.{block_prefix}.*`. The block prefix is the class name with `Type` stripped, and CamelCase converted to snake_case word by word. Each compound word inside an abbreviation becomes its own snake segment. `ImportGitHubRepoFormType` strips `Type` to `ImportGitHubRepoForm`, which gives `import_git_hub_repo_form`, not `import_github_repo_form`.

The form field options a user sees are `label`, `help`, `placeholder`, `invalid_message` and `choice_label`. Every string value you pass to one of them must follow the `module.form.block_prefix.` key prefix convention. This rule holds even when the translation lives in `translations/validators.en.xlf` rather than `messages.en.xlf`.

The key name and the domain file are independent concerns. `invalid_message` on a `RepeatedType` is a validator message, so it belongs in `validators.en.xlf`. Its key must still start with the form prefix, for example `account.form.change_password_form.invalid_message`. Do not use an ad-hoc validator namespace such as `account.change_password.validator.password_mismatch`.

`FormTypeTranslationKeysCheck` enforces this convention at CI time with `Severity::Error`. The check has no inline suppression mechanism. Rename the key when the check fires.

## Which file to use

- `translations/messages.en.xlf` holds UI strings. These are form labels, placeholders, page titles, headings and flash messages. It also holds anything rendered with `{{ 'key'|trans }}` in Twig or `'label' => 'key'` in a FormType.
- `translations/validators.en.xlf` holds Symfony `#[Assert]` constraint messages only. Use it for any string passed as `message:` in `#[Assert\NotBlank]`, `#[Assert\Regex]`, `#[Assert\Choice]` and similar constraints.

A constraint message key in `messages.en.xlf` will silently fail. The Symfony Validator reads `validators.*`, not `messages.*`.

## Some `DomainErrors` keys are deliberately untranslated

A `DomainErrors` key is user-facing only if something renders it. Where the form has no field to attach the error to, the key is a payload value that the controller inspects and never displays. Such a key has no trans-unit, on purpose.

The Billing keys are the standing example. `billing.error.disabled`, `billing.error.no_active_price` and `billing.error.no_customer` are absent from `messages.en.xlf`, because the checkout and portal endpoints are fieldless buttons. Those controllers flash `billing.flash.checkout_unavailable` and `billing.flash.portal_unavailable` instead, and those two keys are translated.

Do not add a trans-unit to fix a key that appears to be missing one. Check first whether anything renders the key. If nothing renders it, the gap is the design.

## Pluralization

Use Symfony interval syntax, not ICU.

- Always include the `{0}` case. Omitting it throws `RuntimeException` at render time if the count can be zero.
- Use `%count%`, with the percent signs, as the substitution key. A bare `count` is silently ignored.
