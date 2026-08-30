---
name: project-e2e
description: Use when writing, fixing, or debugging Playwright e2e tests under `e2e/`.
---

# E2E Tests

## General rules

- **CI's `e2e` check gates the suite. Run it locally only to work on one spec.** Push and read the check instead of running the full suite before a PR. Locally the suite is slower, destructive, and prone to failures that belong to the environment, not the diff (see `working-with-prs`). One spec: `just e2e tests/<area>/<spec>.spec.ts`.
- Never fix a failing test by manipulating the database (resetting passwords, deleting rows). A test that needs a specific DB state must create that state. A fix that needs a one-time DB operation breaks again on the next fresh environment.

## Turbo Drive navigation

Turbo Drive performs form submissions as XHR and pushes state with `history.pushState`. So `page.waitForURL(pattern)` waits for a `load` event that never comes. Use `expect(page).toHaveURL(pattern)`, which polls.

After a POST that redirects **to a different page**, assert that an element of the destination page is visible; that is safer than asserting the URL. When the redirect returns to **the same URL**, `toHaveURL` resolves immediately, so assert the updated form value instead (see below).

```typescript
// ✗ fails with Turbo — URL already matches before XHR completes
await page.waitForURL(/\/some\/path/);

// ✓ polls for URL without requiring a load event
await expect(page).toHaveURL(/\/some\/path/);

// ✓ even better — assert visible content from the destination page
await expect(page.getByRole('button', { name: 'Submit' })).toBeVisible();
```

## Forms that redirect to the same URL

This covers admin edit pages and **any in-place action that re-renders or returns to the current page** (for example a review verdict that PRGs back to the review page). The page never left, so `toHaveURL` and "an element is still visible" resolve **immediately**: the assertion passes whether the submit succeeded, failed validation, or is still in flight, and it silently races the POST under parallel load. Wait for a **post-submit content signal** instead: a success flash, the new row, or an updated field value.

Admin edit controllers are the canonical case: they redirect back to **the same edit URL** on success. Always follow `toHaveURL` with a second assertion for the **updated value**, which can only be true after the redirect re-renders the persisted data.

```typescript
await page.getByRole('button', { name: 'Save' }).click();
await expect(page).toHaveURL(/\/admin\/foos\/\d+\/edit(\?|$)/, { timeout: 15000 });
// Wait for the edit page to re-render with the new value before navigating away;
// without this the toHaveURL above resolves immediately (we were already on the edit URL).
await expect(page.locator('select[name="foo[status]"]')).toHaveValue('active', { timeout: 15000 });
await page.goto(`/admin/foos`);
```

Match the assertion to the field type: `toHaveValue(newValue)` for inputs and selects, `toBeChecked()` / `not.toBeChecked()` for checkboxes, `getByRole('spinbutton', …).toHaveValue(…)` for integer fields.

## Symfony Web Debug Toolbar

The `X-Playwright: 1` header disables WDT for all Playwright requests. Do not add `waitForLoadState('networkidle')` workarounds. Keep `suppressToolbar(page)` in `fixtures.ts`; it covers cached responses where the header was absent.

## Parallelism and PHP sessions

Each spec file that needs a login gets its own dedicated user through `createTest`; share one and a password or `displayName` change in one file invalidates another file's session. Mailpit is never cleared, so a stale email from an earlier run sends the test to another worktree's host and produces a wrong-host 404 that reads as an app bug. `test.use({ storageState: {} })` silently stops the user being registered at all.

Read `references/parallelism-and-sessions.md` before you write an authenticated spec, edit `e2e/tests/fixtures.ts`, or assert on mail.

## Mail is async: never resolve "the newest Mailpit message"

`getLatestEmailTo()` is safe only for a per-run-unique address with a single expected message. For everything else, mark-then-wait: capture `latestEmailIdWithSubject(...)` **before** the triggering action and pass it to `getEmailWithSubject(subject, afterId)`. Mailpit subject search is case-insensitive substring. Registration in fixtures uses this pattern. The authenticated fixture self-heals a registered-but-unverified account through real product surfaces (resend, then verify) instead of failing.

## The suite needs no messenger consumer, and two jobs run inline as a result

`PlaywrightSyncMiddleware` stamps **every** envelope dispatched during a request that carries `X-Playwright`, not only mail, so there is no worker to start and none to forget. It is registered under `when@dev` only, so production dispatch is untouched.

The accepted cost, decided 2026-08-16: `CancelSubscriptionMessage` and `GenerateDataExportMessage` also run inline, which puts their handlers inside the request's Doctrine transaction. Account deletion can therefore call Stripe with the transaction still open, and a Stripe failure rolls the deletion back instead of entering Messenger's retry flow. A Codex review argued for excluding these two jobs; that was declined deliberately, because removing the worker as a variable from e2e was the point.

