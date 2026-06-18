---
name: project-translations
description: Use when adding or modifying UI strings, adding translation keys, removing form fields, or adding a new locale.
---

# Translations

The app supports multiple locales. There are two translation mechanisms:

**XLF string translations** live in `translations/` (e.g. `messages.en.xlf`). Use these for UI strings via `{% trans %}` or the `trans` filter.

When adding a new translation key, add it to **all** locale files in the same edit. Missing a locale causes it to fall back to the bare key string at runtime.

**Locale-specific templates** are used when a page differs structurally between locales (e.g. legal pages, date format partials). Name them `template.{locale}.html.twig` (e.g. `privacy.en.html.twig`, `dashboard/_card_date.en.html.twig`). Every locale-specific template must exist for all supported locales — if you add or modify one, add/update the equivalent file for every other locale.

**Cleaning up orphan keys**: when you remove a field from a Symfony form, also delete the corresponding `*.form.<field>.label` / `*.form.<field>.placeholder` entries (and any `*.form.save.label` if the whole form is gone) from all locale files. PHPStan and CI do not flag unused translation keys, and there is no scanner for them — they will silently rot until someone notices. If the entire form has no fields left, also delete the `*Type` and `*Request` classes; an empty `data_class` DTO is dead code.

## Translation keys inside FormType classes

Each FormType must define and use translation keys scoped to its own feature path. Do not borrow keys from another form's namespace, even when the English text happens to be identical today — each FormType uses its own derived prefix.

The required prefix is `{module_lowercase}.form.{block_prefix}.*`, where the block prefix is the class name with `Type` stripped and CamelCase converted to snake_case word-by-word. Compound words inside abbreviations each become a separate snake segment: `ImportGitHubRepoFormType` → strip `Type` → `ImportGitHubRepoForm` → `import_git_hub_repo_form` (not `import_github_repo_form`).

String values passed to user-facing form field options (`label`, `help`, `placeholder`, `invalid_message`, `choice_label`) must follow the `module.form.block_prefix.` key prefix convention, even when the translation lives in `translations/validators.en.xlf` rather than `messages.en.xlf`.

The key name and the domain file are independent concerns. `invalid_message` on a `RepeatedType` is a validator message, so it belongs in `validators.en.xlf` — but its key must still start with the form prefix (e.g. `account.form.change_password_form.invalid_message`), not an ad-hoc validator namespace (e.g. `account.change_password.validator.password_mismatch`).

`FormTypeTranslationKeysCheck` enforces this convention at CI time with `Severity::Error`. There is no inline suppression mechanism — rename the key if the check fires.

## Which file to use

Two files serve different purposes — always use the correct one:

- `translations/messages.en.xlf` — UI strings: form labels, placeholders, page titles, headings, flash messages, anything rendered via `{{ 'key'|trans }}` in Twig or `'label' => 'key'` in a FormType.
- `translations/validators.en.xlf` — Symfony `#[Assert]` constraint messages only: any string passed as `message:` in `#[Assert\NotBlank]`, `#[Assert\Regex]`, `#[Assert\Choice]`, etc.

Putting a constraint message key in `messages.en.xlf` will silently fail — the Symfony Validator reads `validators.*`, not `messages.*`.

## Pluralization

Use Symfony interval syntax (not ICU). Two required gotchas:
- Always include the `{0}` case — omitting it throws `RuntimeException` at render time if the count can be zero.
- Use `%count%` with percent signs as the substitution key; bare `count` is silently ignored.
