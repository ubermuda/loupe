---
name: project-e2e
description: Use when writing, fixing, or debugging Playwright e2e tests under `e2e/`.
---

# E2E Tests

## General rules

- Never fix a failing test by manipulating the database (resetting passwords, deleting rows, etc.). If the test relies on a specific DB state, the test itself must create that state. A "fix" that requires a one-time DB operation will break again on the next fresh environment.

## Turbo Drive navigation

Turbo Drive intercepts form submissions and performs them as XHR requests, pushing state via `history.pushState`. This means standard `page.waitForURL(pattern)` (which waits for a `load` event) will **not** work after form submissions — use `expect(page).toHaveURL(pattern)` instead, which polls without requiring a navigation event.

After a form POST that redirects **to a different page**, the safest way to confirm the navigation completed is to assert that an element from the destination page is visible rather than asserting the URL. When the redirect goes **back to the same URL** (as admin edit controllers do), `toHaveURL` will resolve immediately — assert the updated value in the form instead (see "Forms that redirect to the same URL" below).

```typescript
// ✗ fails with Turbo — URL already matches before XHR completes
await page.waitForURL(/\/some\/path/);

// ✓ polls for URL without requiring a load event
await expect(page).toHaveURL(/\/some\/path/);

// ✓ even better — assert visible content from the destination page
await expect(page.getByRole('button', { name: 'Submit' })).toBeVisible();
```

## Forms that redirect to the same URL

Any form whose controller redirects back to the URL it was submitted from — admin edit pages, **and any in-place action that re-renders or returns to the current page** (e.g. a review verdict that PRGs back to the review page) — creates this trap: `toHaveURL`, and "an element is still visible", resolve **immediately** because the page never left. The assertion passes whether the submit succeeded, failed validation, or is still in flight, so it silently races the POST under parallel load. Wait for a **post-submit content signal** instead — a success flash, the newly-added row, or an updated field value — before asserting or navigating away.

The admin-edit case below is the canonical example; the rule applies to every same-URL redirect.

Admin edit controllers redirect back to **the same edit URL** on success. This creates a specific testing trap: `expect(page).toHaveURL(/...\/edit/)` resolves immediately because the page was *already* on that URL before the Save click. The assertion passes whether the form save succeeded, failed validation, or is still in flight.

Always follow the `toHaveURL` assertion with a second assertion that the form now shows the **updated value** — this can only be true after the redirect re-renders the page with the newly persisted data:

```typescript
await page.getByRole('button', { name: 'Save' }).click();
await expect(page).toHaveURL(/\/admin\/foos\/\d+\/edit(\?|$)/, { timeout: 15000 });
// Wait for the edit page to re-render with the new value before navigating away;
// without this the toHaveURL above resolves immediately (we were already on the edit URL).
await expect(page.locator('select[name="foo[status]"]')).toHaveValue('active', { timeout: 15000 });
await page.goto(`/admin/foos`);
```

Use whichever assertion matches the field type: `toHaveValue(newValue)` for inputs and selects, `toBeChecked()` / `not.toBeChecked()` for checkboxes, or `getByRole('spinbutton', …).toHaveValue(…)` for integer fields.

## Symfony Web Debug Toolbar

WDT is disabled for all Playwright requests via the `X-Playwright: 1` header — do not add `waitForLoadState('networkidle')` workarounds. Keep `suppressToolbar(page)` in `fixtures.ts` — it covers cached responses where the header was absent.

## Parallelism and PHP sessions

Tests run `fullyParallel: false` (parallel at the file level). Each spec file that needs an authenticated session calls `createTest(credentials)` from `e2e/tests/fixtures.ts` with its own dedicated user. The worker fixture logs in as that user (registering and verifying via Mailpit on first run), giving every file its own PHP session — this avoids CSRF token conflicts and means a mutation to one user (password re-hash, displayName change, etc.) cannot invalidate another file's session.

There is no shared setup phase — users are created lazily by the worker fixture the first time the spec file runs.

Tests that use Mailpit (register, delete account) search by recipient address rather than clearing all messages, so they don't race when running in parallel.

**Helpers in `e2e/tests/helpers.ts`:**
- `registerAndVerify(page, request, credentials)` — fills the registration form, polls Mailpit for the verification link, and navigates to it
- `fetchVerificationUrl(request, email)` — polls Mailpit until a verification email arrives for the given address and returns the URL

**Global setup (`e2e/global-setup.ts`):** runs once before any workers start. Use it for one-time prerequisites that all tests depend on. Do not use `test.beforeAll` for this — the `{ timeout }` option on `beforeAll` is unreliable when `test` comes from `createTest`, and multiple workers racing to do the same setup causes flakiness.