So **a green suite does not exercise the real async path for those two jobs**, and a bug living only there is not caught here. Cover it with a functional test. Revisit the exclusion if either job grows behaviour that depends on the surrounding transaction having committed.

## Guest test scoping

For guest (unauthenticated) flows, put `test.use({ storageState: { cookies: [], origins: [] } })` at describe block level, so no session cookie is carried in. Do not rely on the absence of a `createTest` call; make the intent explicit.

## `getByLabel` substring collisions

When one label is a substring of another on the same page (`"New password"` and `"Repeat new password"`), `getByLabel('New password')` matches both and throws in strict mode. Always use `{ exact: true }` for a label that could be a substring of another label on the page.

## Selector scoping for nested same-tag elements

When the page nests same-tag elements (a `<details>` submenu inside a user-menu `<details>`), do not select with positional CSS such as `details > summary` or `header details summary`: both summaries match, so Playwright strict mode fails. Scope to a stable attribute on the outer element instead: `details[data-controller="user-menu"] > summary` for the parent, `details[data-controller="user-menu"] details > summary` for the nested one. Prefer a Stimulus `data-controller`, a route-bound `id`, or a unique class. Never use tree position.

## Asserting controls inside a closed `<details>`

Accessible-name locators (`getByRole`, `getByText`) match only elements in the **accessibility tree**, and a **closed `<details>`** (kebab / disclosure menus) hides its contents from it. So `row.getByRole('button', { name: 'Delete' })` resolves to **0 elements** when the disclosure is closed, though the button is in the DOM. To assert such a control, either:

- Open it first, then assert visibility: `await row.locator('summary[data-controller="user-menu"]').click()`, then `await expect(row.getByRole('button', { name: 'Delete' })).toBeVisible()`; or
- assert **DOM presence** with a CSS locator, which ignores visibility: `await expect(row.locator('input[name="…"]')).toHaveCount(1)`.

Never conclude "the control isn't rendered" from a `getByRole` count of 0 before you open the disclosure. The gated control can be present and merely collapsed.

## Keep hardcoded copy in sync with the app

When user-facing text changes (email subjects, notification bodies, flash messages, UI labels), any e2e test that hardcodes that string fails silently: it times out instead of failing with a clear assertion error. Before finishing a copy change:

1. `grep -r "old text" e2e/` to find the affected tests.
2. Update Mailpit search queries (`subject:"..."`, `bodyContains` strings), `getByText(...)`, `toHaveText(...)`, `toContain(...)`, and similar assertions.

Email subject changes in `src/Module/*/Notifier/` are the most common source.

## Default-value changes silently break helpers that rely on the default

A helper that creates an entity through a form *without setting a field* depends implicitly on that field's default. Change the default's create-time value (the form `*Request` DTO default, or an entity default read by a direct-instantiation handler) and those tests fail by **timeout**, not by a clear assertion error: the rendered UI branch changes, a different control appears, and the locator never shows up. Before changing a default, `grep` `e2e/` for helpers that create the entity, and set the field explicitly in the helper wherever the test depends on the old value.

## Run the spec after a locator refactor

When you change selectors (`data-*` attribute selectors to `getByRole(...)` / `getByLabel(...)`, or `page.locator(css)` to a semantic locator), neither `just lint` nor `just phpstan` exercises the change. The only validation is the affected spec: `just e2e tests/<area>/<spec>.spec.ts`. Never declare a selector refactor done from a clean static-analysis run: Playwright role and name resolution depends on the rendered DOM (icon `aria-hidden`, accessible-name composition), which nothing else checks.

## Dev seed endpoints (manual + visual verification)

For **manual / visual** verification (for example driving the app with the Chrome MCP), open sessions and seed data through the dev-only endpoints rather than the full Mailpit registration flow. All are gated `#[When('dev')]`, and they are faster because they bypass email round-trips.

- `POST /dev/register-and-verify` (form: `email`, `password`, optional `fullName`) creates an already-verified user; then log in through the `/login` form. Without `fullName` it derives one from the email, exactly as the registration form's client-side suggestion does. Any `username` in the body is accepted and ignored, so older specs that still post it keep working.
- `POST /dev/seed/document` (form: `title`, `markdown`) seeds a review document for the authenticated user and returns `{"documentId": "…"}`.
- `GET /dev/site-review-harness?email=<user-email>` issues a SiteReview API token for that user. Read it from `data-token="…"` in the response HTML, then `POST /api/site-review/batches` with header `Authorization: Bearer <token>` and a JSON body `{"comments":[{"body","selector","url","text"}, …]}` to seed a batch (returns `{"batchId": "…"}`).

The Playwright helper layer (`createTest`, `registerAndVerify`) stays the path **inside specs**; these endpoints are the fast path for ad-hoc and browser-driven verification. Some specs already use them directly, for example `review/review-loop.spec.ts`.