**Adding a new authenticated spec file:** call `createTest({ email: 'e2e-your-feature@example.com', password: 'e2e_password_123', name: '...' })` at the top of the file. The user is created automatically on first run — no central registry to update.

**Session invalidation risk:** any controller that writes back to the `User` entity (e.g. updating `displayName` or `password`) causes Symfony to detect the serialized user changed and invalidates that session on the next request. Because each file has its own user this is contained to that file. Use `test.use({ storageState: {} })` plus a `beforeEach` login whenever:
- a `beforeAll` creates a second context using `workerStorageState` before an authenticated test (the second context can leave the server-side session in an unexpected state)

**Warning:** `test.use({ storageState: { cookies: [], origins: [] } })` severs the `workerStorageState` fixture from Playwright's dependency graph, so the user is never registered. When using this pattern, reference `workerStorageState` explicitly in `beforeEach` — `void workerStorageState` is enough — so the fixture still runs.

**Tests that mutate the user's password** (like `can change password`) should use a throwaway timestamped user via `registerAndVerify` rather than mutating the shared worker user. This avoids leaving the DB in a broken state if the test fails partway through.

## Guest test scoping

Tests that exercise guest (unauthenticated) flows should use `test.use({ storageState: { cookies: [], origins: [] } })` at the describe block level to ensure no session cookie is carried in. Do not rely on the absence of a `createTest` call — make the intent explicit.

## DaisyUI required field labels

The DaisyUI form theme appends a `*` span to required field labels. `getByLabel('Email address', { exact: true })` won't match — use `getByLabel('Email address *', { exact: true })` or drop `exact` when the label is unique enough.

## `getByLabel` substring collisions

When one label is a substring of another on the same page (e.g. `"New password"` vs `"Repeat new password"`), `getByLabel('New password')` matches both and throws in strict mode. Always use `{ exact: true }` for labels that could be a substring of another label on the page.

## Selector scoping for nested same-tag elements

When the page nests same-tag elements (e.g. a `<details>` submenu inside a user-menu `<details>`), do not select with positional CSS like `details > summary` or `header details summary` — Playwright strict mode will fail because both summaries match. Scope to a stable identifying attribute on the outer element instead: `details[data-controller="user-menu"] > summary` for the parent and `details[data-controller="user-menu"] details > summary` for the nested one. Prefer Stimulus `data-controller`, route-bound `id`, or a unique class — never tree position.

## Keep hardcoded copy in sync with the app

When user-facing text changes — email subjects, notification bodies, flash messages, UI labels — any e2e test that hardcodes that string will fail silently: it times out instead of failing with a clear assertion error. Before finishing any copy change:

1. `grep -r "old text" e2e/` to find affected tests.
2. Update Mailpit search queries (`subject:"..."`, `bodyContains` strings), `getByText(...)`, `toHaveText(...)`, `toContain(...)`, and similar assertions.

Email subject changes in `src/Module/*/Notifier/` are the most common source of this pattern.

## Run the spec after a locator refactor

When you change selectors (e.g. swapping `data-*` attribute selectors for `getByRole(...)` / `getByLabel(...)`, or moving from `page.locator(css)` to a semantic locator), neither `just lint` nor `just phpstan` exercises the change. The only validation is running the affected spec: `just e2e tests/<area>/<spec>.spec.ts`. Don't declare a selector refactor done from a clean static-analysis run — Playwright role/name resolution depends on the rendered DOM (icon `aria-hidden`, accessible-name composition) that nothing else checks.

## Dev seed endpoints (manual + visual verification)

For setting up an authenticated browser session or seeding data during **manual / visual** verification (e.g. driving the app with the Chrome MCP), use the dev-only endpoints — all gated `#[When('dev')]` — rather than the full Mailpit registration flow. They are faster and bypass email round-trips:

- `POST /dev/register-and-verify` (form: `username`, `fullName`, `email`, `password`) — creates an already-verified user. Then log in via the `/login` form.
- `POST /dev/seed/document` (form: `title`, `markdown`) — seeds a review document for the authenticated user; returns `{"documentId": "…"}`.
- `GET /dev/site-review-harness?email=<user-email>` — issues a SiteReview API token for that user; read it from `data-token="…"` in the response HTML, then `POST /api/site-review/batches` with header `Authorization: Bearer <token>` and a JSON body `{"comments":[{"body","selector","url","text"}, …]}` to seed a batch (returns `{"batchId": "…"}`).

The Playwright helper layer (`createTest`, `registerAndVerify`) remains the path **inside specs**; these endpoints are the fast path for ad-hoc and browser-driven verification. The same endpoints are already used directly by some specs (e.g. `review/review-loop.spec.ts`).
